# Wedora auf einem eigenen Server betreiben

Diese Anleitung bringt Wedora auf einen privaten Server, damit du es über eine eigene Adresse (z. B. `https://wedora.deine-domain.de`) zeigen kannst – mit denselben Demo-Daten wie lokal.

**Aufbau live:** Wedora läuft als Docker-Stack auf einem Rechner zu Hause (ThinkCentre „abyss“) und belegt dort **nur einen HTTP-Port** (Standard `8190`). HTTPS und das Zertifikat übernimmt ein **Caddy auf einem VPS** mit fester IP, der die Anfragen über **WireGuard** an diesen Port weiterleitet – so wie bei den anderen Projekten auf dem Rechner. Wedora belegt also weder 80 noch 443.

```
Gast ──HTTPS──▶ VPS (Caddy, Zertifikat) ──WireGuard──▶ ThinkCentre :8190 (Wedora-Caddy, HTTP) ──▶ App
```

Getestet als Generalprobe auf dem Entwicklungsrechner (gleiche Befehle, anderer Port, ohne echte Domain/HTTPS): am 19.10.2026 Bauen, Start, Migration, Demo-Daten, Gast-Beitritt, Galerie, Sicherung und Wiederherstellung; am 20.10.2026 erneut Start mit leerer Datenbank (ohne Fehler im Worker), `make prod-status`, `/healthz`, Worker verarbeitet den Zeitplan fürs stündliche Aufräumen (der Aufräum-Auftrag selbst ist per Test geprüft). Ebenfalls am 20.10.2026: **Betrieb hinter einem Reverse-Proxy** wie live (zweiter Caddy als „VPS“ mit HTTPS davor): Beitritt über HTTPS mit sicherem Gast-Cookie, Upload in Stücken, geschützte Bildauslieferung über imgproxy – im echten Browser; nach außen nur der eine HTTP-Port.

---

## Was auf dem Server läuft

Alles in Docker, gestartet über `compose.prod.yaml`:

| Dienst | Aufgabe | Von außen erreichbar? |
|---|---|---|
| `web` (Caddy) | Webserver (nur HTTP), liefert Fotos geschützt aus | ja, **nur ein Port** (`HTTP_PORT`, Standard 8190) – für den VPS |
| `app` (PHP-FPM, Symfony) | die Anwendung | nein |
| `worker` | wandelt Videos um, zeichnet Porträts | nein |
| `database` (MySQL 8.4) | Datenbank | nein |
| `imgproxy` | verkleinert Bilder | nein |

Daten liegen in Docker-Volumes: `database_data` (Datenbank), `media_data` (alle Fotos/Videos/Porträts). Ein Neustart oder Update der Container löscht nichts. Der Projektname `wedora` hält Container, Netze und Volumes getrennt von den anderen Stacks auf dem Rechner.

---

## 1. Voraussetzungen

**Server**
- Linux (z. B. Debian 12 oder Ubuntu 24.04), mind. **2 CPU-Kerne, 4 GB RAM**, Speicher nach Bedarf (Standard-Kontingent pro Hochzeit: 25 GB)
- Docker Engine mit Compose-Plugin: <https://docs.docker.com/engine/install/>
- `make`, `openssl` und `rsync`: `sudo apt install make openssl rsync`

**Domain und VPS**
- Ein DNS-Eintrag (A, bei IPv6 zusätzlich AAAA) für deine Wunschadresse zeigt auf die **feste IP des VPS** (nicht auf den Rechner zu Hause).
  Prüfen: `dig +short wedora.deine-domain.de` muss die VPS-IP liefern.
- Auf dem VPS läuft Caddy mit offenen Ports 80 und 443; die WireGuard-Verbindung zum ThinkCentre steht (`sudo wg show` zeigt einen aktuellen „latest handshake“).
- Merk dir die beiden WireGuard-Adressen: die des ThinkCentre (z. B. `10.8.0.2`) und die des VPS (z. B. `10.8.0.1`).

**Freier Port auf dem ThinkCentre**
- Wedora braucht einen freien TCP-Port. Belegt sind dort u. a. 2222, 3307, 7999, 8080, 8081, 8088, 8089, 8096, 32768, 32769, 32811. Vorschlag: **8190**.
  Prüfen: `ss -ltn | grep :8190` darf nichts ausgeben.

---

## 2. Code auf den Server bringen

Vom Entwicklungsrechner aus (im Projektordner), ohne Git-Hosting:

```bash
rsync -az --delete --exclude-from=.dockerignore ./ benutzer@server:~/wedora/
```

- `.dockerignore` schließt lokale Secrets (`.env.local`, `.env.docker.local`), `var/`, `vendor/` usw. aus.
- `--delete` räumt entfernte Dateien auf; ausgeschlossene Dateien auf dem Server (z. B. `.env.prod.local`) bleiben dabei erhalten.

Alle weiteren Schritte **auf dem Server** im Ordner `~/wedora`.

---

## 3. Secrets anlegen

```bash
cd ~/wedora
make prod-secrets
nano .env.prod.local
```

`make prod-secrets` erzeugt `.env.prod.local` mit zufälligen Passwörtern und Schlüsseln (Rechte 600). Eintragen musst du nur:

```dotenv
DEFAULT_URI=https://wedora.deine-domain.de
HTTP_PORT=8190
HTTP_BIND=10.8.0.2            # WireGuard-IP dieses Rechners – dann ist Wedora nur über den Tunnel erreichbar
REVERSE_PROXY_IPS=10.8.0.1    # WireGuard-IP des VPS – nur dessen X-Forwarded-* (Gast-IP, https) gelten
GEMINI_API_KEY=...            # optional, für gezeichnete Porträts
```

- `DEFAULT_URI` steckt in QR-Codes und Einladungslinks – muss die öffentliche Adresse mit `https://` sein.
- `HTTP_BIND`: Docker veröffentlicht Ports an der Firewall des Rechners (ufw) vorbei. Bindest du an die WireGuard-IP, kommt aus dem Heimnetz oder Internet niemand direkt an Wedora vorbei am VPS. `0.0.0.0` (Standard) lauscht überall – nur nehmen, wenn es nicht anders geht.
- `REVERSE_PROXY_IPS`: Standard `private_ranges` (alle privaten Netze) funktioniert sofort; mit der genauen VPS-Adresse kann sich niemand im Heimnetz als „kam per HTTPS“ oder mit falscher IP ausgeben.
- **Gemini:** Für echte Gäste einen **kostenpflichtigen** API-Zugang verwenden (bei kostenlosen Zugängen darf Google Eingaben zur Produktverbesserung nutzen, siehe ADR 0005). Ohne Schlüssel ist die Porträt-Funktion einfach unsichtbar.
- **Kostenbremse:** Wie viele Zeichnungen pro Tag bzw. Monat über alle Hochzeiten erlaubt sind, stellst du als Plattform-Admin in der Verwaltung unter „Alle Hochzeiten“ ein (0 = Zeichnen aus, gilt sofort). `GEMINI_DAILY_LIMIT` (Standard 50) und `GEMINI_MONTHLY_LIMIT` (Standard 300) sind die Ausgangswerte, solange dort nichts gespeichert ist. Ist eine Grenze erreicht, geht nichts mehr an Gemini; Gäste sehen „für heute ausgeschöpft“. Notaus ohne Verwaltung: `GEMINI_DAILY_LIMIT=0` (danach `make prod-up`) – wirkt nur, solange in der Verwaltung keine Grenzen gespeichert sind; sonst dort auf 0 setzen. Stand ansehen: Verwaltung → Einstellungen oder `make prod-console CMD="app:ai:usage"`.
- **Zweite Sicherung direkt bei Google** (empfohlen, unabhängig von der App):
  - In der Google Cloud Console beim Projekt des API-Schlüssels unter „APIs & Dienste“ → Gemini API → „Kontingente“ eine Obergrenze für Anfragen pro Tag setzen.
  - Unter „Abrechnung“ → „Budgets und Benachrichtigungen“ ein Budget mit E-Mail-Warnung anlegen.
  - Wichtig: Ein Budget warnt nur und stoppt nicht. Hart bremsen tun das Kontingent und die App.
- `.env.prod.local` **nie** ins Repository und nicht weitergeben. Sicher aufbewahren (z. B. Passwortmanager) – ohne die Werte lassen sich Sicherungen nicht wieder einspielen.

---

## 3b. Weiterleitung auf dem VPS einrichten

Auf dem **VPS** im Caddyfile einen Block ergänzen (Adresse und Port anpassen):

```caddyfile
wedora.deine-domain.de {
	reverse_proxy 10.8.0.2:8190
}
```

Dann `sudo systemctl reload caddy` (bzw. `docker exec <caddy-container> caddy reload --config /etc/caddy/Caddyfile`). Caddy holt das Zertifikat selbst und setzt automatisch `X-Forwarded-For`, `X-Forwarded-Proto` und `X-Forwarded-Host` – mehr ist nicht nötig. Uploads kommen in Stücken zu höchstens 8 MB, Video-Downloads werden durchgereicht; Timeouts oder Größenlimits musst du am VPS nicht ändern.

Vom VPS aus prüfen, dass der Tunnel zum Port reicht: `curl -s http://10.8.0.2:8190/healthz` → `{"status":"ok",…}`.

---

## 4. Starten

```bash
make prod-up
```

Baut die Images (beim ersten Mal einige Minuten), startet alle Dienste, wartet bis sie gesund sind und migriert die Datenbank.

Prüfen:

```bash
make prod-status                                   # alle Dienste laufen, „Webserver“ meldet {"status":"ok",…}
curl -I https://wedora.deine-domain.de/login       # vom eigenen Rechner aus: HTTP/2 200 (über den VPS)
```

---

## 5. Verwaltungskonto anlegen

```bash
make prod-console CMD="app:user:create du@example.de 'Wolfgang' --platform-admin"
```

Das Passwort wird verdeckt abgefragt. Anmelden unter `https://wedora.deine-domain.de/login`.

---

## 6. Demo-Daten einspielen (Stand wie lokal)

**a) Beispielmedien hochladen** – vom Entwicklungsrechner:

```bash
rsync -az var/demo-photos/ benutzer@server:~/wedora/var/demo-photos/
```

(18 Dateien, ca. 19 MB: 15 Fotos, 3 Videos, Bildunterschriften als `.txt`. Der Unterordner `quellen/` wird nicht importiert.)

**b) Auf dem Server:**

```bash
make prod-demo
```

Legt die Hochzeit **„Larissa & Tim“** (`larissa-und-tim`, 18.07.2027) an:
- Aufgaben „First Drink“ und „Last Drink“ (Videos), gezeichnete Porträts eingeschaltet
- Umfrage „Wer von beiden?“ mit sechs Fragen und Stimmen der Beispielgäste
- 8 Beispielgäste (Marie, Jonas, Oma Ilse, …) mit allen Fotos und Videos, zufälligen Likes und 3 Gästebucheinträgen
- Videos wandelt der Worker in den nächsten Minuten um

Am Ende steht der **QR-/Beitrittslink** – den kannst du direkt weitergeben oder als QR-Code zeigen.

**c) Dein Konto für die Hochzeit freischalten** (damit du sie in der Verwaltung siehst):

```bash
make prod-console CMD="app:wedding:grant larissa-und-tim du@example.de view_content moderate configure manage_access export manage_lifecycle --label=Brautpaar"
```

**Demo neu aufsetzen** (löscht die Vorführ-Hochzeit vollständig, inkl. Gast-Uploads, und baut sie neu):

```bash
make prod-demo ARGS="--reset"
```

Andere Namen: `make prod-demo ARGS="--slug=mia-und-ben --title='Mia & Ben' --date=2027-05-01"`.

---

## 7. Updates einspielen

Vom Entwicklungsrechner den neuen Stand übertragen (Schritt 2), dann auf dem Server:

```bash
make prod-up
```

Baut neu, startet die geänderten Container, migriert die Datenbank und startet den Worker neu. Daten bleiben erhalten. Vorher eine Sicherung machen (nächster Abschnitt) schadet nie.

---

## 8. Sicherung und Wiederherstellung

```bash
make prod-backup
```

Schreibt nach `backups/<datum>/`:
- `datenbank.sql.gz` – kompletter Datenbank-Dump
- `medien.tar.gz` – alle Originale, Videos, Vorschaubilder und Porträts

**Täglich automatisch** (Beispiel, `crontab -e`):

```cron
30 3 * * * cd ~/wedora && make prod-backup >> backups/cron.log 2>&1 && find backups -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
```

Die Sicherungen liegen auf demselben Server – zusätzlich regelmäßig woanders hin kopieren (z. B. `rsync` auf den eigenen Rechner oder ein NAS).

**Wiederherstellen** (auf einem frisch aufgesetzten Server mit derselben `.env.prod.local`):

```bash
make prod-up
gunzip -c backups/<datum>/datenbank.sql.gz | docker compose -p wedora -f compose.prod.yaml --env-file .env.prod.local exec -T database sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
docker compose -p wedora -f compose.prod.yaml --env-file .env.prod.local run --rm --no-deps -T --entrypoint tar app -C /srv/media -xzf - < backups/<datum>/medien.tar.gz
make prod-up
```

---

## 9. Betrieb

| Was | Befehl |
|---|---|
| **Zustand auf einen Blick** (Container, Datenbank, Speicherplatz, Hintergrundjobs) | `make prod-status` |
| Logs ansehen | `make prod-logs` |
| Speicherverbrauch einer Hochzeit | `make prod-console CMD="app:wedding:usage larissa-und-tim"` |
| Erfahrungswerte Videoumwandlung | `make prod-console CMD="app:media:processing-stats"` |
| Fehlgeschlagene Hintergrundjobs | `make prod-console CMD="messenger:failed:show"` |
| Aufräumen von Hand (läuft sonst stündlich automatisch) | `make prod-console CMD="app:media:cleanup"` |
| QR-/Beitrittslink einer Hochzeit anzeigen | `make prod-console CMD="app:wedding:link larissa-und-tim"` |
| Passwort eines Verwaltungskontos neu setzen | `make prod-console CMD="app:user:set-password du@example.de"` |
| Hochzeit endgültig löschen | `make prod-console CMD="app:wedding:delete <kürzel>"` |
| Alles stoppen (Daten bleiben) | `make prod-down` |

**Läuft von selbst:**
- **Aufräumen, stündlich im Worker:** abgelaufene Uploads samt angefangener Stücke, abgelaufene Exporte samt ZIP, verbrauchte Einmal-Links. Dafür ist kein Cron nötig.
- **Log-Begrenzung:** Docker behält je Dienst höchstens 5 × 10 MB Logs, die Platte läuft also nicht mit Logs voll.

**Überwachung (empfohlen):** Richte einen externen Uptime-Check auf `https://wedora.deine-domain.de/healthz` ein, z. B. mit Uptime Kuma auf einem anderen Gerät, UptimeRobot oder healthchecks.io.
- Die Adresse antwortet mit `{"status":"ok","database":"ok"}`, sonst mit HTTP 503.
- Dazu am besten eine Benachrichtigung aufs Handy, damit du am Hochzeitstag sofort merkst, wenn etwas hängt.
- Den Speicherplatz zeigt `make prod-status`. Vor der Feier lohnt ein Blick, ob genug frei ist (Standard-Kontingent pro Hochzeit: 25 GB).

---

## 10. Wenn etwas nicht klappt

- **Kein HTTPS / Zertifikatsfehler:** Das Zertifikat holt der Caddy **auf dem VPS** – dort ins Log schauen (`journalctl -u caddy` bzw. Container-Logs). Fast immer zeigt der DNS-Eintrag noch nicht auf den VPS oder dort ist Port 80 zu.
- **502 Bad Gateway vom VPS:** Der VPS erreicht Wedora nicht. Auf dem VPS `curl http://10.8.0.2:8190/healthz`; klappt das nicht: WireGuard prüfen (`sudo wg show`, Handshake?), `HTTP_BIND`/`HTTP_PORT` in `.env.prod.local` und `make prod-status` auf dem ThinkCentre.
- **Links/QR-Codes mit `http://` oder Anmeldung klappt nicht (Cookie fehlt):** `DEFAULT_URI` muss mit `https://` beginnen; `REVERSE_PROXY_IPS` muss die WireGuard-IP des VPS enthalten (oder `private_ranges`).
- **„… fehlt in .env.prod.local“ beim Start:** Wert in `.env.prod.local` fehlt (Schritt 3).
- **Video bleibt bei „wird verarbeitet“:** `make prod-logs` → Ausgabe von `worker` prüfen. Hängt eine Nachricht nach einem Neustart, wird sie nach spätestens einer Stunde neu zugestellt; sofort geht es mit `make prod-up`.
- **Porträt wird nicht angeboten:** `GEMINI_API_KEY` gesetzt? Und in der Verwaltung unter Einstellungen → Module „Gezeichnete Porträts“ eingeschaltet?
- **Seite lädt, aber Bilder fehlen:** `imgproxy` läuft? (`… ps`). Die Medien gehören im Container der Gruppe `www-data` (GID 82); imgproxy liest mit dieser Gruppe.

---

## Generalprobe auf dem eigenen Rechner

Bevor du auf den Server gehst, kannst du den Produktions-Stack lokal starten – getrennt von der Entwicklungsumgebung – und einen zweiten Caddy als „VPS“ davorsetzen (HTTPS mit lokalem Zertifikat):

```bash
make prod-secrets PROD_ENV=/tmp/probe.env
sed -i 's#^DEFAULT_URI=.*#DEFAULT_URI=https://wedora.test:8443#; s/^HTTP_PORT=.*/HTTP_PORT=8290/; s/^HTTP_BIND=.*/HTTP_BIND=127.0.0.1/' /tmp/probe.env
make prod-up   PROD_ENV=/tmp/probe.env PROD_PROJECT=wedora-probe
make prod-console PROD_ENV=/tmp/probe.env PROD_PROJECT=wedora-probe CMD='app:wedding:create probe-hochzeit "Lea & Max"'

# „VPS“: HTTPS auf wedora.test:8443, Weiterleitung an den Stack
printf '{\n\tadmin off\n\tauto_https disable_redirects\n}\nhttps://wedora.test:8443 {\n\ttls internal\n\treverse_proxy 127.0.0.1:8290\n}\n' > /tmp/vps.Caddyfile
docker run -d --name wedora-fake-vps --network host -v /tmp/vps.Caddyfile:/etc/caddy/Caddyfile:ro caddy:2.11-alpine
curl -k --resolve wedora.test:8443:127.0.0.1 -I "https://wedora.test:8443/login"   # HTTP/2 200

# aufräumen
docker rm -f wedora-fake-vps
docker compose -p wedora-probe -f compose.prod.yaml --env-file /tmp/probe.env down -v
```
