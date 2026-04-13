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