# Anforderungsspezifikation – Wedora

## Funktionale Anforderungen
- **FR-01 Mehrere Hochzeiten:** Daten, Module, Zugänge und Inhalte sind logisch getrennt.
- **FR-02 Module:** Module pro Hochzeit aktivieren/deaktivieren.
- **FR-03 Rollen:** Rechte für Hochzeitsadministratoren werden durch die Plattformadministration begrenzt; Prüfung serverseitig.
- **FR-04 Zugang:** Link/QR, optional gemeinsames Passwort und widerrufbare persönliche Einladungslinks, kombinierbar.
- **FR-05 Gastidentität:** Anzeigename ohne verpflichtendes E-Mail-/Passwortkonto.
- **FR-06 Upload:** Foto/Video, Mehrfachauswahl, Fortschritt, Limits, Fehler und Wiederholungsversuche.
- **FR-07 Moderation:** nachvollziehbare Upload-, Verarbeitungs-, Freigabe-, Veröffentlichungs- und Fehlerzustände.
- **FR-08 Sichtbarkeit:** unabhängig vom Veröffentlichungsstatus; direkte URL darf keine Berechtigung verleihen.
- **FR-09 Galerie:** responsive Anzeige, optional Likes.
- **FR-10 Gästebuch:** Text, Foto, Video und private Videonachrichten.
- **FR-11 Challenges:** konfigurierbare Aufgaben, Zeitfenster, Beitragstyp und optionale Punkte.
- **FR-12 Lebenszyklus:** Funktionsende, Archivierung, Export und Löschung getrennt.
- **FR-13 Speicherlimits:** Verbrauch und Limits je Hochzeit sichtbar.
- **FR-14 Export/Löschung:** Datenbank, Originale, Derivate, Caches und Providerdaten berücksichtigen.
- **FR-15 Stitch-Design:** bereitgestellte Google-Stitch-Entwürfe sind verbindliche visuelle Referenz.

## Nichtfunktionale Anforderungen
- Mobile-first und browserbasiert.
- Serverseitige Autorisierung für private und unveröffentlichte Inhalte.
- Sichere Medienverarbeitung, Dateityp- und Größenlimits sowie Ressourcenlimits.
- Medienprovider hinter eigenen Interfaces/Adaptern kapseln.
- Diagnostizierbare Logs ohne unnötige personenbezogene Daten.
- Automatisierte Tests, statische Analyse und CI.
- Docker Compose für lokale Entwicklung; containerisierter Produktionsbetrieb.

## Beschlossener Tech-Stack
- Symfony 8.1 als Ausgangsbasis; Version/Supportstatus vor Projektstart erneut prüfen.
- PHP-Version passend zu Symfony und allen Abhängigkeiten.
- Twig + Symfony UX/Stimulus.
- PostgreSQL, Doctrine ORM und Doctrine Migrations.
- Docker und Docker Compose.
- imgproxy OSS für Bilder.
- Hovod als vorgesehener Dienst für Videotranskodierung und HLS.
- PHPStan, Mago und PHPUnit.
- Symfony Messenger/Worker und Redis bei Bedarf.
- S3-kompatibler Objektspeicher; MinIO optional für lokale Entwicklung.

## Medien-Sicherheits-Gate
Symfony bleibt die maßgebliche Quelle für Hochzeit, Beitrag, Uploader, Sichtbarkeit, Moderation, Aufbewahrung, Export und Löschung. Vor Produktivbetrieb muss mit der konkreten Hovod-Version nachgewiesen werden, dass private HLS-Manifeste **und alle Segmente** geschützt sind und URLs/Tokens nicht unbegrenzt Zugriff ermöglichen. Falls Hovod das nicht ausreichend unterstützt, ist eine Schutzschicht oder Alternative erforderlich. Ebenso sind imgproxy-Signierung, erlaubte Quellen und Ressourcenlimits zu prüfen.

## Google Stitch
Stitch-Links, Screenshots und Assets müssen der Entwicklungsumgebung tatsächlich bereitgestellt werden. Claude Code sichtet die Vorlagen, leitet Design-Tokens und Komponenten ab und setzt responsive Zustände um. Keine eigenmächtige Neugestaltung. Wenn die Vorlagen nicht zugänglich sind, muss Claude Code nachfragen statt sie zu erfinden.

## Mindesttests
1. Unberechtigte Gäste können private/unveröffentlichte Medien nicht abrufen.
2. HLS-Manifeste und Segmente sind gleichermaßen geschützt.
3. Widerrufene Einladungen und abgelaufene Zugriffsmittel funktionieren.
4. Uploadabbrüche und Transkodierungsfehler sind nachvollziehbar.
5. Limits werden serverseitig erzwungen.
6. Export und Löschung umfassen Datenbank, Dateien, Derivate, Cache und Provider.
7. Parallele Uploads/Transkodierungen bleiben innerhalb definierter Ressourcenlimits.
8. Kernseiten entsprechen den Stitch-Referenzen und funktionieren mobil.

## Offene Punkte
Produktionsspeicher/Hosting, Upload- und Videolimits, private HLS-Strategie, Backups/Restore, CI/Deployment sowie finale Lizenz- und Reifegradprüfung von imgproxy OSS und Hovod.
