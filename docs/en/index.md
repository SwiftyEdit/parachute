---
title: Parachute - Overview
description: Backups as ZIP files
btn: Overview
group: addons
priority: 10
---

# Parachute

Parachute creates backups of your SwiftyEdit installation as downloadable ZIP files.

## What can be backed up?

| Group | Contents |
|---|---|
| Databases | `data/database/*.sqlite3` – pages, posts, shop, users, settings. With MySQL: SQL dump of the tables |
| Plugin data | `plugins/<name>/data/` and `plugins/<name>/uploads/` of every plugin |
| Uploads | `public/assets/images`, `images_tmb`, `files`, `galleries`, `avatars`, `branding` |
| Configuration | `data/config.php`, `data/config_smtp.php`, `config_database.php` (MySQL), `data/includes/`, `public/.htaccess` |
| Themes | custom themes in `public/assets/themes/` (without `node_modules`) |

Not backed up: the cache (`data/cache/`), the core themes `administration` and `default`
(replaced by every update) and the SwiftyEdit code itself – you can always download it again.

## How a backup works

1. Select the sources (the last selection is remembered), optionally a password and a note.
2. **Create backup** – SQLite databases are saved as a consistent snapshot via
   `VACUUM INTO` instead of a plain copy, which could be broken if taken in the middle of a write.
3. The ZIP is reopened after writing and checked for consistency.
   With MySQL an SQL dump is created instead (see below).
4. Older backups beyond the configured number are deleted automatically.

Every ZIP contains a `parachute-manifest.json` (time, user, SwiftyEdit version, every file
with its SHA-256 checksum) and a short `parachute-restore.txt`.

## MySQL

If the installation runs on MySQL (`config_database.php` in the root folder), the
"Databases" group shows an entry **MySQL: <database name>**. Parachute then creates an SQL
dump in PHP – neither `mysqldump` nor `exec()` is needed.

- Only this installation's tables are saved: everything with the table prefix, without a
  prefix all `se_*` tables. Other applications in the same database are left out.
- The dump runs inside a consistent snapshot (like `mysqldump --single-transaction`), the site
  keeps running normally. This applies to InnoDB tables; MyISAM tables have no snapshots.
- In the ZIP the dump is stored as `database-dump/<database>.sql`.
- Plugins store their data in their own SQLite files even with MySQL – that's what the
  "Plugin data" group is for.

## Important: download your backups!

A backup that only exists on the same server doesn't help when the server fails or is
compromised. **Download important backups** and keep them somewhere else.

Deleting the plugin also deletes all backups in `plugins/parachute/data/`.

## Log

Every create, download and delete is logged with time and user. Deleted backups disappear
from the list, their log entry stays.
