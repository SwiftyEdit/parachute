---
title: Parachute - Security
description: Permissions, storage, encryption
btn: Security
group: addons
priority: 30
---

# Security

A backup contains the whole site – including the user database (password hashes, personal
data) and possibly the SMTP password from `config_smtp.php` or the MySQL credentials from
`config_database.php`. Access is restricted accordingly.

## Permissions

Every function (viewing, creating, downloading, deleting, settings) requires:

- user class **administrator** and
- the **sensitive files** permission (`drm_acp_sensitive_files`).

That's the same level that protects installing plugins and editing theme files. It is
checked on every single request, not only when the menu entry is opened.

## Storage

Backups are stored in `plugins/parachute/data/backups/` – outside the `public/` web root,
so they can't be reached by URL. Downloads only go through the plugin's download endpoint,
which checks the permission and logs the download. An additional `.htaccess` locks the
folder in case the installation is misconfigured.

## Encryption

Optionally the ZIP is encrypted with AES-256. The password is not stored anywhere.

- Without the password the backup **can't be restored**.
- File contents are encrypted, the **file names** in the ZIP stay visible (a property of
  the ZIP format).
- Encryption is strongly recommended for downloaded backups that end up in a cloud storage.

## Privacy

Backups contain personal data. Decide how long backups are kept and delete downloaded
copies you no longer need.
