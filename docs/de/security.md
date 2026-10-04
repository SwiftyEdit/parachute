---
title: Parachute - Sicherheit
description: Rechte, Ablageort, Verschlüsselung
btn: Sicherheit
group: addons
priority: 30
---

# Sicherheit

Ein Backup enthält die komplette Seite – inklusive Benutzerdatenbank (Passwort-Hashes,
personenbezogene Daten) und ggf. dem SMTP-Passwort aus `config_smtp.php` bzw. den MySQL-Zugangsdaten aus
`config_database.php`. Entsprechend
streng ist der Zugriff geregelt.

## Rechte

Alle Funktionen (Anzeigen, Erstellen, Herunterladen, Löschen, Einstellungen) erfordern:

- Benutzerklasse **Administrator** und
- die Berechtigung **sensible Dateien** (`drm_acp_sensitive_files`).

Das ist dieselbe Stufe, die auch das Installieren von Plugins und das Bearbeiten von
Theme-Dateien schützt. Die Prüfung findet bei jedem einzelnen Aufruf statt, nicht nur beim
Öffnen des Menüpunkts.

## Ablageort

Backups liegen in `plugins/parachute/data/backups/` – außerhalb des Webroots `public/`.
Sie sind also nicht per URL erreichbar. Heruntergeladen wird ausschließlich über den
Download-Endpunkt des Plugins, der die Rechte prüft und den Download protokolliert. Eine
zusätzliche `.htaccess` sperrt den Ordner, falls die Installation falsch konfiguriert ist.

## Verschlüsselung

Optional wird das ZIP mit AES-256 verschlüsselt. Das Passwort wird nirgends gespeichert.

- Ohne Passwort ist das Backup **nicht wiederherstellbar**.
- Verschlüsselt werden die Dateiinhalte. Die **Dateinamen** im ZIP bleiben sichtbar
  (Eigenschaft des ZIP-Formats).
- Für heruntergeladene Backups, die z. B. in einer Cloud landen, ist Verschlüsselung
  dringend empfohlen.

## Datenschutz

Backups enthalten personenbezogene Daten. Lege fest, wie lange Backups aufbewahrt werden,
und lösche heruntergeladene Kopien, die nicht mehr gebraucht werden.
