<?php namespace ProcessWire;

/**
 * Zentrale Domänen-API für Medien-Items (Pages unter dem Manager-Root).
 *
 * Kapselt Suche, Erstellung, Dateien, Kategorien und Bildoperationen (Variationen, Master-Resize,
 * WebP). Wird vom Process-Modul, Upload-Flows und optional von anderen Modulen genutzt.
 */
class MediaManagerAPI {

	/** @var ProcessWire */
	protected $wire;

	const ROOT_TEMPLATE      = 'medienmanager-root';
	const ITEM_TEMPLATE      = 'medienmanager-item';
	const KATEGORIE_TEMPLATE = 'medienmanager-kategorie';
	const ROOT_NAME          = 'medienmanager';

	/**
	 * Standard-Vorschau-Variation beim Upload (entspricht Slot „grid“).
	 * Physische Datei per Pageimage::size() neben dem Original.
	 *
	 * @see https://processwire.com/api/ref/pageimage/size/
	 */
	public const THUMB_WIDTH  = 300;
	public const THUMB_HEIGHT = 300;

	/** Vorschau-Slot: Medien-Grid (Kacheln) */
	public const SLOT_GRID_W = 300;
	public const SLOT_GRID_H = 300;
	/** Modal-Picker */
	public const SLOT_PICKER_W = 160;
	public const SLOT_PICKER_H = 120;
	/** Bearbeiten-Formular Vorschau */
	public const SLOT_EDIT_W = 300;
	public const SLOT_EDIT_H = 225;
	/** Inputfield-Chips */
	public const SLOT_CHIP_W = 120;
	public const SLOT_CHIP_H = 90;

	public function __construct(ProcessWire $wire) {
		$this->wire = $wire;
	}

	/**
	 * Verwaltungs-Root-Page (versteckt unter Admin), Fallback aus Modul-Konfiguration.
	 */
	protected function _getRootPage(): Page {
		$adminId = (int) $this->wire->config->adminRootPageID;
		// Root wird bei install als Child von admin angelegt (Template i. d. R. „admin“, nicht medienmanager-root).
		return $this->wire->pages->get("name=" . self::ROOT_NAME . ", parent=$adminId, include=all");
	}

	/** @return int Page-ID des Medien-Manager-Roots (Konfiguration oder ermittelt) */
	public function getRootPageId(): int {
		$config = $this->wire->modules->getModuleConfigData('bsProcessMedienManager');
		$id = isset($config['rootPageID']) ? (int) $config['rootPageID'] : 0;
		if($id <= 0) $id = $this->_getRootPage()->id;
		return (int) $id;
	}

	/**
	 * Medien-Items mit optionalen Filtern (Typ, Kategorie, Volltextsuche).
	 *
	 * @param array{typ?: string, typs?: list<string>, kategorie_id?: int, q?: string} $filters
	 */
	public function findMedia(array $filters = [], int $start = 0, int $limit = 24): PageArray {
		$sanitizer = $this->wire->sanitizer;
		$selector = "template=" . self::ITEM_TEMPLATE . ", include=hidden, sort=-created";

		if(!empty($filters['typ'])) {
			$typ = $sanitizer->name($filters['typ']);
			$selector .= ", mm_typ.title=$typ";
		} elseif(!empty($filters['typs']) && is_array($filters['typs'])) {
			$parts = [];
			foreach($filters['typs'] as $t) {
				$t = $sanitizer->name((string) $t);
				if($t !== '') {
					$parts[] = "mm_typ.title=$t";
				}
			}
			if(count($parts) === 1) {
				$selector .= ', ' . $parts[0];
			} elseif(count($parts) > 1) {
				$selector .= ', (' . implode('|', $parts) . ')';
			}
		}
		if(!empty($filters['kategorie_id'])) {
			$katId = (int) $filters['kategorie_id'];
			if($katId > 0) $selector .= ", mm_kategorie=$katId";
		}
		if(!empty($filters['q'])) {
			$q = $sanitizer->selectorValue($filters['q']);
			// OR über Felder: mm_titel|mm_tags%=wert — NICHT mm_titel%=wert|mm_tags%=wert
			// (sonst endet der Wert beim ersten | und mm_tags wird ignoriert).
			$suchFelder = ['mm_titel'];
			if($this->wire->fields->get('mm_tags')) {
				$suchFelder[] = 'mm_tags';
			}
			if($this->wire->fields->get('mm_beschreibung')) {
				$suchFelder[] = 'mm_beschreibung';
			}
			if($this->wire->fields->get('mm_alt')) {
				$suchFelder[] = 'mm_alt';
			}
			if($this->wire->fields->get('mm_caption')) {
				$suchFelder[] = 'mm_caption';
			}
			$selector .= ', ' . implode('|', $suchFelder) . '%=' . $q;
		}

		$selector .= ", start=$start, limit=$limit";
		return $this->wire->pages->find($selector);
	}

	/** Einzelnes Medien-Item oder leere Page bei ungültiger ID. */
	public function getMediaItem(int $id): Page {
		return $this->wire->pages->get("id=" . (int)$id . ", template=" . self::ITEM_TEMPLATE . ", include=all");
	}

	/** Kategorie-Pages unter dem Root (oder unter $parentId). */
	public function getKategorien(int $parentId = 0): PageArray {
		if($parentId <= 0) $parentId = $this->getRootPageId();
		return $this->wire->pages->find("parent=$parentId, template=" . self::KATEGORIE_TEMPLATE . ", include=hidden, sort=title");
	}

	/**
	 * Neues Medien-Item inkl. optionaler Datei (Bild → mm_bild, sonst mm_datei).
	 *
	 * @param array{titel?: string, typ?: string, beschreibung?: string, tags?: string, kategorie_id?: int} $data
	 */
	public function createMediaItem(array $data, string $uploadedFile = '', string $originalName = ''): Page {
		$sanitizer = $this->wire->sanitizer;
		$rootId = $this->getRootPageId();
		if($rootId <= 0) return $this->wire->pages->newNullPage();

		$p = $this->wire->pages->newPage(['template' => self::ITEM_TEMPLATE, 'parent' => $rootId]);
		$p->title = $sanitizer->text($data['titel'] ?? 'Unbenannt');
		$p->mm_titel = $p->title;

		$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
		$p->mm_typ = $typMap[$data['typ'] ?? 'bild'] ?? 1;

		if($p->hasField('mm_beschreibung') && array_key_exists('beschreibung', $data)) {
			$p->mm_beschreibung = $this->wire->sanitizer->textarea((string) $data['beschreibung']);
		}
		if($p->hasField('mm_tags') && array_key_exists('tags', $data)) {
			$p->mm_tags = $sanitizer->text((string) $data['tags']);
		}

		if(!empty($data['kategorie_id'])) {
			$kat = $this->wire->pages->get((int) $data['kategorie_id']);
			if($kat->id) $p->mm_kategorie = $kat;
		}

		$p->addStatus(Page::statusHidden);
		$p->save();

		if($uploadedFile && file_exists($uploadedFile)) {
			$p->of(false);
			$safeBasename = '';
			if($originalName !== '') {
				$safeBasename = $sanitizer->filename($originalName, true);
			}
			$ext = strtolower(pathinfo($safeBasename !== '' ? $safeBasename : $uploadedFile, PATHINFO_EXTENSION));
			$isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);

			if($isImage && $p->hasField('mm_bild')) {
				$p->mm_bild->add($uploadedFile);
				if($safeBasename !== '') $p->mm_bild->last()->rename($safeBasename);
				$p->save();
				$this->createVariants($p);
				$this->ensureWebpAfterVariants($p);
			} else {
				$p->mm_datei->add($uploadedFile);
				if($safeBasename !== '') $p->mm_datei->last()->rename($safeBasename);
				$p->save();
			}
		}
		return $p;
	}

	/**
	 * Nach `createVariants()`: WebP-Nebenversion für das primäre Bild (wie Bulk „WebP erzeugen“).
	 */
	protected function ensureWebpAfterVariants(Page $p): void {
		$pid = (int) $p->id;
		if($pid <= 0) return;
		$fresh = $this->wire->pages->getFresh($pid);
		if(!$fresh->id) return;
		$fresh->of(false);
		$img = $this->getPrimaryPageimage($fresh);
		if($img instanceof Pageimage) {
			$this->ensureWebpForPageimage($img);
		}
	}

	/**
	 * Legt die feste Admin-/Grid-Vorschau als Pageimage-Variation auf der Festplatte an.
	 *
	 * ProcessWire erzeugt keine „Thumbnails“ als separaten Mechanismus: Variationen entstehen
	 * durch $image->size($w, $h, $options) im files-Verzeichnis der Page (neben dem Original).
	 * Optional am Feld mm_bild: maxWidth/maxHeight (ImageField) — skaliert dann die Hauptdatei
	 * beim Upload, nicht als zweite Datei. Benannte Presets: $config->imageSizes (seit 3.0.151).
	 *
	 * Wichtig: Nach mm_bild->save() liefert pages->get() oft dieselbe gecachte Page ohne
	 * frisch geladene Dateien — dann ist mm_bild leer und size() wird nie aufgerufen.
	 * pages->getFresh() lädt die Page bewusst neu aus der DB (siehe PW-API pages->getFresh).
	 */
	public function createVariants(Page $p): void {
		$pid = (int) $p->id;
		if($pid <= 0) return;

		$page = $this->wire->pages->getFresh($pid);
		if(!$page->id || !$page->hasField('mm_bild') || !count($page->mm_bild)) {
			$this->wire->log->save('medienmanager', "createVariants: keine mm_bild nach Upload (Page $pid)");
			return;
		}

		try {
			$page->of(false);
			$img = $page->mm_bild->first();
			if(!$img instanceof Pageimage) {
				$this->wire->log->save('medienmanager', 'createVariants: kein Pageimage, Klasse=' . ($img ? get_class($img) : 'null'));
				return;
			}
			if(!is_file($img->filename())) {
				$this->wire->log->error('MM Variants: Original fehlt: ' . $img->filename());
				return;
			}

			$thumb = $img->size(self::SLOT_GRID_W, self::SLOT_GRID_H, $this->thumbnailSizeOptions());
			if($img->error) {
				$this->wire->log->error('MM Variants: ' . $img->error);
				return;
			}
			if(!$thumb->filename || !is_file($thumb->filename())) {
				$this->wire->log->error('MM Variants: Variationsdatei fehlt (Page ' . $pid . ')');
			}
		} catch(\Throwable $e) {
			$this->wire->log->error('MM Variants: ' . $e->getMessage());
		}
	}

	/**
	 * Optionen für Vorschau-Größen (Grid, Picker, Upload-Nachgenerierung).
	 * cropping: false = gesamtes Bild in die Box skaliert (ggf. Letterboxing), kein harter Zuschnitt.
	 */
	protected function thumbnailSizeOptions(): array {
		return ['cropping' => false];
	}

	/**
	 * Dreht die Original-Datei eines Pageimage per ImageSizer (In-Place), löscht veraltete Variationen.
	 *
	 * Hinweis: ProcessWire hat kein Pageimage::rotate(); Rotation erfolgt über ImageSizerEngine auf der Quelldatei.
	 *
	 * @return bool True bei Erfolg
	 */
	public function rotateMasterPageimage(Pageimage $bild, int $degrees): bool {
		if(!in_array(abs($degrees), [90, 180, 270], true)) return false;
		$filename = $bild->filename();
		if(!is_file($filename) || !is_readable($filename)) return false;

		$sizer = new ImageSizer($filename, []);
		$this->wire->wire($sizer);
		if(!$sizer->rotate($degrees)) {
			return false;
		}
		$bild->removeVariations();
		$bild->getImageInfo(true);
		return true;
	}

	/**
	 * Skaliert oder beschneidet die **Originaldatei** (Master) per ImageSizer — keine nur neue Variation wie `Pageimage::size()`.
	 *
	 * @param bool $cropping True: Zielbox wird gefüllt (ggf. beschnitten); False: proportionales Einpassen in die Box.
	 */
	public function resizeMasterPageimage(Pageimage $bild, int $width, int $height, bool $cropping = false): bool {
		$width  = max(1, $width);
		$height = max(1, $height);
		if(strtolower((string) $bild->ext) === 'svg') return false;
		$filename = $bild->filename();
		if(!is_file($filename) || !is_readable($filename)) return false;

		$options = [
			'cropping'  => $cropping,
			'upscaling' => false,
		];
		$sizer = new ImageSizer($filename, $options);
		$this->wire->wire($sizer);
		if(!$sizer->resize($width, $height)) {
			return false;
		}
		$bild->removeVariations();
		$bild->getImageInfo(true);
		return true;
	}

	/**
	 * Erzeugt eine .webp-Nebenversion falls die Engine es unterstützt und die Datei noch fehlt.
	 *
	 * @return bool True wenn WebP existiert oder neu erzeugt wurde
	 */
	public function ensureWebpForPageimage(Pageimage $img): bool {
		if(strtolower((string) $img->ext) === 'svg') return false;
		$webp = $img->webp();
		if($webp->exists()) return true;
		return (bool) $webp->create();
	}

	/** Vorschau-URL (Pageimage-Variation) mit festen Maßen. */
	public function getThumbnailUrl(Page $item, int $width = self::SLOT_GRID_W, int $height = self::SLOT_GRID_H): string {
		$img = $this->getPrimaryPageimage($item);
		if(!$img instanceof Pageimage) return '';
		return $img->size($width, $height, $this->thumbnailSizeOptions())->url;
	}

	/**
	 * Thumbnail-URL für einen definierten Anzeige-Slot (einheitliche Maße im gesamten Modul).
	 *
	 * @param string $slot grid | picker | edit | chip
	 */
	public function getThumbnailUrlForSlot(Page $item, string $slot): string {
		$slot = strtolower($slot);
		$w    = self::SLOT_GRID_W;
		$h    = self::SLOT_GRID_H;
		if($slot === 'picker') {
			$w = self::SLOT_PICKER_W;
			$h = self::SLOT_PICKER_H;
		} elseif($slot === 'edit') {
			$w = self::SLOT_EDIT_W;
			$h = self::SLOT_EDIT_H;
		} elseif($slot === 'chip') {
			$w = self::SLOT_CHIP_W;
			$h = self::SLOT_CHIP_H;
		}
		return $this->getThumbnailUrl($item, $w, $h);
	}

	/** Normalisierter Typ: `bild` | `video` | `pdf`. */
	public function getTypString(Page $item): string {
		if (!$item->id || !$item->mm_typ) return 'bild';
		return strtolower((string) $item->mm_typ->title);
	}

	/**
	 * Text für `alt` und Screenreader: zuerst mm_alt, sonst sinnvoller Titel (SEO/A11y).
	 */
	public function getAccessibleLabel(Page $item): string {
		if(!$item->id) return '';
		$san = $this->wire->sanitizer;
		if($item->hasField('mm_alt') && trim((string) $item->mm_alt) !== '') {
			return $san->text((string) $item->mm_alt);
		}
		$t = trim((string) ($item->mm_titel ?: $item->title));

		return $t !== '' ? $san->text($t) : '';
	}

	/**
	 * Bildunterschrift (mm_caption) — kann leer sein.
	 */
	public function getCaption(Page $item): string {
		if(!$item->id || !$item->hasField('mm_caption')) return '';

		return $this->wire->sanitizer->text((string) $item->mm_caption);
	}

	/**
	 * True, wenn für Frontend-Ausgabe ein echtes Rasterbild (Pageimage) vorliegt.
	 */
	public function hasRenderableImage(Page $item): bool {
		return $this->getPrimaryPageimage($item) instanceof Pageimage;
	}

	/** Font-Awesome-Markup für Typ-Icon (Admin). */
	public function getMediaTypeIconHtml(string $typ, string $spanClass = 'mm-grid-icon'): string {
		$map  = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o', 'bild' => 'fa-file-image-o'];
		$icon = $map[$typ] ?? 'fa-file-o';
		return "<span class='{$spanClass}'><i class='fa {$icon}'></i></span>";
	}

	/**
	 * Metadaten eines Medien-Items speichern (Titel, Typ, Kategorie, Tags, Alt, Caption …).
	 *
	 * @param array<string, mixed> $data
	 */
	public function saveMediaItem(int $id, array $data): Page {
		$p = $this->getMediaItem($id);
		if(!$p->id) return $p;
		$p->of(false);
		$sanitizer = $this->wire->sanitizer;
		$p->mm_titel = $sanitizer->text($data['titel'] ?? '');
		if(isset($data['beschreibung']) && $p->hasField('mm_beschreibung')) {
			$p->mm_beschreibung = $sanitizer->textarea((string) $data['beschreibung']);
		}
		if(isset($data['tags']) && $p->hasField('mm_tags')) {
			$p->mm_tags = $sanitizer->text((string) $data['tags']);
		}
		if(isset($data['typ']) && $data['typ'] !== '') {
			$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
			$t      = $sanitizer->name((string) $data['typ']);
			if(isset($typMap[$t])) $p->mm_typ = $typMap[$t];
		}
		if(array_key_exists('kategorie_id', $data)) {
			$p->mm_kategorie = (int) $data['kategorie_id'] ?: null;
		}
		if(isset($data['alt']) && $p->hasField('mm_alt')) {
			$p->mm_alt = $sanitizer->text((string) $data['alt']);
		}
		if(isset($data['caption']) && $p->hasField('mm_caption')) {
			$p->mm_caption = $sanitizer->text((string) $data['caption']);
		}
		$p->save();
		return $p;
	}

	/**
	 * Öffentliche URL der primären Datei (für Einbindung im Frontend).
	 */
	public function getPublicFileUrl(Page $item): string {
		$img = $this->getPrimaryPageimage($item);
		if($img instanceof Pageimage) {
			$u = $img->httpUrl ?? '';
			return $u !== '' ? (string) $u : (string) $img->url;
		}
		$file = $this->getPrimaryNonImageFile($item);
		if($file instanceof Pagefile) {
			$u = $file->httpUrl ?? '';
			return $u !== '' ? (string) $u : (string) $file->url;
		}
		return '';
	}

	/**
	 * Dupliziert Medien-Page inkl. Dateien (Clone, nicht rekursiv).
	 */
	public function duplicateMediaItem(int $id): Page {
		$p = $this->getMediaItem($id);
		if(!$p->id) return $this->wire->pages->newNullPage();
		$parent = $this->wire->pages->get($this->getRootPageId());
		if(!$parent->id) return $this->wire->pages->newNullPage();
		$copy = $this->wire->pages->clone($p, $parent, false);
		if(!$copy->id) return $this->wire->pages->newNullPage();
		$copy->of(false);
		$base = (string) ($p->mm_titel ?: $p->title);
		$copy->mm_titel = $base . ' (Kopie)';
		$copy->title = $copy->mm_titel;
		$copy->save();
		return $copy;
	}

	/**
	 * Primäre Datei (Bild oder Video/PDF) durch Upload ersetzen; Page-ID bleibt gleich.
	 *
	 * @return bool True bei Erfolg
	 */
	public function replacePrimaryFile(Page $item, string $tmpPath, string $originalName): bool {
		if(!$item->id) return false;
		if($tmpPath === '' || !is_uploaded_file($tmpPath)) return false;

		$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

		$item->of(false);
		$img = $this->getPrimaryPageimage($item);
		if($img instanceof Pageimage) {
			$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
			if(!in_array($ext, $allowed, true)) return false;
			$oldExt = strtolower(pathinfo($img->filename(), PATHINFO_EXTENSION));
			if(!$this->fileExtensionMatches($ext, $oldExt)) return false;
			if(!$img->replaceFile($tmpPath, true)) return false;
			$item->save();
			$this->createVariants($item);
			$this->ensureWebpAfterVariants($item);
			return true;
		}
		$file = $this->getPrimaryNonImageFile($item);
		if($file instanceof Pagefile) {
			$allowed = ['pdf', 'mp4', 'mov'];
			if(!in_array($ext, $allowed, true)) return false;
			$oldExt = strtolower(pathinfo($file->filename(), PATHINFO_EXTENSION));
			if(!$this->fileExtensionMatches($ext, $oldExt)) return false;
			if(!$file->replaceFile($tmpPath, true)) return false;
			$item->save();
			return true;
		}
		return false;
	}

	/**
	 * @param string $newExt Endung der neuen Datei (Kleinbuchstaben)
	 * @param string $oldExt Endung der bestehenden Datei (Kleinbuchstaben)
	 */
	protected function fileExtensionMatches(string $newExt, string $oldExt): bool {
		if($newExt === $oldExt) return true;
		if($newExt === 'jpeg' && $oldExt === 'jpg') return true;
		if($newExt === 'jpg' && $oldExt === 'jpeg') return true;
		return false;
	}

	/** Medien-Item und zugehörige Dateien löschen. */
	public function deleteMedia(int $id): bool {
		$p = $this->getMediaItem($id);
		if($p->id) return $this->wire->pages->delete($p, true);
		return false;
	}

	/** Legt Felder, Templates und Root-Page an (Installation / Upgrade-Hook). */
	public function install(): void {
		$this->_installFelder();
		$this->_installFieldgroups();
		$this->_installTemplates();
		$this->_installKategorieTemplate();
		$this->_installRootPage();
	}

	protected function _installFelder(): void {
		$fields = $this->wire->fields;
		$pw = $this->wire;
		
		$fMap = [
			'mm_bild' => ['type' => 'FieldtypeImage', 'label' => 'Bild', 'ext' => 'jpg jpeg png gif webp'],
			'mm_datei' => ['type' => 'FieldtypeFile', 'label' => 'Datei', 'ext' => 'pdf mp4 mov'],
			'mm_titel' => ['type' => 'FieldtypeText', 'label' => 'Titel'],
			'mm_alt' => ['type' => 'FieldtypeText', 'label' => 'Alternativtext'],
			'mm_caption' => ['type' => 'FieldtypeText', 'label' => 'Beschriftung'],
			'mm_beschreibung' => ['type' => 'FieldtypeTextarea', 'label' => 'Beschreibung'],
			'mm_tags' => ['type' => 'FieldtypeText', 'label' => 'Tags'],
			'mm_typ' => ['type' => 'FieldtypeOptions', 'label' => 'Typ'],
			'mm_kategorie' => ['type' => 'FieldtypePage', 'label' => 'Kategorie'],
		];

		foreach($fMap as $name => $cfg) {
			if(!$fields->get($name)) {
				$f = new Field();
				$f->type = $pw->modules->get($cfg['type']);
				$f->name = $name;
				$f->label = $cfg['label'];
				if(isset($cfg['ext'])) $f->extensions = $cfg['ext'];
				if($name === 'mm_bild') $f->maxFiles = 1;
				$f->save();
				if($name === 'mm_typ') $pw->modules->get('SelectableOptionManager')->setOptionsString($f, "1=bild\n2=video\n3=pdf");
			}
		}
	}

	protected function _installFieldgroups(): void {
		$fg = $this->wire->fieldgroups->get(self::ITEM_TEMPLATE) ?: new Fieldgroup();
		$fg->name = self::ITEM_TEMPLATE;
		foreach(['title', 'mm_bild', 'mm_datei', 'mm_titel', 'mm_alt', 'mm_caption', 'mm_beschreibung', 'mm_tags', 'mm_typ', 'mm_kategorie'] as $fn) {
			if($this->wire->fields->get($fn)) $fg->add($fn);
		}
		$fg->save();
	}

	protected function _installTemplates(): void {
		$t = $this->wire->templates->get(self::ITEM_TEMPLATE) ?: new Template();
		$t->name = self::ITEM_TEMPLATE;
		$t->fieldgroup = $this->wire->fieldgroups->get(self::ITEM_TEMPLATE);
		$t->save();
	}

	/**
	 * Template für Kategorie-Pages (Kinder der Medien-Root-Page).
	 */
	protected function _installKategorieTemplate(): void {
		$fg = $this->wire->fieldgroups->get(self::KATEGORIE_TEMPLATE) ?: new Fieldgroup();
		$fg->name = self::KATEGORIE_TEMPLATE;
		if($this->wire->fields->get('title')) {
			if(!$fg->hasField('title')) $fg->add('title');
		}
		$fg->save();

		$t = $this->wire->templates->get(self::KATEGORIE_TEMPLATE) ?: new Template();
		$t->name = self::KATEGORIE_TEMPLATE;
		$t->fieldgroup = $this->wire->fieldgroups->get(self::KATEGORIE_TEMPLATE);
		$t->save();
	}

	protected function _installRootPage(): Page {
		$admin = $this->wire->pages->get($this->wire->config->adminRootPageID);
		$root = $this->wire->pages->get("name=" . self::ROOT_NAME . ", include=all");
		if(!$root->id) {
			$root = $this->wire->pages->newPage(['template' => 'admin', 'parent' => $admin, 'name' => self::ROOT_NAME, 'title' => 'Medien Manager']);
			$root->save();
		}
		$cfg = $this->wire->modules->getModuleConfigData('bsProcessMedienManager');
		if(empty($cfg['rootPageID']) && $root->id) {
			$cfg['rootPageID'] = (int) $root->id;
			$this->wire->modules->saveModuleConfigData('bsProcessMedienManager', $cfg);
		}
		return $root;
	}

	/**
	 * Primäres Bild für Vorschau und Bildbearbeitung: zuerst mm_bild, sonst erstes Pageimage in mm_datei (Edge-Case).
	 */
	public function getPrimaryPageimage(Page $item): ?Pageimage {
		if($item->hasField('mm_bild')) {
			$mmBild = $item->mm_bild;
			$first = null;
			if($mmBild instanceof Pageimage) {
				$first = $mmBild;
			} elseif($mmBild instanceof Pageimages && $mmBild->count()) {
				$first = $mmBild->first();
			}
			return $first instanceof Pageimage ? $first : null;
		}
		if($item->hasField('mm_datei')) {
			$mmDatei = $item->mm_datei;
			$first = null;
			if($mmDatei instanceof Pageimage) {
				$first = $mmDatei;
			} elseif($mmDatei instanceof Pagefiles && $mmDatei->count()) {
				$first = $mmDatei->first();
			}
			return $first instanceof Pageimage ? $first : null;
		}
		return null;
	}

	/**
	 * Erste nicht-Bild-Datei (Video/PDF) für Icon-Vorschau, falls kein Pageimage gesetzt.
	 */
	public function getPrimaryNonImageFile(Page $item): ?Pagefile {
		if(!$item->hasField('mm_datei') || !\count($item->mm_datei)) return null;
		$first = $item->mm_datei->first();
		if($first instanceof Pageimage) return null;
		return $first instanceof Pagefile ? $first : null;
	}

	/**
	 * Primäre Datei für Anzeige (Dateiname, Größe): Bild bevorzugt, sonst Video/PDF.
	 */
	public function getPrimaryPagefileForDisplay(Page $item): ?Pagefile {
		$img = $this->getPrimaryPageimage($item);
		if($img instanceof Pagefile) return $img;
		return $this->getPrimaryNonImageFile($item);
	}

	/** Dateiname der primären Datei (für Karten-Label / Liste). */
	public function getPrimaryBasename(Page $item): string {
		$f = $this->getPrimaryPagefileForDisplay($item);
		return $f ? (string) $f->basename : '';
	}

	/** Lesbare Dateigröße der primären Datei. */
	public function getPrimaryFilesizeStr(Page $item): string {
		$f = $this->getPrimaryPagefileForDisplay($item);
		return $f ? $f->filesizeStr() : '';
	}

	/** Abmessungen z. B. „1920 × 1080“ oder leer (kein Rasterbild). */
	public function getPrimaryDimensionsStr(Page $item): string {
		$img = $this->getPrimaryPageimage($item);
		if($img instanceof Pageimage && $img->width > 0 && $img->height > 0) {
			return (string) $img->width . ' × ' . (string) $img->height;
		}
		return '';
	}

	/**
	 * Neue Kategorie unter der Medien-Root (oder unter $parentId).
	 */
	public function createKategorie(string $titel, int $parentId = 0): Page {
		$sanitizer = $this->wire->sanitizer;
		$titel     = $sanitizer->text($titel);
		if($titel === '') return $this->wire->pages->newNullPage();

		if($parentId <= 0) $parentId = $this->getRootPageId();
		$parent = $this->wire->pages->get((int) $parentId);
		if(!$parent->id) return $this->wire->pages->newNullPage();

		$baseName = $sanitizer->pageName($titel);
		if($baseName === '') $baseName = 'kategorie';
		$name = $this->wire->pages->names()->uniquePageName($baseName, null, ['parent' => $parent]);

		$p = $this->wire->pages->newPage([
			'template' => self::KATEGORIE_TEMPLATE,
			'parent'   => $parent,
			'name'     => $name,
			'title'    => $titel,
		]);
		$p->addStatus(Page::statusHidden);
		$p->save();
		return $p;
	}

	/**
	 * Kategorie löschen, nur wenn keine Medien-Items sie nutzen.
	 */
	public function deleteKategorie(int $id): bool {
		$p = $this->wire->pages->get((int) $id);
		if(!$p->id || $p->template->name !== self::KATEGORIE_TEMPLATE) return false;

		$n = $this->wire->pages->count('template=' . self::ITEM_TEMPLATE . ', mm_kategorie=' . (int) $id . ', include=all');
		if($n > 0) return false;

		$this->wire->pages->delete($p, true);
		return true;
	}

	/**
	 * Deinstallation: keine Medien-Pages und keine Felder/Templates löschen (Daten behalten).
	 *
	 * Leert nur die Modul-Konfiguration (`bsProcessMedienManager`), damit eine Neuinstallation
	 * nicht mit veralteter `rootPageID` o. Ä. arbeitet. Vollständiges Entfernen von Inhalten
	 * bleibt manuell bzw. über separate Wartung (siehe README.md).
	 */
	public function uninstall(): void {
		$this->wire->modules->saveModuleConfigData('bsProcessMedienManager', []);
	}
}