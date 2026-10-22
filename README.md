# VanLog

Eine selbst gehostete Reise-App, die drei Dinge zusammenbringt, die man
sonst in drei verschiedenen Apps macht: ein **öffentliches Reisetagebuch**
(wie Polarsteps/Instagram), eine **Kostenaufteilung zu zweit** (wie
Splitwise/Splid) und ein **klassisches Fahrzeug-Logbuch** mit technischen
Daten. Dazu ein kleines **Reisewörterbuch** für die besuchten Länder.

Gebaut für eine konkrete Reise: ein VW T3 Camper auf dem Weg durch den
Balkan (Slowenien, Kroatien, Montenegro, Bosnien-Herzegowina, Albanien,
Griechenland, Italien), für genau zwei Personen — Dirk & Lisa. Entsprechend
ist vieles hart auf diesen Fall zugeschnitten, siehe unten.

## Die Grundidee

Ein Logbuch-Eintrag ist die zentrale Einheit. Jeder Eintrag kann (muss aber
nicht) enthalten:
- Kilometerstand (daraus werden gefahrene Strecke und Verbrauch berechnet)
- Tanken (Liter + Preis → automatischer Spritverbrauch, und die Kosten
  landen automatisch in der gemeinsamen Kasse)
- Ölstand (per Schieber zwischen Minimum/Maximum des Messstabs, da der
  Camper bauartbedingt Öl verbraucht → Ölverbrauch pro 1000 km)
- GPS-Position (nur an den wichtigen Stationen, nicht bei jedem Tankstopp
  im Umkreis — das wird beim Eintragen einzeln abgefragt)
- Ein Reise-Notiz-Text für den öffentlichen Blog

Aus denselben Einträgen entstehen zwei komplett unterschiedliche
Ansichten für zwei komplett unterschiedliche Zielgruppen — das ist der
eigentliche Kniff der App:

- **Privates Logbuch (PWA, Token-geschützt):** alle Daten, inklusive
  Kosten, Ölstand, interne Notizen, jeder gefahrene Kilometer.
- **Öffentlicher Blog (keine Anmeldung):** nur die Stationen mit GPS,
  nur der Reise-Notiz-Text, Fotos/Videos, eine Karte mit der Route. Keine
  Kosten, keine internen Notizen, keine lückenlosen Kilometerdaten.

Die Trennung passiert serverseitig über zwei getrennte Endpunkte, die auf
dieselben Rohdaten zugreifen (`api.php` vs. `public.php`) — nicht über
Filterung im Frontend. Was im Blog nicht auftauchen soll, verlässt den
Server für den Blog-Aufruf schlicht nie.

## Struktur

```
/ (öffentliche Website – kein Login)
├── index.html     → Blog: Karte, Timeline, Fotos/Videos
└── public.php     → liefert dem Blog nur gefilterte, unkritische Daten

/dash.html          → die App (Token-geschützt, für die beiden Reisenden)
├── api.php         → Backend für die App: volle Daten, lesend + schreibend
├── config.php      → Zugangs-Token + Pfade (lokal, aus config.example.php)
├── manifest.json   → PWA-Manifest (Home-Bildschirm-Icon, Standalone-Modus)
└── data/data.json  → alle Rohdaten (ein JSON-File, kein DB-Server nötig)
```

Die App (`dash.html`) ist eine PWA: auf dem iPhone über Safari →
„Zum Home-Bildschirm" installierbar, danach wie eine normale App nutzbar.
Der Blog (`index.html`) ist eine ganz normale, öffentlich erreichbare
Website ohne Login — gedacht zum Teilen mit Familie/Freunden.

Backend bewusst simpel gehalten: PHP + eine JSON-Datei als Datenspeicher,
läuft auf jedem einfachen Webhosting-Paket, kein eigener Datenbankserver
nötig. Für zwei Nutzer und einen Trip ist das mehr als ausreichend.

## Für alle, die das nachbauen wollen

Dieses Repo ist kein Produkt zum 1:1-Installieren, sondern eine
Referenz/Inspiration. Die Inhalte sind fest auf eine bestimmte Reise, zwei
bestimmte Personen, ein bestimmtes Fahrzeug und bestimmte Länder
zugeschnitten (Sprachen im Wörterbuch, Reifenkorrekturfaktor fürs eigene
Fahrzeug, Kategorien in der Kostenaufteilung, Länder-Bounding-Boxes für die
automatische Erkennung, …).

Der sinnvollste Weg, das für die eigene Reise zu nutzen: den Code als
Ausgangspunkt einer KI (z. B. Claude) geben und anpassen lassen — eigenes
Fahrzeug, eigene Route/Länder, eigene Kategorien, eigenes Design. Die
Struktur (ein Logbuch-Eintrag → zwei gefilterte Ansichten) lässt sich so
in einer bis wenigen Sitzungen auf den eigenen Trip übertragen.

## Setup (eigene Instanz)

1. Dateien per FTP/SFTP auf ein PHP-fähiges Webhosting-Paket laden.
2. `config.example.php` zu `config.php` kopieren, eigenes Token setzen:
   ```php
   'token' => 'EIN-LANGES-ZUFAELLIGES-PASSWORT',
   'data_file' => __DIR__ . '/data/data.json',
   ```
3. `data/` und `photos/` müssen für PHP beschreibbar sein (Rechte 755/775).
4. App im Safari öffnen (eigene Domain + `/dash.html`), beim ersten Start
   API-URL und Token eingeben, dann „Zum Home-Bildschirm".
5. Blog ist unter der Domain-Wurzel erreichbar, ohne Login.

Falls der Token beim Setup nicht angenommen wird: `/api.php?action=debug`
im Browser öffnen — zeigt, was beim Server ankommt, ohne Daten preiszugeben
(nützlich bei Shared Hosting, das eigene HTTP-Header filtert).

## Funktionen im Detail

### App
- Tachostand (mit Vorausfüllung der vorderen Ziffern beim neuen Eintrag)
- Tanken: Liter + Preis + wer gezahlt hat → Verbrauch, Kosten wandern
  automatisch in die gemeinsame Kasse
- Ölstand-Schieber (optional pro Eintrag, nicht jedes Mal gemessen) →
  Verbrauch pro 1000 km
- GPS-Erfassung (optional pro Eintrag), automatische Länder-Erkennung mit
  manueller Korrekturmöglichkeit (Grenzregionen sind per
  Bounding-Box-Ansatz nie 100% exakt)
- Reifengrößen-Korrekturfaktor (Prozentwert in den Einstellungen), wirkt
  auf Strecke/Verbrauch, der rohe Tachostand bleibt unverändert sichtbar
- Kostenaufteilung zu zweit (Splitwise-Stil), mit Kategorien
- Auswertung: Gesamtausgaben nach Kategorie, Gesamt-Sprit, Gesamt-Öl
- Foto-/Video-Upload (Videos auf 15 Sekunden begrenzt, Fotos werden vor
  dem Upload clientseitig verkleinert)
- Jeder Eintrag nachträglich editierbar

### Blog
- Karte mit der gefahrenen Route (nur Stationen mit erfasstem GPS)
- Chronologische Timeline mit Reise-Notizen, Fotos, Videos
- Instagram-ähnliche, wischbare Foto-/Video-Galerie
- Eckdaten-Übersicht (Tage, Kilometer, Länder, Stationen)
- Zeigt nie: Kosten, Tankpreise, interne Notizen, lückenlose Kilometerdaten

### Reisewörterbuch
Basiswortschatz (Begrüßung/Verabschiedung, Bitte/Danke, Essen/Trinken,
Restaurant-Floskeln) für die besuchten, nicht-deutschsprachigen Länder.
