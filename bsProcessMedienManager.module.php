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

	/** @var MediaManagerAPI|null */
	protected ?MediaManagerAPI $api = null;

	/** @var bool Verhindert doppelte JS-Config-Ausgabe pro Request */
	protected bool $jsConfigInjected = false;

	/** Anzahl Items pro Seite in der Grid-Ansicht */
	protected int $limit = 24;

	/**
	 * Modul-Metadaten für ProcessWire.
	 */
	public static function getModuleInfo(): array {
		return [
			'title'       => 'Medien Manager',
			'version'     => 1.5,
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
	// Initialisierung & Helfer
	// -----------------------------------------------------------------------

	/**
	 * API-Instanz lazy initialisieren.
	 * Alle execute*()-Methoden rufen $this->api() auf statt direkt auf
	 * $this->api zuzugreifen — funktioniert unabhängig davon ob ProcessWire
	 * init() vor execute() aufruft (bei autoload=false nicht garantiert).
	 */
	protected function api(): MediaManagerAPI {
		if($this->api === null) {
			require_once __DIR__ . '/MediaManagerAPI.php';
			$this->api = new MediaManagerAPI($this->wire());
		}
		return $this->api;
	}

	/**
	 * JS-Konfiguration als Inline-<script> in den HTML-Output injizieren.
	 *
	 * Hintergrund: $config->js() funktioniert nur wenn es VOR dem Rendern
	 * des <head> aufgerufen wird. Bei autoload=false wird init() nicht
	 * zuverlässig früh genug ausgeführt. Deshalb schreiben wir die Config
	 * direkt als <script>-Tag in den Seiteninhalt — ProcessWire platziert
	 * diesen im Content-Bereich, das Admin-JS liest window.bsProcessMedienManager
	 * beim ersten Klick (nicht beim Laden), daher ist die Reihenfolge egal.
	 *
	 * @return string Inline-<script>-Tag, oder leer wenn bereits injiziert
	 */
	protected function _injectJsConfig(): string {
		if($this->jsConfigInjected) return '';
		$this->jsConfigInjected = true;

		$adminBase = $this->wire->config->urls->admin;
		$ajaxUrl   = $adminBase . 'setup/medienmanager/ajax/';
		// Absolute Basis-URL für POST auf imageedit (Rotate/Resize) — vermeidet fehlerhafte relative Pfade im JS.
		$imageEditUrl = $adminBase . 'setup/medienmanager/imageedit/';
		$csrfName     = $this->wire->session->CSRF->getTokenName();
		$csrfVal      = $this->wire->session->CSRF->getTokenValue();

		return "<script>window.bsProcessMedienManager = " . json_encode([
			'ajaxUrl'      => $ajaxUrl,
			'imageEditUrl' => $imageEditUrl,
			'csrfName'     => $csrfName,
			'csrfVal'      => $csrfVal,
		]) . ";</script>\n";
	}

	/**
	 * Modul initialisieren: Assets einreihen.
	 * Die JS-Config wird NICHT hier gesetzt (wäre bei autoload=false zu spät),
	 * sondern per _injectJsConfig() direkt im HTML-Output der Execute-Methoden.
	 */
	public function init(): void {
		parent::init();
		$this->api();

		$cfg = $this->wire->modules->getModuleConfigData(__CLASS__);
		$gl  = isset($cfg['gridLimit']) ? (int) $cfg['gridLimit'] : 24;
		if($gl < 1) $gl = 24;
		$this->limit = max(6, min(100, $gl));

		if($this->wire->page->template == 'admin') {
			$config    = $this->wire->config;
			$moduleUrl = $config->urls->get('bsProcessMedienManager');
			$config->styles->add($moduleUrl . 'css/medienmanager-admin.css');
			$config->scripts->add($moduleUrl . 'js/medienmanager-admin.js');
		}
	}

	/**
	 * Logging-Helfer.
	 * Schreibt in /site/assets/logs/medienmanager.txt
	 * Nur für Superuser aktiv; $force=true für echte Fehler die immer geloggt werden.
	 */
	protected function log(string $msg, bool $force = false): void {
		if(!$force && !$this->wire->user->isSuperuser()) return;
		$this->wire->log->save('medienmanager', $msg);
	}

	// -----------------------------------------------------------------------
	// Haupt-Execute-Methoden
	// -----------------------------------------------------------------------

	/**
	 * Standard-Ansicht: Medien-Grid mit Filterleiste.
	 */
	public function ___execute(): string {
		$this->_requirePermission();

		$isAjax = $this->wire->config->ajax 
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
        if($isAjax) {
            // Direkt ausgeben und beenden, nicht durch PW-Template routen
            header('Content-Type: application/json; charset=utf-8');
            echo $this->___executeAjax();
            exit;
        }

		$input   = $this->wire->input;
		$start   = (int) $input->get('start') ?: 0;
		$view    = $input->get('view');
		$view    = ($view === 'list') ? 'list' : 'grid';
		$filters = [
			'typ'          => $input->get('typ'),
			'kategorie_id' => $input->get('kategorie_id'),
			'q'            => $input->get('q'),
		];

		$items      = $this->api()->findMedia($filters, $start, $this->limit);
		$kategorien = $this->api()->getKategorien();
		$total      = $items->getTotal();

		$this->wire->breadcrumbs->add(new Breadcrumb('./', 'Medien Manager'));
		$this->headline('Medien Manager');

		return $this->_injectJsConfig()
			. $this->_renderGrid($items, $kategorien, $filters, $start, $total, $view);
	}

	/**
	 * AJAX-Handler: zentraler Einstiegspunkt.
	 * URL: /setup/medienmanager/ajax/
	 */
	public function ___executeAjax(): string {
		$this->_requirePermission();

		$input  = $this->wire->input;
		// POST (FormData vom Admin-JS) und GET (?action= für Picker) unterstützen
		$action = $this->wire->sanitizer->name((string) ($input->post('action') ?: $input->get('action')));

		$this->log("AJAX action: $action");

		$writingActions = [
			'delete',
			'save-kategorie',
			'delete-kategorie',
			'bulk-delete',
			'bulk-set-kategorie',
			'duplicate-media',
		];
		if(in_array($action, $writingActions)) {
			$this->_validateCsrf();
		}

		header('Content-Type: application/json; charset=utf-8');

		switch($action) {
			case 'modal-items':      return $this->_ajaxModalItems();
			case 'thumb':            return $this->_ajaxThumb();
			case 'delete':           return $this->_ajaxDelete();
			case 'bulk-delete':      return $this->_ajaxBulkDelete();
			case 'bulk-set-kategorie': return $this->_ajaxBulkSetKategorie();
			case 'duplicate-media':  return $this->_ajaxDuplicateMedia();
			case 'kategorien':       return $this->_ajaxKategorien();
			case 'save-kategorie':   return $this->_ajaxSaveKategorie();
			case 'delete-kategorie': return $this->_ajaxDeleteKategorie();
			default:
				$this->log("Unbekannte AJAX-Aktion: $action", true);
				http_response_code(400);
				return json_encode(['status' => 'error', 'message' => 'Unbekannte Aktion']);
		}
	}
	
	/**
	 * Nur für Superuser: technische Diagnose (Request-Methode, ajax-Flag, GET/POST-Keys).
	 * URL: /setup/medienmanager/test/
	 */
	public function ___executeTest(): string {
		$this->_requirePermission();
		if(!$this->wire->user->isSuperuser()) {
			throw new WirePermissionException('Nur Superuser');
		}
		header('Content-Type: application/json; charset=utf-8');
		return json_encode([
			'status' => 'ok',
			'ajax'   => $this->wire->config->ajax,
			'method' => $_SERVER['REQUEST_METHOD'] ?? '',
			'get'    => $this->wire->input->get->getArray(),
			'post'   => array_keys($this->wire->input->post->getArray()),
		]);
	}

	/**
	 * Upload-Handler.
	 * URL: /setup/medienmanager/upload/
	 */
	public function ___executeUpload(): string {
		$this->_requirePermission();
		$this->_validateCsrf();

		header('Content-Type: application/json; charset=utf-8');

		$input     = $this->wire->input;
		$sanitizer = $this->wire->sanitizer;

		$filesList = $this->_normalizeUploadedFiles();
		if(!\count($filesList)) {
			$err = isset($_FILES['mm_datei']['error']) && !\is_array($_FILES['mm_datei']['error'])
				? (int) $_FILES['mm_datei']['error']
				: UPLOAD_ERR_NO_FILE;
			$msg = $this->_uploadErrorMessage($err);
			$this->log("Upload fehlgeschlagen — keine Dateien, PHP error=$err ($msg)", true);
			http_response_code(400);
			return json_encode(['status' => 'error', 'message' => $msg, 'results' => []]);
		}

		$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'pdf'];
		$results = [];
		$okCount = 0;

		$postTitel = $sanitizer->text($input->post('titel') ?: '');
		$multi     = \count($filesList) > 1;

		foreach($filesList as $file) {
			$origName = $file['name'];
			if($file['error'] !== UPLOAD_ERR_OK) {
				$results[] = [
					'status'   => 'error',
					'filename' => $origName,
					'message'  => $this->_uploadErrorMessage($file['error']),
				];
				continue;
			}
			$tmp = $file['tmp_name'];
			if(!is_uploaded_file($tmp)) {
				$results[] = [
					'status'   => 'error',
					'filename' => $origName,
					'message'  => 'Ungültige Upload-Datei',
				];
				continue;
			}

			$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
			if(!\in_array($ext, $allowed, true)) {
				$results[] = [
					'status'   => 'error',
					'filename' => $origName,
					'message'  => 'Dateityp nicht erlaubt: ' . $ext,
				];
				continue;
			}

			$baseTitel = $multi
				? pathinfo($origName, PATHINFO_FILENAME)
				: ($postTitel !== '' ? $postTitel : pathinfo($origName, PATHINFO_FILENAME));

			$data = [
				'titel'        => $sanitizer->text($baseTitel),
				'beschreibung' => $sanitizer->textarea($input->post('beschreibung') ?: ''),
				'tags'         => $sanitizer->text($input->post('tags') ?: ''),
				'typ'          => $sanitizer->name($input->post('typ') ?: $this->_extToTyp($ext)),
				'kategorie_id' => (int) $input->post('kategorie_id'),
			];

			$this->log("Upload: " . $data['titel'] . " ($ext)" . ($multi ? " [Mehrfach]" : ''));
			$item = $this->api()->createMediaItem($data, $tmp, $origName);

			if(!$item->id) {
				$results[] = [
					'status'   => 'error',
					'filename' => $origName,
					'message'  => 'Item konnte nicht erstellt werden',
				];
				$this->log("createMediaItem ohne ID für $origName", true);
				continue;
			}

			$okCount++;
			$typStr = $this->api()->getTypString($item);
			$results[] = [
				'status'       => 'ok',
				'filename'     => $origName,
				'id'           => $item->id,
				'titel'        => (string) $item->mm_titel,
				'basename'     => $this->api()->getPrimaryBasename($item),
				'dimensions'   => $this->api()->getPrimaryDimensionsStr($item),
				'filesizeStr'  => $this->api()->getPrimaryFilesizeStr($item),
				'thumb'        => $this->api()->getThumbnailUrlForSlot($item, 'grid'),
				'typ'          => $typStr,
				'hasImage'     => $this->api()->getPrimaryPageimage($item) !== null,
				'publicUrl'    => $this->api()->getPublicFileUrl($item),
			];
		}

		if($okCount === 0) {
			$firstErr = '';
			foreach($results as $r) {
				if(($r['status'] ?? '') === 'error' && !empty($r['message'])) {
					$firstErr = (string) $r['message'];
					break;
				}
			}
			http_response_code(400);
			return json_encode([
				'status'   => 'error',
				'message'  => $firstErr !== '' ? $firstErr : 'Keine Datei konnte verarbeitet werden.',
				'results'  => $results,
				'summary'  => ['ok' => 0, 'failed' => \count($results)],
			]);
		}

		$this->log("Upload abgeschlossen — ok=$okCount, gesamt=" . \count($filesList));

		return json_encode([
			'status'  => 'ok',
			'message' => $okCount === \count($filesList)
				? ''
				: sprintf('%d von %d Dateien übernommen.', $okCount, \count($filesList)),
			'results' => $results,
			'summary' => ['ok' => $okCount, 'failed' => \count($results) - $okCount],
		]);
	}

	/**
	 * Edit-Formular.
	 * URL: /setup/medienmanager/edit/?id=123
	 */
	public function ___executeEdit(): string {
		$this->_requirePermission();

		$id   = (int) $this->wire->input->get('id');
		$item = $id ? $this->api()->getMediaItem($id) : null;

		if(!$item || !$item->id) {
			$this->log("Edit aufgerufen mit ungültiger ID: $id", true);
			$this->wire->session->redirect('./');
		}

		$headTitel = trim((string) $item->mm_titel);
		if($headTitel === '') {
			$headTitel = $this->api()->getPrimaryBasename($item) ?: (string) $item->title;
		}
		$this->headline('Medien bearbeiten: ' . $this->wire->sanitizer->entities($headTitel));
		$this->wire->breadcrumbs->add(new Breadcrumb('../', 'Medien Manager'));

		return $this->_injectJsConfig()
			. $this->_renderEditForm($item);
	}

	/**
	 * Edit speichern.
	 * URL: /setup/medienmanager/save/ (POST)
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
			'alt'          => $sanitizer->text($input->post('mm_alt') ?: ''),
			'caption'      => $sanitizer->text($input->post('mm_caption') ?: ''),
		];

		$item = $this->api()->saveMediaItem($id, $data);

		if($item->id) {
			$this->log("Item gespeichert: ID $id");
			$this->wire->message('Medien-Item gespeichert.');
			$this->wire->session->redirect('../edit/?id=' . $item->id);
		} else {
			$this->log("Speichern fehlgeschlagen für ID: $id", true);
			$this->wire->error('Fehler beim Speichern.');
			$this->wire->session->redirect('../edit/?id=' . $id);
		}

		return '';
	}

	/**
	 * Primärdatei ersetzen (gleiche Page-ID; Fieldtype-Referenzen bleiben gültig).
	 * URL: /setup/medienmanager/replace/ (POST, multipart)
	 */
	public function ___executeReplace(): string {
		$this->_requirePermission();
		$this->_validateCsrf();

		$id   = (int) $this->wire->input->post('id');
		$item = $id ? $this->api()->getMediaItem($id) : null;
		if(!$item || !$item->id) {
			$this->wire->error('Medium nicht gefunden.');
			$this->wire->session->redirect('../');
			return '';
		}

		if(!isset($_FILES['mm_ersatz']) || (int) $_FILES['mm_ersatz']['error'] !== UPLOAD_ERR_OK) {
			$err = isset($_FILES['mm_ersatz']['error']) ? (int) $_FILES['mm_ersatz']['error'] : UPLOAD_ERR_NO_FILE;
			$this->wire->error($this->_uploadErrorMessage($err));
			$this->wire->session->redirect('../edit/?id=' . $id);
			return '';
		}

		$tmp  = (string) $_FILES['mm_ersatz']['tmp_name'];
		$name = (string) $_FILES['mm_ersatz']['name'];
		if($tmp === '' || !is_uploaded_file($tmp)) {
			$this->wire->error('Ungültige Upload-Datei.');
			$this->wire->session->redirect('../edit/?id=' . $id);
			return '';
		}

		if($this->api()->replacePrimaryFile($item, $tmp, $name)) {
			$this->wire->message('Datei ersetzt. Verweise im Frontend (gleiche Medien-ID) bleiben erhalten.');
		} else {
			$this->wire->error('Ersetzen fehlgeschlagen: passender Dateityp und gleiche Endung wie die bestehende Datei nötig.');
		}
		$this->wire->session->redirect('../edit/?id=' . $id);
		return '';
	}

	/**
	 * Item löschen via POST-Formular (Redirect danach).
	 * URL: /setup/medienmanager/delete/
	 */
	public function ___executeDelete(): string {
		$this->_requirePermission();
		$this->_validateCsrf();

		$id = (int) $this->wire->input->post('id');
		if($id) {
			$this->log("Lösche Item via POST-Formular: ID $id");
			$this->api()->deleteMedia($id);
		}

		$this->wire->message('Medien-Item gelöscht.');
		$this->wire->session->redirect('../');
		return '';
	}

	/**
	 * Bildbearbeitung.
	 * URL: /setup/medienmanager/imageedit/?id=123
	 */
	public function ___executeImageedit(): string {
		$this->_requirePermission();

		$input = $this->wire->input;
		$id    = (int) $input->get('id');
		$item  = $id ? $this->api()->getMediaItem($id) : null;

		if(!$item || !$item->id) {
			$this->wire->session->redirect('../');
		}

		$bild = $this->api()->getPrimaryPageimage($item);
		if(!$bild instanceof Pageimage) {
			$this->wire->session->redirect('../edit/?id=' . $id);
		}

		// Wie bei ___execute(): nicht nur $config->ajax — XHR setzt oft nur den Header.
		$isAjax = $this->wire->config->ajax || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

		if($isAjax && $input->post('action') === 'rotate') {
			$this->_validateCsrf();
			header('Content-Type: application/json; charset=utf-8');
			$degrees = (int) $input->post('degrees');
			if(!in_array($degrees, [-90, 90, 180, -180, 270, -270], true)) {
				$degrees = 0;
			}
			$item->of(false);
			$bild = $this->api()->getPrimaryPageimage($item);
			if(!$bild instanceof Pageimage) {
				return json_encode(['status' => 'error', 'message' => 'Kein Bild']);
			}
			if(!$this->api()->rotateMasterPageimage($bild, $degrees)) {
				$this->log('Rotation fehlgeschlagen für Pageimage ' . $bild->filename, true);
				return json_encode(['status' => 'error', 'message' => 'Rotation fehlgeschlagen']);
			}
			$bust = (string) time();
			return json_encode(['status' => 'ok', 'url' => $bild->url . '?cb=' . $bust, 'width' => $bild->width, 'height' => $bild->height]);
		}

		if($isAjax && $input->post('action') === 'resize') {
			$this->_validateCsrf();
			header('Content-Type: application/json; charset=utf-8');
			$w = max(1, (int) $input->post('width'));
			$h = max(1, (int) $input->post('height'));
			$item->of(false);
			$bild = $this->api()->getPrimaryPageimage($item);
			if(!$bild instanceof Pageimage) {
				return json_encode(['status' => 'error', 'message' => 'Kein Bild']);
			}
			$sized = $bild->size($w, $h);
			return json_encode([
				'status' => 'ok',
				'url'    => $sized->url,
				'width'  => $sized->width,
				'height' => $sized->height,
			]);
		}

		$this->headline('Bild bearbeiten');
		$this->wire->breadcrumbs->add(new Breadcrumb('../../', 'Medien Manager'));
		$this->wire->breadcrumbs->add(new Breadcrumb('../edit/?id=' . $id, 'Bearbeiten'));

		return $this->_injectJsConfig()
			. $this->_renderImageEditor($item, $bild);
	}

	/**
	 * Kategorien-Verwaltung.
	 * URL: /setup/medienmanager/kategorien/
	 */
	public function ___executeKategorien(): string {
		$this->_requirePermission();

		$input     = $this->wire->input;
		$sanitizer = $this->wire->sanitizer;

		if($input->post('action') === 'create') {
			$this->_validateCsrf();
			$titel    = $sanitizer->text($input->post('titel') ?: '');
			$parentId = (int) $input->post('parent_id');
			if($titel) {
				$this->api()->createKategorie($titel, $parentId);
				$this->log("Kategorie erstellt: $titel");
			}
			$this->wire->session->redirect('./');
		}

		if($input->post('action') === 'delete') {
			$this->_validateCsrf();
			$katId = (int) $input->post('id');
			if(!$this->api()->deleteKategorie($katId)) {
				$this->wire->error('Kategorie konnte nicht gelöscht werden (enthält noch Items).');
				$this->log("Kategorie löschen fehlgeschlagen: ID $katId", true);
			} else {
				$this->log("Kategorie gelöscht: ID $katId");
			}
			$this->wire->session->redirect('./');
		}

		$this->headline('Kategorien');
		$this->wire->breadcrumbs->add(new Breadcrumb('../', 'Medien Manager'));

		$kategorien = $this->api()->getKategorien();
		return $this->_renderKategorien($kategorien);
	}

	// -----------------------------------------------------------------------
	// Install / Uninstall
	// -----------------------------------------------------------------------

	public function ___install(): void {
		require_once __DIR__ . '/MediaManagerAPI.php';
		$this->api = new MediaManagerAPI($this->wire());
		$this->api->install();
		parent::___install();
	}

	/**
	 * Beim Versions-Sprung: fehlende Felder/Templates nachziehen (z. B. mm_tags nach Update von &lt; 1.2).
	 */
	public function ___upgrade($fromVersion, $toVersion): void {
		require_once __DIR__ . '/MediaManagerAPI.php';
		$this->api = new MediaManagerAPI($this->wire());
		if(version_compare((string) $fromVersion, '1.5', '<')) {
			$this->api->install();
		}
		parent::___upgrade($fromVersion, $toVersion);
	}

	/**
	 * Normalisiert $_FILES['mm_datei'] zu einer Liste (ein oder mehrere Uploads).
	 *
	 * @return list<array{name: string, tmp_name: string, error: int}>
	 */
	protected function _normalizeUploadedFiles(): array {
		if(!isset($_FILES['mm_datei'])) return [];

		$f = $_FILES['mm_datei'];
		if(!\is_array($f['tmp_name'])) {
			if($f['tmp_name'] === '' || $f['tmp_name'] === null) return [];
			return [[
				'name'     => (string) $f['name'],
				'tmp_name' => (string) $f['tmp_name'],
				'error'    => (int) $f['error'],
			]];
		}

		$out = [];
		foreach($f['tmp_name'] as $i => $tmp) {
			if($tmp === '' || $tmp === null) continue;
			$out[] = [
				'name'     => (string) $f['name'][$i],
				'tmp_name' => (string) $tmp,
				'error'    => (int) $f['error'][$i],
			];
		}
		return $out;
	}

	public function ___uninstall(): void {
		if($this->api === null) {
			require_once __DIR__ . '/MediaManagerAPI.php';
			$this->api = new MediaManagerAPI($this->wire());
		}
		// Erst API-Cleanup, dann parent (löscht Admin-Page)
		$this->api->uninstall();
		parent::___uninstall();
	}

	// -----------------------------------------------------------------------
	// AJAX-Helfer
	// -----------------------------------------------------------------------

	protected function _ajaxModalItems(): string {
		$input = $this->wire->input;
		$start = (int) ($input->get('page') ?: 0) * $this->limit;

		$filters = [
			'typ'          => $input->get('typ'),
			'kategorie_id' => $input->get('kategorie_id'),
			'q'            => $input->get('q'),
		];

		$items = $this->api()->findMedia($filters, $start, $this->limit);
		$total = $items->getTotal();
		$html  = $this->_renderPickerGrid($items);

		return json_encode([
			'status' => 'ok',
			'html'   => $html,
			'total'  => $total,
			'start'  => $start,
			'limit'  => $this->limit,
		]);
	}

	protected function _ajaxThumb(): string {
		$id   = (int) $this->wire->input->get('id');
		$item = $id ? $this->api()->getMediaItem($id) : null;

		if(!$item || !$item->id) {
			$this->log("Thumb-Anfrage für ungültige ID: $id", true);
			return json_encode(['status' => 'error', 'url' => '']);
		}

		$alt = '';
		if($item->hasField('mm_alt') && (string) $item->mm_alt !== '') {
			$alt = $this->wire->sanitizer->entities($item->mm_alt);
		}

		return json_encode([
			'status' => 'ok',
			'url'    => $this->api()->getThumbnailUrlForSlot($item, 'chip'),
			'titel'  => (string) ($item->mm_titel ?: $item->title),
			'alt'    => $alt,
			'typ'    => $this->api()->getTypString($item),
		]);
	}

	protected function _ajaxDelete(): string {
		$id = (int) $this->wire->input->post('id');
		$this->log("AJAX-Löschversuch für ID: $id");

		$success = $id ? $this->api()->deleteMedia($id) : false;

		if(!$success) {
			$this->log("AJAX-Löschen fehlgeschlagen für ID: $id", true);
		} else {
			$this->log("AJAX-Löschen erfolgreich für ID: $id");
		}

		return json_encode(['status' => $success ? 'ok' : 'error']);
	}

	protected function _ajaxBulkDelete(): string {
		$input = $this->wire->input;
		$raw   = (string) $input->post('ids');
		$ids   = array_filter(array_map('intval', explode(',', $raw)));
		$deleted = 0;
		foreach($ids as $id) {
			if($id > 0 && $this->api()->deleteMedia($id)) $deleted++;
		}
		return json_encode([
			'status'  => 'ok',
			'deleted' => $deleted,
			'requested' => count($ids),
		]);
	}

	protected function _ajaxBulkSetKategorie(): string {
		$input = $this->wire->input;
		$raw   = (string) $input->post('ids');
		$ids   = array_filter(array_map('intval', explode(',', $raw)));
		$katId = (int) $input->post('kategorie_id');

		$kat = null;
		if($katId > 0) {
			$kat = $this->wire->pages->get($katId);
			if(!$kat->id || $kat->template->name !== MediaManagerAPI::KATEGORIE_TEMPLATE) {
				return json_encode(['status' => 'error', 'message' => 'Ungültige Kategorie']);
			}
		}

		$updated = 0;
		foreach($ids as $id) {
			if($id <= 0) continue;
			$p = $this->api()->getMediaItem($id);
			if(!$p->id) continue;
			$p->of(false);
			$p->mm_kategorie = $katId > 0 ? $kat : null;
			$p->save();
			$updated++;
		}

		return json_encode([
			'status' => 'ok',
			'updated' => $updated,
		]);
	}

	protected function _ajaxDuplicateMedia(): string {
		$id   = (int) $this->wire->input->post('id');
		$copy = $this->api()->duplicateMediaItem($id);
		if(!$copy->id) {
			return json_encode(['status' => 'error', 'message' => 'Duplizieren fehlgeschlagen']);
		}
		$sanitizer = $this->wire->sanitizer;
		$typStr    = $this->api()->getTypString($copy);
		return json_encode([
			'status'      => 'ok',
			'id'          => $copy->id,
			'titel'       => (string) $copy->mm_titel,
			'basename'    => $this->api()->getPrimaryBasename($copy),
			'dimensions'  => $this->api()->getPrimaryDimensionsStr($copy),
			'filesizeStr' => $this->api()->getPrimaryFilesizeStr($copy),
			'thumb'       => $this->api()->getThumbnailUrlForSlot($copy, 'grid'),
			'typ'         => $typStr,
			'hasImage'    => $this->api()->getPrimaryPageimage($copy) !== null,
			'publicUrl'   => $this->api()->getPublicFileUrl($copy),
		]);
	}

	protected function _ajaxKategorien(): string {
		$kategorien = $this->api()->getKategorien();
		$result     = [];

		foreach($kategorien as $kat) {
			$result[] = [
				'id'    => $kat->id,
				'titel' => $this->wire->sanitizer->entities($kat->title),
			];
		}

		return json_encode(['status' => 'ok', 'kategorien' => $result]);
	}

	protected function _ajaxSaveKategorie(): string {
		$sanitizer = $this->wire->sanitizer;
		$titel     = $sanitizer->text($this->wire->input->post('titel') ?: '');
		$parentId  = (int) $this->wire->input->post('parent_id');

		if(!$titel) {
			return json_encode(['status' => 'error', 'message' => 'Titel fehlt']);
		}

		$kat = $this->api()->createKategorie($titel, $parentId);
		return json_encode([
			'status' => $kat->id ? 'ok' : 'error',
			'id'     => $kat->id,
			'titel'  => $sanitizer->entities($kat->title),
		]);
	}

	protected function _ajaxDeleteKategorie(): string {
		$id      = (int) $this->wire->input->post('id');
		$success = $id ? $this->api()->deleteKategorie($id) : false;

		return json_encode([
			'status'  => $success ? 'ok' : 'error',
			'message' => $success ? '' : 'Kategorie enthält noch Elemente oder wurde nicht gefunden',
		]);
	}

	// -----------------------------------------------------------------------
	// Render-Methoden
	// -----------------------------------------------------------------------

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

	// -----------------------------------------------------------------------
	// Sicherheits-Helfer
	// -----------------------------------------------------------------------

	protected function _requirePermission(): void {
		if(!$this->wire->user->hasPermission('medien-manager')) {
			throw new WirePermissionException('Zugriff verweigert: Medien Manager');
		}
	}

	protected function _validateCsrf(): void {
		$this->wire->session->CSRF->validate();
	}

	protected function _extToTyp(string $ext): string {
		$map = [
			'jpg' => 'bild', 'jpeg' => 'bild', 'png' => 'bild', 'gif' => 'bild', 'webp' => 'bild',
			'mp4' => 'video', 'mov' => 'video',
			'pdf' => 'pdf',
		];
		return $map[$ext] ?? 'bild';
	}

	/**
	 * Lesbare Meldung zu PHP-Upload-Fehlercodes (inkl. Größenlimits php.ini).
	 */
	protected function _uploadErrorMessage(int $code): string {
		switch($code) {
			case UPLOAD_ERR_INI_SIZE:
				return 'Datei zu groß (Server-Limit upload_max_filesize/post_max_size in php.ini).';
			case UPLOAD_ERR_FORM_SIZE:
				return 'Datei zu groß (Formularlimit).';
			case UPLOAD_ERR_PARTIAL:
				return 'Datei nur teilweise übertragen.';
			case UPLOAD_ERR_NO_FILE:
				return 'Keine Datei übertragen.';
			case UPLOAD_ERR_NO_TMP_DIR:
				return 'Temporäres Verzeichnis fehlt (PHP).';
			case UPLOAD_ERR_CANT_WRITE:
				return 'Datei konnte nicht geschrieben werden.';
			case UPLOAD_ERR_EXTENSION:
				return 'Upload durch PHP-Erweiterung blockiert.';
			default:
				return 'Upload fehlgeschlagen (PHP-Code ' . $code . ').';
		}
	}

	// -----------------------------------------------------------------------
	// Modul-Konfiguration
	// -----------------------------------------------------------------------

	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$wire    = ProcessWire::getCurrentInstance();
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