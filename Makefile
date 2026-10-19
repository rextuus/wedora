export UID := $(shell id -u)
export GID := $(shell id -g)

ENV_FILE ?= .env.docker.local
export ENV_FILE

DC        = docker compose --env-file $(ENV_FILE)
PHP       = $(DC) exec -T app php
COMPOSER  = $(DC) exec -T app composer

.DEFAULT_GOAL := help
.PHONY: help secrets up worker-restart down sh install db test test-media phpstan mago-lint mago-fmt mago-analyze qa prod-secrets prod-up prod-down prod-logs prod-console prod-demo prod-backup

help: ## Diese Hilfe
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-14s %s\n", $$1, $$2}'

secrets: ## Lokale Secrets erzeugen (.env.docker.local, .env.local) – überschreibt nichts
	@test -f $(ENV_FILE) || ( \
		sed -e "s/^MYSQL_PASSWORD=.*/MYSQL_PASSWORD=$$(openssl rand -hex 16)/" \
		    -e "s/^MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=$$(openssl rand -hex 16)/" \
		    -e "s/^IMGPROXY_KEY=.*/IMGPROXY_KEY=$$(openssl rand -hex 32)/" \
		    -e "s/^IMGPROXY_SALT=.*/IMGPROXY_SALT=$$(openssl rand -hex 32)/" \
		    -e "s/^MEDIA_TOKEN_SECRET=.*/MEDIA_TOKEN_SECRET=$$(openssl rand -hex 32)/" \
		    .env.docker.dist > $(ENV_FILE) && echo "$(ENV_FILE) erzeugt" )
	@test -f .env.local || ( echo "APP_SECRET=$$(openssl rand -hex 32)" > .env.local && echo ".env.local erzeugt" )

up: secrets ## Umgebung starten (app, worker, web, database, imgproxy)
	$(DC) up -d --build --wait

worker-restart: ## Worker nach Codeänderungen neu starten (lang laufende Prozesse halten alten Code)
	$(PHP) bin/console messenger:stop-workers

down: ## Alles stoppen
	$(DC) down

sh: ## Shell im app-Container
	$(DC) exec app sh

install: ## Composer-Abhängigkeiten installieren
	$(COMPOSER) install

db: ## Datenbanken (dev und test) migrieren
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

test: ## PHPUnit (ohne Mediendienste)
	$(PHP) bin/phpunit

test-media: ## Integrationstests: ffmpeg, geschützte Auslieferung via Caddy/imgproxy
	$(PHP) bin/phpunit --group media

phpstan: ## PHPStan
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

mago-lint: ## Mago Linter
	$(PHP) vendor/bin/mago lint

mago-analyze: ## Mago Analyzer
	$(PHP) vendor/bin/mago analyze

mago-fmt: ## Mago Formatter (prüfen)
	$(PHP) vendor/bin/mago format --check

qa: phpstan mago-lint mago-analyze mago-fmt test ## Alle Prüfungen

# ---------- Produktion (auf dem Server ausführen, siehe docs/deployment.md) ----------
PROD_ENV     ?= .env.prod.local
PROD_PROJECT ?= wedora
PROD_DC       = docker compose -p $(PROD_PROJECT) -f compose.prod.yaml --env-file $(PROD_ENV)
PROD_PHP      = $(PROD_DC) exec -T app php
DEMO_DIR     ?= var/demo-photos

prod-secrets: ## Produktion: .env.prod.local mit Zufallswerten anlegen (überschreibt nichts)
	@test -f $(PROD_ENV) || ( \
		sed -e "s/^APP_SECRET=.*/APP_SECRET=$$(openssl rand -hex 32)/" \
		    -e "s/^MYSQL_PASSWORD=.*/MYSQL_PASSWORD=$$(openssl rand -hex 16)/" \
		    -e "s/^MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=$$(openssl rand -hex 16)/" \
		    -e "s/^IMGPROXY_KEY=.*/IMGPROXY_KEY=$$(openssl rand -hex 32)/" \
		    -e "s/^IMGPROXY_SALT=.*/IMGPROXY_SALT=$$(openssl rand -hex 32)/" \
		    -e "s/^MEDIA_TOKEN_SECRET=.*/MEDIA_TOKEN_SECRET=$$(openssl rand -hex 32)/" \
		    .env.prod.dist > $(PROD_ENV) && chmod 600 $(PROD_ENV) && \
		echo "$(PROD_ENV) erzeugt – jetzt SERVER_NAME, DEFAULT_URI (und optional GEMINI_API_KEY) eintragen." )

prod-up: ## Produktion: Images bauen, starten, Datenbank migrieren
	$(PROD_DC) up -d --build --wait
	$(PROD_PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(PROD_PHP) bin/console messenger:stop-workers

prod-down: ## Produktion: stoppen (Daten bleiben in den Volumes)
	$(PROD_DC) down

prod-logs: ## Produktion: Logs verfolgen
	$(PROD_DC) logs -f --tail=100

prod-console: ## Produktion: Symfony-Konsole, z. B. make prod-console CMD="app:wedding:usage larissa-und-tim"
	$(PROD_DC) exec app php bin/console $(CMD)

prod-demo: ## Produktion: Vorführ-Hochzeit mit Beispieldaten aus $(DEMO_DIR) anlegen (ARGS="--reset" zum Neuaufsetzen)
	@test -d $(DEMO_DIR) || ( echo "Ordner $(DEMO_DIR) fehlt – Beispielmedien zuerst hochladen (docs/deployment.md)." && exit 1 )
	$(PROD_DC) exec -T -u root app rm -rf /tmp/demo-photos
	$(PROD_DC) cp $(DEMO_DIR) app:/tmp/demo-photos
	$(PROD_PHP) bin/console app:demo:seed /tmp/demo-photos $(ARGS)
	$(PROD_DC) exec -T -u root app rm -rf /tmp/demo-photos

prod-backup: ## Produktion: Datenbank-Dump und Medien nach ./backups/<datum>/ sichern
	@mkdir -p backups/$$(date +%F)
	$(PROD_DC) exec -T database sh -c 'mysqldump -uroot -p"$$MYSQL_ROOT_PASSWORD" --single-transaction --databases "$$MYSQL_DATABASE"' | gzip > backups/$$(date +%F)/datenbank.sql.gz
	$(PROD_DC) run --rm --no-deps -T --entrypoint tar app -C /srv/media -czf - . > backups/$$(date +%F)/medien.tar.gz
	@echo "Sicherung in backups/$$(date +%F)/"
