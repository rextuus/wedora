# Phase 2 – Domäne und Berechtigungen

Stand: 19.10.2026

## Datenmodell

| Entity | Zweck |
|---|---|
| `Wedding` | Hochzeit: Kürzel (URL), Titel, Datum, Status (aktiv/archiviert), Moderationsmodus, Zugangsregeln, Medienlimits |
| `WeddingModule` | Galerie, Gästebuch, Challenges – je Hochzeit an/aus, optionales Ende für Beiträge |
| `Invitation` | Persönlicher Einladungslink (nur Hash gespeichert), Ablaufdatum, Widerruf |
| `Guest` | Gastidentität ohne Konto: Anzeigename, Hash des Browser-Geheimnisses, Zugangsweg, Sperre |
| `User` | Konto für Plattform- und Hochzeitsadministration |
| `WeddingMember` | Konto + Hochzeit + explizit vergebene Rechte |
| `Media` | Foto/Video mit Sichtbarkeit und Veröffentlichungsstatus (getrennte Achsen), Uploader, Moderation, weiche Löschung |

Code: `src/Wedding`, `src/Guest`, `src/Account`, `src/Media`, `src/Security`. Migration: `migrations/`.

## Zugang für Gäste

```
QR/offener Link  /h/{kürzel}/join?k=…  ──┐
                                         ├─▶ (Passwort, falls gesetzt) ─▶ Anzeigename ─▶ Cookie ─▶ /h/{kürzel}
Einladung        /i/{token}            ──┘   (Einladungen umgehen das Passwort)
```

- Falsche, deaktivierte oder unbekannte Links liefern einheitlich 404, ohne Hinweis auf die Ursache.
- Passwortversuche sind begrenzt: 10 pro 15 Minuten, je Hochzeit und IP.
- Das Gast-Cookie (`wedora_guest`) ist HttpOnly, SameSite=Lax, gilt nur für den Pfad der eigenen Hochzeit und 400 Tage. Inhalt: Gast-ID und ein zufälliges Geheimnis; gespeichert wird nur dessen Hash.
- Die Prüfung erfolgt **bei jeder Anfrage** gegen die Datenbank (zustandslose Firewall). Sperren und Widerrufe wirken sofort, ungültige Cookies werden gelöscht.

## Rechte

| Wer | Darf |
|---|---|
| Gast | eigene Hochzeit sehen, eigene Beiträge immer, fremde nur wenn „für alle Gäste“ **und** veröffentlicht |
| Konto mit `view_content` | veröffentlichte Beiträge „für alle Gäste“ und „für das Brautpaar“ |
| Konto mit `moderate` | zusätzlich unveröffentlichte/abgelehnte Beiträge; freigeben, ablehnen, löschen |
| Konto mit `configure`, `manage_access`, `export`, `manage_lifecycle` | entsprechende Verwaltung (Oberflächen folgen in Phase 4) |
| Plattformadministration | Hochzeiten verwalten (`configure`, `manage_access`, `manage_lifecycle`), **keine Inhalte** ohne Mitgliedschaft |
| „Nur für mich“ | ausschließlich der hochladende Gast, auch nicht die Moderation |

Umsetzung: `MediaAccessPolicy` (zentrale Regeln), Voter `WEDDING_*`, `MODULE_VIEW/CONTRIBUTE` und `MEDIA_VIEW/MODERATE/DELETE`.

Die Medienauslieferung (`/media/{token}/…`) nutzt jetzt dieselbe Regel. Das Token enthält nur Medium, Betrachter und Ablauf; die Berechtigung wird bei jedem Abruf neu geprüft. Die Prototyp-Freigabeliste aus Phase 0 ist entfernt.

## Getroffene Annahmen (bitte prüfen)

1. **Plattformadministration sieht keine Gästefotos**, solange sie nicht Mitglied der Hochzeit ist (Datenschutz). Sie kann sich bei Bedarf selbst Rechte geben.
2. **„Nur für mich“** ist auch für die Moderation unsichtbar. Gemeldete Inhalte können so nicht entstehen, weil niemand anderes sie sieht.
3. **Widerruf einer Einladung** sperrt alle Gäste, die über sie beigetreten sind.
4. **Neuer QR-Code, deaktivierter offener Link oder geändertes Passwort** verhindern nur neue Beitritte. Bereits beigetretene Gäste bleiben drin; einzelne Gäste lassen sich sperren.
5. **Ablaufdatum einer Einladung** begrenzt nur den Beitritt, nicht den späteren Zugang.
6. **Archivierte Hochzeiten:** Gäste können weiterhin hinein und lesen, aber nicht mehr beitragen oder neu beitreten.
7. **Rechtevergabe** erfolgt vorerst nur durch die Plattformadministration per Konsole; eine Oberfläche folgt in Phase 4.

## Konsolenbefehle (bis zur Verwaltungsoberfläche)

```sh
php bin/console app:wedding:create anna-und-lukas "Anna & Lukas" --date=2027-07-18 [--with-password]
php bin/console app:wedding:invite anna-und-lukas "Familie Müller" [--expires="+30 days"]
php bin/console app:wedding:revoke-invitation <einladungs-id>
php bin/console app:user:create paar@example.org "Anna & Lukas" [--platform-admin]
php bin/console app:wedding:grant anna-und-lukas paar@example.org view_content moderate --label=Brautpaar
```

Im Docker-Stack jeweils mit `docker compose --env-file .env.docker.local exec app` davor.

## Tests

| Bereich | Datei | Inhalt |
|---|---|---|
| Sichtbarkeitsmatrix | `tests/Unit/Security/MediaAccessPolicyTest.php` | 29 Kombinationen aus Sichtbarkeit × Status × Betrachter, Löschung, Moderation, manipulierte Identität |
| Domänenregeln | `tests/Unit/Domain/WeddingDomainTest.php` | Kürzel, Limits, Links, Einladungen, Namen, Moderationsmodus, Beitragsfenster |
| Beitritt | `tests/Functional/Guest/JoinFlowTest.php` | offener Link, Passwort, Rate-Limit, Einladung, Widerruf während des Beitritts, keine Umgehung des Namensschritts |
| Gast-Anmeldung | `tests/Functional/Guest/GuestAuthenticationTest.php` | fremde Hochzeit, manipulierte Cookies, Sperre, Widerruf, kein Zugang zur Verwaltung |
| Medienabruf | `tests/Functional/Media/MediaAuthorizationTest.php` | Freigabe, Sichtbarkeitswechsel bei bereits ausgegebenen URLs, Brautpaar, Sperre, Widerruf, fremde Hochzeit, Löschung |
| Voter | `tests/Integration/Security/VoterTest.php` | explizite Rechte, Plattformadministration, Module |
| Anmeldung/Befehle | `tests/Functional/Account/LoginTest.php`, `tests/Integration/Command/…` | |
| End-to-End | `tests/Integration/Media/ProtectedMediaDeliveryTest.php` | über Caddy mit echten Entities; Sperre beendet laufende Wiedergabe |

Ergebnis: `make test` 99 Tests, `make test-media` 11 Tests; PHPStan (max) und Mago ohne Fehler und Warnungen.

## Noch nicht enthalten

- Verwaltungsoberfläche (Phase 4), Upload (Phase 3).
- Die Seiten (Beitritt, Name, Passwort, Startseite, Anmeldung) wurden als HTML getestet, aber noch nicht im Browser auf dem Handy angesehen.
- Anzeigename nachträglich ändern (Methode vorhanden, Oberfläche folgt).
