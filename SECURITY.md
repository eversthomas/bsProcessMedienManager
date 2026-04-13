# Sicherheit — Medien Manager (Checkliste)

Kurzüberblick für Reviews und Betrieb. Ergänzend: [`API.md`](API.md) (technische Endpunkte).

## Zugriff und Authentifizierung

| Maßnahme | Umsetzung |
|----------|-----------|
| Admin-Oberfläche und AJAX | Berechtigung **`medien-manager`** (`_requirePermission()` auf allen `execute*`-Einstiegen) |
| Mutierende AJAX-Aktionen | **CSRF**-Token (`_validateCsrf()`), nur **POST** |
| Lese-AJAX (Picker: `modal-items`, `thumb`, `kategorien`) | Eingeloggt + (**`medien-manager`** oder **`page-edit`**) — damit Seiten-Redakteure den Picker nutzen können; GET ohne CSRF (keine Zustandsänderung) |
| Diagnose **`/test/`** | Nur **Superuser**, JSON ohne GET/POST-**Werte** (nur Schlüssel) |

## Eingaben und Uploads

| Maßnahme | Umsetzung |
|----------|-----------|
| Dateitypen Upload | Positivliste Dateiendungen (Bilder, Video, PDF) |
| Dateigröße | Zusätzlich zu PHP: effektives Maximum = min(`upload_max_filesize`, `post_max_size`) und optional **Modul-Konfiguration** „Max. Upload … MB“ |
| Hochgeladene Dateien | Nur über `is_uploaded_file` / ProcessWire-API; keine Ausführung von Inhalten |
| Bulk-Aktionen | Max. **500** IDs pro Request (Schutz vor überlangen Payloads) |
| Selektoren / Texte | ProcessWire-**Sanitizer** wo sinnvoll |

## Logs und JSON

| Maßnahme | Umsetzung |
|----------|-----------|
| Datei-Log `medienmanager` | Normale Meldungen nur bei **Superuser**; Fehler mit `$force` immer |
| Fehlermeldungen an Browser | Keine Server-Pfade in JSON-Erfolgsmeldungen; generische Texte bei Fehlern |

## Offene Punkte / Betrieb

- **`php.ini`**: `upload_max_filesize` und `post_max_size` für erwartete Dateigrößen setzen  
- **HTTPS** im Admin: übliche ProcessWire-Empfehlung  
- **Backups** vor größeren Datenmigrationen  

*Bei neuen Features diese Liste mitpflegen.*
