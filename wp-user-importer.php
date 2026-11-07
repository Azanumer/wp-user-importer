<?php
/**
 * Plugin Name: WP User Importer
 * Plugin URI: https://github.com/Azanumer/wp-user-importer
 * Description: Import users from a CSV file — dry-run preview first, then import with duplicate handling (skip or update), role assignment and optional welcome email.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-user-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WUI_VERSION', '1.0.0' );

/**
 * Parse an uploaded CSV file into user rows.
 *
 * Expected header (case-insensitive, extra columns ignored):
 *   username, email, first_name, last_name, role, password
 *
 * @param string $path Path to the uploaded CSV file.
 * @return array{rows: array, errors: array} Parsed rows + per-line errors.
 */
function wui_parse_csv( $path ) {
	$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
	if ( false === $handle ) {
		return array(
			'rows'   => array(),
			'errors' => array( __( 'Could not open the uploaded CSV file.', 'wp-user-importer' ) ),
		);
	}

	$rows   = array();
	$errors = array();
	$header = null;
	$line   = 0;

	while ( false !== ( $data = fgetcsv( $handle ) ) ) {
		$line++;
		// Skip blank lines.
		if ( 1 === count( $data ) && '' === trim( (string) $data[0] ) ) {
			continue;
		}
		if ( null === $header ) {
			// Strip a UTF-8 BOM from the first cell if Excel added one.
			$data[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $data[0] );
			$header  = array_map(
				static function ( $cell ) {
					return strtolower( trim( (string) $cell ) );
				},
				$data
			);
			if ( ! in_array( 'username', $header, true ) || ! in_array( 'email', $header, true ) ) {
				$errors[] = __( 'CSV header must contain at least "username" and "email" columns.', 'wp-user-importer' );
				break;
			}
			continue;
		}
		if ( count( $data ) !== count( $header ) ) {
			$errors[] = sprintf(
				/* translators: %d: CSV line number. */
				__( 'Line %d: column count does not match the header — row skipped.', 'wp-user-importer' ),
				$line
			);
			continue;
		}
		$rows[] = array_combine( $header, $data ) + array( '_line' => $line );
	}

	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
	return array( 'rows' => $rows, 'errors' => $errors );
}

/**
 * Validate one parsed row. Returns a list of problems (empty = valid).
 */
function wui_validate_row( $row ) {
	$problems = array();

	$username = isset( $row['username'] ) ? trim( (string) $row['username'] ) : '';
	$email    = isset( $row['email'] ) ? trim( (string) $row['email'] ) : '';

	if ( '' === $username ) {
		$problems[] = __( 'username is empty', 'wp-user-importer' );
	} elseif ( ! validate_username( $username ) ) {
		$problems[] = __( 'username contains invalid characters', 'wp-user-importer' );
	}
	if ( '' === $email ) {
		$problems[] = __( 'email is empty', 'wp-user-importer' );
	} elseif ( ! is_email( $email ) ) {
		$problems[] = __( 'email is not a valid address', 'wp-user-importer' );
	}
	if ( ! empty( $row['role'] ) ) {
		$roles = array_keys( get_editable_roles() );
		if ( ! in_array( strtolower( trim( (string) $row['role'] ) ), $roles, true ) ) {
			$problems[] = sprintf(
				/* translators: %s: role name from the CSV. */
				__( 'role "%s" does not exist on this site', 'wp-user-importer' ),
				$row['role']
			);
		}
	}
	return $problems;
}

/**
 * Import one validated row. Returns array( 'status' => created|updated|skipped|error, 'message' => string ).
 */
function wui_import_row( $row, $duplicate_mode, $default_role, $send_email ) {
	$username = sanitize_user( $row['username'] );
	$email    = sanitize_email( $row['email'] );
	$role     = ! empty( $row['role'] ) ? strtolower( trim( (string) $row['role'] ) ) : $default_role;
	$password = ! empty( $row['password'] ) ? (string) $row['password'] : wp_generate_password( 12, true );

	$existing = get_user_by( 'login', $username );
	if ( ! $existing ) {
		$existing = get_user_by( 'email', $email );
	}

	if ( $existing ) {
		if ( 'skip' === $duplicate_mode ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'user already exists', 'wp-user-importer' ),
			);
		}
		// Update mode: refresh name/role, never overwrite the password unless one was given.
		$update = array( 'ID' => $existing->ID, 'role' => $role );
		if ( isset( $row['first_name'] ) ) {
			$update['first_name'] = sanitize_text_field( $row['first_name'] );
		}
		if ( isset( $row['last_name'] ) ) {
			$update['last_name'] = sanitize_text_field( $row['last_name'] );
		}
		if ( ! empty( $row['password'] ) ) {
			$update['user_pass'] = $password;
		}
		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			return array( 'status' => 'error', 'message' => $result->get_error_message() );
		}
		return array( 'status' => 'updated', 'message' => '' );
	}

	$userdata = array(
		'user_login' => $username,
		'user_email' => $email,
		'user_pass'  => $password,
		'role'       => $role,
	);
	if ( isset( $row['first_name'] ) ) {
		$userdata['first_name'] = sanitize_text_field( $row['first_name'] );
	}
	if ( isset( $row['last_name'] ) ) {
		$userdata['last_name'] = sanitize_text_field( $row['last_name'] );
	}

	$user_id = wp_insert_user( $userdata );
	if ( is_wp_error( $user_id ) ) {
		return array( 'status' => 'error', 'message' => $user_id->get_error_message() );
	}

	if ( $send_email ) {
		wp_new_user_notification( $user_id, null, 'user' );
	}

	return array( 'status' => 'created', 'message' => '' );
}

add_action( 'admin_menu', 'wui_register_menu' );
function wui_register_menu() {
	add_users_page(
		__( 'Import Users', 'wp-user-importer' ),
		__( 'Import Users', 'wp-user-importer' ),
		'create_users',
		'wui-user-importer',
		'wui_render_page'
	);
}

function wui_render_page() {
	if ( ! current_user_can( 'create_users' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-user-importer' ) );
	}

	$results = null;
	$errors  = array();
	$preview = null;

	if ( isset( $_FILES['wui_csv'] ) && isset( $_POST['wui_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wui_nonce'] ), 'wui_import' ) ) {
		$file = $_FILES['wui_csv'];
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$errors[] = __( 'Upload failed — please try again.', 'wp-user-importer' );
		} elseif ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			$errors[] = __( 'Upload failed — the file was not received correctly.', 'wp-user-importer' );
		} else {
			$parsed  = wui_parse_csv( $file['tmp_name'] );
			$errors  = $parsed['errors'];
			$rows    = $parsed['rows'];
			$dry_run = isset( $_POST['wui_dry_run'] );

			$duplicate_mode = isset( $_POST['wui_duplicates'] ) && 'update' === $_POST['wui_duplicates'] ? 'update' : 'skip';
			$default_role   = isset( $_POST['wui_default_role'] ) ? sanitize_key( $_POST['wui_default_role'] ) : get_default_role();
			if ( ! isset( get_editable_roles()[ $default_role ] ) ) {
				$default_role = get_default_role();
			}
			$send_email = isset( $_POST['wui_send_email'] );

			$counts = array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'error'   => 0,
			);
			$detail = array();

			foreach ( $rows as $row ) {
				$problems = wui_validate_row( $row );
				$line     = $row['_line'];
				if ( $problems ) {
					$counts['error']++;
					$detail[] = array(
						'line'    => $line,
						'user'    => $row['username'] ?? '',
						'status'  => 'error',
						'message' => implode( '; ', $problems ),
					);
					continue;
				}
				if ( $dry_run ) {
					$exists = get_user_by( 'login', sanitize_user( $row['username'] ) ) ?: get_user_by( 'email', sanitize_email( $row['email'] ) );
					$status = $exists ? ( 'update' === $duplicate_mode ? 'updated' : 'skipped' ) : 'created';
					$counts[ $status ]++;
					if ( ! $exists ) {
						$note = __( 'would create', 'wp-user-importer' );
					} elseif ( 'update' === $duplicate_mode ) {
						$note = __( 'would update existing user', 'wp-user-importer' );
					} else {
						$note = __( 'would skip — already exists', 'wp-user-importer' );
					}
					$detail[] = array(
						'line'    => $line,
						'user'    => $row['username'],
						'status'  => $status,
						'message' => $note,
					);
					continue;
				}
				$out           = wui_import_row( $row, $duplicate_mode, $default_role, $send_email );
				$counts[ $out['status'] ]++;
				$detail[]      = array(
					'line'    => $line,
					'user'    => $row['username'],
					'status'  => $out['status'],
					'message' => $out['message'],
				);
			}

			if ( $dry_run ) {
				$preview = array( 'counts' => $counts, 'detail' => $detail );
			} else {
				$results = array( 'counts' => $counts, 'detail' => $detail );
			}
		}
	}

	$roles = get_editable_roles();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Import Users from CSV', 'wp-user-importer' ); ?></h1>

		<?php foreach ( $errors as $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endforeach; ?>

		<?php if ( $results ) : ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: 1: created, 2: updated, 3: skipped, 4: errors. */
					esc_html__( 'Import finished: %1$d created, %2$d updated, %3$d skipped, %4$d errors.', 'wp-user-importer' ),
					$results['counts']['created'],
					$results['counts']['updated'],
					$results['counts']['skipped'],
					$results['counts']['error']
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( $preview ) : ?>
			<div class="notice notice-info"><p>
				<?php
				printf(
					/* translators: 1: would create, 2: would update, 3: would skip, 4: errors. */
					esc_html__( 'Dry run — nothing was imported. Would create %1$d, update %2$d, skip %3$d, with %4$d errors.', 'wp-user-importer' ),
					$preview['counts']['created'],
					$preview['counts']['updated'],
					$preview['counts']['skipped'],
					$preview['counts']['error']
				);
				?>
			</p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Upload a CSV with columns username, email, first_name, last_name, role, password. Only username and email are required — missing passwords are generated, missing roles use the default role below. Always run a dry run first.', 'wp-user-importer' ); ?></p>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'wui_import', 'wui_nonce', false ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wui_csv"><?php esc_html_e( 'CSV file', 'wp-user-importer' ); ?></label></th>
					<td><input type="file" name="wui_csv" id="wui_csv" accept=".csv,text/csv" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Duplicates', 'wp-user-importer' ); ?></th>
					<td>
						<label><input type="radio" name="wui_duplicates" value="skip" checked> <?php esc_html_e( 'Skip rows whose username or email already exists', 'wp-user-importer' ); ?></label><br>
						<label><input type="radio" name="wui_duplicates" value="update"> <?php esc_html_e( 'Update existing users (name + role; password only if given in the CSV)', 'wp-user-importer' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wui_default_role"><?php esc_html_e( 'Default role', 'wp-user-importer' ); ?></label></th>
					<td>
						<select name="wui_default_role" id="wui_default_role">
							<?php foreach ( $roles as $slug => $role ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, get_default_role() ); ?>><?php echo esc_html( $role['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'wp-user-importer' ); ?></th>
					<td>
						<label><input type="checkbox" name="wui_send_email" value="1"> <?php esc_html_e( 'Send the new-user welcome email to each created account', 'wp-user-importer' ); ?></label><br>
						<label><input type="checkbox" name="wui_dry_run" value="1" checked> <?php esc_html_e( 'Dry run — preview only, import nothing', 'wp-user-importer' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Import users', 'wp-user-importer' ), 'primary' ); ?>
		</form>

		<?php
		$detail = $results ? $results['detail'] : ( $preview ? $preview['detail'] : array() );
		if ( $detail ) :
			?>
			<h2><?php esc_html_e( 'Row-by-row results', 'wp-user-importer' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Line', 'wp-user-importer' ); ?></th><th><?php esc_html_e( 'Username', 'wp-user-importer' ); ?></th><th><?php esc_html_e( 'Status', 'wp-user-importer' ); ?></th><th><?php esc_html_e( 'Note', 'wp-user-importer' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $detail as $row ) : ?>
						<tr>
							<td><?php echo (int) $row['line']; ?></td>
							<td><code><?php echo esc_html( $row['user'] ); ?></code></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td><?php echo esc_html( $row['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'CSV format', 'wp-user-importer' ); ?></h2>
		<pre>username,email,first_name,last_name,role,password
jdoe,jdoe@example.com,John,Doe,subscriber,
asmith,asmith@example.com,Alice,Smith,editor,CorrectHorse1!</pre>
		<p><?php esc_html_e( 'Leave password blank to auto-generate one. Leave role blank to use the default role. A UTF-8 BOM (Excel exports) is stripped automatically.', 'wp-user-importer' ); ?></p>
	</div>
	<?php
}
