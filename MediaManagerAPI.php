<?php namespace ProcessWire;

/**
 * MediaManagerAPI
 *
 * Service-Klasse für alle Datenbank- und Page-Operationen des Medien Managers.
 * Wird von bsProcessMedienManager und InputfieldMedienManager genutzt.
 *
 * @copyright 2026 bsProcessMedienManager
 */
class MediaManagerAPI {

	/** @var ProcessWire */
	protected $wire;

	// Template-Namen
	const ROOT_TEMPLATE      = 'medienmanager-root';
	const ITEM_TEMPLATE      = 'medienmanager-item';
	const KATEGORIE_TEMPLATE = 'medienmanager-kategorie';

	// Admin-Page-Name unterhalb des Admin-Root
	const ROOT_NAME = 'medienmanager';

	/**
	 * Konstruktor — erhält die ProcessWire-Instanz per Dependency Injection.
	 *
	 * @param ProcessWire $wire
	 */
	public function __construct(ProcessWire $wire) {
		$this->wire = $wire;
	}

	// -----------------------------------------------------------------------
	// Öffentliche Such- und Lese-Methoden
	// -----------------------------------------------------------------------

	/**
	 * Medien-Items anhand von Filtern suchen.
	 *
	 * @param array $filters Mögliche Schlüssel: typ, kategorie_id, q (Suche), start, limit
	 * @param int   $start   Erster Datensatz (Pagination)
	 * @param int   $limit   Anzahl Datensätze
	 * @return PageArray
	 */
	public function findMedia(array $filters = [], int $start = 0, int $limit = 24): PageArray {
		$sanitizer = $this->wire->sanitizer;

		// Basis-Selektor: nur medienmanager-item Pages, auch versteckte einschließen
		$selector = "template=" . self::ITEM_TEMPLATE . ", include=hidden, sort=-created";

		// Filter: Medientyp (bild/video/pdf)
		if(!empty($filters['typ'])) {
			$typ = $sanitizer->name($filters['typ']);
			if(in_array($typ, ['bild', 'video', 'pdf'])) {
				$selector .= ", mm_typ.title=$typ";
			}
		}

		// Filter: Kategorie
		if(!empty($filters['kategorie_id'])) {
			$katId = (int) $filters['kategorie_id'];
			if($katId > 0) {
				$selector .= ", mm_kategorie=$katId";
			}
		}

		// Filter: Volltextsuche in Titel und Tags
		if(!empty($filters['q'])) {
			$q = $sanitizer->selectorValue($filters['q']);
			$selector .= ", mm_titel%=$q|mm_tags%=$q";
		}

		// Pagination
		$selector .= ", start=$start, limit=$limit";

		return $this->wire->pages->find($selector);
	}

	/**
	 * Einzelnes Medien-Item per ID laden.
	 *
	 * @param int $id
	 * @return Page NullPage wenn nicht gefunden
	 */
	public function getMediaItem(int $id): Page {
		if($id <= 0) return $this->wire->pages->newNullPage();
		return $this->wire->pages->get("id=$id, template=" . self::ITEM_TEMPLATE . ", include=all");
	}

	/**
	 * Alle Kategorien laden (direkte Kinder der Root-Page oder eines Elternteils).
	 *
	 * @param int $parentId 0 = Root-Page als Elternteil
	 * @return PageArray
	 */
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

	/**
	 * Neues Medien-Item anlegen.
	 *
	 * @param array  $data  Felder: titel, beschreibung, tags, typ, kategorie_id
	 * @param string $uploadedFile Absoluter Pfad zur hochgeladenen Datei
	 * @return Page Das neu erstellte Item oder NullPage bei Fehler
	 */
	public function createMediaItem(array $data, string $uploadedFile = ''): Page {
		$sanitizer = $this->wire->sanitizer;

		$root = $this->_getRootPage();
		if(!$root->id) return $this->wire->pages->newNullPage();

		// Elternteil bestimmen: Kategorie oder Root
		$parentId = !empty($data['kategorie_id']) ? (int) $data['kategorie_id'] : $root->id;
		$parent   = $this->wire->pages->get("id=$parentId, include=all");
		if(!$parent->id) $parent = $root;

		/** @var Page $p */
		$p = $this->wire->pages->newPage([
			'template' => self::ITEM_TEMPLATE,
			'parent'   => $parent,
		]);

		// Felder setzen
		$p->title           = $sanitizer->text($data['titel'] ?? '');
		$p->mm_titel        = $sanitizer->text($data['titel'] ?? '');
		$p->mm_beschreibung = $sanitizer->textarea($data['beschreibung'] ?? '');
		$p->mm_tags         = $sanitizer->text($data['tags'] ?? '');

		// Typ setzen (FieldtypeOptions erwartet den Schlüsselwert als Integer)
		if(!empty($data['typ'])) {
			$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
			$typKey = $sanitizer->name($data['typ']);
			if(isset($typMap[$typKey])) {
				$p->mm_typ = $typMap[$typKey];
			}
		}

		// Kategorie-Referenz setzen
		if(!empty($data['kategorie_id'])) {
			$katId = (int) $data['kategorie_id'];
			$kat   = $this->wire->pages->get("id=$katId, template=" . self::KATEGORIE_TEMPLATE . ", include=hidden");
			if($kat->id) $p->mm_kategorie = $kat;
		}

		// Item versteckt (nicht im Frontend sichtbar)
		$p->addStatus(Page::statusHidden);

		$p->save();

		// Datei anhängen (nach erstem save, damit das Verzeichnis existiert)
		if($uploadedFile && file_exists($uploadedFile)) {
			$p->mm_datei->add($uploadedFile);
			$p->save('mm_datei');
		}

		return $p;
	}

	/**
	 * Vorhandenes Medien-Item aktualisieren.
	 *
	 * @param int   $id
	 * @param array $data Felder: titel, beschreibung, tags, typ, kategorie_id
	 * @return Page Aktualisiertes Item oder NullPage
	 */
	public function saveMediaItem(int $id, array $data): Page {
		$sanitizer = $this->wire->sanitizer;
		$p = $this->getMediaItem($id);
		if(!$p->id) return $this->wire->pages->newNullPage();

		$p->of(false); // Output-Formatierung deaktivieren

		if(isset($data['titel'])) {
			$p->title    = $sanitizer->text($data['titel']);
			$p->mm_titel = $sanitizer->text($data['titel']);
		}
		if(isset($data['beschreibung'])) {
			$p->mm_beschreibung = $sanitizer->textarea($data['beschreibung']);
		}
		if(isset($data['tags'])) {
			$p->mm_tags = $sanitizer->text($data['tags']);
		}
		if(isset($data['typ'])) {
			$typMap = ['bild' => 1, 'video' => 2, 'pdf' => 3];
			$typKey = $sanitizer->name($data['typ']);
			if(isset($typMap[$typKey])) $p->mm_typ = $typMap[$typKey];
		}
		if(isset($data['kategorie_id'])) {
			$katId = (int) $data['kategorie_id'];
			if($katId > 0) {
				$kat = $this->wire->pages->get("id=$katId, template=" . self::KATEGORIE_TEMPLATE . ", include=hidden");
				if($kat->id) $p->mm_kategorie = $kat;
			} else {
				$p->mm_kategorie = null;
			}
		}

		$p->save();
		return $p;
	}

	/**
	 * Medien-Item löschen.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function deleteMedia(int $id): bool {
		$p = $this->getMediaItem($id);
		if(!$p->id) return false;
		$this->wire->pages->delete($p, true); // true = mit Dateien löschen
		return true;
	}

	/**
	 * Neue Kategorie anlegen.
	 *
	 * @param string $titel
	 * @param int    $parentId 0 = unter Root
	 * @return Page
	 */
	public function createKategorie(string $titel, int $parentId = 0): Page {
		$root     = $this->_getRootPage();
		$parentId = $parentId > 0 ? $parentId : $root->id;
		$parent   = $this->wire->pages->get("id=$parentId, include=all");
		if(!$parent->id) $parent = $root;

		$p = $this->wire->pages->newPage([
			'template' => self::KATEGORIE_TEMPLATE,
			'parent'   => $parent,
		]);
		$p->title           = $this->wire->sanitizer->text($titel);
		$p->mm_beschreibung = '';
		$p->addStatus(Page::statusHidden);
		$p->save();
		return $p;
	}

	/**
	 * Kategorie löschen (nur wenn leer).
	 *
	 * @param int $id
	 * @return bool
	 */
	public function deleteKategorie(int $id): bool {
		$p = $this->wire->pages->get("id=$id, template=" . self::KATEGORIE_TEMPLATE . ", include=all");
		if(!$p->id) return false;

		// Nur löschen wenn keine Kinder vorhanden
		if($p->numChildren(true) > 0) return false;

		$this->wire->pages->delete($p, true);
		return true;
	}

	// -----------------------------------------------------------------------
	// Thumbnail-Hilfsmethoden
	// -----------------------------------------------------------------------

	/**
	 * Thumbnail-URL für ein Medien-Item ermitteln.
	 *
	 * @param Page $item
	 * @param int  $width
	 * @param int  $height
	 * @return string Leerer String wenn kein Thumbnail möglich
	 */
	public function getThumbnailUrl(Page $item, int $width = 200, int $height = 150): string {
		if(!$item->id) return '';

		/** @var Pagefile|Pageimage|null $datei */
		$datei = $item->mm_datei->first();
		if(!$datei) return '';

		// Für Bilder: PW-eigene Größenberechnung nutzen
		if($datei instanceof Pageimage) {
			return $datei->size($width, $height, ['cropping' => true])->url;
		}

		// Für Videos und PDFs: Typ-Icon zurückgeben (wird in JS/CSS behandelt)
		return '';
	}

	/**
	 * Medientyp eines Items als String ermitteln.
	 *
	 * @param Page $item
	 * @return string 'bild'|'video'|'pdf'|''
	 */
	public function getTypString(Page $item): string {
		if(!$item->id || !$item->mm_typ) return '';
		$opt = $item->mm_typ;
		// FieldtypeOptions gibt SelectableOption zurück; title ist der Anzeigewert
		return strtolower((string) $opt);
	}

	// -----------------------------------------------------------------------
	// Install / Uninstall
	// -----------------------------------------------------------------------

	/**
	 * Vollständige Installation: Felder, Fieldgroups, Templates, Root-Page.
	 */
	public function install(): void {
		// Schritt 1: Felder erstellen
		$this->_installFelder();

		// Schritt 2: Fieldgroups erstellen
		$this->_installFieldgroups();

		// Schritt 3: Templates erstellen
		$this->_installTemplates();

		// Schritt 4: Root-Page anlegen
		$root = $this->_installRootPage();

		// Schritt 5: mm_kategorie.parent_id mit Root-ID patchen
		$mmKat = $this->wire->fields->get('mm_kategorie');
		if($mmKat && $root->id) {
			$mmKat->parent_id = $root->id;
			$mmKat->save();
		}

		// Schritt 6: Modul-Config speichern
		$this->wire->modules->saveModuleConfigData('bsProcessMedienManager', [
			'rootPageID' => $root->id,
		]);
	}

	/**
	 * Vollständiges Deinstallieren: Root-Page, Templates, Fieldgroups, Felder entfernen.
	 */
	public function uninstall(): void {
		// Root-Page und alle Kinder löschen
		$root = $this->_getRootPage();
		if($root->id) {
			$this->wire->pages->delete($root, true);
		}

		// Templates entfernen
		$templateNamen = [self::ITEM_TEMPLATE, self::KATEGORIE_TEMPLATE, self::ROOT_TEMPLATE];
		foreach($templateNamen as $name) {
			$t = $this->wire->templates->get($name);
			if($t && !$t->getNumPages()) {
				$fg = $t->fieldgroup;
				$this->wire->templates->delete($t);
				if($fg) $this->wire->fieldgroups->delete($fg);
			}
		}

		// Felder entfernen (nur wenn nicht von anderen Templates genutzt)
		$felder = ['mm_datei', 'mm_titel', 'mm_beschreibung', 'mm_kategorie', 'mm_tags', 'mm_typ'];
		foreach($felder as $name) {
			$f = $this->wire->fields->get($name);
			if($f && !count($f->getTemplates())) {
				$this->wire->fields->delete($f);
			}
		}
	}

	// -----------------------------------------------------------------------
	// Interne Hilfsmethoden
	// -----------------------------------------------------------------------

	/**
	 * Root-Page zurückgeben (oder NullPage wenn nicht vorhanden).
	 */
	protected function _getRootPage(): Page {
		$adminId = $this->wire->config->adminRootPageID;
		return $this->wire->pages->get(
			"name=" . self::ROOT_NAME . ", parent=$adminId, template=" . self::ROOT_TEMPLATE . ", include=all"
		);
	}

	/**
	 * Alle benötigten Felder idempotent erstellen.
	 */
	protected function _installFelder(): void {
		$fields = $this->wire->fields;
		$pw     = $this->wire; // Kurzreferenz für wire()-Aufrufe

		// mm_datei (FieldtypeFile)
		if(!$fields->get('mm_datei')) {
			$f = $pw->wire(new Field());
			$f->type       = $pw->modules->get('FieldtypeFile');
			$f->name       = 'mm_datei';
			$f->label      = 'Datei';
			$f->extensions = 'jpg jpeg png gif webp mp4 mov pdf';
			$f->maxFiles   = 1;
			$f->inputfieldClass = 'InputfieldFile';
			$f->flags      = Field::flagSystem;
			$f->save();
		}

		// mm_titel (FieldtypeText)
		if(!$fields->get('mm_titel')) {
			$f = $pw->wire(new Field());
			$f->type      = $pw->modules->get('FieldtypeText');
			$f->name      = 'mm_titel';
			$f->label     = 'Titel';
			$f->maxlength = 255;
			$f->flags     = Field::flagSystem;
			$f->save();
		}

		// mm_beschreibung (FieldtypeTextarea)
		if(!$fields->get('mm_beschreibung')) {
			$f = $pw->wire(new Field());
			$f->type  = $pw->modules->get('FieldtypeTextarea');
			$f->name  = 'mm_beschreibung';
			$f->label = 'Beschreibung';
			$f->rows  = 3;
			$f->flags = Field::flagSystem;
			$f->save();
		}

		// mm_tags (FieldtypeText)
		if(!$fields->get('mm_tags')) {
			$f = $pw->wire(new Field());
			$f->type      = $pw->modules->get('FieldtypeText');
			$f->name      = 'mm_tags';
			$f->label     = 'Tags';
			$f->maxlength = 512;
			$f->flags     = Field::flagSystem;
			$f->save();
		}

		// mm_typ (FieldtypeOptions)
		if(!$fields->get('mm_typ')) {
			$f = $pw->wire(new Field());
			$f->type  = $pw->modules->get('FieldtypeOptions');
			$f->name  = 'mm_typ';
			$f->label = 'Medientyp';
			$f->flags = Field::flagSystem;
			$f->save();
			// Optionen setzen: "id=title" Format
			$manager = $pw->modules->get('SelectableOptionManager');
			if($manager) {
				$manager->setOptionsString($f, "1=bild\n2=video\n3=pdf", false);
			}
		}

		// mm_kategorie (FieldtypePage) — parent_id wird in install() nach Root-Page-Erstellung gesetzt
		if(!$fields->get('mm_kategorie')) {
			$f = $pw->wire(new Field());
			$f->type         = $pw->modules->get('FieldtypePage');
			$f->name         = 'mm_kategorie';
			$f->label        = 'Kategorie';
			$f->derefAsPage  = 1; // einzelne Page statt PageArray
			$f->inputfield   = 'InputfieldSelect';
			$f->labelFieldName = 'title';
			$f->flags        = Field::flagSystem;
			$f->save();
		}
	}

	/**
	 * Fieldgroups für die drei Templates erstellen.
	 */
	protected function _installFieldgroups(): void {
		$fieldgroups = $this->wire->fieldgroups;
		$fields      = $this->wire->fields;
		$pw          = $this->wire;

		// Fieldgroup: medienmanager-root
		if(!$fieldgroups->get(self::ROOT_TEMPLATE)) {
			$fg = $pw->wire(new Fieldgroup());
			$fg->name = self::ROOT_TEMPLATE;
			$fg->add($fields->get('title'));
			$fg->save();
		}

		// Fieldgroup: medienmanager-kategorie
		if(!$fieldgroups->get(self::KATEGORIE_TEMPLATE)) {
			$fg = $pw->wire(new Fieldgroup());
			$fg->name = self::KATEGORIE_TEMPLATE;
			$fg->add($fields->get('title'));
			$fg->add($fields->get('mm_beschreibung'));
			$fg->save();
		}

		// Fieldgroup: medienmanager-item
		if(!$fieldgroups->get(self::ITEM_TEMPLATE)) {
			$fg = $pw->wire(new Fieldgroup());
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

	/**
	 * Templates für die drei Page-Typen erstellen.
	 */
	protected function _installTemplates(): void {
		$templates   = $this->wire->templates;
		$fieldgroups = $this->wire->fieldgroups;
		$pw          = $this->wire;

		// Template: medienmanager-root
		if(!$templates->get(self::ROOT_TEMPLATE)) {
			$t = $pw->wire(new Template());
			$t->name         = self::ROOT_TEMPLATE;
			$t->fieldgroup   = $fieldgroups->get(self::ROOT_TEMPLATE);
			$t->noGlobal     = 1;
			$t->noMove       = 1;
			$t->noTrash      = 1;
			$t->noChangeTemplate = 1;
			$t->noAppendTemplateFile = 1;
			$t->flags        = Template::flagSystem;
			$t->save();
		}

		// Template: medienmanager-kategorie
		if(!$templates->get(self::KATEGORIE_TEMPLATE)) {
			$t = $pw->wire(new Template());
			$t->name       = self::KATEGORIE_TEMPLATE;
			$t->fieldgroup = $fieldgroups->get(self::KATEGORIE_TEMPLATE);
			$t->noGlobal   = 1;
			$t->noAppendTemplateFile = 1;
			$t->save();
		}

		// Template: medienmanager-item
		if(!$templates->get(self::ITEM_TEMPLATE)) {
			$t = $pw->wire(new Template());
			$t->name       = self::ITEM_TEMPLATE;
			$t->fieldgroup = $fieldgroups->get(self::ITEM_TEMPLATE);
			$t->noGlobal   = 1;
			$t->noMove     = 1;
			$t->noAppendTemplateFile = 1;
			$t->save();
		}
	}

	/**
	 * Root-Page unterhalb des Admin-Roots anlegen.
	 *
	 * @return Page
	 */
	protected function _installRootPage(): Page {
		// Idempotent: prüfen ob bereits vorhanden
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
