<?php namespace ProcessWire;

/**
 * MediaManagerAPI
 *
 * Service-Klasse für alle Datenbank- und Page-Operationen des Medien Managers.
 */
class MediaManagerAPI {

	protected $wire;

	const ROOT_TEMPLATE      = 'medienmanager-root';
	const ITEM_TEMPLATE      = 'medienmanager-item';
	const KATEGORIE_TEMPLATE = 'medienmanager-kategorie';
	const ROOT_NAME          = 'medienmanager';

	public function __construct(ProcessWire $wire) {
		$this->wire = $wire;
	}

	// -----------------------------------------------------------------------
	// Öffentliche Such- und Lese-Methoden
	// -----------------------------------------------------------------------

	public function findMedia(array $filters = [], int $start = 0, int $limit = 24): PageArray {
		$sanitizer = $this->wire->sanitizer;
		$selector = "template=" . self::ITEM_TEMPLATE . ", include=hidden, sort=-created";

		if(!empty($filters['typ'])) {
			$typ = $sanitizer->name($filters['typ']);
			if(in_array($typ, ['bild', 'video', 'pdf'])) {
				$selector .= ", mm_typ.title=$typ";
			}
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
		if($id <= 0) return $this->wire->pages->newNullPage();
		return $this->wire->pages->get("id=$id, template=" . self::ITEM_TEMPLATE . ", include=all");
	}

	public function getKategorien(int $parentId = 0): PageArray {
		if($parentId <= 0) {
			$root = $this->_getRootPage();
			if(!$root->id) return $this->wire->pages->newPageArray();
			$parentId = $root->id;
		}
		return $this->wire->pages->find("parent=$parentId, template=" . self::KATEGORIE_TEMPLATE . ", include=hidden, sort=title");
	}

	// -----------------------------------------------------------------------
	// Öffentliche Schreib-Methoden
	// -----------------------------------------------------------------------

	public function createMediaItem(array $data, string $uploadedFile = ''): Page {
        $sanitizer = $this->wire->sanitizer;
        $root = $this->_getRootPage();
        if(!$root->id) return $this->wire->pages->newNullPage();
    
        $p = $this->wire->pages->newPage([
            'template' => self::ITEM_TEMPLATE, 
            'parent' => !empty($data['kategorie_id']) ? (int)$data['kategorie_id'] : $root->id
        ]);
    
        $p->title = $sanitizer->text($data['titel'] ?? 'Unbenannt');
        $p->mm_titel = $p->title;
        
        // Typ-Mapping
        $typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
        $p->mm_typ = $typMap[$sanitizer->name($data['typ'] ?? 'bild')] ?? 1;
    
        $p->addStatus(Page::statusHidden);
        $p->save();
    
        if($uploadedFile && file_exists($uploadedFile)) {
            $p->of(false);
            $originalName = $_FILES['mm_datei']['name'] ?? 'image.jpg';
            
            // Datei hinzufügen
            $p->mm_datei->add($uploadedFile);
            $file = $p->mm_datei->last();
            
            if($file) {
                $file->rename($originalName);
                // Wir speichern hier erst, damit die Datei physisch sicher liegt
                $p->save('mm_datei');
    
                // Erst JETZT Varianten versuchen, wenn es ein Bild ist
                if($this->getTypString($p) === 'bild') {
                    $this->createVariants($p);
                }
            }
        }
        return $p;
    }
    
    /**
     * Erstellt Vorschaubilder für ein Medien-Item.
     * Wird direkt nach dem Upload aufgerufen.
     */
    /**
     * Erstellt Vorschaubilder für ein Medien-Item.
     */
    protected function createVariants(Page $p) {
        try {
            $datei = $p->mm_datei->first();
            if (!$datei) return;
            
            // Wir prüfen, ob es ein Bild ist
            $imgExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array(strtolower($datei->ext), $imgExtensions)) {
                
                // WICHTIG: So holst du das Pageimage-Objekt absolut sicher,
                // ohne dass der Konstruktor-Fehler auftritt:
                $img = $p->mm_datei->get((string)$datei->basename);
                
                if ($img) {
                    // Thumbs generieren (das schreibt die Dateien auf die Platte)
                    $img->size(200, 150, ['cropping' => true]);
                    $img->size(400, 300, ['cropping' => false]);
                }
            }
        } catch (\Exception $e) {
            $this->wire->log->save('medienmanager', "Variants Error: " . $e->getMessage());
        }
    }

    /**
     * Thumbnail-URL für ein Medien-Item ermitteln.
     */
    public function getThumbnailUrl(Page $item, int $width = 200, int $height = 150): string {
        if (!$item->id || !count($item->mm_datei)) return '';
        
        $datei = $item->mm_datei->first();
        if ($this->getTypString($item) !== 'bild') return '';

        // Sicherer Zugriff auf das Bild-Objekt über das Feld-Objekt
        // Das verhindert den Konstruktor-Fehler von vorhin
        $img = $item->mm_datei->get((string)$datei->basename);
        
        if($img) {
            return $img->size($width, $height, ['cropping' => true])->url;
        }
        
        return $datei->url; // Fallback auf das Originalbild
    }
    

	public function saveMediaItem(int $id, array $data): Page {
		$sanitizer = $this->wire->sanitizer;
		$p = $this->getMediaItem($id);
		if(!$p->id) return $this->wire->pages->newNullPage();

		$p->of(false);
		if(isset($data['titel'])) {
			$p->title = $sanitizer->text($data['titel']);
			$p->mm_titel = $sanitizer->text($data['titel']);
		}
		if(isset($data['beschreibung'])) $p->mm_beschreibung = $sanitizer->textarea($data['beschreibung']);
		if(isset($data['tags'])) $p->mm_tags = $sanitizer->text($data['tags']);
		
		if(isset($data['typ'])) {
			$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
			$typKey = $sanitizer->name($data['typ']);
			if(isset($typMap[$typKey])) $p->mm_typ = $typMap[$typKey];
		}
		
		if(isset($data['kategorie_id'])) {
			$katId = (int) $data['kategorie_id'];
			$p->mm_kategorie = ($katId > 0) ? $this->wire->pages->get($katId) : null;
		}

		$p->save();
		return $p;
	}

	public function deleteMedia(int $id): bool {
		$p = $this->getMediaItem($id);
		if(!$p->id) return false;
		$this->wire->pages->delete($p, true);
		return true;
	}

	public function createKategorie(string $titel, int $parentId = 0): Page {
		$root = $this->_getRootPage();
		$parentId = $parentId > 0 ? $parentId : $root->id;
		$p = $this->wire->pages->newPage(['template' => self::KATEGORIE_TEMPLATE, 'parent' => $parentId]);
		$p->title = $this->wire->sanitizer->text($titel);
		$p->addStatus(Page::statusHidden);
		$p->save();
		return $p;
	}

	public function deleteKategorie(int $id): bool {
		$p = $this->wire->pages->get("id=$id, template=" . self::KATEGORIE_TEMPLATE . ", include=all");
		if(!$p->id || $p->numChildren(true) > 0) return false;
		$this->wire->pages->delete($p, true);
		return true;
	}

	public function getTypString(Page $item): string {
		if (!$item->id || !$item->mm_typ) return '';
		return strtolower((string) $item->mm_typ->title);
	}

	// -----------------------------------------------------------------------
	// Install / Uninstall & Internals
	// -----------------------------------------------------------------------

	public function install(): void {
		$this->_installFelder();
		$this->_installFieldgroups();
		$this->_installTemplates();
		$root = $this->_installRootPage();

		$mmKat = $this->wire->fields->get('mm_kategorie');
		if($mmKat && $root->id) {
			$mmKat->parent_id = $root->id;
			$mmKat->save();
		}

		$this->wire->modules->saveModuleConfigData('bsProcessMedienManager', ['rootPageID' => $root->id]);
	}

	public function uninstall(): void {
		$root = $this->_getRootPage();
		if($root->id) $this->wire->pages->delete($root, true);

		foreach([self::ITEM_TEMPLATE, self::KATEGORIE_TEMPLATE, self::ROOT_TEMPLATE] as $name) {
			$t = $this->wire->templates->get($name);
			if($t && !$t->getNumPages()) {
				$fg = $t->fieldgroup;
				$this->wire->templates->delete($t);
				if($fg) $this->wire->fieldgroups->delete($fg);
			}
		}

		foreach(['mm_datei', 'mm_titel', 'mm_beschreibung', 'mm_kategorie', 'mm_tags', 'mm_typ'] as $name) {
			$f = $this->wire->fields->get($name);
			if($f && !count($f->getTemplates())) $this->wire->fields->delete($f);
		}
	}

	protected function _getRootPage(): Page {
		$adminId = $this->wire->config->adminRootPageID;
		return $this->wire->pages->get("name=" . self::ROOT_NAME . ", parent=$adminId, template=" . self::ROOT_TEMPLATE . ", include=all");
	}

	protected function _installFelder(): void {
		$fields = $this->wire->fields;
		$pw = $this->wire;

		if(!$fields->get('mm_datei')) {
			$f = $pw->wire(new Field());
			$f->type = $pw->modules->get('FieldtypeFile');
			$f->name = 'mm_datei';
			$f->label = 'Datei';
			$f->extensions = 'jpg jpeg png gif webp mp4 mov pdf';
			$f->maxFiles = 1;
			$f->flags = Field::flagSystem;
			$f->save();
		}

		if(!$fields->get('mm_titel')) {
			$f = $pw->wire(new Field());
			$f->type = $pw->modules->get('FieldtypeText');
			$f->name = 'mm_titel';
			$f->label = 'Titel';
			$f->flags = Field::flagSystem;
			$f->save();
		}

		if(!$fields->get('mm_beschreibung')) {
			$f = $pw->wire(new Field());
			$f->type = $pw->modules->get('FieldtypeTextarea');
			$f->name = 'mm_beschreibung';
			$f->label = 'Beschreibung';
			$f->flags = Field::flagSystem;
			$f->save();
		}

		if(!$fields->get('mm_tags')) {
			$f = $pw->wire(new Field());
			$f->type = $pw->modules->get('FieldtypeText');
			$f->name = 'mm_tags';
			$f->label = 'Tags';
			$f->flags = Field::flagSystem;
			$f->save();
		}

		if(!$fields->get('mm_typ')) {
			$f = $pw->wire(new Field());
			$f->type = $pw->modules->get('FieldtypeOptions');
			$f->name = 'mm_typ';
			$f->label = 'Medientyp';
			$f->flags = Field::flagSystem;
			$f->save();
			$manager = $pw->modules->get('SelectableOptionManager');
			if($manager) $manager->setOptionsString($f, "1=bild\n2=video\n3=pdf", false);
		}

		if(!$fields->get('mm_kategorie')) {
			$f = $pw->wire(new Field());
			$f->type = $pw->modules->get('FieldtypePage');
			$f->name = 'mm_kategorie';
			$f->label = 'Kategorie';
			$f->derefAsPage = 1;
			$f->inputfield = 'InputfieldSelect';
			$f->labelFieldName = 'title';
			$f->flags = Field::flagSystem;
			$f->save();
		}
	}

	protected function _installFieldgroups(): void {
		$fieldgroups = $this->wire->fieldgroups;
		$fields = $this->wire->fields;

		if(!$fieldgroups->get(self::ROOT_TEMPLATE)) {
			$fg = $this->wire(new Fieldgroup());
			$fg->name = self::ROOT_TEMPLATE;
			$fg->add($fields->get('title'));
			$fg->save();
		}

		if(!$fieldgroups->get(self::KATEGORIE_TEMPLATE)) {
			$fg = $this->wire(new Fieldgroup());
			$fg->name = self::KATEGORIE_TEMPLATE;
			$fg->add($fields->get('title'));
			$fg->add($fields->get('mm_beschreibung'));
			$fg->save();
		}

		if(!$fieldgroups->get(self::ITEM_TEMPLATE)) {
			$fg = $this->wire(new Fieldgroup());
			$fg->name = self::ITEM_TEMPLATE;
			$fg->add($fields->get('title'));
			$fg->add($fields->get('mm_datei'));
			$fg->add($fields->get('mm_titel'));
			$fg->add($fields->get('mm_beschreibung'));
			$fg->add($fields->get('mm_kategorie'));
			$fg->add($fields->get('mm_tags'));
			$fg->add($fields->get('mm_typ'));
			$fg->save();
		}
	}

	protected function _installTemplates(): void {
		$templates = $this->wire->templates;
		$fieldgroups = $this->wire->fieldgroups;

		if(!$templates->get(self::ROOT_TEMPLATE)) {
			$t = $this->wire(new Template());
			$t->name = self::ROOT_TEMPLATE;
			$t->fieldgroup = $fieldgroups->get(self::ROOT_TEMPLATE);
			$t->flags = Template::flagSystem;
			$t->save();
		}

		if(!$templates->get(self::KATEGORIE_TEMPLATE)) {
			$t = $this->wire(new Template());
			$t->name = self::KATEGORIE_TEMPLATE;
			$t->fieldgroup = $fieldgroups->get(self::KATEGORIE_TEMPLATE);
			$t->save();
		}

		if(!$templates->get(self::ITEM_TEMPLATE)) {
			$t = $this->wire(new Template());
			$t->name = self::ITEM_TEMPLATE;
			$t->fieldgroup = $fieldgroups->get(self::ITEM_TEMPLATE);
			$t->save();
		}
	}

	protected function _installRootPage(): Page {
		$existing = $this->_getRootPage();
		if($existing->id) return $existing;

		$adminRoot = $this->wire->pages->get($this->wire->config->adminRootPageID);
		$root = $this->wire->pages->newPage([
			'template' => self::ROOT_TEMPLATE,
			'parent'   => $adminRoot,
			'name'     => self::ROOT_NAME,
			'title'    => 'Medien Manager',
		]);
		$root->addStatus(Page::statusHidden);
		$root->addStatus(Page::statusSystem);
		$root->save();
		return $root;
	}
}