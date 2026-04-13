<?php namespace ProcessWire;

/**
 * AJAX-JSON-Endpunkte für den Medien Manager (Picker, Bulk, Duplikat, Kategorien).
 *
 * @see bsProcessMedienManager::___executeAjax()
 */
trait MediaManagerAjaxTrait {
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

	/**
	 * WebP-Nebenversion für ausgewählte Bild-Items (primäres Pageimage); Videos/PDFs werden übersprungen.
	 */
	protected function _ajaxBulkWebp(): string {
		$input = $this->wire->input;
		$raw   = (string) $input->post('ids');
		$ids   = array_filter(array_map('intval', explode(',', $raw)));
		$created = 0;
		$skipped = 0;
		$failed  = 0;
		foreach($ids as $id) {
			if($id <= 0) continue;
			$p = $this->api()->getMediaItem($id);
			if(!$p->id) continue;
			$img = $this->api()->getPrimaryPageimage($p);
			if(!$img instanceof Pageimage) {
				$skipped++;
				continue;
			}
			if($img->webp()->exists()) {
				$skipped++;
				continue;
			}
			if($this->api()->ensureWebpForPageimage($img)) {
				$created++;
			} else {
				$failed++;
			}
		}
		return json_encode([
			'status'  => 'ok',
			'created' => $created,
			'skipped' => $skipped,
			'failed'  => $failed,
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
}
