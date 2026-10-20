# Phase 6 – Betrieb

Stand: 20.10.2026. Die Schritt-für-Schritt-Anleitung steht in `docs/deployment.md`; hier der Überblick, was zu jedem Punkt des Umsetzungsplans umgesetzt und wie es geprüft ist.

| Punkt | Umsetzung | Geprüft |
|---|---|---|
| Deployment | `compose.prod.yaml`, Produktions-Images (PHP, Caddy mit kompilierten Assets), `.dockerignore` hält Secrets aus dem Image, `make prod-secrets` / `prod-up` / `prod-demo` | Generalprobe lokal mit Produktions-Build (19./20.10.); Image auf Secrets geprüft |
| Start-Reihenfolge | `make prod-up`: bauen → Datenbank + App → Migration → Rest; der Worker startet erst mit fertigem Schema | leere Datenbank, keine Fehler im Worker-Log |
| Backups/Restore | `make prod-backup`: Datenbank-Dump und Medien-Archiv nach `backups/<datum>/`; Wiederherstellung beschrieben | Sichern → alles inkl. Volumes löschen → wiederherstellen → Galerie wieder vollständig |
| Monitoring | `/healthz` (200 / 503 mit Datenbankprüfung) für externen Uptime-Check; `make prod-status`: Container, Datenbank, Speicherplatz, Warteschlangen | Funktionstest `HealthCheckTest`; `prod-status` in der Generalprobe |
| Logs | Produktion schreibt JSON nach stderr; Docker begrenzt je Dienst auf 5 × 10 MB | Compose-Konfiguration geprüft |
| Ressourcenlimits | Worker 2 CPU / 1 GB, imgproxy 1 CPU / 512 MB; Upload-Grenzen in Caddy (9 MB je Anfrage) und App (pro Hochzeit einstellbar) | bestehende Tests aus Phase 3 |
| Kostenbremse KI | harte Tages-/Monatsgrenzen für Gemini (`GEMINI_DAILY_LIMIT`/`GEMINI_MONTHLY_LIMIT`, 0 = aus), Anzeige in den Einstellungen und `app:ai:usage`; Anleitung für Kontingent und Budget-Warnung bei Google | `AiBudgetTest`, `PortraitSafetyTest` |
| Hängende Aufträge | Neuzustellung nach 15 statt 60 Minuten; hängende Porträts nach 3 Minuten → „Nochmal versuchen“ | `PortraitSafetyTest` |
| Regelmäßiges Aufräumen | stündlich im Worker (Symfony Scheduler, `App\Schedule`): abgelaufene Uploads, Exporte, Einmal-Links; von Hand `app:media:cleanup` | `ScheduleTest`, `CleanupCommandTest` |
| Export | ZIP für das Brautpaar (Phase 3) | `ExportAndErasureTest` |
| Archivierung | Hochzeit archivieren: ansehen ja, beitragen nein (Phase 4) | bestehende Tests |
| Vollständige Löschung | `WeddingEraser`: Originale, Varianten, Upload-Stücke, Exporte, Porträts, Betreiber-Foto, Datensätze | `ExportAndErasureTest`, `PortraitTest`, `PrivacyNoteTest` |
| Betriebsbefehle | `app:wedding:link` (QR-Link anzeigen), `app:user:set-password`, `app:user:create --password-stdin` | `AdministrationCommandsTest` |

## Bewusst nicht umgesetzt

- **Eigenes Monitoring-System** (Prometheus/Grafana o. Ä.): Für einen einzelnen privaten Server reichen ein externer Uptime-Check auf `/healthz` und `make prod-status`.
- **Automatische Sicherung außer Haus:** Die Sicherung landet auf demselben Server. Das Kopieren auf ein anderes Gerät ist in der Anleitung beschrieben, aber nicht automatisiert, weil es vom Zielgerät abhängt.

## Offen

- Echter Einsatz auf dem Zielserver mit Domain und HTTPS (Zertifikat, Kamera im Browser, „Zum Home-Bildschirm“).
