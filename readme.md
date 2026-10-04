# Parachute

Backups for SwiftyEdit as downloadable ZIP files.

See the plugin's own **Hilfe/Help** tab (Addons > Parachute) for the full walkthrough.

## Features

- **Selectable sources**: core databases, plugin data (`data/` + `uploads/` of every plugin),
  uploads (images, files, galleries, ...), configuration (`config.php`, `config_smtp.php`,
  `data/includes/`, `.htaccess`) and custom themes (without `node_modules`).
- **Consistent SQLite snapshots** via `VACUUM INTO` instead of plain file copies.
- **MySQL installations**: pure-PHP SQL dump of the installation's tables (prefix-aware) inside a
  consistent snapshot - no `mysqldump`/`exec()` needed. `config_database.php` is offered as well.
  *Note: MySQL support works, but has not been tested extensively yet* (see below).
- **Optional AES-256 encryption** of the ZIP, password never stored.
- **Manifest** in every ZIP: SwiftyEdit version, user, time, every file with SHA-256 checksum.
- **Integrity check** of every written ZIP, disk space check before starting, lock against
  parallel runs, cleanup of aborted runs.
- **Retention**: keep the last N backups on the server.
- **Audit log**: who created, downloaded or deleted which backup, and when.
- **Restricted access**: administrators with the `drm_acp_sensitive_files` permission only,
  checked on every request. Backups are stored outside the web root.
- No one-click restore by design - see the restore guide in the docs.

## Requirements

- SwiftyEdit build 26-0219 or newer
- PHP 8.1+ with the `zip` extension (AES encryption needs libzip 1.2+)
- SQLite 3.27+ for snapshots (older versions fall back to a plain copy with a warning)

The plugin must be **activated** - downloads go through `/xhr/plugins/parachute/`.

## MySQL status

MySQL support works, but has not been tested extensively yet. So far it has been verified with
a round trip on MySQL 8.4: dump, import into an empty database, and a table-by-table
comparison. The data was identical, and tables of other applications were left out. Not yet
covered: MariaDB, older MySQL versions, MyISAM tables, very large databases and real
production installations. For MySQL sites, also download a backup and test the import
before relying on it.
