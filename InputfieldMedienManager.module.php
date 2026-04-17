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
			'version'  => 2,
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

		// NOTE:
		// $config->js() landet im <head>. In Page-Edit-Forms wird init() oft erst ausgeführt,
		// nachdem der <head> bereits gerendert wurde -> JS-Config fehlt und der Picker fällt auf "./ajax/" zurück.
		// Daher wird die JS-Config zusätzlich inline in ___render() injiziert.
		$this->_queueInlineJsConfig();
	}

	/**
	 * Merkt sich, dass wir Inline-JS-Config ausgeben sollen.
	 * (Wir können nicht zuverlässig erkennen, ob $config->js bereits gerendert wurde.)
	 */
	protected function _queueInlineJsConfig(): void {
		$this->set('_mmInlineJsConfig', true);
	}

	/**
	 * Inline-JS-Config für den Picker (ajaxUrl/CSRF), einmal pro Request.
	 */
	protected function _injectInlineJsConfig(): string {
		static $done = false;
		if($done) return '';
		$done = true;

		$adminBase = rtrim($this->wire->config->urls->admin, '/');
		$ajaxUrl   = $adminBase . '/setup/medienmanager/ajax/';
		$csrfName  = $this->wire->session->CSRF->getTokenName();
		$csrfValue = $this->wire->session->CSRF->getTokenValue();

		return "<script>window.InputfieldMedienManager=" . json_encode([
			'ajaxUrl'    => $ajaxUrl,
			'csrfName'   => $csrfName,
			'csrfValue'  => $csrfValue,
		]) . ";</script>\n";
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
		
		// In ProcessPageEdit ist der zuverlässigste Wert der am Page-Objekt gespeicherte Feldwert.
		// attr('value') kann nach einem Save/Redirect roh/leer sein, obwohl die DB korrekt ist.
		$rawValue = $this->attr('value');
		$hp = $this->get('hasPage');
		$hf = $this->get('hasField');
		if($hp instanceof Page && $hf instanceof Field) {
			$rawValue = $hp->get((string) $hf->name);
		}
		
		$value     = $this->_coerceValueToPageArray($rawValue);
		$maxItems  = (int) $this->maxItems;

		$allowed = $this->allowedTypes;
		if(!is_array($allowed)) {
			$allowed = [];
		}
		$allowedJson = htmlspecialchars(json_encode(array_values($allowed), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
		$onlyBild      = count($allowed) === 1 && ($allowed[0] ?? '') === 'bild';

		$out = '';
		if($this->get('_mmInlineJsConfig')) {
			$out .= $this->_injectInlineJsConfig();
		}

		$out .= "<div class='mm-inputfield-wrap' data-name='" . $sanitizer->entities($name) . "' data-max='$maxItems' data-allowed-types='$allowedJson'>";

		// Aktuell gewählte Items als Thumbnail-Chips
		$out .= "<div class='mm-selected-items'>";
		$meta = [];
		if($value->count()) {
			foreach($value as $item) {
				$out .= $this->_renderChip($item);
				$t = (string) ($item->mm_titel ?: $item->title);
				$t = trim($t) !== '' ? $t : ('ID ' . (int) $item->id);
				$meta[] = $sanitizer->entities($t) . " <span class='mm-selection-id'>(#" . (int) $item->id . ")</span>";
			}
		}
		$out .= "</div>";
		
		// Textuelle Zusammenfassung, damit auch ohne Thumbs klar ist was gespeichert ist
		if(count($meta)) {
			$out .= "<div class='mm-selection-meta'><strong>" . $sanitizer->entities(count($meta) . ' ausgewählt') . ":</strong> " . implode(', ', $meta) . "</div>";
		}

		// Hidden-Inputs für die aktuell gewählten IDs
		$out .= "<div class='mm-hidden-inputs'>";
		if($value->count()) {
			foreach($value as $item) {
				$out .= "<input type='hidden' name='{$name}[]' value='{$item->id}'>";
			}
		}
		$out .= "</div>";

		$btnLabel = $onlyBild ? 'Bild(er) auswählen' : 'Medien auswählen';

		// "Medien auswählen"-Button
		$out .= "<button type='button' class='mm-open-modal ui-button ui-state-default' "
			. "data-field='" . $sanitizer->entities($name) . "'>"
			. "<i class='fa fa-photo'></i> " . $sanitizer->entities($btnLabel)
			. "</button>";

		// Modal-Container (wird per AJAX befüllt)
		$out .= $this->_renderModal($name, $allowed);

		$out .= "</div>"; // .mm-inputfield-wrap

		return $out;
	}

	/**
	 * Read-only Darstellung (z. B. in der Seiten-Übersicht).
	 *
	 * @return string HTML
	 */
	public function ___renderValue(): string {
		$value = $this->_coerceValueToPageArray($this->attr('value'));
		if(!$value->count()) return '<em>Keine Medien ausgewählt</em>';

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
		require_once dirname(__FILE__) . '/MediaManagerAPI.php';
		$name     = $this->attr('name');
		$rawIds   = $input->$name;
		$maxItems = (int) $this->maxItems;

		if(!is_array($rawIds)) $rawIds = $rawIds ? [$rawIds] : [];

		$pageArray = $this->wire->pages->newPageArray();
		$count     = 0;

		$isSu = $this->wire->user && $this->wire->user->isSuperuser();
		if($isSu) {
			$this->wire->log->save('medienmanager', 'IF-MM processInput start | ' . json_encode([
				'pageId'   => (int) ($this->hasPage instanceof Page ? $this->hasPage->id : 0),
				'field'    => (string) $name,
				'maxItems' => (int) $maxItems,
				'rawIds'   => array_values($rawIds),
				'allowed'  => is_array($this->allowedTypes) ? array_values($this->allowedTypes) : [],
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		foreach($rawIds as $rawId) {
			if($maxItems > 0 && $count >= $maxItems) break;

			$id = (int) $rawId;
			if($id <= 0) continue;

			// Sicherheitsvalidierung: muss eine gültige medienmanager-item Page sein
			$p = $this->wire->pages->get(
				"id=$id, template=" . MediaManagerAPI::ITEM_TEMPLATE . ", include=all"
			);
			if($p->id && $this->_itemMatchesAllowedTypes($p)) {
				$pageArray->add($p);
				$count++;
			}
		}

		if($isSu) {
			$this->wire->log->save('medienmanager', 'IF-MM processInput end | ' . json_encode([
				'pageId'    => (int) ($this->hasPage instanceof Page ? $this->hasPage->id : 0),
				'field'     => (string) $name,
				'idsOut'    => array_map('intval', explode('|', $pageArray->implode('|', 'id'))),
				'countOut'  => $pageArray->count(),
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
	 * Prüft mm_typ gegen die im Feld erlaubten Typen (leer = alle).
	 */
	protected function _itemMatchesAllowedTypes(Page $page): bool {
		$allowed = $this->allowedTypes;
		if(!is_array($allowed) || !count($allowed)) {
			return true;
		}
		require_once dirname(__FILE__) . '/MediaManagerAPI.php';
		$api = new MediaManagerAPI($this->wire());

		return in_array($api->getTypString($page), $allowed, true);
	}

	/**
	 * ProcessWire kann dem Inputfield je nach Kontext rohe Werte geben (IDs als array/string/int),
	 * obwohl der Feldwert zur Laufzeit ein PageArray ist. Damit Chips/Thumbs trotzdem zuverlässig
	 * sichtbar sind, normalisieren wir hier in ein PageArray.
	 *
	 * @param mixed $value
	 */
	protected function _coerceValueToPageArray($value): PageArray {
		$pa = $this->wire->pages->newPageArray();

		if($value instanceof PageArray) return $value;
		if($value instanceof Page) {
			if($value->id) $pa->add($value);
			return $pa;
		}

		$ids = [];
		if(is_int($value)) {
			if($value > 0) $ids = [$value];
		} elseif(is_string($value)) {
			$s = trim($value);
			if($s !== '') {
				$parts = preg_split('/[|,\\s]+/', $s) ?: [];
				foreach($parts as $p) {
					if($p === '') continue;
					if(ctype_digit($p)) $ids[] = (int) $p;
				}
			}
		} elseif(is_array($value)) {
			foreach($value as $v) {
				if($v instanceof Page && $v->id) $ids[] = (int) $v->id;
				elseif(is_int($v) && $v > 0) $ids[] = $v;
				elseif(is_string($v) && ctype_digit($v)) $ids[] = (int) $v;
			}
		}

		$ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
		if(!count($ids)) return $pa;

		require_once dirname(__FILE__) . '/MediaManagerAPI.php';
		$items = $this->wire->pages->find(
			"id=" . implode('|', $ids) . ", template=" . MediaManagerAPI::ITEM_TEMPLATE . ", include=all"
		);
		foreach($ids as $id) {
			$p = $items->get("id=$id");
			if($p && $p->id) $pa->add($p);
		}

		return $pa;
	}

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
	 * @param list<string> $allowedTypes leer = alle Typen
	 */
	protected function _renderModal(string $fieldName, array $allowedTypes = []): string {
		$sanitizer = $this->wire->sanitizer;
		$fn        = $sanitizer->entities($fieldName);

		$labels = [
			'bild'  => 'Bilder',
			'video' => 'Videos',
			'pdf'   => 'PDFs',
		];

		$typOpts = '';
		if(!count($allowedTypes)) {
			$typOpts .= "<option value=''>" . $sanitizer->entities('Alle Typen') . "</option>";
			foreach($labels as $val => $lab) {
				$typOpts .= "<option value='" . $sanitizer->entities($val) . "'>" . $sanitizer->entities($lab) . "</option>";
			}
		} elseif(count($allowedTypes) === 1) {
			foreach($allowedTypes as $val) {
				$val = $sanitizer->name((string) $val);
				if($val === '' || !isset($labels[$val])) {
					continue;
				}
				$typOpts .= "<option value='" . $sanitizer->entities($val) . "' selected>" . $sanitizer->entities($labels[$val]) . "</option>";
			}
			if($typOpts === '') {
				$typOpts = "<option value=''>" . $sanitizer->entities('Alle Typen') . "</option>";
				foreach($labels as $val => $lab) {
					$typOpts .= "<option value='" . $sanitizer->entities($val) . "'>" . $sanitizer->entities($lab) . "</option>";
				}
			}
		} else {
			$typOpts .= "<option value=''>" . $sanitizer->entities('Alle erlaubten Typen') . "</option>";
			foreach($allowedTypes as $val) {
				$val = $sanitizer->name((string) $val);
				if($val === '' || !isset($labels[$val])) {
					continue;
				}
				$typOpts .= "<option value='" . $sanitizer->entities($val) . "'>" . $sanitizer->entities($labels[$val]) . "</option>";
			}
		}

		$onlyBild = count($allowedTypes) === 1 && ($allowedTypes[0] ?? '') === 'bild';
		$modalTitle = $onlyBild ? 'Bilder auswählen' : 'Medien auswählen';
		$typHide    = count($allowedTypes) === 1 ? " style='display:none'" : '';

		return "
		<div id='mm-modal-{$fn}' class='mm-modal' style='display:none' data-field='{$fn}'>
			<div class='mm-modal-backdrop'></div>
			<div class='mm-modal-dialog'>
				<div class='mm-modal-header'>
					<span class='mm-modal-title'><i class='fa fa-photo'></i> " . $sanitizer->entities($modalTitle) . "</span>
					<button type='button' class='mm-modal-close'>&times;</button>
				</div>
				<div class='mm-modal-toolbar'>
					<div class='mm-filter-row'>
						<span class='mm-filter-typ-wrap'" . $typHide . ">
						<select class='mm-filter-typ' data-field='{$fn}'>
							{$typOpts}
						</select>
						</span>
						<select class='mm-filter-kategorie' data-field='{$fn}'>
							<option value=''>" . $sanitizer->entities('Alle Kategorien') . "</option>
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