# Bildprompts für Wedora

Prompts für eine Bildgenerierungs-KI (z. B. Midjourney, DALL·E/ChatGPT, Adobe Firefly, Flux oder Stable Diffusion). Ziel sind Texturen und Illustrationen, die der Oberfläche Wärme geben – besonders bei Aufgaben, leeren Zuständen und der Startseite.

## Grundregeln (bitte bei allen Bildern einhalten)

**Damit die Bilder zu jeder Farbpalette passen:**
- **Illustrationen** als **einfarbige Linienzeichnung (schwarz) auf transparentem oder reinweißem Hintergrund**. Die App färbt sie später in der Akzentfarbe ein (Bronze, Salbei, Terrakotta oder Gold – je nach gewählter Palette).
- **Texturen** in **Graustufen**, nahtlos kachelbar. Die App legt sie mit geringer Deckkraft über den Hintergrund.
- **Fotos** (Platzhalter, Demo-Bilder) dürfen farbig sein, aber gedämpft und natürlich – keine knalligen Farben.

**Stil für alle Illustrationen (gleicher „Zeichner“):**
> fine single-weight ink line drawing, hand-drawn feel, elegant and minimal, generous negative space, no shading, no color, no text, no lettering, wedding stationery style, isolated on plain white background

**Immer vermeiden (Negativ-Prompt bzw. „ohne …“):**
> text, letters, words, watermark, signature, logo, frame, border, color, gradient, shading, 3D, photorealistic, cartoon, clipart, emoji, cluttered

**Technik:**

| Art | Format | Größe | Hinweise |
|---|---|---|---|
| Illustration | PNG mit Transparenz (sonst weißer Hintergrund – wir stellen frei) | 1024 × 1024 | Motiv mittig, ca. 15 % Rand |
| Textur | PNG oder JPG, Graustufen | 1024 × 1024, **nahtlos** | Midjourney: `--tile`; sonst „seamless tileable pattern“ |
| Foto / Bühne | JPG | 2048 × 1536 (4:3) bzw. 1536 × 2048 | gedämpfte Farben, viel Ruhe im Bild |

**Ablage:** Fertige Dateien bitte nach `assets/images/<bereich>/<name>.png` (Namen siehe unten). Ich binde sie dann ein, färbe sie passend und optimiere die Dateigröße.

**Midjourney-Kürzel**, an die Prompts anzuhängen:
- Illustration: `--ar 1:1 --style raw --no text, color, shading`
- Textur: `--ar 1:1 --tile --style raw`
- Foto: `--ar 4:3 --style raw` (Hochformat: `--ar 3:4`)

---

## 1. Hintergrund-Texturen

Dezent über der ganzen App oder einzelnen Flächen (z. B. Aufgaben-Karten, Kopfbereich der Startseite).

**`texture/paper.png` – Büttenpapier**
> seamless tileable texture of handmade cotton rag paper, subtle fibers and gentle unevenness, soft natural light, very low contrast, grayscale, top-down flat scan, no objects

**`texture/linen.png` – Leinenstruktur**
> seamless tileable texture of fine natural linen fabric, delicate woven threads, extremely subtle, low contrast, grayscale, flat top-down macro scan, even lighting

**`texture/watercolor-wash.png` – Aquarell-Hauch (für Startseite ohne Fotos)**
> soft abstract watercolor wash on textured paper, pale and airy, irregular organic edges fading to white, very light, grayscale, plenty of white space, no shapes, no objects

**`texture/grain.png` – feines Filmkorn**
> seamless tileable fine film grain noise texture, subtle analog photo grain, neutral mid gray, very low contrast, uniform

---

## 2. Aufgaben (Challenges) – je eine Illustration

Erscheinen groß auf der Aufgaben-Detailseite und klein in der Aufgabenliste. Bitte alle im selben Stil (Grundregeln oben voranstellen).

| Datei | Aufgabe | Prompt-Motiv |
|---|---|---|
| `challenges/first-drink.png` | First Drink | two champagne coupes clinking together, a few tiny bubbles rising |
| `challenges/first-dance.png` | First Dance | a couple dancing close together, seen as elegant silhouettes in line, her dress gently swirling |
| `challenges/new-friends.png` | New Friends | two hands meeting in a warm handshake, small sprig of olive leaves beneath |
| `challenges/dancefloor.png` | Dancefloor Madness | a disco ball with a few short motion lines and scattered stars |
| `challenges/message.png` | Nachricht an das Brautpaar | a sealed envelope with a wax seal and a small sprig of eucalyptus |
| `challenges/last-drink.png` | Last Drink | a single wine glass with a crescent moon and two small stars above |
| `challenges/cake.png` | Torte | a tall three-tier wedding cake with simple flowers on top |
| `challenges/bouquet.png` | Brautstrauß | a hand-tied bouquet of garden roses and eucalyptus with a loose ribbon |
| `challenges/rings.png` | Ringe | two interlocked wedding rings with a tiny sparkle |
| `challenges/table.png` | Tischfoto | a long dinner table seen from above with plates, candles and a vine of leaves |
| `challenges/custom.png` | eigene Aufgabe (Standard) | a vintage film camera with a small heart above the lens |

**Beispiel komplett (Midjourney):**
> two champagne coupes clinking together, a few tiny bubbles rising, fine single-weight ink line drawing, hand-drawn feel, elegant and minimal, generous negative space, no shading, no color, no text, wedding stationery style, isolated on plain white background --ar 1:1 --style raw --no text, color, shading

---

## 3. Leere Zustände

Wenn noch nichts da ist – statt eines leeren Bereichs eine kleine Illustration mit Text.

| Datei | Wo | Prompt-Motiv |
|---|---|---|
| `empty/gallery.png` | Galerie ohne Fotos | an empty vintage photo frame leaning against a small vase with a single flower |
| `empty/album.png` | Mein Album ohne Beiträge | an open photo album with blank pages and a pressed leaf |
| `empty/guestbook.png` | Gästebuch ohne Einträge | an open guestbook with a fountain pen resting on it |
| `empty/challenges.png` | keine Aufgaben aktiv | a small hourglass beside a sprig of lavender |
| `empty/processing.png` | Video wird verarbeitet | a vintage film reel with a loose strip of film curling around |

---

## 4. Ornamente und Trenner

Kleine Schmuckelemente für Überschriften, Abschnittsenden und die Willkommensseite.

**`ornament/divider.png` – Zweig als Trenner (breit, 4:1)**
> a long horizontal delicate olive branch with small leaves, symmetrical, fine single-weight ink line drawing, minimal, no text, isolated on white --ar 4:1 --style raw

**`ornament/monogram-wreath.png` – Kranz für Initialen (leer in der Mitte)**
> an open circular laurel wreath with an empty center, fine single-weight ink line drawing, symmetrical, elegant, no text, no letters, isolated on white --ar 1:1 --style raw

**`ornament/corner.png` – Eckverzierung**
> a small botanical corner ornament of eucalyptus leaves for the top left corner of a card, fine ink line drawing, minimal, no text, isolated on white --ar 1:1 --style raw

**`ornament/sparkles.png` – kleine Funken (Erfolg, z. B. „Aufgabe erledigt“)**
> a loose cluster of five small hand-drawn sparkles and stars of different sizes, fine ink line, minimal, isolated on white --ar 1:1 --style raw

---

## 5. Willkommensseite und Startseite ohne Fotos

**`stage/welcome.jpg` – Willkommensbild beim QR-Einstieg (Hochformat)**
> soft-focus close-up of a wedding reception table at golden hour, linen tablecloth, small vase with garden roses and eucalyptus, out-of-focus fairy lights in the background, muted natural colors, airy, editorial fine-art wedding photography, film look, no people, no text --ar 3:4 --style raw

**`stage/placeholder.jpg` – Bühne der Startseite, solange noch niemand Fotos geteilt hat (Querformat)**
> still life of wedding details on a linen surface, two champagne glasses, a small bouquet, a handwritten place card without readable text, soft window light, muted warm tones, lots of calm empty space, fine-art wedding photography, film grain --ar 4:3 --style raw

---

## 6. Demo-Fotos für die Entwicklung

Damit Galerie und Magazin-Ansicht realistisch aussehen statt mit Farbbalken-Testvideos. Gern 15–25 Stück erzeugen; gemischt Hoch- und Querformat. Personen dürfen erfunden sein.

**Grundstil (voranstellen):**
> candid fine-art wedding photography, natural light, muted warm color grading, shallow depth of field, film grain, authentic moment, no text

**Motive (je ein Bild):**
- guests raising champagne glasses in a garden at sunset
- bride and groom laughing during the first dance, motion blur at the edges
- close-up of the wedding rings on a linen napkin
- an elderly guest smiling at a long dinner table with candles
- confetti falling over the couple leaving the ceremony
- the wedding cake on a wooden table with figs and roses
- friends dancing wildly on the dancefloor under string lights
- a child holding a flower crown, looking away
- table decoration with eucalyptus, candles and place cards
- the bride's bouquet on a chair, soft afternoon light
- groom adjusting his cuff, seen from behind
- couple walking through a vineyard at golden hour
- a toast speech, guests listening, warm interior light
- sparklers at night held by many hands
- the last drink of the night at the bar, empty glasses, warm bokeh

Ablage: `var/demo-photos/` (nicht ins Repository). Einspielen über den regulären Upload-Weg als erfundene Gäste:

```
docker compose --env-file .env.docker.local exec app php bin/console app:demo:import <hochzeit> var/demo-photos --likes
```

Eine gleichnamige `.txt`-Datei neben einem Bild wird zur Bildunterschrift; MP4/MOV werden als Videos vom Worker verarbeitet. Unterordner werden ignoriert.

**Stand 19.10.2026:** 18 Fotos mit Gemini (`gemini-3.1-flash-image`) erzeugt. Drei davon wurden mit ffmpeg zu 9-Sekunden-Videos mit langsamem Zoom gemacht (Tanzfläche, Konfetti, Wunderkerzen); die Ausgangsbilder liegen in `var/demo-photos/quellen/`. Alles wurde in `anna-und-lukas` eingespielt.

---

## Was ich nach dem Erzeugen damit mache

1. Illustrationen freistellen, auf Linienstärke prüfen und als SVG oder PNG (eingefärbt per CSS-Maske in der Akzentfarbe) einbinden.
2. Texturen auf wenige KB komprimieren und mit 3–6 % Deckkraft hinterlegen.
3. Bei den Aufgaben: Illustration groß auf der Detailseite, klein in der Liste; Vorschläge aus dem Konzept bekommen ihr Motiv automatisch, eigene Aufgaben das Standardmotiv (später wählbar in der Verwaltung).
4. Alles per Screenshot prüfen (hell, Handygröße) und dir zeigen, bevor es dauerhaft bleibt.

**Rechtliches:** Bitte die Nutzungsbedingungen des jeweiligen Generators prüfen – für eine kommerziell betriebene Plattform braucht es in der Regel einen kostenpflichtigen Plan (z. B. Midjourney) bzw. die kommerzielle Freigabe.
