# Phase 5 – Design und Qualität

Stand: 20.10.2026. Der Plan sah den Abgleich gegen die Stitch-Vorlagen vor. Der entfällt, weil das Design seitdem eigenständig weiterentwickelt wurde (ADR 0004). Geprüft wurden stattdessen mobile Darstellung, Barrierefreiheit, Lade- und Fehlerzustände sowie Upload-Fortschritt und Wiederholungen.

## Wiederholbar: `make test-browser`

Läuft gegen die gestartete Entwicklungsumgebung (`make up`) in einem Playwright-Container:

- legt jedes Mal die Testhochzeit **„Lea & Max“ (`browser-test`)** frisch an (`app:demo:seed tests/Fixtures --reset`); andere Hochzeiten bleiben unberührt
- nutzt das Konto `browser-test@wedora.local` für die Verwaltung; das Passwort wird beim ersten Lauf erzeugt und liegt in `var/browser-test/admin-password`
- Skript: `tests/Browser/run.mjs`; endet mit Fehlercode, sobald etwas nicht stimmt

| Prüfung | Umfang | Ergebnis |
|---|---|---|
| Barrierefreiheit (axe-core 4.11, WCAG 2.1 A/AA + Best Practices) | 24 Ansichten auf Handygröße: Beitritt, Willkommen, Start, Galerie, Vollbild-Ansicht, Foto-Detail, Aufgaben, Aufgaben-Detail, Umfrage „Wer von beiden?“, Gästebuch, Album, Upload, Porträt, Privatsphäre, alle Verwaltungsseiten | ohne Befund |
| Upload: Verbindungsabbruch im ersten Stück | 10-MB-Foto, erstes Stück bricht ab | „Verbindung unterbrochen – neuer Versuch 1/4“, Fortsetzung ab Serverstand, „Gespeichert“ |
| Upload: Serverfehler beim Start | erste Anfrage liefert 500 | Meldung + „Erneut versuchen“, danach „Gespeichert“ |
| Upload: kaputte Datei | Text als `.jpg` | „Dieses Bildformat wird nicht unterstützt …“, kein Wiederholen-Knopf, „Entfernen“ |

## Behobene Befunde

- **Kontrast:** Die blasse Schriftfarbe (z. B. Beschriftung der unteren Navigation) lag mit 4,27:1 unter 4,5:1. Neu ist `#676b65`: mindestens 4,68:1 auf allen Hintergründen.
- **Landmarks:** Der Vertrauenshinweis war ein verschachteltes `aside` und ist jetzt `role="note"`. Titel und Reiter der Verwaltung liegen in einem benannten Bereich.
- **Foto-Detailseite:** Sie hat jetzt eine Hauptüberschrift, nur für Screenreader sichtbar.
- **Vollbild-Ansicht:** Der Wischbereich ist per Tastatur fokussierbar, beschriftet und zeigt einen sichtbaren Fokusrahmen.
- **Upload-Fehlerzustand:** Der Balken wird rot statt salbeigrün. „Abbrechen“ heißt nach einem Fehler „Entfernen“. Kleine Dateien zeigen KB statt „1 MB“.
- **`hidden`-Attribut:** Es wirkt jetzt überall. Knöpfe mit `.button` blieben vorher trotz `hidden` sichtbar.

## Offen (braucht Menschen oder echte Geräte)

- **Screenreader-Durchgang** mit VoiceOver (iPhone) und TalkBack (Android). Automatische Prüfungen finden nur einen Teil der Barrieren.
- **Echte Geräte:** Kamera-Aufnahme, Upload über Mobilfunk, „Zum Home-Bildschirm“ (ADR 0006).
- **Langsame Verbindung:** gefühlte Wartezeiten bei großen Videos über echtes Mobilfunknetz.
