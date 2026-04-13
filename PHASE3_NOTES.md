# Phase 3 — Kurzevaluation (externe Bild-Libs)

**Stand:** 2026-04-13

| Option | Entscheidung |
|--------|----------------|
| Zusätzliche PHP-Bibliotheken (Intervention Image, Glide, …) | **Nicht eingesetzt.** ProcessWire liefert bereits **ImageSizer** (GD/Imagick/…) und **Pageimage::webp()** inkl. `PagefileExtra` für `.webp` neben dem Original. |
| Frontend-Crop-UI (Cropper.js o. Ä.) | **Nicht eingesetzt.** Zuschneiden erfolgt pragmatisch über **Zielbreite/-höhe + `cropping: true`** auf der Master-Datei (ImageSizer), analog zu PW-internen Mustern. |
| WebP wann? | **Beim Erstupload** und **nach Datei ersetzen** (nach Grid-Varianten) dieselbe Logik wie **Bulk „WebP erzeugen“**; Bulk bleibt für **Bestand** ohne WebP. |

**Folge:** Keine neuen Composer-Abhängigkeiten; Wartung und Parität mit der PW-Pipeline bleiben erhalten.
