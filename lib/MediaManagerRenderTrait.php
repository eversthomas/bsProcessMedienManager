<?php namespace ProcessWire;

/**
 * Admin-HTML (Grid, Liste, Formulare, Picker) für den Medien Manager.
 *
 * @see bsProcessMedienManager::___execute()
 */
trait MediaManagerRenderTrait {
	/**
	 * GET-Query für Medien-Übersicht (Filter, Start, Ansicht).
	 */
	protected function _buildMedienQueryUrl(array $filters, int $start, string $view): string {
		$sanitizer = $this->wire->sanitizer;
		$parts     = ['view' => $view === 'list' ? 'list' : 'grid'];
		if(!empty($filters['typ'])) {
			$parts['typ'] = $sanitizer->name((string) $filters['typ']);
		}
		if(!empty($filters['kategorie_id'])) {
			$parts['kategorie_id'] = (int) $filters['kategorie_id'];
		}
		if(!empty($filters['q'])) {
			$parts['q'] = (string) $filters['q'];
		}
		if($start > 0) {
			$parts['start'] = $start;
		}
		return './?' . http_build_query($parts);
	}

	protected function _renderGrid(PageArray $items, PageArray $kategorien, array $filters, int $start, int $total, string $view = 'grid'): string {
		$sanitizer = $this->wire->sanitizer;
		$csrfName  = $this->wire->session->CSRF->getTokenName();
		$csrfVal   = $this->wire->session->CSRF->getTokenValue();

		$urlGrid = $this->_buildMedienQueryUrl($filters, $start, 'grid');
		$urlList = $this->_buildMedienQueryUrl($filters, $start, 'list');
		$gridAct = $view === 'grid' ? ' uk-active' : '';
		$listAct = $view === 'list' ? ' uk-active' : '';

		$out = "<div class='mm-admin-wrap'>";

		$bulkKat = "<option value='0'>— Keine —</option>";
		foreach($kategorien as $kat) {
			$bulkKat .= "<option value='{$kat->id}'>" . $sanitizer->entities($kat->title) . "</option>";
		}

		$pageItemCount = $items->count();

		$out .= "
		<div class='uk-card uk-card-default uk-card-body uk-padding-remove uk-margin-bottom mm-toolbar-bulk-card'>
			<div class='mm-toolbar uk-padding-small'>
				<div class='mm-toolbar-left'>
					<button type='button' class='uk-button uk-button-primary uk-button-small' id='mm-upload-btn'>
						<i class='fa fa-upload'></i> Medien hochladen
					</button>
					<a href='./kategorien/' class='uk-button uk-button-default uk-button-small'>
						<i class='fa fa-folder'></i> Kategorien
					</a>
					<div class='uk-inline mm-view-toggle-wrap'>
						<div class='uk-button-group mm-view-toggle' role='group' aria-label='Ansicht'>
							<a href='" . $sanitizer->entities($urlGrid) . "' class='uk-button uk-button-default uk-button-small{$gridAct}' title='Kachelansicht'><i class='fa fa-th-large' aria-hidden='true'></i> Raster</a>
							<a href='" . $sanitizer->entities($urlList) . "' class='uk-button uk-button-default uk-button-small{$listAct}' title='Tabellenansicht'><i class='fa fa-list' aria-hidden='true'></i> Liste</a>
						</div>
					</div>
				</div>
				<div class='mm-toolbar-right'>
					<form class='mm-filter-form' method='get' action='./'>
						<input type='hidden' name='view' value='" . $sanitizer->entities($view) . "'>
						<select name='typ' class='uk-select uk-form-small mm-filter-select' onchange='this.form.submit()'>
							<option value=''" . ($filters['typ'] ? '' : ' selected') . ">Alle Typen</option>
							<option value='bild'" . ($filters['typ'] === 'bild' ? ' selected' : '') . ">Bilder</option>
							<option value='video'" . ($filters['typ'] === 'video' ? ' selected' : '') . ">Videos</option>
							<option value='pdf'" . ($filters['typ'] === 'pdf' ? ' selected' : '') . ">PDFs</option>
						</select>
						<select name='kategorie_id' class='uk-select uk-form-small mm-filter-select' onchange='this.form.submit()'>";

		$out .= "<option value=''>Alle Kategorien</option>";
		foreach($kategorien as $kat) {
			$sel  = ((int) $filters['kategorie_id'] === $kat->id) ? ' selected' : '';
			$out .= "<option value='{$kat->id}'{$sel}>" . $sanitizer->entities($kat->title) . "</option>";
		}

		$qVal = $sanitizer->entities($filters['q'] ?? '');
		$out .= "
						</select>
						<input type='search' name='q' value='{$qVal}' placeholder='Suchen…' class='uk-input uk-form-small mm-search-input'>
						<button type='submit' class='uk-button uk-button-default uk-button-small'><i class='fa fa-search'></i></button>
					</form>
				</div>
			</div>
			<hr class='uk-margin-remove mm-toolbar-bulk-divider'>
			<div class='uk-padding-small mm-bulk-bar' id='mm-bulk-bar' data-mm-view='" . $sanitizer->entities($view) . "' data-page-total='{$pageItemCount}'>
				<div class='uk-flex uk-flex-middle uk-flex-wrap'>
					<label class='mm-bulk-select-all uk-margin-small-right'><input type='checkbox' id='mm-select-all' title='Alle auf dieser Seite'> <span>Alle</span></label>
					<span class='mm-bulk-count uk-text-meta uk-margin-small-right' id='mm-bulk-count'>0 von {$pageItemCount} ausgewählt</span>
					<select id='mm-bulk-kategorie' class='uk-select uk-form-small mm-bulk-select uk-margin-small-right'>{$bulkKat}</select>
					<button type='button' id='mm-bulk-apply-cat' class='uk-button uk-button-default uk-button-small uk-margin-small-right'><i class='fa fa-folder'></i> Kategorie setzen</button>
					<button type='button' id='mm-bulk-webp' class='uk-button uk-button-default uk-button-small uk-margin-small-right' title='Nur Bilder: .webp neben dem Original (ProcessWire)'><i class='fa fa-file-image-o'></i> WebP erzeugen</button>
					<button type='button' id='mm-bulk-delete' class='uk-button uk-button-danger uk-button-small'><i class='fa fa-trash'></i> Auswahl löschen</button>
				</div>
			</div>
		</div>";

		if($view === 'list') {
			$out .= "<div class='mm-list-view' id='mm-list-view'>";
			$out .= $this->_renderListView($items, $csrfName, $csrfVal);
			$out .= "</div>";
		} else {
			$out .= "<div class='mm-grid' id='mm-grid'>";
			if($items->count()) {
				foreach($items as $item) {
					$out .= $this->_renderGridItem($item, $csrfName, $csrfVal);
				}
			} else {
				$out .= "<p class='mm-empty'>Keine Medien gefunden.</p>";
			}
			$out .= "</div>";
		}

		if($total > $this->limit) {
			$out .= $this->_renderPagination($start, $total, $filters, $view);
		}

		$out .= $this->_renderUploadForm($kategorien, $csrfName, $csrfVal);
		$out .= "</div>";

		return $out;
	}

	protected function _renderGridItem(Page $item, string $csrfName, string $csrfVal): string {
		$sanitizer = $this->wire->sanitizer;
		$typ       = $this->api()->getTypString($item);
		$basename  = $this->api()->getPrimaryBasename($item);
		$titelRaw  = trim((string) ($item->mm_titel ?: $item->title));
		$fileLabel = $basename !== '' ? $basename : $titelRaw;
		$fileLabel = $sanitizer->entities($fileLabel);

		$imgAlt = $fileLabel;
		if($item->hasField('mm_alt') && (string) $item->mm_alt !== '') {
			$imgAlt = $sanitizer->entities($item->mm_alt);
		}

		$thumbUrl  = $this->api()->getThumbnailUrlForSlot($item, 'grid');
		$pubUrl    = $this->api()->getPublicFileUrl($item);
		$pubAttr   = $pubUrl !== '' ? htmlspecialchars($pubUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

		$titleSecondary = '';
		if($titelRaw !== '' && $basename !== '' && $titelRaw !== $basename) {
			$titleSecondary = '<span class="mm-grid-title-secondary uk-text-meta">' . $sanitizer->entities($titelRaw) . '</span>';
		}

		$captionLine = '';
		if($item->hasField('mm_caption') && (string) $item->mm_caption !== '') {
			$cap = (string) $item->mm_caption;
			$captionLine = '<span class="mm-grid-caption" title="' . htmlspecialchars($cap, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
				. $sanitizer->entities($cap) . '</span>';
		}

		$dim     = $this->api()->getPrimaryDimensionsStr($item);
		$sizeStr = $this->api()->getPrimaryFilesizeStr($item);
		$metaBits = [];
		if($dim !== '') {
			$metaBits[] = $dim;
		}
		if($sizeStr !== '') {
			$metaBits[] = $sizeStr;
		}
		$metaBits[] = $typ;
		$metaLine   = '<span class="mm-grid-meta uk-text-meta">' . $sanitizer->entities(implode(' · ', $metaBits)) . '</span>';

		if($thumbUrl) {
			$thumbInner = "<img class='mm-grid-img' src='" . $sanitizer->entities($thumbUrl) . "' alt='" . $imgAlt . "' loading='lazy'>";
		} else {
			$iconMap    = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o'];
			$icon       = $iconMap[$typ] ?? 'fa-file';
			$thumbInner = "<span class='mm-grid-icon'><i class='fa $icon'></i></span>";
		}

		$copyBtn = $pubAttr !== ''
			? "<button type='button' class='mm-btn-copy-url' data-url='" . $pubAttr . "' title='Öffentliche URL kopieren'><i class='fa fa-link'></i></button>"
			: '';

		$actionsInner = "<a href='./edit/?id={$item->id}' class='mm-btn-edit' title='Bearbeiten'><i class='fa fa-pencil'></i></a>"
			. ($this->api()->getPrimaryPageimage($item) ? "<a href='./imageedit/?id={$item->id}' class='mm-btn-imageedit' title='Bild bearbeiten'><i class='fa fa-crop'></i></a>" : '')
			. $copyBtn
			. "<button type='button' class='mm-btn-duplicate' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "' title='Duplizieren'><i class='fa fa-files-o'></i></button>"
			. "<button type='button' class='mm-btn-delete' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "' title='Löschen'><i class='fa fa-trash'></i></button>";

		return "<div class='mm-grid-item uk-transition-toggle' tabindex='0' data-id='{$item->id}' data-typ='" . $sanitizer->entities($typ) . "'>
			<label class='mm-grid-select'>
				<input type='checkbox' class='mm-bulk-cb' value='{$item->id}' aria-label='Auswahl'>
			</label>
			<div class='mm-grid-thumb mm-grid-thumb-cover'>
				{$thumbInner}
				<div class='mm-grid-overlay'>
					<div class='mm-grid-actions-inner'>{$actionsInner}</div>
				</div>
			</div>
			<div class='mm-grid-info'>
				<span class='mm-grid-filename'>{$fileLabel}</span>
				{$titleSecondary}
				{$captionLine}
				{$metaLine}
			</div>
		</div>";
	}

	protected function _renderListView(PageArray $items, string $csrfName, string $csrfVal): string {
		if(!$items->count()) {
			return "<p class='mm-empty'>Keine Medien gefunden.</p>";
		}
		$sanitizer = $this->wire->sanitizer;
		$out       = "<div class='uk-overflow-auto'><table class='uk-table uk-table-hover uk-table-divider uk-table-middle uk-table-small mm-list-table'>";
		$out      .= '<thead><tr>
			<th class="uk-table-shrink"></th>
			<th class="uk-table-shrink"></th>
			<th>Datei</th>
			<th class="uk-width-small uk-text-nowrap">Maße</th>
			<th class="uk-width-small">Größe</th>
			<th class="uk-width-small">Typ</th>
			<th class="uk-table-shrink"></th>
		</tr></thead><tbody>';
		foreach($items as $item) {
			$out .= $this->_renderListRow($item, $csrfName, $csrfVal);
		}
		$out .= '</tbody></table></div>';
		return $out;
	}

	protected function _renderListRow(Page $item, string $csrfName, string $csrfVal): string {
		$sanitizer = $this->wire->sanitizer;
		$typ       = $this->api()->getTypString($item);
		$basename  = $this->api()->getPrimaryBasename($item);
		$titelRaw  = trim((string) ($item->mm_titel ?: $item->title));
		$fileLabel = $basename !== '' ? $basename : $titelRaw;
		$fileEsc   = $sanitizer->entities($fileLabel);
		$subLine   = '';
		if($titelRaw !== '' && $basename !== '' && $titelRaw !== $basename) {
			$subLine = '<div class="uk-text-meta">' . $sanitizer->entities($titelRaw) . '</div>';
		}
		$thumbUrl = $this->api()->getThumbnailUrlForSlot($item, 'grid');
		if($thumbUrl) {
			$thumb = "<img class='mm-list-thumb' src='" . $sanitizer->entities($thumbUrl) . "' alt='' loading='lazy'>";
		} else {
			$iconMap = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o'];
			$icon    = $iconMap[$typ] ?? 'fa-file';
			$thumb   = "<span class='mm-list-icon'><i class='fa $icon'></i></span>";
		}
		$dim     = $this->api()->getPrimaryDimensionsStr($item);
		$sizeStr = $this->api()->getPrimaryFilesizeStr($item);
		$dimEsc  = $dim !== '' ? $sanitizer->entities($dim) : '—';
		$sizeEsc = $sizeStr !== '' ? $sanitizer->entities($sizeStr) : '—';

		$pubUrl  = $this->api()->getPublicFileUrl($item);
		$pubAttr = $pubUrl !== '' ? htmlspecialchars($pubUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
		$copyBtn = $pubAttr !== ''
			? "<button type='button' class='uk-icon-link mm-btn-copy-url uk-margin-small-right' data-url='" . $pubAttr . "' title='URL kopieren'><i class='fa fa-link'></i></button>"
			: '';

		return "<tr class='mm-list-row' data-id='{$item->id}' data-typ='" . $sanitizer->entities($typ) . "'>
			<td><label class='mm-list-cb-wrap'><input type='checkbox' class='mm-bulk-cb' value='{$item->id}' aria-label='Auswahl'></label></td>
			<td class='mm-list-thumb-cell'>{$thumb}</td>
			<td><div class='mm-list-file'>{$fileEsc}</div>{$subLine}</td>
			<td>{$dimEsc}</td>
			<td>{$sizeEsc}</td>
			<td><span class='uk-label uk-label-default'>" . $sanitizer->entities($typ) . "</span></td>
			<td class='uk-text-nowrap'>
				<a href='./edit/?id={$item->id}' class='uk-icon-link uk-margin-small-right' title='Bearbeiten'><i class='fa fa-pencil'></i></a>"
				. ($this->api()->getPrimaryPageimage($item) ? "<a href='./imageedit/?id={$item->id}' class='uk-icon-link uk-margin-small-right' title='Bild bearbeiten'><i class='fa fa-crop'></i></a>" : '')
				. $copyBtn
				. "<button type='button' class='uk-icon-link mm-btn-duplicate uk-margin-small-right' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "' title='Duplizieren'><i class='fa fa-files-o'></i></button>"
				. "<button type='button' class='uk-icon-link mm-btn-delete' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "' title='Löschen'><i class='fa fa-trash'></i></button>
			</td>
		</tr>";
	}

	protected function _renderPagination(int $start, int $total, array $filters, string $view = 'grid'): string {
		$pages     = (int) ceil($total / $this->limit);
		$current   = (int) floor($start / $this->limit);
		$sanitizer = $this->wire->sanitizer;

		$out = "<nav class='mm-pagination uk-margin'>";
		for($i = 0; $i < $pages; $i++) {
			$s      = $i * $this->limit;
			$active = $i === $current ? ' class="mm-page-active"' : '';
			$href   = $sanitizer->entities($this->_buildMedienQueryUrl($filters, $s, $view));
			$out   .= "<a href='{$href}'{$active}>" . ($i + 1) . "</a>";
		}
		$out .= "</nav>";
		return $out;
	}

	protected function _renderUploadForm(PageArray $kategorien, string $csrfName, string $csrfVal): string {
		$sanitizer  = $this->wire->sanitizer;
		$katOptions = "<option value=''>Keine Kategorie</option>";
		foreach($kategorien as $kat) {
			$katOptions .= "<option value='{$kat->id}'>" . $sanitizer->entities($kat->title) . "</option>";
		}

		return "
		<div id='mm-upload-modal' class='mm-modal' style='display:none'>
			<div class='mm-modal-backdrop'></div>
			<div class='mm-modal-dialog'>
				<div class='mm-modal-header'>
					<span class='mm-modal-title'><i class='fa fa-upload'></i> Medien hochladen</span>
					<button type='button' class='mm-modal-close'>&times;</button>
				</div>
				<div class='mm-modal-body'>
					<form id='mm-upload-form' method='post' enctype='multipart/form-data' action='./upload/'>
						<input type='hidden' name='{$csrfName}' value='{$csrfVal}'>
						<div class='mm-upload-drop-zone' id='mm-drop-zone'>
							<i class='fa fa-cloud-upload'></i>
							<p class='mm-drop-zone-hint'>Datei(en) hier ablegen oder klicken zum Auswählen</p>
							<p class='mm-upload-hint'>Erlaubt: JPG, PNG, GIF, WEBP, MP4, MOV, PDF — mehrere Dateien möglich</p>
							<input type='file' name='mm_datei[]' id='mm-file-input' multiple accept='.jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.pdf'>
						</div>
						<ul class='mm-upload-file-list' id='mm-upload-file-list' hidden></ul>
						<div class='uk-margin'>
							<label>Titel <small class='mm-titel-hint'>(bei einer Datei; bei mehreren je Dateiname)</small></label>
							<input type='text' name='titel' class='uk-input' placeholder='Automatisch aus Dateiname'>
						</div>
						<div class='uk-margin'>
							<label>Kategorie</label>
							<select name='kategorie_id' class='uk-select'>$katOptions</select>
						</div>
						<div class='uk-margin'>
							<label>Tags <small>(kommagetrennt)</small></label>
							<input type='text' name='tags' class='uk-input' placeholder='tag1, tag2'>
						</div>
					</form>
				</div>
				<div class='mm-modal-footer'>
					<div class='mm-upload-batch-result' id='mm-upload-batch-result' style='display:none'></div>
					<div class='mm-upload-progress' style='display:none'>
						<div class='mm-progress-bar'><div class='mm-progress-fill'></div></div>
						<span class='mm-progress-text'>0%</span>
					</div>
					<button type='button' class='mm-modal-cancel ui-button'>Abbrechen</button>
					<button type='button' class='mm-upload-submit ui-button ui-state-default'><i class='fa fa-upload'></i> Hochladen</button>
				</div>
			</div>
		</div>";
	}

	protected function _renderEditForm(Page $item): string {
		$sanitizer  = $this->wire->sanitizer;
		$csrfName   = $this->wire->session->CSRF->getTokenName();
		$csrfVal    = $this->wire->session->CSRF->getTokenValue();
		$kategorien = $this->api()->getKategorien();

		$vorschau = '';
		$primImg    = $this->api()->getPrimaryPageimage($item);
		$primFile = $this->api()->getPrimaryNonImageFile($item);
		if($primImg instanceof Pageimage) {
			$thumbUrl = $this->api()->getThumbnailUrlForSlot($item, 'edit');
			$vorschau = "<div class='mm-edit-preview'><img src='" . $sanitizer->entities($thumbUrl) . "' alt='' loading='lazy'></div>";
		} elseif($primFile) {
			$vorschau = "<div class='mm-edit-preview mm-edit-preview-file'><i class='fa fa-file-o'></i><span>" . $sanitizer->entities($primFile->basename) . "</span></div>";
		}

		$typ   = $this->api()->getTypString($item);
		$katId = $item->mm_kategorie ? $item->mm_kategorie->id : 0;

		$katOptions = "<option value=''>Keine Kategorie</option>";
		foreach($kategorien as $kat) {
			$sel        = $kat->id === $katId ? ' selected' : '';
			$katOptions .= "<option value='{$kat->id}'{$sel}>" . $sanitizer->entities($kat->title) . "</option>";
		}

		$typOptions = '';
		foreach(['bild' => 'Bild', 'video' => 'Video', 'pdf' => 'PDF'] as $val => $label) {
			$sel        = $typ === $val ? ' selected' : '';
			$typOptions .= "<option value='{$val}'{$sel}>{$label}</option>";
		}

		$replaceAccept = '.jpg,.jpeg,.png,.gif,.webp';
		if($typ === 'video') {
			$replaceAccept = '.mp4,.mov';
		} elseif($typ === 'pdf') {
			$replaceAccept = '.pdf';
		}

		$basename  = $this->api()->getPrimaryBasename($item);
		$titelForm = trim((string) $item->mm_titel);
		if($titelForm === '' && $basename !== '') {
			$titelForm = $basename;
		}

		$previewBox = $vorschau !== ''
			? "<div class='mm-edit-preview-sticky uk-card uk-card-default uk-card-body uk-padding-small'>{$vorschau}</div>"
			: '';

		return "
		<div class='mm-edit-wrap'>
			<div class='uk-grid-medium' uk-grid>
				<div class='uk-width-1-1 uk-width-1-3@m'>
					{$previewBox}
				</div>
				<div class='uk-width-1-1 uk-width-2-3@m'>
					<form method='post' action='../save/' class='mm-edit-form'>
						<input type='hidden' name='{$csrfName}' value='{$csrfVal}'>
						<input type='hidden' name='id' value='{$item->id}'>
						<div class='uk-margin'>
							<label class='uk-form-label'>Titel</label>
							<input type='text' name='mm_titel' value='" . $sanitizer->entities($titelForm) . "' class='uk-input' required>
						</div>
						" . ($item->hasField('mm_beschreibung') ? "<div class='uk-margin'>
							<label class='uk-form-label'>Beschreibung</label>
							<textarea name='mm_beschreibung' class='uk-textarea' rows='3'>" . $sanitizer->entities($item->mm_beschreibung) . "</textarea>
						</div>" : '') . "
						" . ($item->hasField('mm_tags') ? "<div class='uk-margin'>
							<label class='uk-form-label'>Tags <small>(kommagetrennt)</small></label>
							<input type='text' name='mm_tags' value='" . $sanitizer->entities($item->mm_tags) . "' class='uk-input'>
						</div>" : '') . "
						" . ($item->hasField('mm_alt') ? "<div class='uk-margin'>
							<label class='uk-form-label'>Alternativtext <small>(Barrierefreiheit)</small></label>
							<input type='text' name='mm_alt' value='" . $sanitizer->entities($item->mm_alt) . "' class='uk-input' placeholder='Bild für Screenreader und SEO beschreiben (was zu sehen ist)'>
						</div>" : '') . "
						" . ($item->hasField('mm_caption') ? "<div class='uk-margin'>
							<label class='uk-form-label'>Beschriftung</label>
							<input type='text' name='mm_caption' value='" . $sanitizer->entities($item->mm_caption) . "' class='uk-input' placeholder='Optional'>
						</div>" : '') . "
						<div class='uk-margin'>
							<label class='uk-form-label'>Typ</label>
							<select name='mm_typ' class='uk-select'>{$typOptions}</select>
						</div>
						<div class='uk-margin'>
							<label class='uk-form-label'>Kategorie</label>
							<select name='mm_kategorie' class='uk-select'>{$katOptions}</select>
						</div>
						<div class='mm-edit-actions'>
							<button type='submit' class='uk-button uk-button-primary'><i class='fa fa-save'></i> Speichern</button>
							<a href='../' class='uk-button uk-button-default'>Abbrechen</a>"
							. ($this->api()->getPrimaryPageimage($item) ? "<a href='../imageedit/?id={$item->id}' class='uk-button uk-button-default'><i class='fa fa-crop'></i> Bild bearbeiten</a>" : '')
							. "
							<button type='button' class='mm-btn-delete-single uk-button uk-button-danger' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "'>
								<i class='fa fa-trash'></i> Löschen
							</button>
						</div>
					</form>
					<div class='mm-edit-replace uk-margin'>
						<label class='uk-form-label'>Datei ersetzen</label>
						<p class='mm-hint'>Gleiche Dateiendung wie die aktuelle Datei. Verknüpfungen im Frontend bleiben gültig.</p>
						<form method='post' action='../replace/' enctype='multipart/form-data' class='mm-replace-form'>
							<input type='hidden' name='{$csrfName}' value='{$csrfVal}'>
							<input type='hidden' name='id' value='{$item->id}'>
							<input type='file' name='mm_ersatz' class='uk-input' accept='{$replaceAccept}' required>
							<button type='submit' class='uk-button uk-button-default'><i class='fa fa-refresh'></i> Datei ersetzen</button>
						</form>
					</div>
				</div>
			</div>
		</div>";
	}

	protected function _renderImageEditor(Page $item, Pageimage $bild): string {
		$sanitizer = $this->wire->sanitizer;
		$csrfName  = $this->wire->session->CSRF->getTokenName();
		$csrfVal   = $this->wire->session->CSRF->getTokenValue();

		return "
		<div class='mm-imageedit-wrap'>
			<div class='mm-imageedit-canvas'>
				<img id='mm-edit-img' src='" . $sanitizer->entities($bild->url) . "' alt=''>
			</div>
			<div class='mm-imageedit-toolbar'>
				<div class='mm-imageedit-rotate'>
					<p class='mm-imageedit-section-title'>Drehen</p>
					<p class='mm-hint'>Klick dreht die <strong>Originaldatei</strong> (nicht nur die Vorschau).</p>
					<button type='button' class='ui-button mm-rotate-btn' data-degrees='-90' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "'>
						<i class='fa fa-rotate-left'></i> 90° links
					</button>
					<button type='button' class='ui-button mm-rotate-btn' data-degrees='90' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "'>
						<i class='fa fa-rotate-right'></i> 90° rechts
					</button>
					<button type='button' class='ui-button mm-rotate-btn' data-degrees='180' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "'>
						<i class='fa fa-refresh'></i> 180°
					</button>
				</div>
				<div class='mm-imageedit-resize'>
					<p class='mm-imageedit-section-title'>Größe &amp; Zuschnitt</p>
					<p class='mm-hint'>Zielmaße in Pixel eingeben, Modus wählen, dann <strong>Größe übernehmen</strong>. Die hochgeladene Datei wird <strong>dauerhaft</strong> angepasst (kein zusätzliches „Export-Bild“).</p>
					<label>Breite (px): <input type='number' id='mm-resize-w' value='{$bild->width}' min='1' class='uk-input'></label>
					<label>Höhe (px): <input type='number' id='mm-resize-h' value='{$bild->height}' min='1' class='uk-input'></label>
					<fieldset class='mm-imageedit-mode'>
						<legend>Wie soll das Bild in dieses Format passen?</legend>
						<label><input type='radio' name='mm_resize_mode' value='0' checked> <span><strong>Einpassen</strong> — gesamtes Bild sichtbar, Seitenverhältnis bleibt (Innenraum der Box, ggf. schmaler als angegeben).</span></label>
						<label><input type='radio' name='mm_resize_mode' value='1'> <span><strong>Zuschneiden</strong> — Rahmen wird komplett gefüllt, überstehende Ränder werden abgeschnitten.</span></label>
					</fieldset>
					<button type='button' class='ui-button mm-resize-btn' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "'>
						<i class='fa fa-check'></i> Größe übernehmen
					</button>
				</div>
				<div class='mm-imageedit-actions'>
					<a href='../edit/?id={$item->id}' class='ui-button'><i class='fa fa-arrow-left'></i> Zurück</a>
				</div>
			</div>
		</div>";
	}

	protected function _renderPickerGrid(PageArray $items): string {
		$sanitizer = $this->wire->sanitizer;

		if(!$items->count()) {
			return "<p class='mm-empty'>Keine Medien gefunden.</p>";
		}

		$out = "<div class='mm-picker-grid'>";
		foreach($items as $item) {
			$titel    = $sanitizer->entities($item->mm_titel ?: $item->title);
			$typ      = $this->api()->getTypString($item);
			$thumbUrl = $this->api()->getThumbnailUrlForSlot($item, 'picker');
			$imgAlt   = $titel;
			if($item->hasField('mm_alt') && (string) $item->mm_alt !== '') {
				$imgAlt = $sanitizer->entities($item->mm_alt);
			}
			$titleTip = '';
			if($item->hasField('mm_caption') && (string) $item->mm_caption !== '') {
				$titleTip = ' title="' . htmlspecialchars((string) $item->mm_caption, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
			}

			if($thumbUrl) {
				$thumb = "<img src='" . $sanitizer->entities($thumbUrl) . "' alt='" . $imgAlt . "' loading='lazy'>";
			} else {
				$iconMap = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o'];
				$icon    = $iconMap[$typ] ?? 'fa-file';
				$thumb   = "<span class='mm-type-icon'><i class='fa $icon'></i></span>";
			}

			$out .= "<div class='mm-picker-item' data-id='{$item->id}' data-typ='" . $sanitizer->entities($typ) . "' data-titel='" . $titel . "'{$titleTip}>
				<div class='mm-picker-thumb'>{$thumb}</div>
				<div class='mm-picker-title'>{$titel}</div>
			</div>";
		}
		$out .= "</div>";
		return $out;
	}

	protected function _renderKategorien(PageArray $kategorien): string {
		$sanitizer = $this->wire->sanitizer;
		$csrfName  = $this->wire->session->CSRF->getTokenName();
		$csrfVal   = $this->wire->session->CSRF->getTokenValue();

		$out  = "<div class='mm-kategorien-wrap'>";
		$out .= "
		<form method='post' action='./' class='mm-kat-form'>
			<input type='hidden' name='{$csrfName}' value='{$csrfVal}'>
			<input type='hidden' name='action' value='create'>
			<div class='uk-inline'>
				<input type='text' name='titel' class='uk-input' placeholder='Neue Kategorie…' required>
				<button type='submit' class='ui-button ui-state-default'>
					<i class='fa fa-plus'></i> Anlegen
				</button>
			</div>
		</form>";

		$out .= "<table class='uk-table uk-table-striped mm-kat-table'><thead><tr><th>Name</th><th>Aktionen</th></tr></thead><tbody>";
		if($kategorien->count()) {
			foreach($kategorien as $kat) {
				$out .= "<tr>
					<td>" . $sanitizer->entities($kat->title) . "</td>
					<td>
						<form method='post' action='./' style='display:inline'>
							<input type='hidden' name='{$csrfName}' value='{$csrfVal}'>
							<input type='hidden' name='action' value='delete'>
							<input type='hidden' name='id' value='{$kat->id}'>
							<button type='submit' class='ui-button mm-kat-delete' title='Löschen'><i class='fa fa-trash'></i></button>
						</form>
					</td>
				</tr>";
			}
		} else {
			$out .= "<tr><td colspan='2'><em>Keine Kategorien vorhanden</em></td></tr>";
		}
		$out .= "</tbody></table>";
		$out .= "<a href='../' class='ui-button'><i class='fa fa-arrow-left'></i> Zurück</a>";
		$out .= "</div>";
		return $out;
	}
}
