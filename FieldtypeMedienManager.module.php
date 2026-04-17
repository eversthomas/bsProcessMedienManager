<?php namespace ProcessWire;

require_once __DIR__ . '/MediaManagerAPI.php';

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
	 * Normalisiert gemischte Werte (Page/ID/Strings/Arrays) zu einer eindeutigen ID-Liste.
	 *
	 * @param mixed $value
	 * @return int[]
	 */
	protected function normalizeIds($value): array {
		if($value instanceof Page) {
			return $value->id ? [(int) $value->id] : [];
		}
		if(is_int($value)) {
			return $value > 0 ? [$value] : [];
		}
		if(is_string($value) && ctype_digit($value)) {
			$id = (int) $value;
			return $id > 0 ? [$id] : [];
		}
		if($value instanceof PageArray) {
			$ids = [];
			foreach($value as $p) {
				if($p instanceof Page && $p->id) $ids[] = (int) $p->id;
			}
			return array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
		}
		if(is_array($value)) {
			$ids = [];
			foreach($value as $v) {
				foreach($this->normalizeIds($v) as $id) {
					$ids[] = $id;
				}
			}
			return array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
		}
		// letzter Versuch: Objekt mit id-Property
		if(is_object($value) && isset($value->id) && ctype_digit((string) $value->id)) {
			$id = (int) $value->id;
			return $id > 0 ? [$id] : [];
		}
		return [];
	}
	
	/**
	 * MaxItems aus Feldkonfiguration anwenden (0=unbegrenzt, 1=Einzelauswahl).
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	protected function applyMaxItems(Field $field, array $ids): array {
		$max = isset($field->maxItems) ? (int) $field->maxItems : 0;
		if($max <= 0) return $ids;
		$ids = array_values($ids);
		if(count($ids) <= $max) return $ids;
		// Bei Einzelauswahl: letztes Element behalten (entspricht „neueste Auswahl gewinnt“)
		if($max === 1) return [ (int) end($ids) ];
		return array_slice($ids, 0, $max);
	}

	/**
	 * Debug-Logging (nur Superuser) nach /site/assets/logs/medienmanager.txt
	 *
	 * @param array<string, mixed> $ctx
	 */
	protected function debugLog(string $msg, array $ctx = []): void {
		try {
			if(!$this->wire->user || !$this->wire->user->isSuperuser()) return;
			if(count($ctx)) {
				$msg .= ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
			$this->wire->log->save('medienmanager', $msg);
		} catch(\Throwable $e) {
			// nie Debug-Logging Fehler werfen lassen
		}
	}

	/**
	 * Modul-Metadaten für ProcessWire.
	 */
	public static function getModuleInfo(): array {
		return [
			'title'    => 'Medien (Manager)',
			'version'  => 2,
			'summary'  => 'Referenzen auf Medien-Items (Bibliothek) — vergleichbar mit Image/Images, inkl. Template-Snippets.',
			'requires' => ['InputfieldMedienManager'],
			'installs' => ['InputfieldMedienManager'],
		];
	}

	/**
	 * @param array<string, mixed> $a
	 */
	public function getFieldClass(array $a = array()) {
		return 'MedienManagerField';
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

		// IDs normalisieren und maxItems anwenden
		$ids = $this->applyMaxItems($field, $this->normalizeIds($value));

		if(empty($ids)) return $pageArray;

		// Pages laden — include=all da Items statusHidden haben
		$items = $this->wire->pages->find(
			"id=" . implode('|', $ids) . ", template=" . MediaManagerAPI::ITEM_TEMPLATE . ", include=all"
		);

		// Sortierung aus DB beibehalten
		foreach($ids as $id) {
			$p = $items->get("id=$id");
			if($p && $p->id) $pageArray->add($p);
		}

		$this->debugLog('FT-MM wakeupValue', [
			'pageId'      => (int) $page->id,
			'field'       => (string) $field->name,
			'rawCount'    => count($value),
			'idsIn'       => $ids,
			'loadedCount' => $pageArray->count(),
			'loadedIds'   => array_map('intval', explode('|', $pageArray->implode('|', 'id'))),
		]);

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

		$type = is_object($value) ? get_class($value) : gettype($value);

		// Einzelwert (z. B. bei maxItems=1 kann ProcessWire hier eine ID liefern)
		if(is_int($value) || (is_string($value) && ctype_digit($value))) {
			$id = (int) $value;
			if($id > 0) {
				$p = $this->wire->pages->get("id=$id, template=" . MediaManagerAPI::ITEM_TEMPLATE . ", include=all");
				if($p && $p->id) {
					$ids = [$p->id];
				}
			}
			$this->debugLog('FT-MM sleepValue (single)', [
				'pageId' => (int) $page->id,
				'field'  => (string) $field->name,
				'type'   => $type,
				'idIn'   => (int) $value,
				'idsOut' => $ids,
			]);
			return $ids;
		}

		if($value instanceof Page) {
			$value = $this->wire->pages->newPageArray()->add($value);
		}
		if($value instanceof PageArray) {
			foreach($value as $item) {
				// Nur gültige medienmanager-item Pages akzeptieren
				if($item instanceof Page && $item->id && $item->template->name === MediaManagerAPI::ITEM_TEMPLATE) {
					$ids[] = $item->id;
				}
			}
			$ids = $this->applyMaxItems($field, $ids);
			$this->debugLog('FT-MM sleepValue (PageArray)', [
				'pageId' => (int) $page->id,
				'field'  => (string) $field->name,
				'type'   => $type,
				'idsOut' => $ids,
			]);
			return $ids;
		}

		// Fallback: ProcessWire kann bei Multi-Feldern auch ein Array von IDs liefern
		if(is_array($value)) {
			$raw = $this->applyMaxItems($field, $this->normalizeIds($value));
			if(!count($raw)) return $ids;

			// include=all, weil Medien-Items typischerweise hidden sind
			$items = $this->wire->pages->find(
				"id=" . implode('|', $raw) . ", template=" . MediaManagerAPI::ITEM_TEMPLATE . ", include=all"
			);

			foreach($raw as $id) {
				$p = $items->get("id=$id");
				if($p && $p->id) $ids[] = $p->id;
			}
			$ids = $this->applyMaxItems($field, $ids);

			$this->debugLog('FT-MM sleepValue (array)', [
				'pageId'   => (int) $page->id,
				'field'    => (string) $field->name,
				'type'     => $type,
				'idsIn'    => $raw,
				'idsOut'   => $ids,
				'foundCnt' => $items->count(),
			]);
		}

		if(!is_array($value) && !$value instanceof PageArray) {
			$this->debugLog('FT-MM sleepValue (unhandled)', [
				'pageId' => (int) $page->id,
				'field'  => (string) $field->name,
				'type'   => $type,
			]);
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
		if(is_array($value)) {
			// Wichtig: array-cast zu int wäre immer 1 und würde beim Speichern alles verlieren.
			return $this->normalizeIds($value);
		}
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
	 * Vorlagen beim Anlegen eines neuen Feldes (wie Image/Images).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function ___getFieldSetups() {
		return [
			'single_image' => [
				'title'        => $this->_('Einzelbild (wie Image) — nur Bilder, eine Datei'),
				'maxItems'     => 1,
				'allowedTypes' => ['bild'],
			],
			'multiple_images' => [
				'title'        => $this->_('Mehrere Bilder (wie Images) — nur Bilder'),
				'maxItems'     => 0,
				'allowedTypes' => ['bild'],
			],
			'single_media' => [
				'title'        => $this->_('Einzelmedium — Bild, Video oder PDF'),
				'maxItems'     => 1,
				'allowedTypes' => [],
			],
			'multiple_media' => [
				'title'        => $this->_('Mehrere Medien — freie Kombination'),
				'maxItems'     => 0,
				'allowedTypes' => [],
			],
		];
	}

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

		$fn = (string) $field->name;
		$snippets = $this->_buildTemplateSnippetsMarkup($fn);

		/** @var InputfieldMarkup $markup */
		$markup = $this->wire->modules->get('InputfieldMarkup');
		$markup->attr('name', '_mm_template_snippets');
		$markup->label = $this->_('PHP-Beispiele (Frontend) — SEO & Barrierefreiheit');
		$markup->collapsed = Inputfield::collapsedYes;
		$markup->textFormat = Inputfield::textFormatNone;
		$markup->entityEncodeText = false;
		$markup->markupText = $snippets;
		$wrapper->add($markup);

		return $wrapper;
	}

	/**
	 * HTML mit Code-Snippets für Entwickler (Feldname wird eingesetzt).
	 */
	protected function _buildTemplateSnippetsMarkup(string $fieldName): string {
		$fn = $this->wire->sanitizer->fieldName($fieldName);
		if($fn === '') {
			$fn = 'fieldname';
		}

		$singleCode = implode("\n", [
			'<?php namespace ProcessWire;',
			"require_once \$config->paths->site . 'modules/bsProcessMedienManager/MediaManagerAPI.php';",
			'$mm = new MediaManagerAPI($wire);',
			'$media = $page->' . $fn . '->first();',
			'if($media && $media->id && $mm->hasRenderableImage($media)) {',
			'	$img = $mm->getPrimaryPageimage($media);',
			"	\$sized = \$img->size(1200, 1200, ['cropping' => false, 'upscaling' => false]);",
			'	$alt = $mm->getAccessibleLabel($media);',
			"	echo '<figure class=\"mm-figure\" role=\"group\">';",
			"	echo '<img src=\"' . \$sized->url . '\" width=\"' . (int) \$sized->width . '\" height=\"' . (int) \$sized->height . '\" alt=\"' . \$wire->sanitizer->entities(\$alt) . '\" loading=\"lazy\" decoding=\"async\">';",
			'	$cap = $mm->getCaption($media);',
			"	if(\$cap !== '') echo '<figcaption class=\"mm-figcaption\">' . \$wire->sanitizer->entities(\$cap) . '</figcaption>';",
			"	echo '</figure>';",
			'}',
		]);

		$multiCode = implode("\n", [
			'<?php namespace ProcessWire;',
			"require_once \$config->paths->site . 'modules/bsProcessMedienManager/MediaManagerAPI.php';",
			'$mm = new MediaManagerAPI($wire);',
			'foreach($page->' . $fn . ' as $media) {',
			'	if(!$media->id || !$mm->hasRenderableImage($media)) continue;',
			'	$img = $mm->getPrimaryPageimage($media);',
			"	\$sized = \$img->size(800, 800, ['cropping' => false, 'upscaling' => false]);",
			'	$alt = $mm->getAccessibleLabel($media);',
			"	echo '<img class=\"mm-gallery-img\" src=\"' . \$sized->url . '\" width=\"' . (int) \$sized->width . '\" height=\"' . (int) \$sized->height . '\" alt=\"' . \$wire->sanitizer->entities(\$alt) . '\" loading=\"lazy\" decoding=\"async\">';",
			'}',
		]);

		$h = static function(string $s): string {
			return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		};

		$out = '<div class="mm-field-snippets">';
		$out .= '<p class="description">' . $this->_('Der Feldwert ist ein <strong>PageArray</strong> von <code>medienmanager-item</code>-Seiten. Für Alternativtext: <code>getAccessibleLabel()</code> (mm_alt oder Titel). Für <code>&lt;figcaption&gt;</code>: <code>getCaption()</code> (mm_caption).') . '</p>';

		$out .= '<p class="detail">' . $this->_('Ein Bild (maxItems = 1 oder erste Auswahl):') . '</p>';
		$out .= '<pre class="pw-ui-code" style="white-space:pre-wrap;">' . $h($singleCode) . '</pre>';

		$out .= '<p class="detail">' . $this->_('Mehrere Bilder (Schleife):') . '</p>';
		$out .= '<pre class="pw-ui-code" style="white-space:pre-wrap;">' . $h($multiCode) . '</pre>';

		$out .= '<p class="notes"><strong>' . $this->_('Edge Cases') . '</strong>: ';
		$out .= $this->_('Nach Löschen eines Medien-Items entfällt die Referenz beim nächsten Speichern der Seite; in Schleifen sind nur noch gültige Pages enthalten. Video/PDF: statt Pageimage <code>getPublicFileUrl()</code> nutzen. Öffentliche Dateien unterliegen den üblichen PW-Datei-URLs.') . '</p>';
		$out .= '</div>';

		return $out;
	}
}

require_once __DIR__ . '/MedienManagerField.php';