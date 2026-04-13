<?php namespace ProcessWire;

class MediaManagerAPI {

	protected $wire;

	const ROOT_TEMPLATE      = 'medienmanager-root';
	const ITEM_TEMPLATE      = 'medienmanager-item';
	const KATEGORIE_TEMPLATE = 'medienmanager-kategorie';
	const ROOT_NAME          = 'medienmanager';

	/**
	 * Standard-Vorschau-Variation (Breite × Höhe in px).
	 *
	 * Wird beim Bild-Upload per Pageimage::size() als physische Datei im selben
	 * Ordner wie das Original abgelegt (ProcessWire-Namensmuster z. B. foto.300x300.jpg).
	 *
	 * @see https://processwire.com/api/ref/pageimage/size/
	 */
	public const THUMB_WIDTH  = 300;
	public const THUMB_HEIGHT = 300;

	public function __construct(ProcessWire $wire) {
		$this->wire = $wire;
	}

	protected function _getRootPage(): Page {
		$adminId = $this->wire->config->adminRootPageID;
		return $this->wire->pages->get("name=" . self::ROOT_NAME . ", parent=$adminId, template=" . self::ROOT_TEMPLATE . ", include=all");
	}

	public function getRootPageId(): int {
		$config = $this->wire->modules->getModuleConfigData('bsProcessMedienManager');
		$id = isset($config['rootPageID']) ? (int) $config['rootPageID'] : 0;
		if($id <= 0) $id = $this->_getRootPage()->id;
		return (int) $id;
	}

	public function findMedia(array $filters = [], int $start = 0, int $limit = 24): PageArray {
		$sanitizer = $this->wire->sanitizer;
		$selector = "template=" . self::ITEM_TEMPLATE . ", include=hidden, sort=-created";

		if(!empty($filters['typ'])) {
			$typ = $sanitizer->name($filters['typ']);
			$selector .= ", mm_typ.title=$typ";
		}
		if(!empty($filters['kategorie_id'])) {
			$katId = (int) $filters['kategorie_id'];
			if($katId > 0) $selector .= ", mm_kategorie=$katId";
		}
		if(!empty($filters['q'])) {
			$q = $sanitizer->selectorValue($filters['q']);
			$selector .= ", mm_titel%=$q|mm_tags%=$q";
		}

		$selector .= ", start=$start, limit=$limit";
		return $this->wire->pages->find($selector);
	}

	public function getMediaItem(int $id): Page {
		return $this->wire->pages->get("id=" . (int)$id . ", template=" . self::ITEM_TEMPLATE . ", include=all");
	}

	public function getKategorien(int $parentId = 0): PageArray {
		if($parentId <= 0) $parentId = $this->getRootPageId();
		return $this->wire->pages->find("parent=$parentId, template=" . self::KATEGORIE_TEMPLATE . ", include=hidden, sort=title");
	}

	public function createMediaItem(array $data, string $uploadedFile = '', string $originalName = ''): Page {
		$sanitizer = $this->wire->sanitizer;
		$rootId = $this->getRootPageId();
		if($rootId <= 0) return $this->wire->pages->newNullPage();

		$p = $this->wire->pages->newPage(['template' => self::ITEM_TEMPLATE, 'parent' => $rootId]);
		$p->title = $sanitizer->text($data['titel'] ?? 'Unbenannt');
		$p->mm_titel = $p->title;
		
		$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
		$p->mm_typ = $typMap[$data['typ'] ?? 'bild'] ?? 1;

		if(!empty($data['kategorie_id'])) {
			$kat = $this->wire->pages->get((int)$data['kategorie_id']);
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
			} else {
				$p->mm_datei->add($uploadedFile);
				if($safeBasename !== '') $p->mm_datei->last()->rename($safeBasename);
				$p->save();
			}
		}
		return $p;
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

			$thumb = $img->size(self::THUMB_WIDTH, self::THUMB_HEIGHT, $this->thumbnailSizeOptions());
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

	/** Optionen für Vorschau-Größen (Grid, Picker, Upload-Nachgenerierung). */
	protected function thumbnailSizeOptions(): array {
		return ['cropping' => true];
	}

	public function getThumbnailUrl(Page $item, int $width = self::THUMB_WIDTH, int $height = self::THUMB_HEIGHT): string {
		if($item->hasField('mm_bild') && count($item->mm_bild)) {
			return $item->mm_bild->first()->size($width, $height, $this->thumbnailSizeOptions())->url;
		}
		return '';
	}

	public function getTypString(Page $item): string {
		if (!$item->id || !$item->mm_typ) return 'bild';
		return strtolower((string) $item->mm_typ->title);
	}

    public function getMediaTypeIconHtml(string $typ, string $spanClass = 'mm-grid-icon'): string {
        $map = ['video' => 'fa-film', 'pdf' => 'fa-file-pdf-o', 'bild' => 'fa-file-image-o'];
        $icon = $map[$typ] ?? 'fa-file-o';
        return "<span class='{$spanClass}'><i class='fa {$icon}'></i></span>";
    }

	public function saveMediaItem(int $id, array $data): Page {
		$p = $this->getMediaItem($id);
		if(!$p->id) return $p;
		$p->of(false);
		$p->mm_titel = $this->wire->sanitizer->text($data['titel']);
		if(isset($data['beschreibung'])) $p->mm_beschreibung = $data['beschreibung'];
		if(isset($data['kategorie_id'])) $p->mm_kategorie = (int)$data['kategorie_id'] ?: null;
		$p->save();
		return $p;
	}

	public function deleteMedia(int $id): bool {
		$p = $this->getMediaItem($id);
		if($p->id) return $this->wire->pages->delete($p, true);
		return false;
	}

	public function install(): void {
		$this->_installFelder();
		$this->_installFieldgroups();
		$this->_installTemplates();
		$this->_installRootPage();
	}

	protected function _installFelder(): void {
		$fields = $this->wire->fields;
		$pw = $this->wire;
		
		$fMap = [
			'mm_bild' => ['type' => 'FieldtypeImage', 'label' => 'Bild', 'ext' => 'jpg jpeg png gif webp'],
			'mm_datei' => ['type' => 'FieldtypeFile', 'label' => 'Datei', 'ext' => 'pdf mp4 mov'],
			'mm_titel' => ['type' => 'FieldtypeText', 'label' => 'Titel'],
			'mm_typ' => ['type' => 'FieldtypeOptions', 'label' => 'Typ'],
			'mm_kategorie' => ['type' => 'FieldtypePage', 'label' => 'Kategorie']
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
		foreach(['title', 'mm_bild', 'mm_datei', 'mm_titel', 'mm_typ', 'mm_kategorie'] as $fn) {
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

	protected function _installRootPage(): Page {
		$admin = $this->wire->pages->get($this->wire->config->adminRootPageID);
		$root = $this->wire->pages->get("name=" . self::ROOT_NAME);
		if(!$root->id) {
			$root = $this->wire->pages->newPage(['template' => 'admin', 'parent' => $admin, 'name' => self::ROOT_NAME, 'title' => 'Medien Manager']);
			$root->save();
		}
		return $root;
	}

	public function uninstall(): void {} // Implementierung bei Bedarf
}