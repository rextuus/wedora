# Phase 3 – Medien

Stand: 19.10.2026

## Upload (fortsetzbar, in Stücken)

```
POST   /h/{kürzel}/api/uploads        {type, size, visibility}  → 201 {id, chunkSize}
PATCH  /h/{kürzel}/api/uploads/{id}   Upload-Offset + 8-MiB-Stück → 204 (weiter) | 201 {media} (fertig)
HEAD   /h/{kürzel}/api/uploads/{id}   → Upload-Offset (Fortsetzen nach Abbruch)
DELETE /h/{kürzel}/api/uploads/{id}   → Abbruch, Puffer und Reservierung weg
```

- Angelehnt an das tus-Protokoll, aber selbst umgesetzt, ohne zusätzliche Abhängigkeit.
- Stücke landen im Medien-Volume (`_uploads/`) und werden beim Abschluss nur umbenannt, nicht kopiert.
- Falscher Offset → 409 mit dem richtigen Stand. Zu große Stücke → 413, der Puffer bleibt unverändert. Caddy nimmt Anfragen über 9 MB gar nicht erst an.
- Schreibende Anfragen brauchen den Header `X-Requested-With: wedora`; fremde `Origin` bzw. `Sec-Fetch-Site: cross-site` werden abgelehnt (CSRF-Schutz).
- Uploads anderer Gäste sind nicht von unbekannten unterscheidbar (404).
- Upload-Seite für Gäste: `/h/{kürzel}/upload`, umgesetzt mit dem Stimulus-Controller `assets/controllers/upload_controller.js`:
  - mehrere Dateien, höchstens zwei gleichzeitig,
  - Fortschrittsbalken,
  - nach Verbindungsabbruch bis zu 4 automatische Neuversuche mit Fortsetzung, danach „Erneut versuchen“,
  - verständliche Fehlermeldungen vom Server, Statusanzeige während der Videoverarbeitung.

## Serverseitige Limits (ADR 0003)

| Prüfung | Wann | Antwort |
|---|---|---|
| Dateigröße je Typ (Foto/Video, pro Hochzeit) | beim Ankündigen | 413 |
| Speicher der Hochzeit (inkl. Originale, Varianten, offene Uploads) | beim Ankündigen | 507 |
| Speicher pro Gast | beim Ankündigen | 507 |
| Höchstens 3 offene Uploads pro Gast | beim Ankündigen | 429 |
| Modul aktiv, Beitragsfenster offen, Hochzeit nicht archiviert | beim Ankündigen | 403 |
| Format am **Inhalt** erkannt (JPEG, PNG, WebP, HEIC / MP4, MOV, WebM, 3GP) | beim Abschluss | 422, Datei wird gelöscht |
| Videolänge (min. aus Hochzeit und Plattform-Obergrenze) per `ffprobe` | beim Abschluss | 422, Datei wird gelöscht |
| Daten über die angekündigte Größe hinaus | bei jedem Stück | 413 |

## Verarbeitung

- **Fotos** sind sofort fertig. Varianten erzeugt imgproxy beim Abruf in festen Größen.
- **Videos:** Der Upload legt das Medium im Status `processing` an und stellt `ProcessVideo` in die Warteschlange. Der Worker wandelt um (MP4 720p, Standbild, Metadaten inkl. GPS entfernt) und setzt den Status auf `ready`.
- **Fehler:**
  - Eine fachliche Ablehnung setzt sofort `failed` mit einer Meldung für den Gast.
  - Technische Fehler führen zu 2 Wiederholungen (nach 30 s und 2 min), danach `failed`.
- **Sichtbarkeit:** Unfertige oder fehlgeschlagene Medien sieht nur der Uploader, damit er den Status verfolgen kann.
- **Worker:** läuft jetzt immer mit (`make up`), mit Grenze von 2 CPUs und 1 GB RAM. Mehr Parallelität bedeutet mehr Worker-Container.

## Erfahrungswerte und Zeitprognose

- **Messwerte:** Jede Videoumwandlung schreibt einen Messwert-Datensatz (`video_processing_record`):
  - Videolänge, Auflösung, Dateigröße, Codec,
  - reine Umwandlungsdauer, Wartezeit in der Warteschlange, Ergebnisgröße,
  - Erfolg oder Fehlergrund, Thread-Anzahl.
- **Datenschutz:** Die Datensätze sind **anonym und ohne Fremdschlüssel** (keine Gäste, Hochzeiten, Inhalte) und bleiben deshalb auch nach dem Löschen einer Hochzeit erhalten.
- **Prognose** (`ProcessingTimeEstimator`):
  - Modell: Ausgleichsgerade *Startaufwand + Faktor × Videolänge*, getrennt nach Auflösungsklasse (bis 720p, Full HD, 4K).
  - Grundlage: die jüngsten 300 erfolgreichen Messungen, mindestens 5.
  - Reicht eine Klasse nicht, werden alle Klassen gemeinsam genutzt; sonst gibt es keine Zahl.
- **Restzeit** (`ProcessingForecast`): eigene Umwandlung plus Videos davor in der Warteschlange (geteilt durch `VIDEO_WORKERS`), abzüglich der bereits vergangenen Zeit. Die Reihenfolge ergibt sich aus der zeitlich geordneten UUIDv7, weil `created_at` nur Sekunden speichert.
- **Upload-Seite:**
  - Mit Prognose: „noch etwa 0:23“ und ein Fortschrittsbalken, der bei 95 % stehen bleibt; bei Überschreitung „dauert etwas länger als üblich“.
  - Ohne Prognose: der allgemeine Hinweis mit mitlaufender Zeit.
- **Auswertung:** `app:media:processing-stats` zeigt das Modell je Auflösung und Beispielwerte für 30, 60 und 120 Sekunden.
- **Kalibrierung auf dem Entwicklungsrechner** (19.10.2026, 6 Full-HD-Videos von 5 bis 60 s):
  - Ergebnis: ca. 0,74 s Umwandlung je Videosekunde.
  - Gegenprobe mit einem 30-s-Video: Prognose 23 s, tatsächlich 17 s.

## Löschen, Export, Speicherübersicht

- **Einzelnes Medium** (Uploader oder Moderation): Original, Video und Standbild werden sofort gelöscht, der Datensatz bleibt als „gelöscht“ markiert.
- **Export** (`app:wedding:export`; Download für Konten mit Recht `export`):
  - Inhalt: ZIP mit den Originalen aller Beiträge für Gäste oder das Brautpaar, ohne „nur für mich“, abgelehnte und gelöschte Beiträge, dazu `uebersicht.csv`.
  - Ablauf: Erstellung im Hintergrund, Auslieferung über Caddy, gültig 7 Tage.
- **Hochzeit endgültig löschen** (`app:wedding:delete`, mit Bestätigung):
  - Entfernt Originale, Varianten, Standbilder, offene Upload-Stücke, Export-Archive und alle Datensätze.
  - Es gibt keinen Bild-Cache und keinen externen Anbieter, der zusätzlich zu leeren wäre.
- **Speicherübersicht:** `app:wedding:usage` zeigt den Verbrauch der Hochzeit (inkl. Reservierungen) im Verhältnis zum Limit. Die Anzeige in der Verwaltung folgt in Phase 4.
- **Aufräumen:** `app:media:cleanup` entfernt abgelaufene Uploads (24 h) und Exporte (7 Tage). Er sollte regelmäßig laufen; die Einrichtung als Cron oder Scheduler folgt in Phase 6.

## Gefundene und behobene Fehler

- **UUID-Parameter in Doctrine-Abfragen:** Wird eine Entity mit UUID-Schlüssel als Parameter übergeben, bindet Doctrine die UUID als Text. Folge:
  - Kontingent und Parallel-Limit zählten offene Uploads nicht mit.
  - Der Export wäre leer gewesen.
  - Die Löschung hätte Upload-Reste übersehen.
  - Behoben durch explizites Binden (`UuidType::NAME`); die Tests decken alle betroffenen Stellen ab.

## Tests

| Bereich | Datei |
|---|---|
| Upload-Protokoll, Limits, Formate, Videolänge, Worker, CSRF, fremde Uploads, Abbruch, Löschen | `tests/Functional/Media/UploadApiTest.php` |
| Export-Inhalt und -Download, Ablauf, vollständige Löschung, Speicherverbrauch | `tests/Functional/Media/ExportAndErasureTest.php` |
| Aufräumen | `tests/Integration/Command/CleanupCommandTest.php` |
| Unfertige Medien nur für Uploader | `tests/Unit/Security/MediaAccessPolicyTest.php` |

Ergebnis: `make test` 121 Tests, `make test-media` 11 Tests; PHPStan (max) und Mago ohne Fehler und Warnungen.

**Manuell über den echten Stack (Caddy, Worker):**
- 20-MB-Video in drei Stücken hochgeladen, mit simuliertem Abbruch und Fortsetzung.
- Der Worker wandelte es zu 1,8 MB H.264 720p um, GPS-Ort entfernt.
- Abruf geschützt: 200, Vorspulen 206, Vorschaubild WebP.
- Caddy lehnt ein 12-MB-Stück ab (413).

## Offen / Hinweise

- Die Upload-Seite wurde vom Auftraggeber mit einem 50-MB-Video im Browser getestet: Upload und Verarbeitung funktionieren. Daraufhin ergänzt:
  - Bewegte Anzeige mit Spinner für die beiden Wartephasen ohne messbaren Fortschritt (Server prüft die Datei, Video wird umgewandelt).
  - Mitlaufende Zeit während der Umwandlung.
  - Bei reduzierter Bewegung (Systemeinstellung) ein ruhiger, gestreifter Balken statt Animation.
- **Originale behalten ihre Metadaten** (auch GPS bei Fotos) – so entschieden (19.10.2026). Gäste bekommen nie Originale, nur bereinigte Varianten; die Originale gehen nur im Export an das Brautpaar.
- **HEIC-Fotos:** Format wird erkannt und angenommen, Abmessungen kann PHP nicht ermitteln. Ob imgproxy sie darstellen kann, ist nicht mit einer echten iPhone-Datei geprüft.
- **HDR-Videos** werden ohne Tone-Mapping umgewandelt (siehe ADR 0001).
- **Upload durch Konten** (z. B. Brautpaar in der Verwaltung) folgt mit Phase 4.
