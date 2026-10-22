<?php
// ─── KONFIGURATION ────────────────────────────────────────────────────────────
// 1. Diese Datei kopieren zu "config.php"
// 2. Token ändern (ein langes zufälliges Passwort)
// 3. Pfad zur Datendatei anpassen — IDEALERWEISE AUSSERHALB des Web-Roots!

return [
    // Gemeinsamer Zugangs-Token (Dirk & Lisa nutzen denselben).
    // Bitte durch etwas zufälliges ersetzen (z.B. 32 Zeichen).
    'token' => 'BITTE-AENDERN-zufaelliger-langer-string-hier',

    // Pfad zur JSON-Datendatei.
    // Bei Hetzner-Webhosting liegt der Web-Root oft unter /usr/www/users/USERNAME/
    // und es gibt darüber einen privaten Bereich. Beispiel:
    //   '/usr/home/USERNAME/vanlog-data/data.json'
    // Falls das nicht möglich ist, kann die Datei auch direkt neben api.php liegen
    // (die .htaccess schützt sie dann vor direktem Zugriff):
    //   __DIR__ . '/data/data.json'
    'data_file' => __DIR__ . '/data/data.json',

    // Zeitzone (für Datumsangaben in Logs)
    'timezone' => 'Europe/Berlin',
];
