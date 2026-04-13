# bsProcessMedienManager — Entwickler-Kurzreferenz

Kurzübersicht über das **Bundle** (Process-Modul, API, Traits, Inputfield, Fieldtype). Detaillierte Template-Ausgabe (Frontend) folgt mit **Phase 7** (Fieldtype & Snippets).

## Verzeichnisaufbau

| Pfad | Rolle |
|------|--------|
| `bsProcessMedienManager.module.php` | Process: `execute*`-Routen, Rechte, Konfiguration; **dünne Fassade** |
| `lib/MediaManagerAjaxTrait.php` | JSON-Endpunkte für Admin-JS / Picker |
| `lib/MediaManagerRenderTrait.php` | Admin-HTML (Grid, Listen, Formulare) |
| `MediaManagerAPI.php` | Domänenlogik: Suche, Dateien, Kategorien, Bild/WebP |
| `InputfieldMedienManager.module.php` | Backend-Feld-UI |
| `FieldtypeMedienManager.module.php` | Feldspeicher (Referenz auf Medien-Items) |
| `js/` · `css/` | Admin- und Inputfield-Assets |

## `MediaManagerAPI`

Instanziierung: `new MediaManagerAPI($wire)` — im Process-Modul über `api()`.

**Suche & Zugriff:** `findMedia()`, `getMediaItem()`, `getKategorien()`

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
| `ajax/` | Zentraler AJAX-POST (`action` im Body oder GET) |
| `upload/` | Mehrfach-Upload (JSON) |
| `edit/` | Bearbeiten |
| `save/` | POST Speichern |
| `replace/` | Datei ersetzen |
| `imageedit/` | Bild drehen / Größe (Master-Datei) |
| `kategorien/` | Kategorien |
| `delete/` | Eintrag löschen |

## AJAX-Aktionen (`action`)

Lesezugriffe ohne CSRF; schreibende Aktionen mit Session-CSRF.

| `action` | Kurzbeschreibung |
|----------|------------------|
| `modal-items` | Picker: HTML-Fragment + Pagination |
| `thumb` | Chip-Vorschau |
| `delete` | Einzel löschen |
| `bulk-delete` | Mehrfach löschen |
| `bulk-set-kategorie` | Kategorie setzen |
| `bulk-webp` | WebP-Nebenversionen |
| `duplicate-media` | Duplikat-Page |
| `kategorien` | JSON-Liste (Picker) |
| `save-kategorie` | Kategorie anlegen |
| `delete-kategorie` | Kategorie löschen |

## Berechtigung

`medien-manager` — siehe Modul `permissions` in `getModuleInfo()`.

## Version

Siehe `getModuleInfo()['version']` — bei Updates **Module aktualisieren**, damit `___upgrade` läuft.
