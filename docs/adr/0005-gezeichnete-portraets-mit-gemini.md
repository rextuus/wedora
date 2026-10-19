# ADR 0005 – Gezeichnete Gästeporträts mit Gemini

- **Status:** angenommen (19.10.2026, Wunsch des Auftraggebers)
- **Betrifft:** Gast-App (Startseite, Beitritt), Verwaltung (Einstellungen), Löschung

## Kontext

Gäste sollen zu Beginn ein Selfie machen können. Daraus entsteht per KI eine feine Linienzeichnung im Stil der App-Ornamente. Sie erscheint oben auf der Startseite des Gastes und als kleines Bild in der Kopfzeile.

Dafür verlässt ein Gesichtsfoto den eigenen Server. Das ist die erste Stelle, an der Wedora einen externen Anbieter nutzt (vgl. ADR 0001: alles auf einem privaten Server).

## Entscheidung

- **Wiederverwendbarer Dienst:** `App\Ai\ImageGenerator` (Schnittstelle) mit der Umsetzung `App\Ai\Gemini\GeminiImageGenerator`.
  - Er nutzt die Gemini API `generateContent` mit Bildausgabe.
  - Der Schlüssel kommt aus `GEMINI_API_KEY`, gesetzt nur in `.env.local` bzw. der Server-Umgebung.
  - Das Modell ist über `GEMINI_IMAGE_MODEL` einstellbar (Standard `gemini-3.1-flash-image`).
  - Ohne Schlüssel ist die Funktion unsichtbar.
- **Pro Hochzeit schaltbar, Standard aus:** Das Brautpaar schaltet sie in den Einstellungen ein (`Wedding::portraitsEnabled`).
- **Einwilligung je Gast:** Ohne angehakte Einwilligung lehnt der Server ab. Der Text nennt Google als Empfänger und die sofortige Löschung des Fotos.
- **Ablauf:**
  1. Das Selfie wird im Browser auf höchstens 1600 px verkleinert.
  2. Der Server prüft Format (am Inhalt) und Größe (max. 8 MB) und legt das Selfie kurz ab.
  3. Ein Messenger-Job (`CreatePortrait`) lässt es zeichnen.
  4. ffmpeg macht daraus ein transparentes PNG; die Oberfläche färbt es per CSS-Maske in der Akzentfarbe ein.
  5. Das Selfie wird danach **immer** gelöscht, auch bei Fehlern.
  6. Fehlgeschlagene Zeichnungen werden nicht automatisch wiederholt; der Gast kann es erneut versuchen.
- **Begrenzung:** höchstens 5 Zeichnungen pro Gast und Tag (Rate-Limiter `guest_portrait`), weil jede Zeichnung beim Anbieter Geld kostet.
- **Sichtbarkeit:** Nur der Gast selbst sieht sein Porträt (`/h/{slug}/portraet/bild` liefert immer nur das eigene). Brautpaar und Verwaltung sehen es nicht.
- **Ablage:** `<MEDIA_ROOT>/portraits/<hochzeit>/<gast>.png`.

## Löschung und Export

- Der Gast kann sein Porträt jederzeit löschen und herunterladen.
- Beim Löschen einer Hochzeit entfernt `WeddingEraser` den ganzen Porträt-Ordner, einschließlich eventuell noch liegender Selfies.
- Porträts sind **nicht** im Export für das Brautpaar enthalten. Sie gehören dem Gast und waren nie für das Brautpaar sichtbar.
- **Daten beim Anbieter:** Wir speichern bei Google nichts dauerhaft (keine Dateien-API, keine Verläufe). Was Google mit Anfragedaten macht, richtet sich nach deren Bedingungen und lässt sich von uns nicht löschen.
  - **Vor dem Produktivbetrieb** einen kostenpflichtigen API-Zugang (Paid Tier) verwenden. Bei kostenlosen Zugängen kann Google Eingaben zur Produktverbesserung nutzen.
  - Den Hinweis in die Datenschutzerklärung aufnehmen (Auftragsverarbeitung, Drittlandübermittlung prüfen).

## Konsequenzen

- Neue Spalten: `guest.portrait_status`, `guest.portrait_updated_at`, `wedding.portraits_enabled` (Migration `Version20261019140931`).
- Tests sprechen nie mit Google. In der Testumgebung beantwortet `App\Tests\Support\FakeGemini` alle ausgehenden HTTP-Anfragen (`framework.http_client.mock_response_factory`).
- Weitere KI-Bildfunktionen (z. B. Illustrationen für Aufgaben in der Verwaltung) können `ImageGenerator` direkt nutzen.
