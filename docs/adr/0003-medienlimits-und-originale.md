# ADR 0003 – Medienlimits pro Hochzeit, Originale werden behalten

- **Status:** angenommen (19.10.2026, durch den Auftraggeber)

## Entscheidung

**Limits sind pro Hochzeit einstellbar.** Standardwerte für neue Hochzeiten:

| Grenze | Standard |
|---|---|
| Max. Videolänge | 2 Minuten |
| Max. Dateigröße Video | 500 MB |
| Max. Dateigröße Foto | 30 MB |
| Speicher pro Hochzeit | 25 GB |
| Speicher pro Gast | 2 GB (optional, abschaltbar) |

**Originale werden behalten**, zusätzlich zu den umgewandelten Varianten (MP4, Standbild). Damit enthält der Export die volle Qualität.

## Umsetzung

- **Zwei Ebenen** (Konzept: Hochzeitsadmins werden durch die Plattformadministration begrenzt):
  - Die Plattform legt Obergrenzen fest. Diese gelten serverweit; im Code etwa `VIDEO_MAX_DURATION_SECONDS` als harte Grenze für ffmpeg.
  - Pro Hochzeit gelten Werte bis zu diesen Obergrenzen. Sie stehen in der Datenbank als Teil der Hochzeit (Phase 2).
- **Serverseitige Durchsetzung** bei jedem Upload (Phase 3):
  - Dateigröße schon während des Uploads.
  - Videolänge nach `ffprobe`, vor der Umwandlung.
  - Speicherkontingent von Hochzeit und Gast, gezählt inkl. Originale und Varianten.
- **Uploads in Stücken, fortsetzbar** (Phase 3): Große Dateien laufen nicht in einem Request durch PHP. Das hält die PHP-Limits klein (`upload_max_filesize` 25 MB) und erlaubt Wiederaufnahme nach Verbindungsabbruch.
- **Speicherübersicht** für Hochzeit und Plattform zeigt Verbrauch und Limit (FR-13).

## Konsequenzen

- Der Speicherbedarf liegt bei etwa dem Doppelten der umgewandelten Varianten. Schätzung für 100 Gäste: ca. 12 GB pro Hochzeit.
- Die Plattengröße des Servers und Backups sind danach zu planen (Phase 6).
