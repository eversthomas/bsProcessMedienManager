# bsProcessMedienManager — Entwickler-Kurzreferenz

Kurzübersicht über das **Bundle** (Process-Modul, API, Traits, Inputfield, Fieldtype).

**Sicherheit:** [`SECURITY.md`](SECURITY.md) (Checkliste: Rechte, CSRF, Uploads, Logs).

## Verzeichnisaufbau

| Pfad | Rolle |
|------|--------|
| `bsProcessMedienManager.module.php` | Process: `execute*`-Routen, Rechte, Konfiguration; **dünne Fassade** |
| `lib/MediaManagerAjaxTrait.php` | JSON-Endpunkte für Admin-JS / Picker |
| `lib/MediaManagerRenderTrait.php` | Admin-HTML (Grid, Listen, Formulare) |
| `MediaManagerAPI.php` | Domänenlogik: Suche, Dateien, Kategorien, Bild/WebP |
| `InputfieldMedienManager.module.php` | Backend-Feld-UI (Modal-Picker) |
| `FieldtypeMedienManager.module.php` | Feldspeicher (Referenzen auf Medien-**Pages**) |
| `MedienManagerField.php` | `Field`-Unterklasse (`@property`-Doku) |
| `js/` · `css/` | Admin- und Inputfield-Assets |
| `SECURITY.md` | Sicherheits-Checkliste |

## Fieldtype „Medien (Manager)“ (Phase 7)

- **Speicher:** wie `FieldtypeMulti` — eine Zeile pro referenzierter `medienmanager-item`-Page (`data` = ID). Sortierung über `sort`-Spalte.
- **Laufzeitwert:** `PageArray` gültiger Medien-Pages (versteckte Items werden mit `include=all` geladen).
- **Vorlagen** (beim Anlegen eines neuen Feldes): u. a. *Einzelbild (wie Image)*, *Mehrere Bilder (wie Images)*, *Einzelmedium*, *Mehrere Medien* — setzen `maxItems` und `allowedTypes` (`bild` / `video` / `pdf`).
- **Feldkonfiguration:** Zusätzlich zu **maxItems** ein einklappbarer Block **PHP-Beispiele (Frontend)** mit kopierbaren Snippets (Alternativtext, `figure`/`figcaption`, Lazy Loading).

### Template-API (Frontend)

Der Feldwert ist immer ein **`PageArray`**. Pro Eintrag:

| Methode (`MediaManagerAPI`) | Zweck |
|-----------------------------|--------|
| `getPrimaryPageimage(Page)` | `Pageimage` oder `null` |
| `getPublicFileUrl(Page)` | Öffentliche URL (Bild/Datei) |
| `getAccessibleLabel(Page)` | Text für **`alt`** / Screenreader: `mm_alt`, sonst Titel |
| `getCaption(Page)` | `mm_caption` für **`figcaption`** (kann leer sein) |
| `hasRenderableImage(Page)` | Prüfung vor `<img>` |

**Konventionen (SEO / Barrierefreiheit):**

- `alt` immer aus `getAccessibleLabel()` (oder leer lassen nur bei rein dekorativem Bild — dann bewusst `alt=""`).
- Sinnvolle Ausgabe von Breite/Höhe (`width`/`height`) über die gewählte `Pageimage::size()`-Variation.
- `loading="lazy"` / `decoding="async"` für unter den Fold platzierte Medien.
- Optional: `<figure role="group">` + `getCaption()` als `<figcaption>`.

### Edge Cases

| Situation | Verhalten |
|-----------|-----------|
| Medien-Item wurde gelöscht | Referenz wird beim Speichern der **Seite** entfernt; bis dahin kann die ID in der DB stehen — `wakeup` lädt nur existierende Pages. |
| Kein Bild (nur Video/PDF) | `hasRenderableImage()` ist false; URL z. B. mit `getPublicFileUrl()`. |
| Frontend-Zugriff | Ausgabe-URLs sind normale PW-Datei-URLs; wer die URL kennt, kann die Datei abrufen (wie bei `Pagefile::url`). Feinrechte pro Rolle sind nicht Bestandteil dieses Feldes. |

## `MediaManagerAPI`

Instanziierung: `new MediaManagerAPI($wire)` — im Process-Modul über `api()`.

**Suche & Zugriff:** `findMedia()` (u. a. `typ`, `typs` für Mehrfach-Typfilter, `q`, `kategorie_id`), `getMediaItem()`, `getKategorien()`

**Anlegen / Ändern / Löschen:** `createMediaItem()`, `saveMediaItem()`, `deleteMedia()`, `duplicateMediaItem()`, `replacePrimaryFile()`

**Dateien & URLs:** `getPrimaryPageimage()`, `getPrimaryNonImageFile()`, `getPublicFileUrl()`, `getThumbnailUrlForSlot()`, `getPrimaryBasename()`, …

**Bildverarbeitung:** `createVariants()`, `rotateMasterPageimage()`, `resizeMasterPageimage()`, `ensureWebpForPageimage()`

**Kategorien:** `createKategorie()`, `deleteKategorie()`

**Installation:** `install()`  
**Deinstallation:** `uninstall()` leert nur die Modul-Konfiguration; Medien-Pages und Felder bleiben (siehe `README.md`).

## Admin-URLs (Process)

Basis: `{adminUrl}setup/medienmanager/`

| Pfad | Zweck |
|------|--------|
| `./` | Grid/Liste |
| `ajax/` | Zentraler AJAX (`action`); schreibende Aktionen nur **POST** + CSRF |
| `upload/` | Mehrfach-Upload (JSON) |
| `edit/` | Bearbeiten |
| `save/` | POST Speichern |
| `replace/` | Datei ersetzen |
| `imageedit/` | Bild drehen / Größe (Master-Datei) |
| `kategorien/` | Kategorien |
| `delete/` | Eintrag löschen |

## AJAX-Aktionen (`action`)

Lesezugriffe ohne CSRF. **Schreibende** Aktionen: Session-**CSRF** und nur **POST** (siehe `___executeAjax()`).

| `action` / Parameter | Kurzbeschreibung |
|----------------------|------------------|
| `modal-items` | Picker: HTML + Pagination; optional `allowed_types=bild,video` (Einschränkung mehrerer Typen) |
| `thumb` | Chip-Vorschau |
| … | (weitere wie in früherer Version) |

## Berechtigung

`medien-manager` — siehe Modul `permissions` in `getModuleInfo()`.

## Modul-Konfiguration

Zusätzlich zu **Grid-Items pro Seite**: **Max. Upload-Größe pro Datei (MB)** — obere Kappe neben `php.ini` (`upload_max_filesize` / `post_max_size`). `0` = nur php.ini.

## Version

Siehe `getModuleInfo()['version']` — bei Updates **Module aktualisieren**, damit `___upgrade` läuft.
