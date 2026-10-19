# Technische Architekturentscheidung – Wedora

**Status:** Zielarchitektur für den Prototyp; Produktionsfreigabe hängt von den unten genannten Prüfungen ab.

## Entscheidungen
- Produktname: Wedora (Arbeitsname; Marke/Domain nicht geprüft).
- Backend: Symfony 8.1 als Ausgangsbasis.
- Frontend: Twig + Symfony UX/Stimulus.
- Datenbank: PostgreSQL; Persistenz mit Doctrine ORM/Migrations.
- Laufzeit: Docker und Docker Compose.
- Bilder: imgproxy OSS.
- Videos: Hovod für Transkodierung und HLS, vorbehaltlich Sicherheitsprüfung.
- Qualität: PHPStan, Mago, PHPUnit.
- Asynchrones: Symfony Messenger/Worker bei Bedarf.
- Speicher: S3-kompatibler Objektspeicher noch auszuwählen; MinIO optional lokal.

Version, Supportstatus und Kompatibilität aller Abhängigkeiten vor Projektstart erneut prüfen und Versionen/Images pinnen.

## Logische Dienste
- `app`: Symfony/PHP.
- `web`: Nginx oder Caddy.
- `database`: PostgreSQL.
- `worker`: Messenger Worker.
- `imgproxy`: Bildtransformation.
- `hovod`: Videodienst gemäß dessen unterstützter Deployment-Architektur.
- optional Redis und S3-kompatibler Speicher.

Videotranskodierung kann CPU/RAM-intensiv sein. Produktionskomponenten sollen bei Bedarf getrennt skaliert werden können.

## Zuständigkeiten
Symfony ist maßgeblich für Hochzeit, Beitrag, Uploader, Sichtbarkeit, Veröffentlichung, Moderation, Zugriff, Aufbewahrung, Export und Löschung. Provider-IDs und Verarbeitungsstatus werden gespeichert; Provider-spezifische Logik wird hinter Interfaces/Adaptern gekapselt.

imgproxy liefert Bildvarianten. Vor Produktion sind Quellbeschränkung, Signierung, URL-Validierung und Ressourcenlimits zu prüfen.

Hovod übernimmt die vorgesehene Videoverarbeitung und HLS-Auslieferung. Konkrete API, Uploadverfahren, Statusmeldungen, private Wiedergabe, Löschung und Export sind mit der gewählten Version praktisch zu prüfen.

## Blockierendes Sicherheits-Gate: private Videos
Ein HLS-Stream umfasst ein Manifest und referenzierte Segmente. Es reicht nicht, nur das Manifest zu schützen.
Vor Produktion muss nachgewiesen sein:
1. Unberechtigte Gäste können private Videos nicht allein über eine bekannte URL abrufen.
2. Manifest, Segmente und sonstige Ressourcen sind geschützt.
3. Temporäre Tokens/URLs laufen ab.
4. Widerrufene Einladungen und geänderte Sichtbarkeit werden berücksichtigt.
5. Unveröffentlichte/abgelehnte Beiträge sind nicht öffentlich.
6. Wenn Hovod diese Anforderungen nicht erfüllt, muss eine Schutzschicht oder Alternative gewählt werden.

## Upload-/Verarbeitungsablauf
1. Symfony prüft Hochzeit, Rechte, Limits und Beitragstyp.
2. Sicherer Upload wird vorbereitet; große Dateien möglichst nicht vollständig durch PHP leiten.
3. Medium wird Hochzeit und Beitrag zugeordnet.
4. Hovod verarbeitet; Status/Fehler werden nachvollziehbar in Symfony geführt.
5. Veröffentlichung folgt der Moderationskonfiguration.
6. Jede Wiedergabe unterliegt Zugriffsschutz.
7. Export/Löschung umfassen Datenbank, Original, Derivate, Provider und Cache.

## Qualität und Betrieb
PHPStan-Level nach initialer Prüfung festlegen. Mago für unterstützte Analyse-/Lint-/Format-Aufgaben nutzen. CI führt Tests und Qualitätsprüfungen aus. Secrets nie ins Git-Repository legen. Versionen, Lizenzen, Wartungsstatus, Sicherheitsupdates und API-Stabilität der Mediendienste vor Produktion prüfen.

Selbsthosting vermeidet ggf. Nutzungsgebühren, nicht aber Kosten für Rechenleistung, Speicher, Bandbreite, Backups, Monitoring und Wartung.

## Offene Punkte
Produktionsspeicher/Hosting, Videoformate und Limits, HLS-Autorisierung, Hovod-API, Backups/Restore, CI/Deployment, Lizenzprüfung und finale Marken-/Domainprüfung.
