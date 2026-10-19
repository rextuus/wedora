# ADR 0001 – Videos als MP4, lokaler Medienspeicher, Caddy statt nginx

- **Status:** angenommen (19.10.2026, durch den Auftraggeber)
- **Ersetzt:** Teile von `docs/konzept/04_Technische_Architekturentscheidung.md` (Hovod, HLS, S3)

## Kontext

Die Architektur sah Hovod für Transkodierung und HLS sowie S3-kompatiblen Speicher vor. Die Prüfung in Phase 0 hat ergeben:

- Hovod 1.0.4 hat keine Zugriffskontrolle für die Wiedergabe. Eine eigene Schutzschicht in Symfony wäre ohnehin nötig.
- Hovod verlangt S3, bringt eine eigene Datenbank und Benutzerverwaltung mit und ist ein sehr junges Projekt ohne Release-Policy.
- Hovod speichert jedes Video zusätzlich als vollständige MP4-Kopie.
- Gästevideos sind kurz (in den Stitch-Vorlagen 12–24 s), die Zuschauerzahl ist klein. Adaptives Streaming (HLS) bringt hier kaum Nutzen.
- Betrieb ist ein einzelner privater Server mit Docker-Stack, kein Cloud-Speicher.

## Entscheidung

1. **Videos:** Ein Messenger-Worker transkodiert mit **ffmpeg** zu **H.264/AAC-MP4** (lange Seite max. 1280 px, `faststart`, Metadaten inkl. GPS entfernt) und erzeugt ein Standbild. Kein HLS.
2. **Speicher:** **Lokales Docker-Volume** (`media_data`), Zugriff nur über das Interface `MediaStorage`. Ein späterer Wechsel auf S3 bleibt über eine weitere Implementierung möglich.
3. **Bilder:** imgproxy liest direkt aus dem Volume (`local:///`). Von außen sind nur feste Presets (`thumb`, `display`) anforderbar.
4. **Webserver:** **Caddy** statt nginx (im ursprünglichen Konzept bereits als Option genannt). Automatisches HTTPS in Produktion.
5. **Zugriffsschutz:** Symfony prüft bei jeder Anfrage ein kurzlebiges, signiertes Token und die Berechtigung. Danach liefert Caddy per `X-Accel-Redirect` (`handle_response`) aus dem Volume bzw. über das interne imgproxy aus. Weder Volume noch imgproxy sind direkt erreichbar.

## Konsequenzen

- **Entfällt:** Hovod, S3-Container, AsyncAws und das Sicherheits-Gate „HLS-Manifest und Segmente gleich schützen“ (eine Datei pro Video).
- **Weniger Dienste:** app, worker, web (Caddy), database, imgproxy.
- **Transkodierlogik wird selbst gepflegt** (`FfmpegVideoTranscoder`). Ressourcen werden über Threads, Timeout, Worker-Anzahl und CPU-Limits begrenzt.
- **Export und Löschung** sind einfach: ein Verzeichnis pro Medium (`original`, `video.mp4`, `poster.jpg`). Da es keinen Bild-Cache gibt, entfällt auch das Cache-Löschen. Kommt später ein Cache dazu, muss die Löschung ihn einschließen.
- **Speicherplatz und Backups** des Volumes liegen in eigener Verantwortung (Phase 6).
- **HLS kann nachgerüstet werden** (weitere `MediaFile`-Variante, zusätzlicher ffmpeg-Aufruf), falls später lange Videos dazukommen.
- **Bekannte Grenze:** HDR-Videos (iPhone, 10 bit) werden ohne Tone-Mapping nach 8 bit konvertiert und können blasser wirken. Bei Bedarf in Phase 3 nachbessern.
