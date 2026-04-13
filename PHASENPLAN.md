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

**Querschnitt (bei jeder größeren Änderung mitdenken):**

- **Modularisierung:** klare Grenzen zwischen API, Admin-UI, AJAX, Fieldtype(s) — siehe Phase 4.
- **Sicherheit:** nicht nur „funktioniert“, sondern absichtlich gehärtet — siehe Phase 6.

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
| 0.1 | `[x]` | **Primärmedium-Logik:** Zentrale Hilfsmethoden (z. B. in `MediaManagerAPI`): primäres Bild (`mm_bild`) vs. Datei (`mm_datei`); alle Stellen im Modul (Grid, Edit, Imageedit, Inputfield-Chips) nutzen dieselbe Logik — keine falschen Vorschauen/Bearbeitung am falschen Feld. | — |
| 0.2 | `[x]` | **Felder & Speichern:** Fehlende Felder in `install` ergänzen (`mm_tags`, `mm_beschreibung` o. Ä., falls im UI verwendet); `saveMediaItem` und `createMediaItem` speichern alle im Formular/Upload übergebenen Metadaten konsistent (inkl. Tags, Typ wo sinnvoll). | 0.1 |
| 0.3 | `[x]` | **Modulkonfiguration `gridLimit`:** Wert aus Modulconfig lesen und für Grid + AJAX (`modal-items`) verwenden; sinnvolle Defaults. | 0.1 |
| 0.4 | `[x]` | **Admin-JS Bildbearbeitung:** URL zur `imageedit`-Action korrekt (kein falsches `../..`); idealerweise Basis-URL aus PHP-Config (`_injectJsConfig` o. Ä.), damit relative Pfade nicht brechen. | 0.1 |
| 0.5 | `[x]` | **Aufräumen / harte Kanten:** `executeTest` entfernen oder nur für Superuser + klares Kennzeichen; Logging in `_injectJsConfig` entschärfen (kein unnötiges CSRF-Logging). | — |

**Phase 0 abgeschlossen, wenn:** Medien können angelegt, bearbeitet, im Grid und im Picker konsistent angezeigt werden; Bildbearbeitung greift auf das richtige Bild; Konfiguration wirkt.

---

## Phase 1 — Upload & Performance

**Ziel:** Produktiver Umgang mit vielen Dateien und klaren Vorschau-Größen.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 1.1 | `[x]` | **Mehrfach-Upload (Server):** Ein Request mit mehreren Dateien oder dokumentierte sequentielle Strategie; einheitliche Fehlerantwort (JSON). | Phase 0 |
| 1.2 | `[x]` | **Mehrfach-Upload (UI):** Mehrere Dateien wählen; Fortschritt; Fehler pro Datei anzeigen. | 1.1 |
| 1.3 | `[x]` | **Thumbnail-Konstanten:** Ein zentrales Set an Größen (Grid, Picker, Liste), überall `Pageimage::size()` / bestehende Variationen; unnötige Neuberechnung vermeiden (z. B. feste Namenskonvention / eine Variation pro „Slot“). | Phase 0 |
| 1.4 | `[x]` | **Lazy-Loading:** Entweder natives `loading="lazy"` konsequent oder `data-src` + Observer — nicht beides widersprüchlich. | 1.3 |

---

## Phase 2 — Redakteurs-Features (ohne WordPress nachzubauen)

**Ziel:** Arbeiten wie in einer professionellen Mediathek: Metadaten, Bulk, Komfort — ergänzt um **UIkit 3**-konsistente Oberfläche für Redakteure (Schritte 2.5 ff.).

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 2.1 | `[x]` | **Alternativtext / Beschriftung:** Felder + Anzeige im Grid/Edit/Picker wo sinnvoll. | Phase 0 |
| 2.2 | `[x]` | **Bulk-Aktionen:** Mehrfachauswahl im Grid; Kategorie setzen; Löschen mit Bestätigung. | 1.x sinnvoll, mindestens Phase 0 |
| 2.3 | `[x]` | **„URL kopieren“** für die öffentliche Datei-URL (Clipboard-API + Fallback). | Phase 0 |
| 2.4 | `[x]` | **Duplizieren / Ersetzen** (optional getrennt): Duplikat-Page; oder Datei ersetzen mit Hinweis zu Referenzen (Fieldtype hält IDs). | 2.2 |
| 2.5 | `[x]` | **Grid-Karten & Vorschau:** Freischwebende Aktions-Icons durch **Hover-Overlay auf der Karte** ersetzen (halbtransparenter Hintergrund, zentrierte Aktionen). **Primär-Label:** Dateiname (aus primärer `Pagefile`/`Pageimage`), nicht numerische Page-ID. **Sekundärinfo:** Abmessungen (wo sinnvoll, z. B. Bild) und Dateigröße. **Einheitliches Kachelformat:** `uk-cover` bzw. CSS `object-fit` / festes Seitenverhältnis — konsistent zum restlichen UIkit-Layout. | 2.1–2.4 |
| 2.6 | `[x]` | **Bearbeiten-Ansicht (UIkit Grid):** Zwei Spalten — links **sticky** Bildvorschau, rechts Formularfelder. **Titel:** bei leerem/neuem Eintrag sinnvoll mit **Originaldateiname** vorbelegen. **Alternativtext:** Placeholder editorfreundlich formulieren (z. B. Bild für Screenreader und SEO beschreiben). | 2.1 |
| 2.7 | `[x]` | **Bulk-Leiste (UIkit):** Statt isoliertem Block eine **visuell verbundene** UIkit-Leiste (Toolbar/Card/Utility-Klassen). Zähler **live** im Muster **„3 von 12 ausgewählt“** (Auswahl vs. Treffer auf der aktuellen Seite / sinnvoll definiertes Total). | 2.2 |
| 2.8 | `[x]` | **Raster vs. Liste:** Umschaltbare **Listen- oder Tabellenansicht** als Alternative zum Grid (gleiche Datenquelle/Filter; kein separater Workflow). Optional UIkit-Table-Komponenten. | 2.5 |
| 2.9 | `[x]` | **Polish & Redaktions-UX:** UIkit-**Transitions** und **Hover-Zustände** durchgängig; **keine Roh-IDs** für Redakteure sichtbar (überall Titel, Dateiname oder neutrale Bezeichner — technische IDs nur falls absolut nötig und nicht im Standard-UI). | 2.5–2.8 |

---

## Phase 3 — Bildbearbeitung vertiefen

**Ziel:** PW-Pipeline nutzen, keine zweite Bild-Engine parallel.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 3.1 | `[x]` | **Rotation/Resize review:** Sicherstellen, dass Operationen auf dem Original/`Pageimage` laufen und Vorschau/Cache-Busting stimmt. | Phase 0 |
| 3.2 | `[x]` | **Zuschneiden (Crop):** Über PW-API (z. B. `size()` mit Cropping, oder passende Optionen) + einfache UI — Umfang pragmatisch halten. | 3.1 |
| 3.3 | `[x]` | **Externe Lib nur falls nötig:** Evaluation dokumentieren; wenn ja, eine Abhängigkeit mit Begründung. | bei Bedarf |
| 3.4 | `[x]` | **Bulk: Bilder nachträglich optimieren** (z. B. **WebP-Variante** neben dem Original, oder feste Ausgabe-Presets): Auswahl im Grid/Liste; serverseitig nur mit vorhandenen PW-/ImageSizer- bzw. GD/Imagick-Möglichkeiten; klare Regeln (wann neu berechnen, Speicherort, Fallback für ältere Browser). Optional abhängig von Phase 3.1. | 3.1, Phase 2.2 |

---

## Phase 4 — Architektur, Modularisierung & Dokumentation im Modul

**Ziel:** Wartbarkeit, Erweiterbarkeit — **das gesamte Bundle** (Process-Modul, API, Assets, Inputfield, später Fieldtype) **sinnvoll schneiden**, ohne Over-Engineering.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 4.1 | `[ ]` | **Modularisierung / Aufteilung:** Zuständigkeiten trennen (z. B. AJAX-Handler-Klasse, Render-Helfer, Services, Traits) — `*_module.php` als dünnere Fassade; nachvollziehbare Ordner-/Namenskonvention für das **gesamte** Modul; keine unnötige Fragmentierung. | Phase 0 |
| 4.2 | `[ ]` | **PHPDoc** an öffentlichen API-Methoden; kurze Abschnittskommentare bei komplexen Blöcken. | laufend / 4.1 |
| 4.3 | `[ ]` | **Optional `API.md`** im Modul (nur wenn gewünscht): Kurzreferenz für Entwickler; mit **Phase 7** abgleichen, sobald Fieldtype & Template-API stehen. | 4.2, Phase 7 |

---

## Phase 5 — Aufräumen Installation / Deinstallation

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 5.1 | `[ ]` | **`uninstall`:** Strategie dokumentieren und optional implementieren (Daten löschen vs. nur Modul deaktivieren — oft Daten behalten). | Phase 0 |

---

## Phase 6 — Sicherheit & Härtung

**Ziel:** Das Modul verhält sich **bewusst sicher** — nicht nur zufällig; Anforderungen sind prüfbar und nachziehbar.

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 6.1 | `[ ]` | **Zugriff & Schreibaktionen:** `medien-manager`-Permission konsequent; **CSRF** bei allen mutierenden Requests (inkl. AJAX); keine privilegierten Aktionen ohne Session/Auth. | Phase 0 |
| 6.2 | `[ ]` | **Eingaben & Uploads:** Dateitypen/Größen serverseitig enforce’n; Pfade und Benennung sanitizen; keine Ausführung hochgeladener Inhalte; wo nötig **Superuser**-Gates für riskante Diagnose-Endpunkte. | 6.1 |
| 6.3 | `[ ]` | **Ausgabe & Betrieb:** Keine sensiblen Daten in Logs/JSON für Redakteure; **Security-Review**-Checkliste (kurz dokumentiert, z. B. in `API.md` oder Modul-README-Abschnitt). | 6.2 |

---

## Phase 7 — Fieldtype & Template-Integration (Frontend)

**Ziel:** Redakteure wählen Medien aus dem Manager auf **Seiten** per Feld; Entwickler nutzen eine **klare Template-API** mit **Code-Snippets** — vergleichbar mit ProcessWires **Image** / **Images** (Felddokumentation, typische `$page->…`-Muster).

| # | Schritt | Beschreibung | Nach |
|---|---------|--------------|-----|
| 7.1 | `[ ]` | **Fieldtype + Inputfield:** Feld speichert Referenz(en) auf Medien-Items (Page-IDs oder abgeleitetes Modell); Validierung, leere Werte, kompatibel mit bestehendem `InputfieldMedienManager` oder klar getrennte Evolution. | Phase 4.1 |
| 7.2 | `[ ]` | **Template-API & Snippets:** Ausgabe im Frontend (URL, `Pageimage` wo möglich, Alt-Text aus Medien-Metadaten); **Codebeispiele** (ein Bild / mehrere Bilder) analog zu `Image`/`Images`-Dokumentation; Verweis in **API.md** oder zentraler Modul-Doku. | 7.1 |
| 7.3 | `[ ]` | **Edge Cases:** Was passiert bei gelöschtem Medien-Item, fehlender Datei, Rechte im Frontend — definiertes Verhalten (NullPage, leere Ausgabe, Hinweis). | 7.2 |

---

## Changelog dieses Plans

| Datum | Änderung |
|-------|----------|
| 2026-04-13 | Erstversion |
| 2026-04-13 | Phase 0 im Code umgesetzt — **Checkboxen `[ ]` erst nach deiner Test-Bestätigung** auf `[x]` setzen. |
| 2026-04-13 | **Phase 1** im Code umgesetzt (Mehrfach-Upload, Slot-Konstanten, natives `loading="lazy"`). Checkboxen `[ ]` → `[x]` nach deiner Bestätigung. |
| 2026-04-13 | **Phase 1** ausdrücklich bestätigt; **Phase 0** mitabgenommen (bereits umgesetzt, gemeinsam mit Phase 1 getestet). |
| 2026-04-13 | **Phase 2** im Code umgesetzt (mm_alt/mm_caption, Bulk, URL kopieren, Duplizieren, Datei ersetzen). Modulversion **1.4**; nach Update Modul aktualisieren. |
| 2026-04-13 | **Phase 2** um UX-Arbeitspakete erweitert (2.5–2.9): UIkit-Oberfläche Grid/Bulk/Edit, Listenansicht, Polish; keine neue Phasennummerierung. |
| 2026-04-13 | **Phase 2.5–2.9** im Code umgesetzt (UIkit-Card Toolbar+Bulk, Grid-Overlay, Liste, Meta-Infos, Edit-Zweispalter). Modulversion **1.5**. |
| 2026-04-13 | Plan erweitert: **Phase 4** (Modularisierung gesamtes Modul), **Phase 6** (Sicherheit), **3.4** (Bulk-Bildoptimierung/WebP), **Phase 7** (Fieldtype + Template-Snippets wie Image/Images). |
| 2026-04-13 | **Phase 3** im Code umgesetzt: Master-Resize/Crop (`ImageSizer`), Grid-Thumbs nach Bearbeitung, Bulk-WebP (`Pageimage::webp()`), Kurzevaluation in `PHASE3_NOTES.md`. Modulversion **1.6**. |

---

*Ende des Phasenplans.*
