# WP User Importer

Import users from a CSV file — **dry-run preview first**, then import with duplicate handling, role assignment and an optional welcome email. Built for the "migrate 200 members from the old site" job without hand-creating accounts one by one.

## CSV format

```csv
username,email,first_name,last_name,role,password
jdoe,jdoe@example.com,John,Doe,subscriber,
asmith,asmith@example.com,Alice,Smith,editor,CorrectHorse1!
```

- Only `username` and `email` are required.
- Leave `password` blank to auto-generate a secure one.
- Leave `role` blank to use the default role chosen on the import screen.
- A UTF-8 BOM (from Excel exports) is stripped automatically.

## Features

- **Dry run checked by default** — see exactly what would be created, updated or skipped before anything changes.
- **Duplicate handling** — skip rows whose username or email already exists, or update the existing user (name + role; password only if the CSV provides one).
- **Role validation** — rows with a role that doesn't exist on the site are rejected with a per-line error instead of silently falling back.
- **Row-by-row report** — line number, username, status and note for every row, including validation errors.
- **Optional welcome email** — sends WordPress's standard new-user notification to each created account.
- **Safe** — only users with the `create_users` capability can open the page; the upload is nonce-checked and verified with `is_uploaded_file()`.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **Users → Import Users**.

## License

MIT — see [LICENSE](LICENSE).
