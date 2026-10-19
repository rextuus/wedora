# Entwicklung

## Voraussetzungen

Docker mit Compose v2, `make`, `openssl`. PHP/Composer werden im Container ausgeführt.

## Secrets

Im Repository liegen **keine** Secrets.

| Datei | Zweck | Versioniert |
|---|---|---|
| `.env` | Symfony-Defaults ohne Secrets | ja |
| `.env.docker.dist` | Vorlage der Compose-Variablen | ja |
| `.env.docker.local` | Compose-Secrets (DB, imgproxy, Medien-Token) | **nein** |
| `.env.local` | `APP_SECRET` | **nein** |

`make secrets` erzeugt die beiden lokalen Dateien mit Zufallswerten und überschreibt nichts.
Für die Produktion sind Symfony Secrets (`secrets:set`) oder Umgebungsvariablen der Laufzeitplattform vorgesehen (Phase 6).

## Dienste

| Dienst | Profil | Erreichbar |
|---|---|---|
| `app` (PHP-FPM 8.4 + ffmpeg) | – | intern |
| `web` (Caddy) | – | `127.0.0.1:${WEB_PORT:-8180}` |
| `database` (MySQL 8.4) | – | `127.0.0.1:3306` (override) |
| `imgproxy` | – | nur intern |
| `mailer` (Mailpit) | – | `127.0.0.1:8025` (override) |
| `worker` (Messenger: Videoverarbeitung, Export; 2 CPU / 1 GB) | – | intern |

Ports lassen sich in `.env.docker.local` anpassen (`WEB_PORT`, `MYSQL_PORT`, `MAILPIT_PORT`).

## Medien

- Alle Medien liegen im Docker-Volume `media_data` (`/srv/media`), je Medium ein Verzeichnis mit `original`, `video.mp4`, `poster.jpg`.
- Das Volume ist von außen nie direkt erreichbar. Ausgeliefert wird nur über `/media/{token}/{variante}`: Symfony prüft, Caddy liefert aus (`X-Accel-Redirect`).
- Videos werden mit ffmpeg zu H.264/AAC-MP4 umgewandelt. Plattformweite Grenzen per Umgebung: `VIDEO_MAX_DURATION_SECONDS`, `VIDEO_FFMPEG_THREADS`. Limits pro Hochzeit: [ADR 0003](adr/0003-medienlimits-und-originale.md).
- Hintergründe: [ADR 0001](adr/0001-video-als-mp4-lokaler-speicher-caddy.md).

## Worker nach Codeänderungen neu starten

Der Worker ist ein lang laufender Prozess und behält geladenen Code im Speicher. Nach Änderungen an Handlern oder Diensten: `make worker-restart`. In Produktion gehört das zu jedem Deployment (Phase 6).

## Regelmäßige Aufgaben

`php bin/console app:media:cleanup` entfernt abgelaufene Uploads und Exporte. In der Entwicklung bei Bedarf von Hand ausführen; in Produktion stündlich (Phase 6).

## Produktion (Ausblick, Phase 6)

`SERVER_NAME` auf die Domain setzen. Caddy holt dann automatisch ein TLS-Zertifikat; dafür müssen die Ports 80 und 443 veröffentlicht werden.

## Qualität

```sh
make qa            # alles außer Medien-Integrationstests
make phpstan
make mago-lint
make mago-analyze
make mago-fmt      # nur prüfen; formatieren: docker compose … exec app vendor/bin/mago format
make test
make test-media    # Gruppe "media": ffmpeg + geschützte Auslieferung, braucht make up
```

Die CI (`.github/workflows/ci.yaml`) führt dieselben Prüfungen aus. Der Job `media-gate` startet den Stack und führt die Medien-Tests aus.
