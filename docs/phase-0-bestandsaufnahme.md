# Phase 0 – Bestandsaufnahme, Prüfung und Prototyp

Stand: 19.10.2026. Alle Aussagen beruhen auf den im Repository liegenden Unterlagen, den
tatsächlich gestarteten Containern und den hier dokumentierten Testläufen.

## 1. Vorgefundener Bestand

| Element | Inhalt | Ablage |
|---|---|---|
| Git-Repository | nur Initial-Commit mit einer (Symfony-2-)`.gitignore` | – |
| `Hochzeitsplattform_Konzeptpaket.zip` | Produktkonzept, Anforderungsspezifikation, Umsetzungsplan, Architekturentscheidung, Gesamtkonzept (.docx) | entpackt nach `docs/konzept/` |
| `stitch_modern_wedding_memory_platform.zip` | 5 Stitch-Screens (je `code.html` + `screen.png`) und Designsystem `botanical_keepsake/DESIGN.md` | entpackt nach `design/stitch/` |

Die ZIP-Dateien selbst sind per `.gitignore` ausgeschlossen; versioniert werden die entpackten Inhalte.

## 2. Google-Stitch-Vorlagen (gesichtet)

Gesichtet wurden alle fünf Screenshots, die zugehörigen HTML-Exporte und `DESIGN.md`.
Stitch-Links (URLs) lagen nicht vor; es wurde nur der Export ausgewertet.

> **Einordnung durch den Auftraggeber (19.10.2026):** Die Stitch-Entwürfe sind eine **Design-Idee**, keine pixelgenaue Vorgabe.
> Verbindlich sind das **Farbkonzept** und die **allgemeine Gestaltung** (warm, elegant, zurückhaltend). Was genau gebaut wird,
> legen wir pro Seite fest, wenn es so weit ist.

| Screen | Inhalt |
|---|---|
| `1b._willkommen_mit_drink_challenge_popup` | Bottom-Sheet „Willkommen, Sophie!“, Einladung zur First-Drink-Challenge, Video-Vorschau, Gäste-Avatare, Primär-CTA „Ja, jetzt Glas knipsen“, sekundär „Später & zum Dashboard“ |
| `2._minimales_dashboard_mit_fokus_mission` | Gast-Startseite: Kamera-CTA, „Aus Handy auswählen“, Offline-Hinweis „Automatisch gesichert“, aktive Mission + gesperrte Folgemissionen, Feed „Frisch von der Feier“, Hilfe-Kontakt, Bottom-Navigation (Kamera/Galerie) |
| `3._first_last_drink_mission_minimal` | Missionsdetail mit Schritten (Erledigt/Offen), Zeitfreigabe („Wird ab 23:00 Uhr aktiv“), gesperrte Galerie |
| `4._gemeinsame_galerie_minimal` | Masonry-Galerie (2 Spalten), Filter-Chips (Alle/Highlights/Gästebuch/Videos), Uploader-Badge, Likes, Video-Dauer, FAB „Foto hinzufügen“ |
| `5._mein_pers_nliches_album_minimal` | Persönliches Album: Zähler, chronologische Timeline, Badges („Mission erfüllt“, „Nur für Brautpaar“), Missionsfortschritt, „Alle Fotos herunterladen“ |

### Abgeleitete Design-Grundlagen

- **Farben:** Material-3-artige Token aus `DESIGN.md`/Tailwind-Config (u. a. `surface #fbf9f6`, `primary #48584a`, `primary-container #607062`, `secondary-container #ebdece`, `on-surface #1b1c1a`).
- **Typografie:** Playfair Display (Überschriften, z. T. kursiv), Plus Jakarta Sans (UI). Skala `display-hero` … `label-caption` gemäß `DESIGN.md`.
- **Abstände/Raster:** 4/8-px-Rhythmus; mobil 1,25 rem Außenrand; Tablet 6, Desktop 12 Spalten (max. 1160 px).
- **Komponenten:** Pill-Buttons (min. 48 px), Status-Pills (Offen/Erledigt/Freigeschaltet), Stationery-Cards mit Hairline-Border, Bottom-Navigation mit Blur, FAB, Bottom-Sheet, Filter-Chips, Avatar-Stapel.
- **Icons:** Material Symbols Outlined.
- **Schatten:** sage-getönt, keine schwarzen Schatten.

### Abweichungen innerhalb der Vorlagen (geklärt: nicht entscheidend)

1. **Primärfarbe:** `DESIGN.md` (Fließtext) nennt `#607062` für Primär-Buttons, die HTML-Exporte nutzen `bg-primary` = `#48584a`. Die Screenshots zeigen den dunkleren Ton.
2. **Eckenradien:** `DESIGN.md` definiert `DEFAULT 0.5rem / lg 1rem / xl 1.5rem`, die Tailwind-Config der Exporte `DEFAULT 0.25rem / lg 0.5rem / xl 0.75rem`.
3. **Neutralflächen:** Fließtext nennt `#FAF8F5`/`#F5F1EB`/`#EFECE6`, die Token `#fbf9f6`/`#f5f3f0`/`#efeeeb`.

Weil Stitch nur die Richtung vorgibt, verwenden wir die Token aus der Tailwind-Config der Exporte (das, was in den Screenshots zu sehen ist) als Ausgangspunkt und justieren bei der Umsetzung nach.

### Was die Vorlagen nicht abdecken

- Screen „1a“ (Einstieg) fehlt: Nummerierung beginnt bei 1b. Kein Screen für QR-Einstieg, Passwort, Anzeigename.
- Kein Screen für Gästebuch, Upload-Fortschritt/-Fehler/-Wiederholung, Moderation/Meldung, leere Zustände.
- Keine Administrationsoberfläche (Plattform- und Hochzeitsadministration).
- Keine Desktop-/Tablet-Varianten (alle Exporte mobil).

### Elemente in den Vorlagen, die im Konzept nicht beschrieben sind

**Zurückgestellt (Auftraggeber, 19.10.2026):** Diese Funktionen werden später besprochen und bis dahin nicht gebaut.

- **„Einfach-Modus“-Schalter** (Dashboard).
- **Offline-Upload-Warteschlange** („Fotos bleiben auf dem Handy gespeichert und laden automatisch hoch“) – technisch Service Worker/IndexedDB, nicht trivial.
- **Hilfe-Kontakt** („Trauzeugin Laura fragen“).
- **Tisch-/Ortsangabe** am Beitrag („Tisch 4 • Brunnenplatz“).
- **Massendownload für Gäste** („Alle Fotos herunterladen“).

### Technische Hinweise zu den Exporten

- Exporte laden Tailwind per CDN-Skript, Google Fonts und Bilder von `lh3.googleusercontent.com`. Das ist für Produktion ungeeignet: Tailwind wird gebaut bzw. durch eigenes CSS mit denselben Token ersetzt, Schriften/Icons werden **selbst gehostet** (DSGVO, vgl. LG München I, 3 O 17493/20), Beispielfotos sind Platzhalter.
- Viewport `user-scalable=no, maximum-scale=1.0` verhindert Zoomen und verletzt WCAG 1.4.4. Das wird nicht übernommen.
- Mehrere Textgrößen von 11 px, z. T. `text-secondary` auf hellem Grund: Kontraste werden bei der Umsetzung geprüft.

## 3. Versionen, Lizenzen, Reifegrad

| Komponente | Version | Lizenz | Befund |
|---|---|---|---|
| Symfony | 8.1.9 | MIT | **Bleibt (Entscheidung Auftraggeber).** Keine LTS, Support endet im Januar 2027 → Upgrade auf 8.2 (erwartet Nov. 2026) einplanen, langfristig 8.4 LTS (erwartet Nov. 2027). |
| PHP | 8.4.26 (Container) | PHP License | Mindestanforderung von Symfony 8.1. Geprüft wird ausschließlich im Container. |
| MySQL | 8.4.11 (LTS) | GPL-2.0 (Community) | gepinnt, `utf8mb4`; siehe [ADR 0002](adr/0002-mysql-statt-postgresql.md) |
| Doctrine ORM / Migrations | 3.7.5 / Bundle 4.x | MIT | – |
| Caddy | 2.11.7 | Apache-2.0 | `handle_response` für X-Accel-Redirect, automatisches HTTPS |
| imgproxy (OSS) | v4.0.18 (14.10.2026) | MIT | aktiv gepflegt; die Pro-Edition wird nicht benötigt |
| ffmpeg | 8.1.2 (Alpine-Paket im PHP-Image) | LGPL/GPL (Build mit libx264/libx265) | Nutzung als eigenständiges Programm, keine Einbettung in eigenen Code |
| PHPUnit / PHPStan / Mago | 13.4.3 / 2.3.1 / 1.57.0 | BSD-3 / MIT / MIT-Apache | PHPStan Level max |

Geprüft und verworfen (siehe [ADR 0001](adr/0001-video-als-mp4-lokaler-speicher-caddy.md)):
- **Hovod 1.0.4 (MIT):** keine Zugriffskontrolle für die Wiedergabe (README **und** Quellcode im Image geprüft: `/v1/playback/:id` öffentlich, Dateien standardmäßig `public-read`), S3-Pflicht, sehr junges Projekt, doppelte Speicherung als MP4. Fehler beim Upload gegen S3-kompatible Speicher (CRC32 in vorsignierten URLs, umgehbar).
- **S3/MinIO:** Kein Cloud-Speicher gewünscht. MinIO veröffentlicht ohnehin keine Community-Images mehr.

## 4. Medienverarbeitung und Zugriffsschutz (Prototyp)

```
Browser ──GET /media/{token}/{variante}──▶ Caddy ──▶ Symfony (ProtectedMediaController)
                                                     │ 1. Token prüfen (Medium + Betrachter + Ablauf, HMAC)
                                                     │ 2. MediaAccessChecker::canView() – bei JEDER Anfrage
                                                     │ 3. nur feste Varianten: video.mp4 | image/thumb | image/display
                                                     ▼
                                    X-Accel-Redirect: /_protected/files/…   oder   /_protected/imgproxy/<signiert>
Caddy (handle_response) ──▶ Medien-Volume (file_server, Range) bzw. internes imgproxy ──▶ Browser
```

- **Verarbeitung:** `FfmpegVideoTranscoder` (H.264/AAC, lange Seite ≤ 1280 px, yuv420p, `faststart`, Metadaten entfernt, Standbild, atomare Ausgabe, Längenlimit, Timeout, Thread-Limit).
- **Speicher:** `LocalMediaStorage`, je Medium ein Verzeichnis `xx/<uuid>/{original,video.mp4,poster.jpg}` mit Rechten `0750`. imgproxy liest nur lesend mit der Gruppe der App.
- **Intern:** `/_protected/*` beantwortet Caddy von außen mit 404. imgproxy hat keinen veröffentlichten Port.

Code: `src/Media/**`, `src/Controller/ProtectedMediaController.php`, `docker/caddy/Caddyfile`.

### Nachweis (`make test-media`, gegen den echten Stack)

`tests/Integration/Media/FfmpegVideoTranscoderTest.php`:

| Prüfung | Ergebnis |
|---|---|
| Querformat 1920×1080 → H.264 1280×720, AAC, yuv420p, faststart, Standbild, keine Temp-Dateien | ✅ |
| GPS-Ort und Titel aus den Metadaten entfernt | ✅ |
| iPhone-typisches HEVC/MOV im Hochformat → H.264 720×1280 | ✅ |
| Video über dem Längenlimit → abgelehnt | ✅ |
| Datenmüll → abgelehnt, keine Ausgabedateien | ✅ |
| Nur-Audio-Datei → abgelehnt | ✅ |

`tests/Integration/Media/ProtectedMediaDeliveryTest.php` (HTTP über Caddy):

| Prüfung | Ergebnis |
|---|---|
| Berechtigter Abruf: `video.mp4` 200, vollständige Datei, `Cache-Control: private, no-store`, kein interner Header nach außen | ✅ |
| Range-Request (Vorspulen) → 206 | ✅ |
| Bilder nur als feste Presets über imgproxy (WebP, ≤ 720 px), Video-Vorschau aus Standbild | ✅ |
| Unbekannte Variante, Original, Video eines Bildes → 404 | ✅ |
| Ungültiges, abgelaufenes, fremd signiertes, manipuliertes Token sowie Betrachter ohne Freigabe → 403 für Video **und** Bild | ✅ |
| Direkter Zugriff auf Speicher und imgproxy (`/_protected/…`, `/index.php/_protected/…`, Pfad-Traversal, geratene Pfade) → 404/400 | ✅ |
| Widerruf wirkt sofort, auch für laufende Wiedergabe (Range) | ✅ |
| Löschung entfernt Original, Video und Standbild; danach 404 | ✅ |

Letzter Lauf: `OK (11 tests, 82 assertions)`.

### Vor Produktionsfreigabe offen

- `MediaAccessChecker` ist im Prototyp eine Cache-basierte Freigabeliste. Die echte Autorisierung (Sichtbarkeit, Veröffentlichung, widerrufene Einladungen) folgt in Phase 2 und muss dieselben Tests bestehen.
- Die Route ist bewusst nur in `dev`/`test` aktiv.
- Upload-Ablauf, Messenger-Job mit Statusverwaltung und Fehlern, Limits pro Hochzeit: Phase 3.
- Kein Browser-Test (Safari/iOS-Wiedergabe) und kein Lasttest: Phase 5/6.
- HDR-Tone-Mapping (siehe ADR).

## 5. Annahmen (bis auf Widerruf)

1. Stitch dient als Design-Richtung (Farben, Stil); Details werden pro Seite festgelegt.
2. PHP 8.4 im Container.
3. PHPStan Level max.
4. Limits und Originale: siehe [ADR 0003](adr/0003-medienlimits-und-originale.md).
5. Das Medien-Volume wird in Produktion gesichert (Phase 6). Die Dateien liegen auf dem eigenen Server.
6. Frontend: AssetMapper + Stimulus/Turbo, kein Node-Build. Das Tailwind-CDN der Stitch-Exporte wird durch eigenes CSS mit denselben Token ersetzt; Schriften und Icons werden selbst gehostet.

## 6. Entscheidungen des Auftraggebers (19.10.2026)

- Symfony 8.1 bleibt (Upgrade auf 8.2 einplanen).
- MySQL statt PostgreSQL ([ADR 0002](adr/0002-mysql-statt-postgresql.md)).
- Keine Cloud/S3, kein Hovod; Videos als MP4, Caddy ([ADR 0001](adr/0001-video-als-mp4-lokaler-speicher-caddy.md)).
- Stitch = Design-Idee (Farbkonzept, allgemeine Gestaltung); Details später.
- Zusatzfunktionen aus Stitch, die nicht im Konzept stehen: später besprechen.
- Medienlimits pro Hochzeit einstellbar, Standardwerte und Originale behalten ([ADR 0003](adr/0003-medienlimits-und-originale.md)).
- Commits macht der Auftraggeber selbst.

- Vorerst keine GitHub-Anbindung. Prüfungen laufen lokal (`make qa`, `make test-media`); `.github/workflows/ci.yaml` bleibt als Vorlage liegen.
