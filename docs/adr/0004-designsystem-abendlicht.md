# ADR 0004 – Designsystem: zurück zu Stitch-Farben und -Formen (Zwischenstände „Abendlicht“, „Leinen“)

- **Status:** angenommen (19.10.2026, Auftrag des Auftraggebers)
- **Ersetzt:** Google Stitch „Botanical Keepsake“ als Designreferenz; seit der dritten Runde wieder Grundlage für Farben und Formen

## Kontext

Nach Phase 4 hat der Auftraggeber die Oberfläche bewertet:
- insgesamt zu weiß und zu stark abgerundet,
- Videos sehen schlecht aus,
- zweispaltige Galerie unpassend.

Gewünscht war ein komplett neuer Entwurf, außerdem Weiterwischen in geöffneten Fotos.

## Aktueller Stand (19.10.2026, dritte Runde)

Auch „Leinen“ hat nicht überzeugt. Der Auftraggeber hat entschieden: **Farben original von Stitch**, dazu **rundere Formen, Karten mit Rahmen, mehr Schmuck und Wärme**.

- **Farben (Stitch-Token):** Hintergrund Elfenbein `#fbf9f6` mit kaum sichtbarer Papierstruktur, Karten `#ffffff` mit Haarlinie `#e9dfd3`, Flächen `#efeeeb`/`#f5f3f0`, Text `#1b1c1a`/`#434843`, Hauptbuttons Salbei `#48584a` mit weißem Text, Akzent `#607062`, Plaketten Champagner-Sand `#ebdece` auf `#4e453a`, Fehler `#ba1a1a`.
- **Formen:** Karten 20 px, Bilder und Eingabefelder 14 px, Buttons, Plaketten, Filter und Auswahl als Pille; weiche, salbeigetönte Schatten; Navigationsleiste oben gerundet, aktiver Eintrag mit Sand-Pille.
- **Karten:** Aufgaben (Liste, Detail, aktive Aufgabe auf der Startseite), Gästebuch-Einträge, leere Zustände und die Aufgabenübersicht der Startseite stehen in gerahmten Karten.
- **Schmuck:** selbst gezeichnete SVG-Linienillustrationen (`templates/components/ornament.html.twig`), eingefärbt über `currentColor`: je Aufgabe ein Motiv (`App\Challenge\ChallengeMotif` wählt es anhand des Titels, Standard: Kamera), Zweig als Trenner unter Überschriften, Lorbeerkranz mit Initiale auf der Startseite ohne Foto, Eukalyptus-Ecke auf Gästebuch-Karten.
- **Behalten aus den Zwischenständen:** Magazin-Galerie (jetzt mit Abstand und runden Kacheln), schwarze Vollbild-Ansicht mit Wischen, Blur-up und Einblend-Übergänge, Typografie.
- **Kein Lorbeerkranz:** Er wirkte zu sehr nach Hoheitszeichen. Stattdessen gibt es einen asymmetrischen Blütenkreis (feiner Ring, rundblättriger Eukalyptus unten links, Blüten oben rechts). Er rahmt das gezeichnete Porträt. Solange es keins gibt, wechseln sich darin zwei abstrakte Ganzkörper-Figuren sanft ab: Bräutigam im Anzug und Braut im Abendkleid.
- **Porträt klein in der Ecke:** Auf der Startseite steht es als Medaillon (ca. 92 px) rechts neben der Begrüßung. Die Startseite lädt vor allem zum Fotografieren ein; der Kamera-Button bleibt ohne Scrollen sichtbar, das Titelfoto ist darunter kompakt.
- **Weitere Motive:** Ballons, Sektflasche mit Korken, Polaroids und eine Wimpelkette.
  - Ballons: Startseite ohne Foto, leere Galerie.
  - Polaroids: Kopf der Galerie (immer), leeres Album, Upload-Seite, „keine Aufgabe aktiv“.
  - Sektflasche: „alle Aufgaben erledigt“.
  - Ringe: Beitrittsseite.
  - Wimpelkette: über „Frisch von der Feier“.
- Bildgenerierung (z. B. Gemini) ist optional; Prompts in `docs/design/bildprompts.md`.

## Überarbeitung (19.10.2026, zweite Runde)

Der dunkle Entwurf „Abendlicht“ war besser als Stitch, dem Auftraggeber aber zu dunkel. Er hat gewählt:
- **Farbton „Warmes Leinen“:** Hintergrund `#efeae2`, Flächen `#f7f4ee`, Text in Tinte `#22201c`, Akzent Bronze `#8a6d3b`, Hauptbuttons dunkel mit hellem Text, Salbei `#4e6a52` für „erledigt“.
- **Galerie „Magazin mit Highlights“:** Blöcke zu sechs, im Wechsel „groß links + zwei klein“ und „zwei klein + groß rechts“. Die großen Plätze erhalten die Beiträge mit den meisten Likes des Blocks (bei Gleichstand Fotos vor Videos), mit Name und Bildunterschrift (`MagazineLayout`).

Unverändert aus „Abendlicht“: klare Kanten, Typografie, randlose Bilder, schwarze Vollbild-Ansicht mit Wischen, Startseite mit Foto-Bühne (jetzt Text unter dem Bild statt darauf), Linien statt Karten in Listen.

## Ursprüngliche Entscheidung (erste Runde, „Abendlicht“)

| Aspekt | „Abendlicht“ |
|---|---|
| Grundton | Dunkel und warm: Anthrazit `#121412`, Flächen `#1a1d1a`/`#242824`, Linien als feine Elfenbein-Transparenz |
| Text | Elfenbein `#efebe4`, gedämpft `#aaa69e` |
| Akzent | Champagner `#d8c49c` (Hauptaktionen, aktive Navigation, kursive Hervorhebungen), Salbei `#9fb7a1` für „erledigt“ |
| Form | klare Kanten (2–4 px), Bilder randlos; Kreise nur für Avatar und Kamera-Button |
| Typografie | Playfair Display für Titel, Hervorhebungen kursiv in Champagner; Plus Jakarta Sans für UI; Beschriftungen in gesperrten Großbuchstaben |
| Galerie | Dreispaltiges Quadrat-Raster (4/5 Spalten auf größeren Bildschirmen), 2 px Fuge, randlos; Videos nur mit kleinem ▶ und Dauer unten links |
| Vollbild | Schwarzer Hintergrund, Wischen (Scroll-Snap), Pfeiltasten, Esc, Zurück-Taste schließt; Videos spielen dort; Like, Name, Zeit, Bildunterschrift unten |
| Startseite | Bühne mit dem neuesten Foto, Begrüßung, Kamera-Aktion als größte Fläche, Aufgabe als ruhiger Block, Streifen „Frisch von der Feier“ |
| Listen | Linien statt Karten (Aufgaben nummeriert, Gästebuch als Zitate) |

Die Verwaltung nutzt dieselben Token.

## Konsequenzen

- `assets/styles/app.css` wurde neu geschrieben, die Klassennamen der Formulare und Bausteine blieben erhalten.
- Neuer Stimulus-Controller `assets/controllers/lightbox_controller.js`; ohne JavaScript führen die Kacheln weiterhin zur Detailseite.
- Der Like-Endpunkt antwortet auf `Accept: application/json` mit JSON (für die Vollbild-Ansicht).
- Ein Prüfwerkzeug für Screenshots (Playwright im Docker-Netz) wurde während der Umsetzung benutzt. Es ist noch nicht im Repository; eine dauerhafte Einbindung ist für Phase 5 vorgesehen.
