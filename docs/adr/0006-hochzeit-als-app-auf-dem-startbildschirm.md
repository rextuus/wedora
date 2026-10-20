# ADR 0006 – Hochzeit als App auf dem Startbildschirm

- **Status:** angenommen (20.10.2026)
- **Betrifft:** Gast-App (Startseite, Seitenkopf), Gast-Anmeldung

## Kontext

Gäste lassen die Seite nicht den ganzen Abend offen, wollen aber auch nicht jedes Mal den QR-Code scannen. Ein Symbol auf dem Startbildschirm löst das. Dabei gibt es eine Falle:

- **Android/Chrome:** Die installierte Web-App teilt sich die Cookies mit dem Browser. Der Gast bleibt angemeldet.
- **iPhone/iPad:** Eine Web-App vom Home-Bildschirm hat einen **eigenen, leeren Cookie-Speicher**. Ohne Gegenmaßnahme müsste der Gast neu beitreten und wäre eine neue Person; seine bisherigen Beiträge könnte er dann nicht mehr verwalten.

## Entscheidung

- **Manifest pro Hochzeit** (`/h/{slug}/join/manifest.webmanifest`):
  - Name, Symbol, `display: standalone`, Gültigkeitsbereich `/h/{slug}/`.
  - Bewusst **ohne `start_url` und ohne Gastdaten**: Browser übernehmen dann die aktuelle Adresse.
- **Hinweis-Karte auf der Startseite** „Auf den Startbildschirm“:
  - wegklickbar (gemerkt im Browser), in der installierten App unsichtbar
  - Android: Knopf „Hinzufügen“ (`beforeinstallprompt`), sonst eine Anleitung zum Browsermenü
- **iPhone: Einmal-Link auf Knopfdruck** („Link vorbereiten“):
  1. Der Server erzeugt einen zufälligen Code. Er gilt **15 Minuten** und ist **nur einmal einlösbar**.
  2. Die Seite setzt die Adresse auf `/h/{slug}/join/app#t=<Code>`; Safari übernimmt sie für das Symbol.
  3. Der Code steht im Fragment und wird deshalb nie im Klartext an den Server geschickt (nicht in Server-Logs).
  4. Nach fünf Minuten oder beim Wegklicken wird die Adresse zurückgesetzt.
  5. Beim ersten App-Start tauscht die Seite den Code per POST (nur vom eigenen Frontend: Header `X-Requested-With`, Origin-Prüfung) gegen das Gast-Cookie.
- **Speicherung:**
  - In der Datenbank liegt nur der Hash des Codes.
  - Das Gast-Geheimnis wird zum Ausstellen des Cookies gebraucht, aber nur gehasht gespeichert. Es wird deshalb beim Erzeugen aus dem aktuellen Cookie genommen und **mit dem Code verschlüsselt** abgelegt (libsodium secretbox). Ohne den Code, den nur das Gerät kennt, ist es wertlos.
- Gesperrte Gäste, fremde Hochzeiten, abgelaufene oder schon eingelöste Codes werden abgelehnt.
- `app:media:cleanup` löscht abgelaufene und eingelöste Codes.

### Verworfen

Zuerst stand das Gast-Cookie selbst in der Startadresse. Auf dem iPhone wäre der vollständige Zugang dann in der Adressleiste sichtbar gewesen. Beim Teilen der Seite hätte ein anderer die Identität des Gastes übernommen und zum Beispiel dessen Fotos löschen können.

## Konsequenzen

- Neue Tabelle `guest_reentry_token` (Migration `Version20261020071112`).
- Installierbarkeit braucht HTTPS. Lokal über `http://localhost` funktioniert sie nur eingeschränkt, auf dem Server mit Domain voll.
- **Offen:** Test auf einem echten iPhone (Übernahme der Adresse mit Fragment beim „Zum Home-Bildschirm“) und einem echten Android-Gerät. Automatisiert getestet sind Server-Logik und Browser-Ablauf mit Geräte-Emulation; das Verhalten des echten Home-Bildschirms lässt sich so nicht nachstellen.
