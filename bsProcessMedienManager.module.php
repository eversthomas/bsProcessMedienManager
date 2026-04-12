<?php namespace ProcessWire;

/**
 * bsProcessMedienManager
 *
 * Haupt-Process-Modul des Medien Manager Bundles.
 * Stellt die Admin-Oberfläche (Grid, Upload, Edit, Bildbearbeitung, Kategorien)
 * sowie alle AJAX-Endpunkte bereit, die vom InputfieldMedienManager genutzt werden.
 *
 * @copyright 2026 bsProcessMedienManager
 */
class bsProcessMedienManager extends Process implements ConfigurableModule {

	/** @var MediaManagerAPI */
	protected MediaManagerAPI $api;

	/** Anzahl Items pro Seite in der Grid-Ansicht */
	protected int $limit = 24;

	/**
	 * Modul-Metadaten für ProcessWire.
	 */
	public static function getModuleInfo(): array {
		return [
			'title'       => 'Medien Manager',
			'version'     => 1,
			'summary'     => 'Zentrales Medienmanagement für Bilder, Videos und PDFs.',
			'author'      => 'bsProcessMedienManager',
			'icon'        => 'photo',
			'permission'  => 'medien-manager',
			'permissions' => [
				'medien-manager' => 'Medien Manager verwenden',
			],
			'page'        => [
				'name'   => 'medienmanager',
				'parent' => 'setup',
				'title'  => 'Medien Manager',
			],
			'requires'    => ['PHP>=8.0'],
			'singular'    => true,
			'autoload'    => false,
		];
	}

	// -----------------------------------------------------------------------
	// Initialisierung
	// -----------------------------------------------------------------------

	/**
	 * Modul initialisieren: API laden, Assets einreihen.
	 */
	public function init(): void {
		parent::init();

		// Service-Klasse laden und instanziieren
		require_once __DIR__ . '/MediaManagerAPI.php';
		$this->api = new MediaManagerAPI($this->wire());

		// Admin-Assets nur im Admin-Kontext einreihen
		if($this->wire->page->template == 'admin') {
			$config    = $this->wire->config;
			$moduleUrl = $config->urls->get('bsProcessMedienManager');
			$config->styles->add($moduleUrl . 'css/medienmanager-admin.css');
			$config->scripts->add($moduleUrl . 'js/medienmanager-admin.js');
		}
	}

	// -----------------------------------------------------------------------
	// Haupt-Execute-Methoden
	// -----------------------------------------------------------------------

	/**
	 * Standard-Ansicht: Medien-Grid mit Filterleiste.
	 *
	 * @return string HTML
	 */
	public function ___execute(): string {
		// Berechtigungsprüfung
		$this->_requirePermission();

		// AJAX-Anfragen direkt weiterleiten
		if($this->wire->config->ajax) {
			return $this->___executeAjax();
		}

		$input  = $this->wire->input;
		$start  = (int) $input->get('start') ?: 0;
		$filters = [
			'typ'          => $input->get('typ'),
			'kategorie_id' => $input->get('kategorie_id'),
			'q'            => $input->get('q'),
		];

		$items      = $this->api->findMedia($filters, $start, $this->limit);
		$kategorien = $this->api->getKategorien();
		$total      = $items->getTotal();

		// Breadcrumb
		$this->wire->breadcrumbs->add(new Breadcrumb('./', 'Medien Manager'));

		// Seitentitel
		$this->headline('Medien Manager');

		return $this->_renderGrid($items, $kategorien, $filters, $start, $total);
	}

	/**
	 * AJAX-Handler: zentraler Einstiegspunkt für alle AJAX-Anfragen.
	 * URL: /processwire/setup/medienmanager/ajax/
	 *
	 * @return string JSON oder HTML-Fragment
	 */
	public function ___executeAjax(): string {
		$this->_requirePermission();

		$input  = $this->wire->input;
		$action = $this->wire->sanitizer->name((string) $input->get('action'));

		// Bei schreibenden Aktionen: CSRF prüfen
		$writingActions = ['delete', 'save-kategorie', 'delete-kategorie'];
		if(in_array($action, $writingActions)) {
			$this->_validateCsrf();
		}

		header('Content-Type: application/json; charset=utf-8');

		switch($action) {
			case 'modal-items':
				return $this->_ajaxModalItems();

			case 'thumb':
				return $this->_ajaxThumb();

			case 'delete':
				return $this->_ajaxDelete();

			case 'kategorien':
				return $this->_ajaxKategorien();

			case 'save-kategorie':
				return $this->_ajaxSaveKategorie();

			case 'delete-kategorie':
				return $this->_ajaxDeleteKategorie();

			default:
				http_response_code(400);
				return json_encode(['status' => 'error', 'message' => 'Unbekannte Aktion']);
		}
	}

	/**
	 * Upload-Handler.
	 * URL: /processwire/setup/medienmanager/upload/
	 *
	 * @return string JSON
	 */
	public function ___executeUpload(): string {
		$this->_requirePermission();
		$this->_validateCsrf();

		header('Content-Type: application/json; charset=utf-8');

		$input     = $this->wire->input;
		$sanitizer = $this->wire->sanitizer;

		// Hochgeladene Datei prüfen
		if(empty($_FILES['mm_datei']['tmp_name'])) {
			http_response_code(400);
			return json_encode(['status' => 'error', 'message' => 'Keine Datei übertragen']);
		}

		$uploadedFile = $_FILES['mm_datei']['tmp_name'];
		$originalName = $_FILES['mm_datei']['name'];

		// Dateierweiterung prüfen (Whitelist)
		$ext     = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'pdf'];
		if(!in_array($ext, $allowed)) {
			http_response_code(400);
			return json_encode(['status' => 'error', 'message' => 'Dateityp nicht erlaubt']);
		}

		$data = [
			'titel'        => $sanitizer->text($input->post('titel') ?: pathinfo($originalName, PATHINFO_FILENAME)),
			'beschreibung' => $sanitizer->textarea($input->post('beschreibung') ?: ''),
			'tags'         => $sanitizer->text($input->post('tags') ?: ''),
			'typ'          => $sanitizer->name($input->post('typ') ?: $this->_extToTyp($ext)),
			'kategorie_id' => (int) $input->post('kategorie_id'),
		];

		$item = $this->api->createMediaItem($data, $uploadedFile);

		if(!$item->id) {
			http_response_code(500);
			return json_encode(['status' => 'error', 'message' => 'Item konnte nicht erstellt werden']);
		}

		return json_encode([
			'status' => 'ok',
			'id'     => $item->id,
			'titel'  => $this->wire->sanitizer->entities($item->mm_titel),
			'thumb'  => $this->api->getThumbnailUrl($item),
		]);
	}

	/**
	 * Edit-Formular für ein einzelnes Medien-Item.
	 * URL: /processwire/setup/medienmanager/edit/?id=123
	 *
	 * @return string HTML
	 */
	public function ___executeEdit(): string {
		$this->_requirePermission();

		$id   = (int) $this->wire->input->get('id');
		$item = $id ? $this->api->getMediaItem($id) : null;

		if(!$item || !$item->id) {
			$this->wire->session->redirect('./');
		}

		$this->headline('Medien bearbeiten: ' . $this->wire->sanitizer->entities($item->mm_titel ?: $item->title));
		$this->wire->breadcrumbs->add(new Breadcrumb('../', 'Medien Manager'));

		return $this->_renderEditForm($item);
	}

	/**
	 * Edit-Formular speichern.
	 * URL: /processwire/setup/medienmanager/save/ (POST)
	 *
	 * @return string
	 */
	public function ___executeSave(): string {
		$this->_requirePermission();
		$this->_validateCsrf();

		$input = $this->wire->input;
		$id    = (int) $input->post('id');

		if(!$id) $this->wire->session->redirect('../');

		$sanitizer = $this->wire->sanitizer;
		$data = [
			'titel'        => $sanitizer->text($input->post('mm_titel') ?: ''),
			'beschreibung' => $sanitizer->textarea($input->post('mm_beschreibung') ?: ''),
			'tags'         => $sanitizer->text($input->post('mm_tags') ?: ''),
			'typ'          => $sanitizer->name($input->post('mm_typ') ?: ''),
			'kategorie_id' => (int) $input->post('mm_kategorie'),
		];

		$item = $this->api->saveMediaItem($id, $data);

		if($item->id) {
			$this->wire->message('Medien-Item gespeichert.');
			$this->wire->session->redirect('../edit/?id=' . $item->id);
		} else {
			$this->wire->error('Fehler beim Speichern.');
			$this->wire->session->redirect('../edit/?id=' . $id);
		}

		return '';
	}

	/**
	 * Item löschen (POST).
	 * URL: /processwire/setup/medienmanager/delete/
	 *
	 * @return string
	 */
	public function ___executeDelete(): string {
		$this->_requirePermission();
		$this->_validateCsrf();

		$id = (int) $this->wire->input->post('id');
		if($id) $this->api->deleteMedia($id);

		$this->wire->message('Medien-Item gelöscht.');
		$this->wire->session->redirect('../');
		return '';
	}

	/**
	 * Bildbearbeitung (Crop/Rotate/Resize).
	 * URL: /processwire/setup/medienmanager/imageedit/?id=123
	 *
	 * @return string HTML oder JSON (bei AJAX)
	 */
	public function ___executeImageedit(): string {
		$this->_requirePermission();

		$input = $this->wire->input;
		$id    = (int) $input->get('id');
		$item  = $id ? $this->api->getMediaItem($id) : null;

		if(!$item || !$item->id) {
			$this->wire->session->redirect('../');
		}

		/** @var Pageimage|null $bild */
		$bild = $item->mm_datei->first();
		if(!$bild instanceof Pageimage) {
			$this->wire->session->redirect('../edit/?id=' . $id);
		}

		// AJAX: Rotate anwenden
		if($this->wire->config->ajax && $input->post('action') === 'rotate') {
			$this->_validateCsrf();
			header('Content-Type: application/json; charset=utf-8');
			$degrees = (int) $input->post('degrees');
			$degrees = in_array($degrees, [-90, 90, 180]) ? $degrees : 0;
			$rotated = $bild->rotate($degrees);
			return json_encode([
				'status' => 'ok',
				'url'    => $rotated->url,
			]);
		}

		// AJAX: Resize anwenden
		if($this->wire->config->ajax && $input->post('action') === 'resize') {
			$this->_validateCsrf();
			header('Content-Type: application/json; charset=utf-8');
			$w      = max(1, (int) $input->post('width'));
			$h      = max(1, (int) $input->post('height'));
			$sized  = $bild->size($w, $h);
			return json_encode([
				'status' => 'ok',
				'url'    => $sized->url,
				'width'  => $sized->width,
				'height' => $sized->height,
			]);
		}

		// Normales HTML rendern
		$this->headline('Bild bearbeiten');
		$this->wire->breadcrumbs->add(new Breadcrumb('../../', 'Medien Manager'));
		$this->wire->breadcrumbs->add(new Breadcrumb('../edit/?id=' . $id, 'Bearbeiten'));

		return $this->_renderImageEditor($item, $bild);
	}

	/**
	 * Kategorien-Verwaltung.
	 * URL: /processwire/setup/medienmanager/kategorien/
	 *
	 * @return string HTML
	 */
	public function ___executeKategorien(): string {
		$this->_requirePermission();

		$input     = $this->wire->input;
		$sanitizer = $this->wire->sanitizer;

		// POST: Neue Kategorie anlegen
		if($input->post('action') === 'create') {
			$this->_validateCsrf();
			$titel    = $sanitizer->text($input->post('titel') ?: '');
			$parentId = (int) $input->post('parent_id');
			if($titel) $this->api->createKategorie($titel, $parentId);
			$this->wire->session->redirect('./');
		}

		// POST: Kategorie löschen
		if($input->post('action') === 'delete') {
			$this->_validateCsrf();
			$katId = (int) $input->post('id');
			if(!$this->api->deleteKategorie($katId)) {
				$this->wire->error('Kategorie konnte nicht gelöscht werden (enthält noch Items).');
			}
			$this->wire->session->redirect('./');
		}

		$this->headline('Kategorien');
		$this->wire->breadcrumbs->add(new Breadcrumb('../', 'Medien Manager'));

		$kategorien = $this->api->getKategorien();
		return $this->_renderKategorien($kategorien);
	}

	// -----------------------------------------------------------------------
	// Install / Uninstall
	// -----------------------------------------------------------------------

	/**
	 * Modul installieren: Felder, Templates, Root-Page und Admin-Page anlegen.
	 */
	public function ___install(): void {
		require_once __DIR__ . '/MediaManagerAPI.php';
		$this->api = new MediaManagerAPI($this->wire());
		$this->api->install();
		parent::___install();
	}

	/**
	 * Modul deinstallieren: Alle Pages, Templates und Felder entfernen.
	 */
	public function ___uninstall(): void {
		if(!isset($this->api)) {
			require_once __DIR__ . '/MediaManagerAPI.php';
			$this->api = new MediaManagerAPI($this->wire());
		}
		parent::___uninstall();
		$this->api->uninstall();
	}

	// -----------------------------------------------------------------------
	// AJAX-Helfer
	// -----------------------------------------------------------------------

	/**
	 * Grid-Items für den Modal-Picker zurückgeben (HTML-Fragment).
	 *
	 * @return string JSON mit HTML-Fragment
	 */
	protected function _ajaxModalItems(): string {
		$input = $this->wire->input;
		$start = (int) ($input->get('page') ?: 0) * $this->limit;

		$filters = [
			'typ'          => $input->get('typ'),
			'kategorie_id' => $input->get('kategorie_id'),
			'q'            => $input->get('q'),
		];

		$items      = $this->api->findMedia($filters, $start, $this->limit);
		$total      = $items->getTotal();
		$html       = $this->_renderPickerGrid($items);

		return json_encode([
			'status' => 'ok',
			'html'   => $html,
			'total'  => $total,
			'start'  => $start,
			'limit'  => $this->limit,
		]);
	}

	/**
	 * Thumbnail-URL für eine einzelne Item-ID zurückgeben.
	 *
	 * @return string JSON
	 */
	protected function _ajaxThumb(): string {
		$id   = (int) $this->wire->input->get('id');
		$item = $id ? $this->api->getMediaItem($id) : null;

		if(!$item || !$item->id) {
			return json_encode(['status' => 'error', 'url' => '']);
		}

		return json_encode([
			'status' => 'ok',
			'url'    => $this->api->getThumbnailUrl($item),
			'titel'  => $this->wire->sanitizer->entities($item->mm_titel ?: $item->title),
			'typ'    => $this->api->getTypString($item),
		]);
	}

	/**
	 * Item per AJAX löschen.
	 *
	 * @return string JSON
	 */
	protected function _ajaxDelete(): string {
		$id      = (int) $this->wire->input->post('id');
		$success = $id ? $this->api->deleteMedia($id) : false;

		return json_encode([
			'status' => $success ? 'ok' : 'error',
		]);
	}

	/**
	 * Kategorien-Liste als JSON zurückgeben.
	 *
	 * @return string JSON
	 */
	protected function _ajaxKategorien(): string {
		$kategorien = $this->api->getKategorien();
		$result     = [];

		foreach($kategorien as $kat) {
			$result[] = [
				'id'    => $kat->id,
				'titel' => $this->wire->sanitizer->entities($kat->title),
			];
		}

		return json_encode(['status' => 'ok', 'kategorien' => $result]);
	}

	/**
	 * Kategorie anlegen oder aktualisieren (AJAX).
	 *
	 * @return string JSON
	 */
	protected function _ajaxSaveKategorie(): string {
		$sanitizer = $this->wire->sanitizer;
		$titel     = $sanitizer->text($this->wire->input->post('titel') ?: '');
		$parentId  = (int) $this->wire->input->post('parent_id');

		if(!$titel) {
			return json_encode(['status' => 'error', 'message' => 'Titel fehlt']);
		}

		$kat = $this->api->createKategorie($titel, $parentId);
		return json_encode([
			'status' => $kat->id ? 'ok' : 'error',
			'id'     => $kat->id,
			'titel'  => $sanitizer->entities($kat->title),
		]);
	}

	/**
	 * Kategorie per AJAX löschen.
	 *
	 * @return string JSON
	 */
	protected function _ajaxDeleteKategorie(): string {
		$id      = (int) $this->wire->input->post('id');
		$success = $id ? $this->api->deleteKategorie($id) : false;

		return json_encode([
			'status'  => $success ? 'ok' : 'error',
			'message' => $success ? '' : 'Kategorie enthält noch Elemente oder wurde nicht gefunden',
		]);
	}

	// -----------------------------------------------------------------------
	// Render-Methoden
	// -----------------------------------------------------------------------

	/**
	 * Responsive Grid-Ansicht mit Filterleiste rendern.
	 *
	 * @param PageArray $items
	 * @param PageArray $kategorien
	 * @param array     $filters
	 * @param int       $start
	 * @param int       $total
	 * @return string HTML
	 */
	protected function _renderGrid(PageArray $items, PageArray $kategorien, array $filters, int $start, int $total): string {
		$sanitizer = $this->wire->sanitizer;
		$csrfName  = $this->wire->session->CSRF->getTokenName();
		$csrfVal   = $this->wire->session->CSRF->getTokenValue();
		$moduleUrl = $this->wire->config->urls->get('bsProcessMedienManager');

		$out = "<div class='mm-admin-wrap'>";

		// Toolbar: Upload-Button + Filter
		$out .= "<div class='mm-toolbar'>
			<div class='mm-toolbar-left'>
				<button type='button' class='mm-upload-btn ui-button ui-state-default' id='mm-upload-btn'>
					<i class='fa fa-upload'></i> Medien hochladen
				</button>
				<a href='./kategorien/' class='ui-button ui-state-default'>
					<i class='fa fa-folder'></i> Kategorien
				</a>
			</div>
			<div class='mm-toolbar-right'>
				<form class='mm-filter-form' method='get' action='./'>
					<select name='typ' class='mm-filter-select' onchange='this.form.submit()'>
						<option value=''" . ($filters['typ'] ? '' : " selected") . ">Alle Typen</option>
						<option value='bild'" . ($filters['typ'] === 'bild' ? ' selected' : '') . ">Bilder</option>
						<option value='video'" . ($filters['typ'] === 'video' ? ' selected' : '') . ">Videos</option>
						<option value='pdf'" . ($filters['typ'] === 'pdf' ? ' selected' : '') . ">PDFs</option>
					</select>
					<select name='kategorie_id' class='mm-filter-select' onchange='this.form.submit()'>";

		$out .= "<option value=''>Alle Kategorien</option>";
		foreach($kategorien as $kat) {
			$sel  = ((int) $filters['kategorie_id'] === $kat->id) ? ' selected' : '';
			$out .= "<option value='{$kat->id}'{$sel}>" . $sanitizer->entities($kat->title) . "</option>";
		}

		$qVal = $sanitizer->entities($filters['q'] ?? '');
		$out .= "
					</select>
					<input type='search' name='q' value='{$qVal}' placeholder='Suchen…' class='mm-search-input'>
					<button type='submit' class='ui-button ui-state-default'><i class='fa fa-search'></i></button>
				</form>
			</div>
		</div>";

		// Grid
		$out .= "<div class='mm-grid' id='mm-grid'>";

		if($items->count()) {
			foreach($items as $item) {
				$out .= $this->_renderGridItem($item, $csrfName, $csrfVal);
			}
		} else {
			$out .= "<p class='mm-empty'>Keine Medien gefunden.</p>";
		}

		$out .= "</div>"; // .mm-grid

		// Pagination
		if($total > $this->limit) {
			$out .= $this->_renderPagination($start, $total, $filters);
		}

		// Upload-Formular (versteckt, wird per JS geöffnet)
		$out .= $this->_renderUploadForm($kategorien, $csrfName, $csrfVal);

		$out .= "</div>"; // .mm-admin-wrap

		return $out;
	}

	/**
	 * Einzelnes Grid-Item rendern.
	 *
	 * @param Page   $item
	 * @param string $csrfName
	 * @param string $csrfVal
	 * @return string HTML
	 */
	protected function _renderGridItem(Page $item, string $csrfName, string $csrfVal): string {
		$sanitizer = $this->wire->sanitizer;
		$titel     = $sanitizer->entities($item->mm_titel ?: $item->title);
		$typ       = $this->api->getTypString($item);
		$thumbUrl  = $this->api->getThumbnailUrl($item);

		// Thumbnail-HTML
		if($thumbUrl) {
			$thumbHtml = "<img src='" . $sanitizer->entities($thumbUrl) . "' alt='" . $titel . "' loading='lazy'>";
		} else {
			$iconMap   = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o'];
			$icon      = $iconMap[$typ] ?? 'fa-file';
			$thumbHtml = "<span class='mm-grid-icon'><i class='fa $icon'></i></span>";
		}

		return "<div class='mm-grid-item' data-id='{$item->id}' data-typ='" . $sanitizer->entities($typ) . "'>
			<div class='mm-grid-thumb'>$thumbHtml</div>
			<div class='mm-grid-info'>
				<span class='mm-grid-title'>$titel</span>
				<span class='mm-grid-typ'>$typ</span>
			</div>
			<div class='mm-grid-actions'>
				<a href='./edit/?id={$item->id}' class='mm-btn-edit' title='Bearbeiten'><i class='fa fa-pencil'></i></a>"
				. ($typ === 'bild' ? "<a href='./imageedit/?id={$item->id}' class='mm-btn-imageedit' title='Bearbeiten'><i class='fa fa-crop'></i></a>" : '')
				. "<button type='button' class='mm-btn-delete' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "' title='Löschen'><i class='fa fa-trash'></i></button>
			</div>
		</div>";
	}

	/**
	 * Einfache Pagination rendern.
	 *
	 * @param int   $start
	 * @param int   $total
	 * @param array $filters
	 * @return string HTML
	 */
	protected function _renderPagination(int $start, int $total, array $filters): string {
		$pages     = (int) ceil($total / $this->limit);
		$current   = (int) floor($start / $this->limit);
		$sanitizer = $this->wire->sanitizer;

		$queryBase = '?';
		foreach($filters as $k => $v) {
			if($v) $queryBase .= $sanitizer->entities($k) . '=' . $sanitizer->entities((string)$v) . '&';
		}

		$out = "<nav class='mm-pagination'>";
		for($i = 0; $i < $pages; $i++) {
			$s      = $i * $this->limit;
			$active = $i === $current ? ' class="mm-page-active"' : '';
			$out   .= "<a href='{$queryBase}start={$s}'{$active}>" . ($i + 1) . "</a>";
		}
		$out .= "</nav>";
		return $out;
	}

	/**
	 * Upload-Formular rendern (versteckt, via JS eingeblendet).
	 *
	 * @param PageArray $kategorien
	 * @param string    $csrfName
	 * @param string    $csrfVal
	 * @return string HTML
	 */
	protected function _renderUploadForm(PageArray $kategorien, string $csrfName, string $csrfVal): string {
		$sanitizer = $this->wire->sanitizer;

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
							<p>Datei hier ablegen oder klicken zum Auswählen</p>
							<p class='mm-upload-hint'>Erlaubt: JPG, PNG, GIF, WEBP, MP4, MOV, PDF</p>
							<input type='file' name='mm_datei' id='mm-file-input' accept='.jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.pdf'>
						</div>
						<div class='uk-margin'>
							<label>Titel</label>
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

	/**
	 * Edit-Formular für ein Medien-Item rendern.
	 *
	 * @param Page $item
	 * @return string HTML
	 */
	protected function _renderEditForm(Page $item): string {
		$sanitizer = $this->wire->sanitizer;
		$csrfName  = $this->wire->session->CSRF->getTokenName();
		$csrfVal   = $this->wire->session->CSRF->getTokenValue();
		$kategorien = $this->api->getKategorien();

		// Vorschau
		$vorschau = '';
		$datei    = $item->mm_datei->first();
		if($datei instanceof Pageimage) {
			$thumb    = $datei->size(300, 225, ['cropping' => true]);
			$vorschau = "<div class='mm-edit-preview'><img src='" . $sanitizer->entities($thumb->url) . "' alt=''></div>";
		} elseif($datei) {
			$vorschau = "<div class='mm-edit-preview mm-edit-preview-file'><i class='fa fa-file-o'></i><span>" . $sanitizer->entities($datei->basename) . "</span></div>";
		}

		$typ = $this->api->getTypString($item);
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

		return "
		<div class='mm-edit-wrap'>
			{$vorschau}
			<form method='post' action='../save/' class='mm-edit-form'>
				<input type='hidden' name='{$csrfName}' value='{$csrfVal}'>
				<input type='hidden' name='id' value='{$item->id}'>

				<div class='uk-margin'>
					<label class='uk-form-label'>Titel</label>
					<input type='text' name='mm_titel' value='" . $sanitizer->entities($item->mm_titel) . "' class='uk-input' required>
				</div>
				<div class='uk-margin'>
					<label class='uk-form-label'>Beschreibung</label>
					<textarea name='mm_beschreibung' class='uk-textarea' rows='3'>" . $sanitizer->entities($item->mm_beschreibung) . "</textarea>
				</div>
				<div class='uk-margin'>
					<label class='uk-form-label'>Tags <small>(kommagetrennt)</small></label>
					<input type='text' name='mm_tags' value='" . $sanitizer->entities($item->mm_tags) . "' class='uk-input'>
				</div>
				<div class='uk-margin'>
					<label class='uk-form-label'>Typ</label>
					<select name='mm_typ' class='uk-select'>{$typOptions}</select>
				</div>
				<div class='uk-margin'>
					<label class='uk-form-label'>Kategorie</label>
					<select name='mm_kategorie' class='uk-select'>{$katOptions}</select>
				</div>
				<div class='mm-edit-actions'>
					<button type='submit' class='ui-button ui-state-default'><i class='fa fa-save'></i> Speichern</button>
					<a href='../' class='ui-button'>Abbrechen</a>"
					. ($typ === 'bild' ? "<a href='../imageedit/?id={$item->id}' class='ui-button'><i class='fa fa-crop'></i> Bild bearbeiten</a>" : '')
					. "
					<button type='button' class='mm-btn-delete-single ui-button' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "'>
						<i class='fa fa-trash'></i> Löschen
					</button>
				</div>
			</form>
		</div>";
	}

	/**
	 * Bild-Editor rendern (Crop/Rotate/Resize).
	 *
	 * @param Page       $item
	 * @param Pageimage  $bild
	 * @return string HTML
	 */
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
					<label>Breite (px): <input type='number' id='mm-resize-w' value='{$bild->width}' min='1' class='uk-input'></label>
					<label>Höhe (px): <input type='number' id='mm-resize-h' value='{$bild->height}' min='1' class='uk-input'></label>
					<button type='button' class='ui-button mm-resize-btn' data-id='{$item->id}' data-csrf-name='" . $sanitizer->entities($csrfName) . "' data-csrf-val='" . $sanitizer->entities($csrfVal) . "'>
						<i class='fa fa-expand'></i> Größe ändern
					</button>
				</div>
				<div class='mm-imageedit-actions'>
					<a href='../edit/?id={$item->id}' class='ui-button'><i class='fa fa-arrow-left'></i> Zurück</a>
				</div>
			</div>
		</div>";
	}

	/**
	 * Picker-Grid für das Modal (HTML-Fragment für AJAX).
	 *
	 * @param PageArray $items
	 * @return string HTML
	 */
	protected function _renderPickerGrid(PageArray $items): string {
		$sanitizer = $this->wire->sanitizer;

		if(!$items->count()) {
			return "<p class='mm-empty'>Keine Medien gefunden.</p>";
		}

		$out = "<div class='mm-picker-grid'>";
		foreach($items as $item) {
			$titel    = $sanitizer->entities($item->mm_titel ?: $item->title);
			$typ      = $this->api->getTypString($item);
			$thumbUrl = $this->api->getThumbnailUrl($item, 160, 120);

			if($thumbUrl) {
				$thumb = "<img src='" . $sanitizer->entities($thumbUrl) . "' alt='" . $titel . "' loading='lazy'>";
			} else {
				$iconMap = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o'];
				$icon    = $iconMap[$typ] ?? 'fa-file';
				$thumb   = "<span class='mm-type-icon'><i class='fa $icon'></i></span>";
			}

			$out .= "<div class='mm-picker-item' data-id='{$item->id}' data-typ='" . $sanitizer->entities($typ) . "' data-titel='" . $titel . "'>
				<div class='mm-picker-thumb'>{$thumb}</div>
				<div class='mm-picker-title'>{$titel}</div>
			</div>";
		}
		$out .= "</div>";

		return $out;
	}

	/**
	 * Kategorien-Verwaltungsseite rendern.
	 *
	 * @param PageArray $kategorien
	 * @return string HTML
	 */
	protected function _renderKategorien(PageArray $kategorien): string {
		$sanitizer = $this->wire->sanitizer;
		$csrfName  = $this->wire->session->CSRF->getTokenName();
		$csrfVal   = $this->wire->session->CSRF->getTokenValue();

		$out = "<div class='mm-kategorien-wrap'>";

		// Neue Kategorie anlegen
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

		// Liste
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

	// -----------------------------------------------------------------------
	// Sicherheits-Helfer
	// -----------------------------------------------------------------------

	/**
	 * Berechtigungsprüfung — wirft Exception wenn nicht berechtigt.
	 */
	protected function _requirePermission(): void {
		if(!$this->wire->user->hasPermission('medien-manager')) {
			throw new WirePermissionException('Zugriff verweigert: Medien Manager');
		}
	}

	/**
	 * CSRF-Token validieren — wirft Exception wenn ungültig.
	 */
	protected function _validateCsrf(): void {
		$this->wire->session->CSRF->validate(); // wirft WireCSRFException bei Fehler
	}

	/**
	 * Dateiendung in Medientyp-String umwandeln.
	 *
	 * @param string $ext
	 * @return string 'bild'|'video'|'pdf'
	 */
	protected function _extToTyp(string $ext): string {
		$map = [
			'jpg' => 'bild', 'jpeg' => 'bild', 'png' => 'bild', 'gif' => 'bild', 'webp' => 'bild',
			'mp4' => 'video', 'mov' => 'video',
			'pdf' => 'pdf',
		];
		return $map[$ext] ?? 'bild';
	}

	// -----------------------------------------------------------------------
	// Modul-Konfiguration
	// -----------------------------------------------------------------------

	/**
	 * Konfigurationsseite des Moduls im Backend.
	 *
	 * @param array $data Gespeicherte Konfigurationsdaten
	 * @return InputfieldWrapper
	 */
	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$wire    = wire();
		$wrapper = $wire->modules->get('InputfieldWrapper');

		/** @var InputfieldInteger $f */
		$f = $wire->modules->get('InputfieldInteger');
		$f->attr('name', 'gridLimit');
		$f->label       = 'Grid-Items pro Seite';
		$f->attr('value', (int) ($data['gridLimit'] ?? 24));
		$f->min         = 6;
		$f->max         = 100;
		$wrapper->add($f);

		return $wrapper;
	}
}
