# bsProcessMedienManager — Medien Manager für ProcessWire

![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-777BB4?style=flat-square)
![ProcessWire](https://img.shields.io/badge/ProcessWire-3.x-2480e6?style=flat-square)

Zentrales **ProcessWire**-Modul für Bilder, Videos und PDFs: Upload, Metadaten, Kategorien, Suche und Admin-Oberfläche unter **Einstellungen → Medien Manager**.

| Dokument | Inhalt |
|----------|--------|
| Diese **README** | Überblick, Bedienung, FAQ, Deinstallation — für Menschen & GitHub |
| [`API.md`](API.md) | Technische Referenz (Klassen, AJAX, URLs) |
| [`SECURITY.md`](SECURITY.md) | Sicherheits-Checkliste (Rechte, CSRF, Uploads) |
| [`PHASENPLAN.md`](PHASENPLAN.md) | Roadmap und erledigte Phasen |

---

## Inhaltsverzeichnis

1. [Repository-Struktur](#repository-struktur)
2. [Voraussetzungen](#voraussetzungen)
3. [Installation](#installation)
4. [Updates](#updates)
5. [FAQ](#faq)
6. [Bedienung für Redakteure](#bedienung-für-redakteure)
7. [Neue Funktionen und Versionshinweise](#neue-funktionen-und-versionshinweise)
8. [Erweiterte Hinweise](#erweiterte-hinweise)
9. [Deinstallation und Daten](#deinstallation-und-daten)
10. [Entwicklung](#entwicklung)

Hinweis: Detaillierte Sicherheitsübersicht → [`SECURITY.md`](SECURITY.md).

---

## Repository-Struktur

```
bsProcessMedienManager/
├── bsProcessMedienManager.module.php   # Process-Modul (Routen, Konfiguration)
├── MediaManagerAPI.php                 # Domänen-API
├── lib/
│   ├── MediaManagerAjaxTrait.php       # JSON-Endpunkte
│   └── MediaManagerRenderTrait.php     # Admin-Markup
├── InputfieldMedienManager.module.php
├── FieldtypeMedienManager.module.php
├── js/ · css/
├── API.md
├── README.md
├── SECURITY.md
└── PHASENPLAN.md
```

---

## Voraussetzungen

- ProcessWire **3.x**
- **PHP ≥ 8.0**
- Nach Installation den Benutzerrollen die Berechtigung **„Medien Manager verwenden“** (`medien-manager`) zuweisen

---

## Installation

1. Repository bzw. Modulordner nach `site/modules/bsProcessMedienManager/` legen  
2. Im Backend **Module** öffnen → **Medien Manager** installieren  

---

## Updates

1. Dateien im Modulordner ersetzen (oder `git pull` im Projekt)  
2. Im Backend **Module** → **Aktualisieren** / zur Modulzeile navigieren und **aktualisieren** ausführen  

So werden `___upgrade`-Schritte und Schema-Anpassungen ausgeführt.

---

## FAQ

### Muss ich nach einem Update deinstallieren und neu installieren?

**Nein — das ist für normale Updates nicht nötig.**  
Nach dem Kopieren der neuen Version reicht **Modul aktualisieren** im ProcessWire-Backend. So bleiben Konfiguration, Medien-Daten und Berechtigungen erhalten und Upgrade-Hooks laufen wie vorgesehen.

### Wann sind Deinstallation und Neuinstallation sinnvoll?

Nur wenn du **explizit** testen willst:

- ob die **Deinstallation** (Menü weg, Config leer) so wirkt wie dokumentiert, oder  
- eine **Neuinstallation auf einer leeren / Kopie-Installation** (Staging).

> [!WARNING]
> **Deinstallation auf einer produktiven Seite** nur mit Backup und Bewusstsein: Die Admin-Oberfläche verschwindet, die Modul-Konfiguration wird geleert; Medien-**Seiten** und **Dateien** werden standardmäßig **nicht** automatisch mitgelöscht (siehe unten). Fieldtype-Felder, die noch auf Medien verweisen, vorher prüfen.

### Reicht „Refresh“ der Module-Liste?

Das allein ersetzt keinen **Update-Klick**. Nach Dateiänderungen immer **Module aktualisieren** für diese Modulversion ausführen.

---

## Bedienung für Redakteure

*Hier können später ausführliche Anleitungen ergänzt werden. Kurzfassung:*

### Übersicht

- **Einstellungen → Medien Manager**: Raster- oder Listenansicht, Filter (Typ, Kategorie), Suche  
- **Medien hochladen**: mehrere Dateien möglich; Titel, Kategorie, Tags optional  
- **Bearbeiten**: Titel, Beschreibung, Tags, Alternativtext, Beschriftung, Typ, Kategorie  
- **Kategorien**: eigene Verwaltungsseite im gleichen Menübereich  

### Medien hochladen

- Erlaubte Formate u. a. JPG, PNG, GIF, WebP, MP4, MOV, PDF (Details im Upload-Dialog)  
- Bei mehreren Dateien kann der Titel pro Dateiname gesetzt werden  

### Bearbeiten und Datei ersetzen

- **Datei ersetzen** nur mit **gleicher Dateiendung** wie die aktuelle Datei (bestehende Verweise bleiben gültig)  
- Öffentliche URL über **URL kopieren** (Grid) in die Zwischenablage  

### Bildbearbeitung

- **Bild bearbeiten**: Drehen; Größe/Zuschnitt (Originaldatei wird angepasst)  
- **Bulk „WebP erzeugen“**: WebP-Nebenversionen für ausgewählte Bilder  

### Suche

Suchfeld berücksichtigt u. a. Titel, Tags und (falls vorhanden) Beschreibung, Alternativtext, Beschriftung.

---

## Neue Funktionen und Versionshinweise

*Bei Releases hier ergänzen: Was ist neu, Breaking Changes, Migration.*

| Version | Kurz |
|---------|------|
| 1.9 | Phase 6: CSRF+POST für Schreib-API, Upload-Limit (ini + MB-Kappe), Bulk-ID-Limit, `SECURITY.md`, Test-Endpoint ohne GET-Werte |
| 1.8 | README (GitHub); Deinstallationsstrategie; Config-Cleanup bei Uninstall |
| 1.7 | Traits unter `lib/`; `API.md`; PHPDoc API |
| 1.6 | Bildbearbeitung Master-Datei, Bulk-WebP, … |

---

## Erweiterte Hinweise

*Platz für später: Fieldtype im Frontend, Performance, Backup-Strategie.*

---

## Deinstallation und Daten

**Standardverhalten:**

| Was | Verhalten |
|-----|-----------|
| Admin-Menü „Medien Manager“ | wird entfernt (Process-Page) |
| Modul-Konfiguration (`rootPageID`, Raster-Limit, …) | wird **geleert** |
| Medien-Seiten (`medienmanager-item`), Kategorien, Dateien unter `site/assets/files/` | werden **nicht** automatisch gelöscht |
| Felder `mm_*` und Templates | werden **nicht** automatisch entfernt |

Vollständiges Entfernen von Inhalten nur geplant mit Backup und ggf. manuellem Aufräumen der Seiten/Felder.

---

## Entwicklung

| Datei | Thema |
|-------|--------|
| [`API.md`](API.md) | Klassen, Traits, AJAX, URLs |
| [`SECURITY.md`](SECURITY.md) | Sicherheits-Checkliste |
| [`PHASENPLAN.md`](PHASENPLAN.md) | Roadmap |
| [`PHASE3_NOTES.md`](PHASE3_NOTES.md) | Bildpipeline / WebP (Kurznotiz) |
