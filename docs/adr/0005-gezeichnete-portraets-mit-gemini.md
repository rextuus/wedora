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
- **Sichtbarkeit:** Standardmäßig sieht nur der Gast selbst sein Porträt (`/h/{slug}/portraet/bild` liefert immer nur das eigene). Brautpaar und Verwaltung sehen es nie.
- **Gästewand (Ergänzung 20.10., auf Wunsch des Betreibers):** Ein Gast kann sein Porträt freigeben (`guest.portrait_shared`, Standard aus, jederzeit widerrufbar; Löschen des Porträts beendet die Freigabe). Freigegebene Porträts erscheinen auf der Seite „Wer ist alles da?“ (`/h/{slug}/gaeste`) und klein neben Fotos und Gästebuch-Einträgen. Sehen darf sie nur, wer selbst ein freigegebenes Porträt hat (wie bei den Drink-Videos). Das prüft `PortraitWall::canSee` auch bei der Bildadresse `/h/{slug}/portraet/von/{gast}`. Gesperrte Gäste erscheinen nicht. Die Einwilligung beim Selfie betrifft nur die Übertragung an Google; die Freigabe ist ein eigener, ausdrücklicher Schritt.
- **Ablage:** `<MEDIA_ROOT>/portraits/<hochzeit>/<gast>.png`.

## Ergänzung: Stile und Aufgaben-Karte (19.10.2026)

- **Stile:** Feine Linien (Standard, transparent, per CSS in der Akzentfarbe eingefärbt), Aquarell, Bleistift, Comic, Pop-Art (farbig, rund zugeschnitten). Das Brautpaar wählt in den Einstellungen, welche Stile angeboten werden (`Wedding::portraitStyles`, Standard nur „Feine Linien“). Der Server lehnt nicht angebotene Stile ab, bevor etwas an Gemini geht. Prompts stehen im Enum `PortraitStyle`.
- **Vorschaubilder** der Stile liegen unter `assets/images/portrait-styles/`. Sie wurden einmalig mit Gemini von einer erfundenen Person erzeugt, nicht von einem echten Gast.
- **Als Aufgabe:** Unter „Aufgaben“ erscheint eine Karte „Lass dich zeichnen“ mit „Erledigt“, sobald ein Porträt da ist. Es ist bewusst keine echte Upload-Aufgabe, weil das Selfie nicht in die Galerie soll.
- **Einstieg:** Gibt es eine First-Drink-Aufgabe, führt der Beitritt zuerst dorthin. Das Porträt wird dann über Startseite und Aufgaben angeboten statt direkt nach dem Namen.

## Ergänzung: Zufälliges Accessoire (20.10.2026)

- Jedes Porträt bekommt zufällig ein Accessoire, im selben Stil mitgezeichnet: Sonnenbrille, Partyhut, Blumenkranz, Fliege, Zylinder, Herzbrille, Krone oder Konfettiregen. Ein neues Porträt bekommt möglichst ein anderes als das letzte.
- Schalter pro Hochzeit „Zufälliges Accessoire mitzeichnen“, Standard **an**.
- Das gezogene Accessoire steht am Gast (`portrait_gadget`) und wird auf der Porträt-Seite als „Überraschung: mit Partyhut“ gezeigt.
- Der Prompt schneidet jetzt ausdrücklich knapp unter den Schultern ab. Bei Selfies wurde sonst gelegentlich der ausgestreckte Arm mitgezeichnet.

## Ergänzung: Accessoire aus einer Frage (20.10.2026)

- Das Accessoire ist nicht mehr reiner Zufall. Beim Selfie erscheint zufällig eine von fünf kurzen Fragen (`PortraitQuestions`), etwa „Woher kennst du das Brautpaar?“, „Was wünschst du den beiden?“ oder „Wen kennst du länger?“ (mit den Namen aus dem Hochzeitstitel).
- Jede Antwort steht für ein bestimmtes Accessoire, zum Beispiel Familie → Krone. Für den Gast bleibt es eine Überraschung.
- Ohne gültige Antwort lehnt der Server ab. Das passiert, bevor das Tageslimit zählt und bevor etwas an Gemini geht.
- Die Antwort wird am Gast gespeichert (`portrait_answer`). Den Gästen wird **nicht** gesagt, dass sie das Accessoire bestimmt; sie sehen nur „Überraschung: mit Krone“.
- Sind die Accessoires ausgeschaltet, gibt es keine Frage.
- **Herz-Tattoo (20.10.2026):** Neues Accessoire, ein kleines Herz-Tattoo auf der Wange mit den Initialen des Paares aus dem Hochzeitstitel (z. B. „L & T“). Es gehört zur Antwort „Beide gleich lang“. Nur dafür erlaubt der Prompt Buchstaben im Bild; ohne zwei erkennbare Namen bleibt das Herz leer und es gilt weiter „kein Text“.

## Ergänzung: Kostenbremse und hängende Aufträge (20.10.2026)

- **Kostenbremse:** Jede angeforderte Zeichnung wird gezählt (`ai_image_usage`, ohne personenbezogene Daten).
  - Harte Obergrenzen pro Tag und Monat über alle Hochzeiten (`GEMINI_DAILY_LIMIT`, Standard 50; `GEMINI_MONTHLY_LIMIT`, Standard 300).
  - Ist eine Grenze erreicht, lehnt der Server ab, bevor etwas an Gemini geht. Tageslimit 0 ist der Notaus.
  - Die Grenzen stellt die Plattformadministration in der Verwaltung ein (`ai_budget_setting`, sofort wirksam). Die Umgebungsvariablen sind nur Ausgangswerte. Brautpaar-Konten können sie nicht ändern.
  - Zusätzlich gilt das bisherige Limit von 5 Porträts pro Gast und Tag.
- **Hängende Aufträge:** Ein Absturz des Workers mitten im Auftrag ließ ein Porträt bis zu einer Stunde auf „wird gezeichnet“ stehen (Neuzustellung der Warteschlange).
  - Jetzt wird nach 15 Minuten neu zugestellt.
  - Porträts gelten nach 3 Minuten „in Arbeit“ als fehlgeschlagen: Der Gast sieht „Nochmal versuchen“, und das Selfie wird gelöscht.
  - Geprüft wird beim Aufruf der Startseite bzw. Porträt-Seite und stündlich im Aufräumen.

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
