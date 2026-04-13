<?php namespace ProcessWire;

/**
 * FieldtypeMedienManager
 *
 * Fieldtype zum Speichern von Medien-Referenzen als Page-IDs.
 * Basiert auf FieldtypeMulti (eine DB-Zeile pro referenziertem Item).
 * Beim Laden (wakeup) werden IDs zu einem PageArray aufgelöst.
 *
 * @copyright 2026 bsProcessMedienManager
 */
class FieldtypeMedienManager extends FieldtypeMulti {

	/**
	 * Modul-Metadaten für ProcessWire.
	 */
	public static function getModuleInfo(): array {
		return [
			'title'    => 'Medien Manager Fieldtype',
			'version'  => 1,
			'summary'  => 'Speichert Referenzen auf Medien-Items des Medien Managers (Page-IDs).',
			'requires' => ['InputfieldMedienManager'],
			'installs' => ['InputfieldMedienManager'],
		];
	}

	// -----------------------------------------------------------------------
	// Pflicht-Methoden des Fieldtype-Interface
	// -----------------------------------------------------------------------

	/**
	 * Passendes Inputfield für die Backend-Bearbeitung zurückgeben.
	 *
	 * @param Page  $page
	 * @param Field $field
	 * @return InputfieldMedienManager
	 */
	public function getInputfield(Page $page, Field $field): Inputfield {
		/** @var InputfieldMedienManager $inputfield */
		$inputfield = $this->wire->modules->get('InputfieldMedienManager');
		$inputfield->setField($field);
		return $inputfield;
	}

	/**
	 * Datenbankschema definieren — identisch mit FieldtypePage (int data-Spalte).
	 *
	 * @param Field $field
	 * @return array
	 */
	public function getDatabaseSchema(Field $field): array {
		$schema         = parent::getDatabaseSchema($field);
		$schema['data'] = 'int NOT NULL';
		$schema['keys']['data'] = 'KEY data (data, pages_id, sort)';
		return $schema;
	}

	/**
	 * Aus der Datenbank geladene IDs in ein PageArray umwandeln (wakeup).
	 *
	 * @param Page  $page
	 * @param Field $field
	 * @param mixed $value Array von int-IDs aus der DB
	 * @return PageArray
	 */
	public function ___wakeupValue(Page $page, Field $field, $value): PageArray {
		$pageArray = $this->wire->pages->newPageArray();

		if(empty($value) || !is_array($value)) return $pageArray;

		// IDs als Integer sicherstellen
		$ids = array_map('intval', $value);
		$ids = array_filter($ids, fn($id) => $id > 0);

		if(empty($ids)) return $pageArray;

		// Pages laden — include=all da Items statusHidden haben
		$items = $this->wire->pages->getById($ids, [
			'template' => MediaManagerAPI::ITEM_TEMPLATE,
			'cache'    => true,
		]);

		// Sortierung aus DB beibehalten
		foreach($ids as $id) {
			$p = $items->get("id=$id");
			if($p && $p->id) $pageArray->add($p);
		}

		return $pageArray;
	}

	/**
	 * PageArray vor dem Speichern in ein Array von IDs umwandeln (sleep).
	 *
	 * @param Page  $page
	 * @param Field $field
	 * @param mixed $value PageArray oder einzelne Page
	 * @return array
	 */
	public function ___sleepValue(Page $page, Field $field, $value): array {
		$ids = [];

		if($value instanceof Page) {
			$value = $this->wire->pages->newPageArray()->add($value);
		}
		if(!$value instanceof PageArray) return $ids;

		foreach($value as $item) {
			// Nur gültige medienmanager-item Pages akzeptieren
			if($item instanceof Page && $item->id && $item->template->name === MediaManagerAPI::ITEM_TEMPLATE) {
				$ids[] = $item->id;
			}
		}

		return $ids;
	}

	/**
	 * Wert aus Eingabe für den Vergleich sanitieren (für Selektoren).
	 *
	 * @param Page  $page
	 * @param Field $field
	 * @param mixed $value
	 * @return int
	 */
	public function sanitizeValue(Page $page, Field $field, $value): mixed {
		if($value instanceof Page) return $value->id ? $value : $this->wire->pages->newNullPage();
		if($value instanceof PageArray) return $value;
		return (int) $value;
	}

	// -----------------------------------------------------------------------
	// Hooks
	// -----------------------------------------------------------------------

	/**
	 * Hook registrieren: beim Löschen eines medienmanager-item alle Referenzen bereinigen.
	 */
	public function init(): void {
		parent::init();
		$this->wire->pages->addHookAfter('deleted', $this, 'hookPagesDeleted');
	}

	/**
	 * Alle Referenzen auf eine gelöschte medienmanager-item Page aus Feldtabellen entfernen.
	 *
	 * @param HookEvent $event
	 */
	public function hookPagesDeleted(HookEvent $event): void {
		/** @var Page $deletedPage */
		$deletedPage = $event->arguments(0);

		if(!$deletedPage instanceof Page) return;
		if($deletedPage->template->name !== MediaManagerAPI::ITEM_TEMPLATE) return;

		// Alle Felder dieses Typs finden
		foreach($this->wire->fields->find("type=" . $this->className) as $field) {
			$table = $field->getTable();
			try {
				$stmt = $this->wire->database->prepare("DELETE FROM `$table` WHERE data=:pid");
				$stmt->bindValue(':pid', $deletedPage->id, \PDO::PARAM_INT);
				$stmt->execute();
			} catch(\Exception $e) {
				$this->wire->log->error("FieldtypeMedienManager: Fehler beim Bereinigen von Referenzen in $table: " . $e->getMessage());
			}
		}
	}

	// -----------------------------------------------------------------------
	// Feld-Konfiguration
	// -----------------------------------------------------------------------

	/**
	 * Konfigurationsformular für das Feld im Backend.
	 *
	 * @param Field $field
	 * @return InputfieldWrapper
	 */
	public function ___getConfigInputfields(Field $field): InputfieldWrapper {
		$wrapper = parent::___getConfigInputfields($field);

		/** @var InputfieldInteger $f */
		$f = $this->wire->modules->get('InputfieldInteger');
		$f->attr('name', 'maxItems');
		$f->label       = 'Maximale Anzahl auswählbarer Medien';
		$f->description = '0 = unbegrenzt, 1 = Einzelauswahl';
		$f->attr('value', (int) ($field->maxItems ?? 0));
		$f->min         = 0;
		$wrapper->add($f);

		return $wrapper;
	}
}