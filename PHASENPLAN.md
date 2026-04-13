# Phasenplan — bsProcessMedienManager

Dieses Dokument beschreibt die **geplante Reihenfolge** von Verbesserungen und Erweiterungen.  
Änderungen am Code erfolgen **nur** in diesem Modulordner (sofern nicht ausdrücklich anders vereinbart).

---

## Ablauf pro Schritt (Qualitätssicherung)

1. **Umsetzung** eines beschriebenen Teilschritts im Code.
2. **Kurze Testanleitung** (vom Entwickler geliefert) — manuelle Checks im Admin / ggf. erwartetes Verhalten.
3. **Du testest** und bestätigst („Schritt OK“ o. Ä.).
4. **Erst dann** wird der entsprechende Eintrag hier als **erledigt** markiert (`[x]`).
5. Anschließend startet der **nächste** Teilschritt bzw. die nächste Phase.

> Hinweis: Diese Datei wird bei Bestätigung aktualisiert (`[ ]` → `[x]`). Kein automatisches Überspringen von Schritten.

---

## ProcessWire vs. externe PHP-Bibliotheken — strategische Einordnung

**Kurzantwort:** Der Großteil der Ziele lässt sich **mit der ProcessWire-API und PW-Konventionen** umsetzen (Pages, Felder, Files, `Pageimage`, ImageSizer-Engine, Sessions, CSRF, Selektoren). Für ein **wartbares, PW-natives** Modul ist das die bevorzugte Basis.

**Wo PW ausreicht (typisch ohne zusätzliche Libs):**

| Bereich | PW-Mittel |
|--------|-----------|
| Medien als Pages, Metadaten, Zugriffsrechte | `Pages`, `Fields`, Templates, `User`/`Permission` |
| Bilder skalieren, zuschneiden, drehen | `Pageimage`, `$image->size()`, `$image->rotate()`, Konfiguration (`$config->imageSizerOptions` etc.) |
| Datei-Upload | `Pagefiles`, `Pagefile`, bestehende Upload-Pfade im Modul |
| Suche / Filter | Selektoren, `$pages->find()` |
| Mehrfach-Upload (serverseitig) | Mehrere Einträge in `$_FILES` oder sequentielle Requests — reines PHP + PW |
| CSRF, Session | `$session->CSRF` |

**Wo externe, gut gepflegte PHP-Bibliotheken **optional** Sinn machen können:**

| Bedarf | PW allein | Optional extern |
|--------|-----------|-----------------|
| **Komplexe Bildoperationen** (z. B. fortgeschrittene Filter, EXIF-Schreiben, spezielle Formate) | Basis: ImageSizer/GD oder Imagick über PW | `intervention/image` o. Ä. — nur wenn PW-API an Grenzen stößt und ihr klare Anforderungen habt |
| **PDF-Thumbnails / Vorschau** | Nicht Kern-PW; oft `exec` zu `ghostscript`/`imagick` oder schlanke Wrapper | z. B. dedizierte PDF-Libs **nur** wenn Server-Politik und Hosting das erlauben |
| **Video-Metadaten / Dauer** | Kein Standard in PW | `getID3` o. ä. — nur bei konkretem Bedarf |
| **HTTP-Client** (externe APIs) | `WireHttp` | Für OAuth2/REST oft `symfony/http-client` — nur wenn WireHttp nicht reicht |

**Empfehlung für dieses Modul:**

1. **Zuerst** alles mit **ProcessWire-Methoden** und sauberem **modernen OOPHP** (typisierte Eigenschaften, klare Services im Modul, keine Mischung von Concerns) umsetzen.
2. **Externe Composer-Pakete** nur einführen, wenn eine Anforderung **nachweislich** nicht sinnvoll mit PW erfüllbar ist oder Wartung/Security (z. B. geprüfte Krypto-Libs) es erzwingt — und dann **minimal** (eine klar abgegrenzte Abhängigkeit, Versionspin).
3. Kein „Framework im Modul“ ohne Bedarf; das Modul soll **in typische PW-Installationen** ohne Überraschungen laufen.

---

## Legende

- `[ ]` offen  
- `[x]` erledigt und von dir bestätigt  
- Abhängigkeiten: „Nach:“ verweist auf vorherige Schritte

---

## Phase 0 — Grundlagen stabilisieren (technische Schulden)

**Ziel:** Korrektes Verhalten bei Bild vs. Datei, Speicherung/Installation konsistent mit UI, Konfiguration wirksam, keine toten Debug-Endpunkte ohne Absicherung.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 0.1 | `[ ]` | **Primärmedium-Logik:** Zentrale Hilfsmethoden (z. B. in `MediaManagerAPI`): primäres Bild (`mm_bild`) vs. Datei (`mm_datei`); alle Stellen im Modul (Grid, Edit, Imageedit, Inputfield-Chips) nutzen dieselbe Logik — keine falschen Vorschauen/Bearbeitung am falschen Feld. | — |
| 0.2 | `[ ]` | **Felder & Speichern:** Fehlende Felder in `install` ergänzen (`mm_tags`, `mm_beschreibung` o. Ä., falls im UI verwendet); `saveMediaItem` und `createMediaItem` speichern alle im Formular/Upload übergebenen Metadaten konsistent (inkl. Tags, Typ wo sinnvoll). | 0.1 |
| 0.3 | `[ ]` | **Modulkonfiguration `gridLimit`:** Wert aus Modulconfig lesen und für Grid + AJAX (`modal-items`) verwenden; sinnvolle Defaults. | 0.1 |
| 0.4 | `[ ]` | **Admin-JS Bildbearbeitung:** URL zur `imageedit`-Action korrekt (kein falsches `../..`); idealerweise Basis-URL aus PHP-Config (`_injectJsConfig` o. Ä.), damit relative Pfade nicht brechen. | 0.1 |
| 0.5 | `[ ]` | **Aufräumen / harte Kanten:** `executeTest` entfernen oder nur für Superuser + klares Kennzeichen; Logging in `_injectJsConfig` entschärfen (kein unnötiges CSRF-Logging). | — |

**Phase 0 abgeschlossen, wenn:** Medien können angelegt, bearbeitet, im Grid und im Picker konsistent angezeigt werden; Bildbearbeitung greift auf das richtige Bild; Konfiguration wirkt.

---

## Phase 1 — Upload & Performance

**Ziel:** Produktiver Umgang mit vielen Dateien und klaren Vorschau-Größen.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 1.1 | `[ ]` | **Mehrfach-Upload (Server):** Ein Request mit mehreren Dateien oder dokumentierte sequentielle Strategie; einheitliche Fehlerantwort (JSON). | Phase 0 |
| 1.2 | `[ ]` | **Mehrfach-Upload (UI):** Mehrere Dateien wählen; Fortschritt; Fehler pro Datei anzeigen. | 1.1 |
| 1.3 | `[ ]` | **Thumbnail-Konstanten:** Ein zentrales Set an Größen (Grid, Picker, Liste), überall `Pageimage::size()` / bestehende Variationen; unnötige Neuberechnung vermeiden (z. B. feste Namenskonvention / eine Variation pro „Slot“). | Phase 0 |
| 1.4 | `[ ]` | **Lazy-Loading:** Entweder natives `loading="lazy"` konsequent oder `data-src` + Observer — nicht beides widersprüchlich. | 1.3 |

---

## Phase 2 — Redakteurs-Features (ohne WordPress nachzubauen)

**Ziel:** Arbeiten wie in einer professionellen Mediathek: Metadaten, Bulk, Komfort.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 2.1 | `[ ]` | **Alternativtext / Beschriftung:** Felder + Anzeige im Grid/Edit/Picker wo sinnvoll. | Phase 0 |
| 2.2 | `[ ]` | **Bulk-Aktionen:** Mehrfachauswahl im Grid; Kategorie setzen; Löschen mit Bestätigung. | 1.x sinnvoll, mindestens Phase 0 |
| 2.3 | `[ ]` | **„URL kopieren“** für die öffentliche Datei-URL (Clipboard-API + Fallback). | Phase 0 |
| 2.4 | `[ ]` | **Duplizieren / Ersetzen** (optional getrennt): Duplikat-Page; oder Datei ersetzen mit Hinweis zu Referenzen (Fieldtype hält IDs). | 2.2 |

---

## Phase 3 — Bildbearbeitung vertiefen

**Ziel:** PW-Pipeline nutzen, keine zweite Bild-Engine parallel.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 3.1 | `[ ]` | **Rotation/Resize review:** Sicherstellen, dass Operationen auf dem Original/`Pageimage` laufen und Vorschau/Cache-Busting stimmt. | Phase 0 |
| 3.2 | `[ ]` | **Zuschneiden (Crop):** Über PW-API (z. B. `size()` mit Cropping, oder passende Optionen) + einfache UI — Umfang pragmatisch halten. | 3.1 |
| 3.3 | `[ ]` | **Externe Lib nur falls nötig:** Evaluation dokumentieren; wenn ja, eine Abhängigkeit mit Begründung. | bei Bedarf |

---

## Phase 4 — Architektur & Dokumentation im Modul

**Ziel:** Wartbarkeit, Erweiterbarkeit.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 4.1 | `[ ]` | **Aufteilung:** z. B. AJAX-Handler-Klasse, Render-Helfer oder Traits — ohne unnötige Fragmentierung; `*_module.php` schlank halten. | Phase 0 |
| 4.2 | `[ ]` | **PHPDoc** an öffentlichen API-Methoden; kurze Abschnittskommentare bei komplexen Blöcken. | laufend / 4.1 |
| 4.3 | `[ ]` | **Optional `API.md`** im Modul (nur wenn gewünscht): Fieldtype/Inputfield-Kurzreferenz für Entwickler. | 4.2 |

---

## Phase 5 — Aufräumen Installation / Deinstallation

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 5.1 | `[ ]` | **`uninstall`:** Strategie dokumentieren und optional implementieren (Daten löschen vs. nur Modul deaktivieren — oft Daten behalten). | Phase 0 |

---

## Changelog dieses Plans

| Datum | Änderung |
|-------|----------|
| 2026-04-13 | Erstversion |
| 2026-04-13 | Phase 0 im Code umgesetzt — **Checkboxen `[ ]` erst nach deiner Test-Bestätigung** auf `[x]` setzen. |
| 2026-04-13 | **Phase 1** im Code umgesetzt (Mehrfach-Upload, Slot-Konstanten, natives `loading="lazy"`). Checkboxen `[ ]` → `[x]` nach deiner Bestätigung. |

---

*Ende des Phasenplans.*
