<?php namespace ProcessWire;

/**
 * Konfigurierbare Eigenschaften für Felder vom Typ FieldtypeMedienManager.
 *
 * Entspricht dem Muster von {@see ImageField} / PageField — nur PHPDoc, keine Logik.
 *
 * @copyright 2026 bsProcessMedienManager
 */
class MedienManagerField extends Field {

	/**
	 * @property int $maxItems Maximale Anzahl referenzierter Medien-Items (0 = unbegrenzt, 1 = eine Auswahl).
	 * @property array<int, string> $allowedTypes Erlaubte Medientypen: `bild`, `video`, `pdf` — leer = alle Typen.
	 */
}
