# Wedora

Mobile-first Webplattform für gemeinsame Hochzeitserinnerungen (Fotos, Videos, Challenges, Gästebuch).
*Wedora* ist ein Arbeitsname.

- Konzept: [`docs/konzept/`](docs/konzept/)
- Designsystem „Leinen“: [`docs/adr/0004-designsystem-abendlicht.md`](docs/adr/0004-designsystem-abendlicht.md) (die ursprünglichen Stitch-Entwürfe liegen zur Historie in [`design/stitch/`](design/stitch/))
- Stand Phase 0 inkl. Sicherheits-Gate, Annahmen und offener Fragen: [`docs/phase-0-bestandsaufnahme.md`](docs/phase-0-bestandsaufnahme.md)
- Phase 2 (Datenmodell, Zugang, Rechte): [`docs/phase-2-domaene-und-berechtigungen.md`](docs/phase-2-domaene-und-berechtigungen.md)
- Phase 3 (Upload, Verarbeitung, Export, Löschung): [`docs/phase-3-medien.md`](docs/phase-3-medien.md)
- Phase 4 (Galerie, Gästebuch, Aufgaben, Moderation, Verwaltung): [`docs/phase-4-module-und-verwaltung.md`](docs/phase-4-module-und-verwaltung.md)
- Architekturentscheidungen: [`docs/adr/`](docs/adr/)
- Entwicklung: [`docs/entwicklung.md`](docs/entwicklung.md)

## Stack

Symfony 8.1 · PHP 8.4 · Twig + Symfony UX (Stimulus/Turbo, AssetMapper) · MySQL 8.4 · Doctrine ORM/Migrations ·
Docker Compose · Caddy · imgproxy (Bilder) · ffmpeg (Videos → MP4) · lokaler Medienspeicher (Docker-Volume) ·
PHPUnit · PHPStan (max) · Mago

## Schnellstart

```sh
make up          # erzeugt lokale Secrets, startet app, worker, web (Caddy), database, imgproxy
make install     # Composer-Abhängigkeiten
make db          # Migrationen (dev + test)
make qa          # PHPStan, Mago, PHPUnit
make test-media  # ffmpeg- und Zugriffsschutz-Tests gegen den laufenden Stack
```

App: http://localhost:8180 · Health: http://localhost:8180/healthz
