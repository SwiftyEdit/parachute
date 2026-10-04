---
title: Parachute - Wiederherstellen
description: Daten aus einem Backup zurückspielen
btn: Wiederherstellen
group: addons
priority: 20
---

# Wiederherstellen

Parachute spielt Backups bewusst **nicht** per Klick zurück. Ein fehlgeschlagener oder
halber Restore über das Backend kann die Seite unbrauchbar machen – ausgerechnet dann, wenn
man sie am dringendsten braucht. Das Zurückspielen passiert deshalb per FTP/SFTP oder SSH.

## Aufbau des ZIPs

Alle Pfade im ZIP sind relativ zum Installationsverzeichnis (dem Ordner mit `config.php`,
`data/`, `plugins/` und `public/`). Eine Datei gehört also genau an den Pfad, unter dem
sie im ZIP liegt:

```
data/database/content.sqlite3   ->  <installation>/data/database/content.sqlite3
plugins/former/data/...         ->  <installation>/plugins/former/data/...
public/assets/images/...        ->  <installation>/public/assets/images/...
```

## Vorgehen

1. **Vorher ein aktuelles Backup machen** – auch vom kaputten Zustand.
2. ZIP lokal entpacken. Verschlüsselte ZIPs (AES-256) mit 7-Zip, Keka oder The Unarchiver
   öffnen – der Standard-Entpacker von macOS/Windows kann das nicht.
3. In `parachute-manifest.json` prüfen, welche **SwiftyEdit-Version** gesichert wurde.
   Datenbanken sollten in dieselbe Version zurückgespielt werden.
4. Nur die Dateien hochladen, die du wirklich zurückholen willst, und die vorhandenen
   überschreiben. Bei SQLite-Datenbanken eventuell vorhandene `-wal`/`-shm`-Dateien daneben
   löschen.
5. Dateirechte prüfen: Der Webserver braucht Schreibrechte auf die Datenbanken und Ordner.
6. Den Cache-Ordner `data/cache/` leeren, damit nichts Veraltetes ausgeliefert wird.

Auf einem Server mit SSH geht das z. B. so:

```
cd /pfad/zur/installation
7z x /pfad/zum/parachute-....zip data/database/content.sqlite3
```

## MySQL-Dump einspielen

`database-dump/<datenbank>.sql` wird nicht kopiert, sondern **importiert**. Der Dump löscht
jede enthaltene Tabelle (`DROP TABLE`) und legt sie neu an – andere Tabellen in der Datenbank
bleiben unberührt.

- phpMyAdmin / Adminer: Datenbank auswählen > **Importieren** > Datei hochladen.
- Per SSH:

```
mysql -u BENUTZER -p DATENBANK < database-dump/datenbank.sql
```

Passt das Tabellen-Präfix der Zielinstallation nicht zu dem im Dump, muss
`DB_PREFIX` in `config_database.php` angepasst werden.

## Prüfsummen

Ob eine Datei unverändert ist, lässt sich mit der Prüfsumme aus dem Manifest kontrollieren:

```
shasum -a 256 data/database/content.sqlite3
```
