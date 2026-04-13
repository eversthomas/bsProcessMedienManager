<?php namespace ProcessWire;

/**
 * InputfieldMedienManager
 *
 * Modal-basierter Inputfield für die Auswahl von Medien aus dem Medien Manager.
 * Rendert eine Thumbnail-Leiste der aktuell gewählten Items sowie einen Button
 * zum Öffnen des Auswahl-Modals. Die Auswahl kommuniziert per AJAX mit
 * bsProcessMedienManager.
 *
 * @copyright 2026 bsProcessMedienManager
 */
class InputfieldMedienManager extends Inputfield implements InputfieldHasArrayValue {

	/**
	 * Modul-Metadaten für ProcessWire.
	 */
	public static function getModuleInfo(): array {
		return [
			'title'    => 'Medien Manager Inputfield',
			'version'  => 1,
			'summary'  => 'Modal-Picker zur Auswahl von Medien aus dem Medien Manager.',
			'requires' => ['bsProcessMedienManager'],
		];
	}

	/**
	 * Standard-Eigenschaften initialisieren.
	 */
	public function init(): void {
		parent::init();

		// Standard-Wert: keine Begrenzung, alle Typen erlaubt
		$this->set('maxItems', 0);
		$this->set('allowedTypes', []);

		// CSS und JS einreihen
		$config  = $this->wire->config;
		$moduleUrl = $config->urls->get('bsProcessMedienManager');

		$config->styles->add($moduleUrl . 'css/medienmanager-inputfield.css');
		$config->scripts->add($moduleUrl . 'js/medienmanager-inputfield.js');

		// AJAX-URL und CSRF-Token für JS bereitstellen
		$processPage = $this->wire->pages->get("template=admin, process=bsProcessMedienManager, include=all");
		$ajaxUrl     = $processPage->id ? $processPage->url . 'ajax/' : '';

		$config->js('InputfieldMedienManager', [
			'ajaxUrl'      => $ajaxUrl,
			'csrfName'     => $this->wire->session->CSRF->getTokenName(),
			'csrfValue'    => $this->wire->session->CSRF->getTokenValue(),
			'maxItems'     => (int) $this->maxItems,
			'allowedTypes' => $this->allowedTypes,
		]);
	}

	// -----------------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------------

	/**
	 * Inputfield im Edit-Modus rendern.
	 *
	 * @return string HTML
	 */
	public function ___render(): string {
		$sanitizer = $this->wire->sanitizer;
		$name      = $this->attr('name');
		$value     = $this->attr('value'); // PageArray nach wakeup
		$maxItems  = (int) $this->maxItems;

		$out = "<div class='mm-inputfield-wrap' data-name='" . $sanitizer->entities($name) . "' data-max='$maxItems'>";

		// Aktuell gewählte Items als Thumbnail-Chips
		$out .= "<div class='mm-selected-items'>";
		if($value instanceof PageArray && $value->count()) {
			foreach($value as $item) {
				$out .= $this->_renderChip($item);
			}
		}
		$out .= "</div>";

		// Hidden-Inputs für die aktuell gewählten IDs
		$out .= "<div class='mm-hidden-inputs'>";
		if($value instanceof PageArray && $value->count()) {
			foreach($value as $item) {
				$out .= "<input type='hidden' name='{$name}[]' value='{$item->id}'>";
			}
		}
		$out .= "</div>";

		// "Medien auswählen"-Button
		$out .= "<button type='button' class='mm-open-modal ui-button ui-state-default' "
			. "data-field='" . $sanitizer->entities($name) . "'>"
			. "<i class='fa fa-photo'></i> Medien auswählen"
			. "</button>";

		// Modal-Container (wird per AJAX befüllt)
		$out .= $this->_renderModal($name);

		$out .= "</div>"; // .mm-inputfield-wrap

		return $out;
	}

	/**
	 * Read-only Darstellung (z. B. in der Seiten-Übersicht).
	 *
	 * @return string HTML
	 */
	public function ___renderValue(): string {
		$value = $this->attr('value');
		if(!$value instanceof PageArray || !$value->count()) return '<em>Keine Medien ausgewählt</em>';

		$out = "<div class='mm-selected-items mm-readonly'>";
		foreach($value as $item) {
			$out .= $this->_renderChip($item, true);
		}
		$out .= "</div>";
		return $out;
	}

	// -----------------------------------------------------------------------
	// Eingabe verarbeiten
	// -----------------------------------------------------------------------

	/**
	 * Formulardaten verarbeiten und Value-Array mit validierten Page-IDs setzen.
	 *
	 * @param WireInputData $input
	 * @return $this
	 */
	public function ___processInput(WireInputData $input): self {
		$name     = $this->attr('name');
		$rawIds   = $input->$name;
		$maxItems = (int) $this->maxItems;

		if(!is_array($rawIds)) $rawIds = $rawIds ? [$rawIds] : [];

		$pageArray = $this->wire->pages->newPageArray();
		$count     = 0;

		foreach($rawIds as $rawId) {
			if($maxItems > 0 && $count >= $maxItems) break;

			$id = (int) $rawId;
			if($id <= 0) continue;

			// Sicherheitsvalidierung: muss eine gültige medienmanager-item Page sein
			$p = $this->wire->pages->get(
				"id=$id, template=" . MediaManagerAPI::ITEM_TEMPLATE . ", include=all"
			);
			if($p->id) {
				$pageArray->add($p);
				$count++;
			}
		}

		// Geändert? Dann dirty markieren
		$current = $this->attr('value');
		if(!$current instanceof PageArray || $current->implode(',', 'id') !== $pageArray->implode(',', 'id')) {
			$this->trackChange('value');
		}

		$this->attr('value', $pageArray);
		return $this;
	}

	// -----------------------------------------------------------------------
	// Konfiguration
	// -----------------------------------------------------------------------

	/**
	 * Konfigurationsfelder für das Inputfield (erscheint in Feld-Einstellungen).
	 *
	 * @param Field $field
	 * @return InputfieldWrapper
	 */
	public function ___getConfigInputfields(): InputfieldWrapper {
		$wrapper = parent::___getConfigInputfields();

		/** @var InputfieldInteger $f */
		$f = $this->wire->modules->get('InputfieldInteger');
		$f->attr('name', 'maxItems');
		$f->label       = 'Maximale Anzahl auswählbarer Medien';
		$f->description = '0 = unbegrenzt, 1 = Einzelauswahl';
		$f->attr('value', (int) ($this->maxItems ?? 0));
		$f->min         = 0;
		$wrapper->add($f);

		/** @var InputfieldCheckboxes $f2 */
		$f2 = $this->wire->modules->get('InputfieldCheckboxes');
		$f2->attr('name', 'allowedTypes');
		$f2->label   = 'Erlaubte Medientypen';
		$f2->addOption('bild', 'Bilder');
		$f2->addOption('video', 'Videos');
		$f2->addOption('pdf', 'PDFs');
		$f2->attr('value', $this->allowedTypes ?? []);
		$wrapper->add($f2);

		return $wrapper;
	}

	// -----------------------------------------------------------------------
	// Interne Hilfsmethoden
	// -----------------------------------------------------------------------

	/**
	 * Thumbnail-Chip für ein einzelnes Medien-Item rendern.
	 *
	 * @param Page $item
	 * @param bool $readonly
	 * @return string HTML
	 */
	protected function _renderChip(Page $item, bool $readonly = false): string {
		$sanitizer = $this->wire->sanitizer;
		$titel     = $sanitizer->entities($item->mm_titel ?: $item->title);

		require_once dirname(__FILE__) . '/MediaManagerAPI.php';
		$api = new MediaManagerAPI($this->wire());

		$thumbHtml = '';
		$primImg   = $api->getPrimaryPageimage($item);

		if($primImg instanceof Pageimage) {
			$thumbUrl  = $api->getThumbnailUrlForSlot($item, 'chip');
			$thumbHtml = $thumbUrl !== ''
				? "<img src='" . $sanitizer->entities($thumbUrl) . "' alt='" . $titel . "' loading='lazy'>"
				: '';
		}
		if($thumbHtml === '') {
			$typMap = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o', 'bild' => 'fa-file-image-o'];
			$typ    = $api->getTypString($item);
			$icon   = $typMap[$typ] ?? 'fa-file';
			$thumbHtml = "<span class='mm-type-icon'><i class='fa $icon'></i></span>";
		}

		$removeBtn = $readonly ? '' : "<button type='button' class='mm-remove-item' data-id='{$item->id}' title='Entfernen'>&times;</button>";

		return "<div class='mm-chip' data-id='{$item->id}'>"
			. $thumbHtml
			. "<span class='mm-chip-title'>$titel</span>"
			. $removeBtn
			. "</div>";
	}

	/**
	 * Modal-Markup rendern (wird per JS mit Inhalt gefüllt).
	 *
	 * @param string $fieldName
	 * @return string HTML
	 */
	protected function _renderModal(string $fieldName): string {
		$sanitizer = $this->wire->sanitizer;
		$fn        = $sanitizer->entities($fieldName);

		return "
		<div id='mm-modal-{$fn}' class='mm-modal' style='display:none' data-field='{$fn}'>
			<div class='mm-modal-backdrop'></div>
			<div class='mm-modal-dialog'>
				<div class='mm-modal-header'>
					<span class='mm-modal-title'><i class='fa fa-photo'></i> Medien auswählen</span>
					<button type='button' class='mm-modal-close'>&times;</button>
				</div>
				<div class='mm-modal-toolbar'>
					<div class='mm-filter-row'>
						<select class='mm-filter-typ' data-field='{$fn}'>
							<option value=''>Alle Typen</option>
							<option value='bild'>Bilder</option>
							<option value='video'>Videos</option>
							<option value='pdf'>PDFs</option>
						</select>
						<select class='mm-filter-kategorie' data-field='{$fn}'>
							<option value=''>Alle Kategorien</option>
						</select>
						<input type='search' class='mm-filter-suche' placeholder='Suchen…' data-field='{$fn}'>
					</div>
				</div>
				<div class='mm-modal-body'>
					<div class='mm-picker-content'>
						<div class='mm-loading'><i class='fa fa-spinner fa-spin'></i> Wird geladen…</div>
					</div>
				</div>
				<div class='mm-modal-footer'>
					<span class='mm-selection-count'>0 ausgewählt</span>
					<button type='button' class='mm-modal-cancel ui-button'>Abbrechen</button>
					<button type='button' class='mm-modal-confirm ui-button ui-state-default' data-field='{$fn}'>Übernehmen</button>
				</div>
			</div>
		</div>";
	}
}