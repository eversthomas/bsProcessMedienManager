<?php namespace ProcessWire;

require_once __DIR__ . '/lib/MediaManagerAjaxTrait.php';
require_once __DIR__ . '/lib/MediaManagerRenderTrait.php';

/**
 * bsProcessMedienManager
 *
 * Haupt-Process-Modul des Medien Manager Bundles.
 * Stellt die Admin-Oberfläche (Grid, Upload, Edit, Bildbearbeitung, Kategorien)
 * sowie alle AJAX-Endpunkte bereit, die vom InputfieldMedienManager genutzt werden.
 *
 * Architektur (Phase 4): Ausführungslogik und Konfiguration hier; AJAX- und Render-Markup
 * in {@see MediaManagerAjaxTrait} bzw. {@see MediaManagerRenderTrait} unter `lib/`.
 *
 * @copyright 2026 bsProcessMedienManager
 */
class bsProcessMedienManager extends Process implements ConfigurableModule {

	use MediaManagerAjaxTrait;
	use MediaManagerRenderTrait;

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
			'version'     => 1.8,
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
			'bulk-webp',
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
			case 'bulk-webp':        return $this->_ajaxBulkWebp();
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
			$this->api()->createVariants($this->api()->getMediaItem($item->id));
			$itemFresh = $this->api()->getMediaItem($item->id);
			$itemFresh->of(false);
			$bild = $this->api()->getPrimaryPageimage($itemFresh);
			if(!$bild instanceof Pageimage) {
				return json_encode(['status' => 'error', 'message' => 'Kein Bild']);
			}
			$bust = (string) time();
			return json_encode(['status' => 'ok', 'url' => $bild->url . '?cb=' . $bust, 'width' => $bild->width, 'height' => $bild->height]);
		}

		if($isAjax && $input->post('action') === 'resize') {
			$this->_validateCsrf();
			header('Content-Type: application/json; charset=utf-8');
			$w = max(1, (int) $input->post('width'));
			$h = max(1, (int) $input->post('height'));
			$cropRaw = strtolower(trim((string) $input->post('cropping')));
			$cropping = ($cropRaw === '1' || $cropRaw === 'true' || $cropRaw === 'yes');
			$item->of(false);
			$bild = $this->api()->getPrimaryPageimage($item);
			if(!$bild instanceof Pageimage) {
				return json_encode(['status' => 'error', 'message' => 'Kein Bild']);
			}
			if(!$this->api()->resizeMasterPageimage($bild, $w, $h, $cropping)) {
				$this->log('Master-Resize fehlgeschlagen für Pageimage ' . $bild->filename, true);
				return json_encode(['status' => 'error', 'message' => 'Größenänderung fehlgeschlagen']);
			}
			$this->api()->createVariants($this->api()->getMediaItem($item->id));
			$itemFresh = $this->api()->getMediaItem($item->id);
			$itemFresh->of(false);
			$bild = $this->api()->getPrimaryPageimage($itemFresh);
			if(!$bild instanceof Pageimage) {
				return json_encode(['status' => 'error', 'message' => 'Kein Bild']);
			}
			$bust = (string) time();
			return json_encode([
				'status' => 'ok',
				'url'    => $bild->url . '?cb=' . $bust,
				'width'  => $bild->width,
				'height' => $bild->height,
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
		// Konfiguration leeren (README: keine Medien/Felder automatisch löschen), dann Admin-Page
		$this->api->uninstall();
		parent::___uninstall();
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