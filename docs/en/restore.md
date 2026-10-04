---
title: Parachute - Restore
description: Restoring data from a backup
btn: Restore
group: addons
priority: 20
---

# Restore

Parachute deliberately does **not** restore backups with a click. A failed or partial
restore through the backend can break the site – exactly when you need it most. Restoring
is done via FTP/SFTP or SSH instead.

## ZIP layout

All paths in the ZIP are relative to the installation root (the folder that contains
`config.php`, `data/`, `plugins/` and `public/`). Every file belongs at exactly the path
it has in the ZIP:

```
data/database/content.sqlite3   ->  <installation>/data/database/content.sqlite3
plugins/former/data/...         ->  <installation>/plugins/former/data/...
public/assets/images/...        ->  <installation>/public/assets/images/...
```

## Steps

1. **Make a fresh backup first** – of the broken state, too.
2. Unpack the ZIP locally. Open encrypted ZIPs (AES-256) with 7-Zip, Keka or The
   Unarchiver – the built-in macOS/Windows unzip can't.
3. Check the **SwiftyEdit version** in `parachute-manifest.json`. Databases should be
   restored into the same version.
4. Upload only the files you actually want back and overwrite the existing ones. For
   SQLite databases, delete any `-wal`/`-shm` files next to them.
5. Check file permissions: the web server needs write access to the databases and folders.
6. Empty the cache folder `data/cache/` so nothing stale is served.

On a server with SSH, for example:

```
cd /path/to/installation
7z x /path/to/parachute-....zip data/database/content.sqlite3
```

## Importing a MySQL dump

`database-dump/<database>.sql` is not copied but **imported**. The dump drops every table it
contains (`DROP TABLE`) and recreates it – other tables in the database stay untouched.

- phpMyAdmin / Adminer: select the database > **Import** > upload the file.
- Via SSH:

```
mysql -u USER -p DATABASE < database-dump/database.sql
```

If the table prefix of the target installation differs from the dump, adjust `DB_PREFIX`
in `config_database.php`.

## Checksums

Verify that a file is unchanged with the checksum from the manifest:

```
shasum -a 256 data/database/content.sqlite3
```
