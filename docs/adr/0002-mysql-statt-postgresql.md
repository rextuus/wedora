# ADR 0002 – MySQL statt PostgreSQL

- **Status:** angenommen (19.10.2026, durch den Auftraggeber)
- **Ersetzt:** „PostgreSQL“ in `docs/konzept/04_Technische_Architekturentscheidung.md`

## Entscheidung

Datenbank ist **MySQL 8.4 (LTS)**, Image `mysql:8.4.11`, Zeichensatz `utf8mb4` / `utf8mb4_unicode_ci`. Doctrine ORM und Migrations bleiben.

## Konsequenzen

- PHP-Erweiterung `pdo_mysql` statt `pdo_pgsql`.
- Die Testdatenbank `app_test` legt beim ersten Start das Init-Skript `docker/mysql/init/01-test-database.sh` an. Der App-Benutzer hat nur Rechte auf `app` und `app_test`.
- **Init-Skripte laufen nur bei leerem Volume.** Wer das Skript ändert, muss das Volume neu anlegen.
- In der CI verbindet sich PHPUnit als root mit dem MySQL-Service, damit die Testdatenbank angelegt werden kann (nur CI-Zugangsdaten).
- Weil noch keine Migrationen existierten, ist keine Datenmigration nötig.
- Beim Datenmodell (Phase 2) beachten: UUIDs als `BINARY(16)` bzw. Doctrine-Typ `uuid`, kein natives JSONB (MySQL-`JSON` genügt), Indizes auf `utf8mb4`-Spalten max. 3072 Byte.
