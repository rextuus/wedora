# Umsetzungsplan für Claude Code – Wedora

## Vor Beginn
Lies Produktkonzept, Anforderungsspezifikation und Architekturentscheidung. Prüfe zuerst die vorhandene Codebasis und ändere keine bestätigten Produkt-/Architekturentscheidungen eigenmächtig. Halte offene Fragen und Annahmen fest.

## Verbindliche Designreferenz: Google Stitch
Die vorhandenen, vom Auftraggeber bestätigten Google-Stitch-Designvorlagen sind die maßgebliche visuelle Referenz.
1. Suche nach bereitgestellten Stitch-Links, Screenshots, Assets und Designunterlagen.
2. Sichte die tatsächlich zugänglichen Vorlagen vor der Implementierung der betreffenden Seiten.
3. Leite Layouts, Farben, Typografie, Abstände, Komponenten, responsive Zustände und Interaktionen daraus ab.
4. Implementiere mit Twig und Symfony UX/Stimulus.
5. Ersetze die Gestaltung nicht durch ein generisches Admin-Template und erfinde keine neue visuelle Richtung.
6. Sind Vorlagen nicht vorhanden/zugänglich, frage nach Links oder Exporten. Behaupte nicht, nicht gesehene Vorlagen analysiert zu haben.
7. Ergänze unabhängig davon Barrierefreiheit, Validierung, Lade-/Fehlerzustände und Tests.

## Festgelegter Stack
Symfony 8.1 als Ausgangsbasis; kompatibles PHP; Twig + Symfony UX/Stimulus; PostgreSQL; Doctrine; Docker/Compose; imgproxy OSS für Bilder; Hovod für Videoverarbeitung/HLS vorbehaltlich Sicherheitstest; PHPStan, Mago, PHPUnit; Messenger/Worker bei Bedarf; S3-kompatibler Speicher.

## Phasen
### Phase 0 – Bestandsaufnahme und Prototyp
- Repository und vorhandene Dateien sichten.
- Stitch-Designs und Assets lokalisieren.
- Versionen, Lizenzen und API-Stabilität von imgproxy/Hovod prüfen.
- Prototyp für Bildtransformation, Video-Upload, Transkodierung und HLS-Wiedergabe erstellen.
- Private HLS-Wiedergabe testen: Manifest und Segmente müssen geschützt sein. Ohne Nachweis keine Produktionsfreigabe privater Videos.

### Phase 1 – Fundament
Symfony-Projekt, Docker-Entwicklungsumgebung, PostgreSQL, Doctrine, Migrationen, PHPUnit, PHPStan, Mago, CI und sichere Secret-Verwaltung einrichten.

### Phase 2 – Domäne und Berechtigungen
Hochzeit, Module, Gastidentität, Zugangsmodelle, Rollen, serverseitige Autorisierung sowie getrennte Sichtbarkeits- und Veröffentlichungsmodelle implementieren.

### Phase 3 – Medien
Provider-Interfaces/Adapter, Foto-Upload und imgproxy, Video-Upload und Hovod, Statusverarbeitung, Fehler, Limits, Moderation, Zugriffsschutz, Export und Löschung.

### Phase 4 – Kernmodule
Galerie, Likes, Moderation, Gästebuch, Challenges und Administration.

### Phase 5 – Design und Qualität
Kernseiten gegen Stitch-Vorlagen visuell prüfen; mobile Darstellung, Barrierefreiheit, Lade-/Fehlerzustände, Uploadfortschritt und Wiederholungen testen.

### Phase 6 – Betrieb
Backups/Restore, Monitoring, Logs, Ressourcenlimits, Export, Archivierung, vollständige Löschung und Deploymentdokumentation.

## Nicht verhandelbare Kriterien
- Serverautorisierung für private Inhalte.
- Keine Secrets im Repository.
- Uploadlimits serverseitig.
- HLS-Manifeste und Segmente gleichwertig geschützt.
- Export/Löschung schließen Originale, Derivate, Caches und Providerdaten ein.
- Keine grundlegenden Designänderungen ohne Rücksprache.
- Tests und statische Prüfungen ausführen und Ergebnisse wahrheitsgemäß dokumentieren.
