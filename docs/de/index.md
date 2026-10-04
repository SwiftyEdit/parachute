---
title: Parachute - Übersicht
description: Backups als ZIP-Dateien
btn: Übersicht
group: addons
priority: 10
---

# Parachute

Parachute erstellt Backups deiner SwiftyEdit-Installation als ZIP-Dateien zum Herunterladen.

## Was kann gesichert werden?

| Gruppe | Inhalt |
|---|---|
| Datenbanken | `data/database/*.sqlite3` – Seiten, Beiträge, Shop, Benutzer, Einstellungen. Bei MySQL: SQL-Dump der Tabellen |
| Plugin-Daten | `plugins/<name>/data/` und `plugins/<name>/uploads/` jedes Plugins |
| Uploads | `public/assets/images`, `images_tmb`, `files`, `galleries`, `avatars`, `branding` |
| Konfiguration | `data/config.php`, `data/config_smtp.php`, `config_database.php` (MySQL), `data/includes/`, `public/.htaccess` |
| Themes | eigene Themes unter `public/assets/themes/` (ohne `node_modules`) |

Nicht gesichert werden der Cache (`data/cache/`), die Core-Themes `administration` und
`default` (kommen mit jedem Update) und der SwiftyEdit-Code selbst – den bekommst du jederzeit
neu als Download.

## So läuft ein Backup ab

1. Quellen auswählen (die letzte Auswahl wird gemerkt), optional Passwort und Notiz.
2. **Backup erstellen** – SQLite-Datenbanken werden per `VACUUM INTO` als konsistenter
   Snapshot gesichert, nicht einfach kopiert. Eine Kopie mitten in einem Schreibvorgang
   könnte sonst defekt sein.
3. Das ZIP wird nach dem Schreiben noch einmal geöffnet und auf Vollständigkeit geprüft.
   Bei MySQL wird stattdessen ein SQL-Dump erstellt (siehe unten).
4. Ältere Backups über der eingestellten Anzahl werden automatisch gelöscht.

Jedes ZIP enthält eine `parachute-manifest.json` (Zeitpunkt, Benutzer, SwiftyEdit-Version,
alle Dateien mit SHA-256-Prüfsumme) und eine kurze `parachute-restore.txt`.

## MySQL

Läuft die Installation mit MySQL (`config_database.php` im Hauptverzeichnis), erscheint
unter „Datenbanken“ der Eintrag **MySQL: <Datenbankname>**. Parachute erzeugt dann in PHP einen
SQL-Dump – `mysqldump` oder `exec()` werden nicht benötigt.

- Gesichert werden nur die Tabellen dieser Installation: alle mit dem Tabellen-Präfix, ohne
  Präfix alle `se_*`-Tabellen. Andere Anwendungen in derselben Datenbank bleiben außen vor.
- Der Dump läuft in einem konsistenten Snapshot (wie `mysqldump --single-transaction`), die
  Seite kann währenddessen normal weiterlaufen. Das gilt für InnoDB-Tabellen; MyISAM-Tabellen
  haben keine Snapshots.
- Im ZIP liegt der Dump unter `database-dump/<datenbank>.sql`.
- Die Plugins speichern ihre Daten auch bei MySQL in eigenen SQLite-Dateien – dafür ist die
  Gruppe „Plugin-Daten“ zuständig.

## Wichtig: Backups herunterladen!

Ein Backup, das nur auf demselben Server liegt, hilft nicht, wenn der Server ausfällt oder
kompromittiert wird. **Lade wichtige Backups herunter** und bewahre sie woanders auf.

Wird das Plugin gelöscht, werden auch alle Backups in `plugins/parachute/data/` gelöscht.

## Protokoll

Jedes Erstellen, Herunterladen und Löschen wird mit Zeitpunkt und Benutzer protokolliert.
Gelöschte Backups verschwinden aus der Liste, ihr Protokolleintrag bleibt erhalten.
