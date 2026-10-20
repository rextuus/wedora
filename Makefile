export UID := $(shell id -u)
export GID := $(shell id -g)

ENV_FILE ?= .env.docker.local
export ENV_FILE

DC        = docker compose --env-file $(ENV_FILE)
PHP       = $(DC) exec -T app php
COMPOSER  = $(DC) exec -T app composer

.DEFAULT_GOAL := help
.PHONY: help secrets up worker-restart down sh install db test test-media phpstan mago-lint mago-fmt mago-analyze qa prod-secrets prod-up prod-down prod-logs prod-console prod-demo prod-status prod-backup test-browser

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
		echo "$(PROD_ENV) erzeugt – jetzt DEFAULT_URI, HTTP_BIND, REVERSE_PROXY_IPS (und optional GEMINI_API_KEY) eintragen." )

prod-up: ## Produktion: Images bauen, Datenbank migrieren, alles starten
	$(PROD_DC) build
	$(PROD_DC) up -d --wait database app
	$(PROD_PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(PROD_DC) up -d --wait
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

prod-status: ## Produktion: Zustand auf einen Blick (Container, Gesundheit, Speicher, Hintergrundjobs)
	@$(PROD_DC) ps --format 'table {{.Service}}\t{{.Status}}'
	@echo; echo "Webserver (das, was der Reverse-Proxy auf dem VPS erreicht):"
	@$(PROD_DC) exec -T web wget -q -O- http://127.0.0.1/healthz 2>/dev/null | sed 's/^/  /' || echo "  FEHLER"
	@echo; echo "Gesundheit (Datenbank aus Sicht der App):"
	@$(PROD_DC) exec -T app php -r 'require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env"); $$k = new App\Kernel("prod", false); $$k->boot(); $$k->getContainer()->get("doctrine")->getConnection()->executeQuery("SELECT 1"); echo "  ok\n";' || echo "  FEHLER"
	@echo; echo "Speicher:"
	@$(PROD_DC) exec -T app df -hP /srv/media | tail -1 | awk '{print "  Festplatte: " $$(NF-3) " belegt, " $$(NF-2) " frei (" $$(NF-1) " voll)"}'
	@$(PROD_DC) exec -T app du -sh /srv/media 2>/dev/null | awk '{print "  davon Wedora-Medien: " $$1}'
	@echo; echo "Hintergrundjobs:"
	@$(PROD_DC) exec -T app php bin/console messenger:stats --no-interaction 2>/dev/null | grep -E "async|failed" | sed 's/^/  /' || true

prod-backup: ## Produktion: Datenbank-Dump und Medien nach ./backups/<datum>/ sichern
	@mkdir -p backups/$$(date +%F)
	$(PROD_DC) exec -T database sh -c 'mysqldump -uroot -p"$$MYSQL_ROOT_PASSWORD" --single-transaction --databases "$$MYSQL_DATABASE"' | gzip > backups/$$(date +%F)/datenbank.sql.gz
	$(PROD_DC) run --rm --no-deps -T --entrypoint tar app -C /srv/media -czf - . > backups/$$(date +%F)/medien.tar.gz
	@echo "Sicherung in backups/$$(date +%F)/"

# ---------- Browser-Prüfungen (Phase 5) ----------
BROWSER_DIR = var/browser-test
PLAYWRIGHT  = mcr.microsoft.com/playwright:v1.64.1-noble

test-browser: ## Browser-Prüfungen gegen die laufende Umgebung: Barrierefreiheit (axe-core) und Upload-Fehlerfälle
	@mkdir -p $(BROWSER_DIR)
	$(PHP) bin/console app:demo:seed tests/Fixtures --slug=browser-test --title='Lea & Max' --reset > /dev/null
	@test -f $(BROWSER_DIR)/admin-password || openssl rand -hex 16 > $(BROWSER_DIR)/admin-password
	@cat $(BROWSER_DIR)/admin-password | $(DC) exec -T app php bin/console app:user:create browser-test@wedora.local 'Browser-Test' --platform-admin --password-stdin --no-interaction > /dev/null 2>&1 || true
	@$(PHP) bin/console app:wedding:grant browser-test browser-test@wedora.local view_content moderate configure manage_access export manage_lifecycle > /dev/null
	@$(DC) exec -T app sh -c 'cd $(BROWSER_DIR) && ( [ -f gross.jpg ] || ffmpeg -nostdin -v error -f lavfi -i "nullsrc=s=3000x3000,geq=random(1)*255:random(1)*255:random(1)*255" -frames:v 1 -q:v 1 gross.jpg ) && cp ../../tests/Fixtures/phase0.jpg klein.jpg && printf "kein Bild\n%.0s" $$(seq 200) > kaputt.jpg'
	docker run --rm --network wedora_default -v $(CURDIR)/tests/Browser:/work/tests:ro -v $(CURDIR)/$(BROWSER_DIR):/data \
		-v wedora_pw_modules:/work/node_modules -w /work \
		-e JOIN_PATH="$$($(PHP) bin/console app:wedding:link browser-test --plain | sed -E 's#^https?://[^/]+##')" \
		-e ADMIN_EMAIL=browser-test@wedora.local -e ADMIN_PASSWORD="$$(cat $(BROWSER_DIR)/admin-password)" \
		$(PLAYWRIGHT) sh -c '( [ -d node_modules/playwright ] && [ -d node_modules/axe-core ] ) || npm i playwright@1.64.1 axe-core@4.11.0 > /dev/null 2>&1; node tests/run.mjs'
