/**
 * medienmanager-admin.js
 *
 * JavaScript für die Admin-Oberfläche des Medien Managers.
 * Zuständig für: Grid-Interaktionen, Upload-Modal, Lösch-Bestätigungen,
 * Bildbearbeitungs-Aktionen.
 * Lazy Loading: natives loading="lazy" bei Grid-/Vorschaubildern (kein zweiter Observer).
 *
 * Abhängigkeiten: ProcessWire-eigenes UIkit (im Admin verfügbar)
 */

(function() {
	'use strict';

	// -----------------------------------------------------------------------
	// Konfiguration
	// Liest ajaxUrl aus window.bsProcessMedienManager (gesetzt via PHP).
	// CSRF-Token wird aus ProcessWire.config gelesen — dieser ist immer
	// aktuell und wird von PW selbst in den <head> geschrieben.
	// -----------------------------------------------------------------------

	function getCfg() {
		return window.bsProcessMedienManager || {};
	}

	/**
	 * Aktuellen CSRF-Token aus ProcessWire.config.SessionCSRF lesen.
	 * PW schreibt diesen automatisch in den <head> als ProcessWire.config.
	 * Fallback: aus dem ersten gefundenen Hidden-Input im DOM.
	 *
	 * @returns {{name: string, value: string}|null}
	 */
	function getCsrf() {
        var cfg = getCfg();
        if (cfg.csrfName && cfg.csrfVal) {
            return { name: cfg.csrfName, value: cfg.csrfVal };
        }
        // Fallback: Hidden-Input im DOM
        var inp = document.querySelector('input[name^="TOKEN"]');
        if (inp) return { name: inp.name, value: inp.value };
        return null;
    }

	/**
	 * Zentraler AJAX-POST mit X-Requested-With-Header.
	 * ProcessWire setzt $config->ajax nur wenn dieser Header gesetzt ist.
	 *
	 * @param {string}   url
	 * @param {FormData} formData
	 * @param {Function} callback
	 */
	function ajaxPost(url, formData, callback) {
		var xhr = new XMLHttpRequest();
		xhr.addEventListener('load', function() {
			try {
				callback(JSON.parse(xhr.responseText));
			} catch (e) {
				showAdminError('Ungültige Server-Antwort');
			}
		});
		xhr.addEventListener('error', function() {
			showAdminError('Netzwerkfehler');
		});
		xhr.open('POST', url, true);
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.send(formData);
	}

	// -----------------------------------------------------------------------
	// Upload-Modal
	// -----------------------------------------------------------------------

	function initUploadModal() {
		var uploadBtn = document.getElementById('mm-upload-btn');
		var modal     = document.getElementById('mm-upload-modal');
		if (!uploadBtn || !modal) return;

		var closeBtn  = modal.querySelector('.mm-modal-close');
		var cancelBtn = modal.querySelector('.mm-modal-cancel');
		var submitBtn = modal.querySelector('.mm-upload-submit');
		var dropZone  = document.getElementById('mm-drop-zone');
		var fileInput = document.getElementById('mm-file-input');

		uploadBtn.addEventListener('click', function() {
			modal.style.display = 'flex';
		});

		[closeBtn, cancelBtn].forEach(function(btn) {
			if (btn) btn.addEventListener('click', closeUploadModal);
		});

		modal.querySelector('.mm-modal-backdrop').addEventListener('click', closeUploadModal);

		if (dropZone && fileInput) {
			dropZone.addEventListener('click', function() { fileInput.click(); });

			dropZone.addEventListener('dragover', function(e) {
				e.preventDefault();
				dropZone.classList.add('mm-drag-over');
			});

			dropZone.addEventListener('dragleave', function() {
				dropZone.classList.remove('mm-drag-over');
			});

			dropZone.addEventListener('drop', function(e) {
				e.preventDefault();
				dropZone.classList.remove('mm-drag-over');
				if (e.dataTransfer.files.length) {
					fileInput.files = e.dataTransfer.files;
					showSelectedFiles(fileInput.files);
				}
			});

			fileInput.addEventListener('change', function() {
				if (fileInput.files.length) showSelectedFiles(fileInput.files);
			});
		}

		if (submitBtn) submitBtn.addEventListener('click', submitUpload);
	}

	/**
	 * Ausgewählte Dateinamen im Modal anzeigen (Mehrfach-Upload).
	 */
	function showSelectedFiles(fileList) {
		var dropZone = document.getElementById('mm-drop-zone');
		var ul = document.getElementById('mm-upload-file-list');
		if (!dropZone) return;
		var hint = dropZone.querySelector('.mm-drop-zone-hint');
		var n = fileList.length;
		if (hint) {
			hint.textContent = n === 1
				? fileList[0].name
				: n + ' Dateien ausgewählt';
		}
		if (ul) {
			ul.innerHTML = '';
			if (n > 1) {
				ul.hidden = false;
				for (var i = 0; i < n; i++) {
					var li = document.createElement('li');
					li.textContent = fileList[i].name;
					ul.appendChild(li);
				}
			} else {
				ul.hidden = true;
			}
		}
	}

	/**
	 * FormData für einen oder mehrere Uploads (mm_datei[]).
	 */
	function buildUploadFormData(form, fileInput) {
		var fd = new FormData();
		var csrf = getCsrf();
		if (csrf) fd.append(csrf.name, csrf.value);
		var titel = form.querySelector('[name="titel"]');
		var tags = form.querySelector('[name="tags"]');
		var kat = form.querySelector('[name="kategorie_id"]');
		if (titel) fd.append('titel', titel.value || '');
		if (tags) fd.append('tags', tags.value || '');
		if (kat) fd.append('kategorie_id', kat.value || '');
		var files = fileInput.files;
		for (var i = 0; i < files.length; i++) {
			fd.append('mm_datei[]', files[i], files[i].name);
		}
		return fd;
	}

	function submitUpload() {
		var form      = document.getElementById('mm-upload-form');
		var fileInput = document.getElementById('mm-file-input');
		var progress  = document.querySelector('.mm-upload-progress');
		var bar       = document.querySelector('.mm-progress-fill');
		var text      = document.querySelector('.mm-progress-text');
		var batchEl   = document.getElementById('mm-upload-batch-result');
		if (!form || !fileInput) return;

		if (!fileInput.files.length) {
			showAdminError('Bitte mindestens eine Datei auswählen.');
			return;
		}

		var formData = buildUploadFormData(form, fileInput);
		var xhr      = new XMLHttpRequest();

		if (batchEl) {
			batchEl.style.display = 'none';
			batchEl.innerHTML = '';
		}
		if (progress) progress.style.display = 'flex';

		xhr.upload.addEventListener('progress', function(e) {
			if (e.lengthComputable) {
				var pct = Math.round((e.loaded / e.total) * 100);
				if (bar)  bar.style.width = pct + '%';
				if (text) text.textContent = pct + '%';
			}
		});

		xhr.addEventListener('load', function() {
			var resp;
			try {
				resp = JSON.parse(xhr.responseText);
			} catch (err) {
				showAdminError('Ungültige Server-Antwort');
				if (progress) progress.style.display = 'none';
				return;
			}

			if (xhr.status === 200 && resp.status === 'ok' && resp.results) {
				var bulkBar = document.getElementById('mm-bulk-bar');
				var listMode = bulkBar && bulkBar.getAttribute('data-mm-view') === 'list';
				if (listMode) {
					window.location.reload();
					return;
				}
				resp.results.forEach(function(r) {
					if (r.status === 'ok') prependGridItem(r);
				});
				var hasErr = resp.results.some(function(r) { return r.status === 'error'; });
				if (hasErr && batchEl) {
					batchEl.style.display = 'block';
					var summary = resp.message ? '<p class="mm-upload-summary">' + escapeHtml(resp.message) + '</p>' : '';
					batchEl.innerHTML = summary + renderUploadErrors(resp.results);
				}
				if (!hasErr) {
					closeUploadModal();
					form.reset();
					var ul = document.getElementById('mm-upload-file-list');
					if (ul) { ul.innerHTML = ''; ul.hidden = true; }
					var hint = document.querySelector('.mm-drop-zone-hint');
					if (hint) hint.textContent = 'Datei(en) hier ablegen oder klicken zum Auswählen';
				}
			} else if (resp.results && resp.results.length) {
				if (batchEl) {
					batchEl.style.display = 'block';
					batchEl.innerHTML = renderUploadErrors(resp.results);
				}
				showAdminError(resp.message || 'Upload fehlgeschlagen');
			} else {
				showAdminError(resp.message || 'Upload fehlgeschlagen (HTTP ' + xhr.status + ')');
			}
			if (progress) progress.style.display = 'none';
		});

		xhr.addEventListener('error', function() {
			showAdminError('Netzwerkfehler beim Upload');
			if (progress) progress.style.display = 'none';
		});

		xhr.open('POST', form.getAttribute('action'), true);
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.send(formData);
	}

	function renderUploadErrors(results) {
		var html = '<div class="mm-upload-errors-title">Fehler pro Datei:</div><ul class="mm-upload-errors-list">';
		results.forEach(function(r) {
			if (r.status === 'error') {
				html += '<li><strong>' + escapeHtml(r.filename || '') + '</strong>: ' + escapeHtml(r.message || '') + '</li>';
			}
		});
		html += '</ul>';
		return html;
	}

	function closeUploadModal() {
		var modal = document.getElementById('mm-upload-modal');
		if (modal) modal.style.display = 'none';
	}

	// -----------------------------------------------------------------------
	// Grid-Item nach Upload voranstellen
	// -----------------------------------------------------------------------

	function gridItemInnerHtml(data) {
		var cfg = getCfg();
		var csrfName = cfg.csrfName || '';
		var csrfVal = cfg.csrfVal || '';
		var typ = data.typ || '';
		var basename = data.basename || data.titel || '';
		var titel = data.titel || '';
		var fileLabel = escapeHtml(basename);
		var titleSecondary = '';
		if (titel && basename && titel !== basename) {
			titleSecondary = '<span class="mm-grid-title-secondary uk-text-meta">' + escapeHtml(titel) + '</span>';
		}
		var metaBits = [];
		if (data.dimensions) metaBits.push(data.dimensions);
		if (data.filesizeStr) metaBits.push(data.filesizeStr);
		metaBits.push(typ);
		var metaLine = '<span class="mm-grid-meta uk-text-meta">' + escapeHtml(metaBits.join(' · ')) + '</span>';

		var showImgEdit = data.hasImage || typ === 'bild';
		var thumbInner = data.thumb
			? '<img class="mm-grid-img" src="' + escapeHtml(data.thumb) + '" alt="' + fileLabel + '" loading="lazy">'
			: '<span class="mm-grid-icon"><i class="fa fa-file"></i></span>';
		var imgEditBtn = showImgEdit
			? '<a href="./imageedit/?id=' + data.id + '" class="mm-btn-imageedit" title="Bild bearbeiten"><i class="fa fa-crop"></i></a>'
			: '';
		var pubUrl = data.publicUrl || '';
		var copyBtn = pubUrl
			? '<button type="button" class="mm-btn-copy-url" data-url="' + escapeHtml(pubUrl) + '" title="Öffentliche URL kopieren"><i class="fa fa-link"></i></button>'
			: '';
		var actionsInner = '<a href="./edit/?id=' + data.id + '" class="mm-btn-edit" title="Bearbeiten"><i class="fa fa-pencil"></i></a>'
			+ imgEditBtn
			+ copyBtn
			+ '<button type="button" class="mm-btn-duplicate" data-id="' + data.id + '" data-csrf-name="' + escapeHtml(csrfName) + '" data-csrf-val="' + escapeHtml(csrfVal) + '" title="Duplizieren"><i class="fa fa-files-o"></i></button>'
			+ '<button type="button" class="mm-btn-delete" data-id="' + data.id + '" data-csrf-name="' + escapeHtml(csrfName) + '" data-csrf-val="' + escapeHtml(csrfVal) + '" title="Löschen"><i class="fa fa-trash"></i></button>';

		return '<label class="mm-grid-select"><input type="checkbox" class="mm-bulk-cb" value="' + data.id + '" aria-label="Auswahl"></label>'
			+ '<div class="mm-grid-thumb mm-grid-thumb-cover">' + thumbInner
			+ '<div class="mm-grid-overlay"><div class="mm-grid-actions-inner">' + actionsInner + '</div></div></div>'
			+ '<div class="mm-grid-info">'
			+ '<span class="mm-grid-filename">' + fileLabel + '</span>'
			+ titleSecondary
			+ metaLine
			+ '</div>';
	}

	function prependGridItem(data) {
		var bulkBar = document.getElementById('mm-bulk-bar');
		if (bulkBar && bulkBar.getAttribute('data-mm-view') === 'list') {
			window.location.reload();
			return;
		}
		var grid = document.getElementById('mm-grid');
		if (!grid) return;

		var item = document.createElement('div');
		item.className = 'mm-grid-item mm-grid-item-new';
		item.dataset.id = data.id;
		item.dataset.typ = data.typ || '';
		item.innerHTML = gridItemInnerHtml(data);

		var empty = grid.querySelector('.mm-empty');
		if (empty) empty.remove();

		grid.insertBefore(item, grid.firstChild);

		requestAnimationFrame(function() {
			item.classList.add('mm-item-highlight');
			setTimeout(function() { item.classList.remove('mm-item-highlight'); }, 1500);
		});
	}

	// -----------------------------------------------------------------------
	// Löschen — Event-Delegation auf document
	// Damit werden alle Buttons erfasst, unabhängig vom Ladezeitpunkt.
	// -----------------------------------------------------------------------

	function initDeleteButtons() {
		document.addEventListener('click', function(e) {
			var btn = e.target.closest('.mm-btn-delete, .mm-btn-delete-single');
			if (!btn) return;
			handleDeleteClick(btn);
		});
	}

	function handleDeleteClick(btn) {
		var id = btn.dataset.id;
		if (!confirm('Dieses Medium wirklich löschen?')) return;

		var cfg     = getCfg();
		var ajaxUrl = cfg.ajaxUrl;
		if (!ajaxUrl) {
			showAdminError('AJAX-URL nicht konfiguriert');
			return;
		}

		var csrf     = getCsrf();
		var formData = new FormData();
		formData.append('action', 'delete');
		formData.append('id', id);
		if (csrf) formData.append(csrf.name, csrf.value);

		ajaxPost(ajaxUrl, formData, function(resp) {
			if (resp.status === 'ok') {
				var item = document.querySelector('.mm-grid-item[data-id="' + id + '"], tr.mm-list-row[data-id="' + id + '"]');
				if (item) {
					item.style.opacity = '0';
					setTimeout(function() { item.remove(); }, 300);
				}
				if (btn.classList.contains('mm-btn-delete-single')) {
					window.location.href = '../';
				}
			} else {
				showAdminError('Löschen fehlgeschlagen');
			}
		});
	}

	// -----------------------------------------------------------------------
	// Bildbearbeitung (Rotate / Resize)
	// -----------------------------------------------------------------------

	function initImageEdit() {
		document.addEventListener('click', function(e) {
			var rotateBtn = e.target.closest('.mm-rotate-btn');
			if (rotateBtn) handleRotate(rotateBtn);

			var resizeBtn = e.target.closest('.mm-resize-btn');
			if (resizeBtn) handleResize(resizeBtn);
		});
	}

	/**
	 * Absolute URL zur imageedit-Action (Rotate/Resize), aus PHP-Config (imageEditUrl).
	 */
	function getImageEditPostUrl(id) {
		var cfg = getCfg();
		var base = cfg.imageEditUrl || '';
		if (!base) {
			var a = cfg.ajaxUrl || '';
			if (a.indexOf('/ajax/') !== -1) {
				base = a.replace(/\/ajax\/$/, '/imageedit/');
			}
		}
		if (!base) return '';
		return base + (base.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(id);
	}

	function handleRotate(btn) {
		var id      = btn.dataset.id;
		var degrees = btn.dataset.degrees;
		var csrf    = getCsrf();

		var postUrl = getImageEditPostUrl(id);
		if (!postUrl) {
			showAdminError('Bildbearbeitungs-URL nicht konfiguriert');
			return;
		}

		var formData = new FormData();
		formData.append('action', 'rotate');
		formData.append('degrees', degrees);
		if (csrf) formData.append(csrf.name, csrf.value);

		ajaxPost(postUrl, formData, function(resp) {
			if (resp.status === 'ok') {
				var img = document.getElementById('mm-edit-img');
				if (img) img.src = resp.url + (resp.url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
				var wEl = document.getElementById('mm-resize-w');
				var hEl = document.getElementById('mm-resize-h');
				if (resp.width && wEl) wEl.value = resp.width;
				if (resp.height && hEl) hEl.value = resp.height;
			} else if (resp.message) {
				showAdminError(resp.message);
			}
		});
	}

	function handleResize(btn) {
		var id   = btn.dataset.id;
		var csrf = getCsrf();
		var w    = document.getElementById('mm-resize-w');
		var h    = document.getElementById('mm-resize-h');
		if (!w || !h) return;

		var postUrl = getImageEditPostUrl(id);
		if (!postUrl) {
			showAdminError('Bildbearbeitungs-URL nicht konfiguriert');
			return;
		}

		var formData = new FormData();
		formData.append('action', 'resize');
		formData.append('width', w.value);
		formData.append('height', h.value);
		if (csrf) formData.append(csrf.name, csrf.value);

		ajaxPost(postUrl, formData, function(resp) {
			if (resp.status === 'ok') {
				var img = document.getElementById('mm-edit-img');
				if (img) img.src = resp.url + (resp.url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
				if (w) w.value = resp.width;
				if (h) h.value = resp.height;
			} else if (resp.message) {
				showAdminError(resp.message);
			}
		});
	}

	// -----------------------------------------------------------------------
	// Hilfsfunktionen
	// -----------------------------------------------------------------------

	function showAdminError(msg) {
		if (window.ProcessWire && ProcessWire.notices) {
			ProcessWire.notices.addError(msg);
		} else {
			alert(msg);
		}
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function getBulkScopeRoot() {
		var bulk = document.getElementById('mm-bulk-bar');
		if (bulk && bulk.getAttribute('data-mm-view') === 'list') {
			return document.getElementById('mm-list-view');
		}
		return document.getElementById('mm-grid');
	}

	function getSelectedBulkIds() {
		var root = getBulkScopeRoot();
		if (!root) return [];
		return Array.prototype.map.call(
			root.querySelectorAll('.mm-bulk-cb:checked'),
			function(cb) { return cb.value; }
		);
	}

	function updateBulkCount() {
		var el = document.getElementById('mm-bulk-count');
		var bulk = document.getElementById('mm-bulk-bar');
		if (!el || !bulk) return;
		var total = parseInt(bulk.getAttribute('data-page-total'), 10);
		if (isNaN(total)) total = 0;
		var n = getSelectedBulkIds().length;
		el.textContent = n + ' von ' + total + ' ausgewählt';
	}

	function copyUrlToClipboard(url) {
		if (!url) return;
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(url).then(function() {
				if (window.ProcessWire && ProcessWire.notices) {
					ProcessWire.notices.add('URL kopiert.');
				}
			}).catch(function() {
				window.prompt('URL kopieren:', url);
			});
		} else {
			window.prompt('URL kopieren:', url);
		}
	}

	function initBulkBar() {
		var selAll = document.getElementById('mm-select-all');
		var delBtn = document.getElementById('mm-bulk-delete');
		var catBtn = document.getElementById('mm-bulk-apply-cat');
		var cfg = getCfg();
		var ajaxUrl = cfg.ajaxUrl;

		document.addEventListener('change', function(e) {
			if (e.target && e.target.classList && e.target.classList.contains('mm-bulk-cb') && e.target.closest('#mm-grid, #mm-list-view')) {
				updateBulkCount();
			}
		});

		if (selAll) {
			selAll.addEventListener('change', function() {
				var on = this.checked;
				var scope = getBulkScopeRoot();
				if (scope) {
					scope.querySelectorAll('.mm-bulk-cb').forEach(function(cb) {
						cb.checked = on;
					});
				}
				updateBulkCount();
			});
		}

		if (delBtn && ajaxUrl) {
			delBtn.addEventListener('click', function() {
				var ids = getSelectedBulkIds();
				if (!ids.length) {
					showAdminError('Nichts ausgewählt.');
					return;
				}
				if (!confirm(ids.length + ' Medien wirklich löschen?')) return;
				var csrf = getCsrf();
				var fd = new FormData();
				fd.append('action', 'bulk-delete');
				fd.append('ids', ids.join(','));
				if (csrf) fd.append(csrf.name, csrf.value);
				ajaxPost(ajaxUrl, fd, function(resp) {
					if (resp.status === 'ok') {
						ids.forEach(function(id) {
							var item = document.querySelector('.mm-grid-item[data-id="' + id + '"], tr.mm-list-row[data-id="' + id + '"]');
							if (item) item.remove();
						});
						if (selAll) selAll.checked = false;
						updateBulkCount();
						if (window.ProcessWire && ProcessWire.notices) {
							ProcessWire.notices.add('Gelöscht: ' + (resp.deleted || ids.length));
						}
					} else {
						showAdminError('Massen-Löschen fehlgeschlagen');
					}
				});
			});
		}

		if (catBtn && ajaxUrl) {
			catBtn.addEventListener('click', function() {
				var ids = getSelectedBulkIds();
				if (!ids.length) {
					showAdminError('Nichts ausgewählt.');
					return;
				}
				var katEl = document.getElementById('mm-bulk-kategorie');
				var kat = katEl ? katEl.value : '0';
				var csrf = getCsrf();
				var fd = new FormData();
				fd.append('action', 'bulk-set-kategorie');
				fd.append('ids', ids.join(','));
				fd.append('kategorie_id', kat);
				if (csrf) fd.append(csrf.name, csrf.value);
				ajaxPost(ajaxUrl, fd, function(resp) {
					if (resp.status === 'ok') {
						if (window.ProcessWire && ProcessWire.notices) {
							ProcessWire.notices.add('Kategorie aktualisiert (' + (resp.updated || ids.length) + ').');
						}
					} else {
						showAdminError(resp.message || 'Kategorie setzen fehlgeschlagen');
					}
				});
			});
		}
	}

	function initCopyUrlButtons() {
		document.addEventListener('click', function(e) {
			var btn = e.target.closest('.mm-btn-copy-url');
			if (!btn) return;
			e.preventDefault();
			var url = btn.dataset.url || '';
			copyUrlToClipboard(url);
		});
	}

	function initDuplicateButtons() {
		document.addEventListener('click', function(e) {
			var btn = e.target.closest('.mm-btn-duplicate');
			if (!btn) return;
			e.preventDefault();
			var id = btn.dataset.id;
			var cfg = getCfg();
			var ajaxUrl = cfg.ajaxUrl;
			if (!ajaxUrl) {
				showAdminError('AJAX-URL nicht konfiguriert');
				return;
			}
			var csrf = getCsrf();
			var fd = new FormData();
			fd.append('action', 'duplicate-media');
			fd.append('id', id);
			if (csrf) fd.append(csrf.name, csrf.value);
			ajaxPost(ajaxUrl, fd, function(resp) {
				if (resp.status === 'ok') {
					if (document.getElementById('mm-bulk-bar') && document.getElementById('mm-bulk-bar').getAttribute('data-mm-view') === 'list') {
						window.location.reload();
						return;
					}
					prependGridItem(resp);
					if (window.ProcessWire && ProcessWire.notices) {
						ProcessWire.notices.add('Duplikat angelegt.');
					}
				} else {
					showAdminError(resp.message || 'Duplizieren fehlgeschlagen');
				}
			});
		});
	}

	// -----------------------------------------------------------------------
	// Initialisierung
	// -----------------------------------------------------------------------

	document.addEventListener('DOMContentLoaded', function() {
		initUploadModal();
		initDeleteButtons();
		initImageEdit();
		initBulkBar();
		initCopyUrlButtons();
		initDuplicateButtons();
		updateBulkCount();
	});

})();