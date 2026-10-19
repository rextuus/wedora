# Phase 4 – Kernmodule und Verwaltung

Stand: 19.10.2026

## Gast-App (`/h/{kürzel}`)

Die Gestaltung folgt der Stitch-Idee: Farbkonzept, Serifentitel, Karten und Navigationsleiste unten. Icons sind kleine eingebettete SVGs statt der großen Material-Symbols-Schrift.

| Seite | Inhalt |
|---|---|
| Start | Begrüßung, „Foto knipsen“ (öffnet die Kamera), „Aus Handy auswählen“, aktive Aufgabe mit Teilnehmerzahl, weitere Aufgaben mit Status (offen / erledigt / ab … Uhr), „Frisch von der Feier“ |
| Galerie | Gemeinsames Album als zweispaltige Masonry, Filter (Alle, Fotos, Videos, Gästebuch), Likes, Video-Dauer, „Mehr laden“ (Turbo-Frame, lädt beim Scrollen nach), Button „Foto hinzufügen“ |
| Detail | Großes Bild bzw. Videoplayer, Like, eigene Beiträge: Bildunterschrift ändern und löschen; fremde: Melden (Grund + Hinweis) |
| Mein Album | Eigene Beiträge mit Status (in Verarbeitung, wartet auf Freigabe, nicht freigegeben, nur für dich, nur Brautpaar, Aufgabe), Zähler, Aufgabenfortschritt |
| Aufgaben | Liste mit Status, Zeitfenster und Teilnehmerzahl; Rangliste, wenn Punkte aktiviert sind; Detailseite mit Beiträgen |
| Gästebuch | Text für alle Gäste oder nur das Brautpaar, Foto- und Video-Grüße, eigene Einträge mit Status und Löschen |
| Upload | Kontext: Kamera, Aufgabe (prüft Zeitfenster und Medientyp) oder Gästebuch; optionale Bildunterschrift |

- **Sichtbarkeit in Listen:** Die Abfragen für Galerie, Album und Gästebuch (`GalleryQuery`, `GuestbookFeed`) bilden die Regeln aus `MediaAccessPolicy` ab. Ein Test prüft, dass jede Liste nur Erlaubtes enthält.
- **Aktionen** (Like, Melden, Löschen, Bildunterschrift, Gästebuch) verlangen ein CSRF-Token und prüfen die Berechtigung.

## Verwaltung (`/admin`)

| Bereich | Recht | Inhalt |
|---|---|---|
| Übersicht | Mitglied oder Plattform | Gäste, Fotos, Videos, Speicherbalken, Moderations-Zähler, Zugangsstatus |
| Moderation | `moderate` | Freigabe, nachträgliche Prüfung, Meldungen; freigeben, ablehnen/ausblenden, gesichtet, löschen. Ablehnen oder Löschen erledigt die Meldungen zum Beitrag. |
| Zugang & Gäste | `manage_access` | QR-Code (SVG zum Drucken), neuer Code, Link an/aus, Hochzeitspasswort, Einladungen (Link wird **einmal** angezeigt, widerrufbar, Ablaufdatum), Einladungen pausieren, Gäste sperren/entsperren |
| Aufgaben | `configure` | Vorschläge aus dem Konzept übernehmen, eigene Aufgaben (Titel, Beschreibung, Foto/Video, Punkte, Zeitfenster, sichtbar), bearbeiten, löschen (Beiträge bleiben) |
| Einstellungen | `configure` | Name, Datum, Moderationsmodus, Module an/aus mit Beitragsende, Punkte/Rangliste; Archivieren (`manage_lifecycle`); Limits (nur Plattform, gedeckelt durch die Plattform-Obergrenze) |
| Export | `export` | ZIP anfordern, Liste mit Download |
| Team | nur Plattform | Konto anlegen (Startpasswort) oder vorhandenes zuordnen, Rechte als Häkchen, entfernen |
| Löschen | nur Plattform | endgültige Löschung mit Eingabe des Kürzels |

- **Rechte:** Plattformadministration ohne Mitgliedschaft sieht weder Moderation noch Export (Datenschutz-Annahme aus Phase 2).
- **Unbekannte Hochzeiten:** Fremde und unbekannte Hochzeiten antworten mit 404.

## Datenmodell (neu)

`Challenge`, `MediaLike`, `MediaReport`, `GuestbookEntry`. Am Medium neu: Bildunterschrift und Aufgabe. An der Hochzeit neu: Punkte an/aus.

Medien und Gästebucheinträge teilen sich die Schnittstelle `Contribution`; Sichtbarkeit, Moderation und Löschen folgen damit denselben Regeln.

## Tests

- `tests/Functional/App/GuestAppTest.php`:
  - Galerie-Sichtbarkeit (inkl. Konsistenz mit der Rechteprüfung), Filter, Blättern.
  - Like mit Token und Sichtbarkeit, Meldungen, Album.
  - Gästebuch: Moderation, Sichtbarkeit, fremde Einträge nicht löschbar.
  - Aufgaben: Zuordnung, Zeitfenster, Medientyp, Rangliste.
  - Ausgeschaltete Module, Bildunterschrift.
- `tests/Functional/App/AdminTest.php`:
  - Seitenzugriff je Recht, Plattformadmin ohne Inhaltszugriff.
  - Moderationsaktionen inkl. Dateilöschung und Meldungen, CSRF.
  - Einstellungen, Module, Archiv; Limits nur Plattform und gedeckelt.
  - Zugang: QR, Einladung einmalig, Widerruf, Sperre, neuer Code, Passwortregeln.
  - Aufgaben, Export, Team, Anlegen/Löschen.

Ergebnis: `make test` 156 Tests, `make test-media` 11 Tests; PHPStan (max) und Mago ohne Fehler und Warnungen.

Im echten Stack wurden alle Gast- und Verwaltungsseiten mit Beispieldaten aufgerufen (HTTP 200). Dabei kam ein Fehler auf der Teamseite ans Licht und wurde behoben (fehlende Methode; der Test deckt die Seite jetzt ab).

## Offen

- **Nicht im Browser angesehen:** Optik, Layout auf dem Handy und Turbo-Verhalten (Like ohne Neuladen, „Mehr laden“) sind nur über HTML-Ausgabe und Tests geprüft.
- **Barrierefreiheit:** Grundlagen sind umgesetzt (Labels, Fokusrahmen, `aria-current`, `aria-pressed`, Skip-Link, Alternativtexte, reduzierte Bewegung, Touch-Ziele ≥ 44 px). Ein Prüfdurchlauf mit Screenreader und Kontrastmessung steht aus (Phase 5).
- **Ohne E-Mail:** Konten werden mit Startpasswort angelegt; es gibt keinen Mailversand und kein Zurücksetzen per E-Mail.
- **Zurückgestellt laut Auftraggeber:** Offline-Warteschlange, Einfach-Modus, Hilfe-Kontakt, Tischangabe, Massendownload für Gäste, Willkommens-Popup.
