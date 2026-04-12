/**
 * medienmanager-admin.js
 *
 * JavaScript für die Admin-Oberfläche des Medien Managers.
 * Zuständig für: Grid-Interaktionen, Upload-Modal, Lösch-Bestätigungen,
 * Lazy Loading und Bildbearbeitungs-Aktionen.
 *
 * Abhängigkeiten: ProcessWire-eigenes UIkit (im Admin verfügbar)
 */

(function() {
	'use strict';

	// -----------------------------------------------------------------------
	// Upload-Modal
	// -----------------------------------------------------------------------

	/**
	 * Upload-Modal initialisieren.
	 */
	function initUploadModal() {
		var uploadBtn  = document.getElementById('mm-upload-btn');
		var modal      = document.getElementById('mm-upload-modal');
		if (!uploadBtn || !modal) return;

		var closeBtn   = modal.querySelector('.mm-modal-close');
		var cancelBtn  = modal.querySelector('.mm-modal-cancel');
		var submitBtn  = modal.querySelector('.mm-upload-submit');
		var dropZone   = document.getElementById('mm-drop-zone');
		var fileInput  = document.getElementById('mm-file-input');

		// Modal öffnen
		uploadBtn.addEventListener('click', function() {
			modal.style.display = 'flex';
		});

		// Modal schließen
		[closeBtn, cancelBtn].forEach(function(btn) {
			if (btn) btn.addEventListener('click', closeUploadModal);
		});

		// Backdrop-Klick schließt Modal
		modal.querySelector('.mm-modal-backdrop').addEventListener('click', closeUploadModal);

		// Drag & Drop
		if (dropZone && fileInput) {
			dropZone.addEventListener('click', function() {
				fileInput.click();
			});

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
					showSelectedFile(e.dataTransfer.files[0]);
				}
			});

			fileInput.addEventListener('change', function() {
				if (fileInput.files.length) {
					showSelectedFile(fileInput.files[0]);
				}
			});
		}

		// Upload absenden
		if (submitBtn) {
			submitBtn.addEventListener('click', submitUpload);
		}
	}

	/**
	 * Ausgewählten Dateinamen im Drop-Bereich anzeigen.
	 *
	 * @param {File} file
	 */
	function showSelectedFile(file) {
		var dropZone = document.getElementById('mm-drop-zone');
		if (!dropZone) return;
		var para = dropZone.querySelector('p');
		if (para) para.textContent = file.name;
	}

	/**
	 * Upload-Formular per XMLHttpRequest absenden.
	 */
	function submitUpload() {
		var form      = document.getElementById('mm-upload-form');
		var progress  = document.querySelector('.mm-upload-progress');
		var bar       = document.querySelector('.mm-progress-fill');
		var text      = document.querySelector('.mm-progress-text');
		if (!form) return;

		var formData = new FormData(form);
		var xhr      = new XMLHttpRequest();

		// Fortschrittsanzeige
		if (progress) progress.style.display = 'flex';

		xhr.upload.addEventListener('progress', function(e) {
			if (e.lengthComputable) {
				var pct = Math.round((e.loaded / e.total) * 100);
				if (bar)  bar.style.width = pct + '%';
				if (text) text.textContent = pct + '%';
			}
		});

		xhr.addEventListener('load', function() {
			if (xhr.status === 200) {
				try {
					var resp = JSON.parse(xhr.responseText);
					if (resp.status === 'ok') {
						// Grid-Item einfügen und Modal schließen
						prependGridItem(resp);
						closeUploadModal();
						form.reset();
					} else {
						showAdminError(resp.message || 'Upload fehlgeschlagen');
					}
				} catch (e) {
					showAdminError('Ungültige Server-Antwort');
				}
			} else {
				showAdminError('Upload fehlgeschlagen (HTTP ' + xhr.status + ')');
			}
			if (progress) progress.style.display = 'none';
		});

		xhr.addEventListener('error', function() {
			showAdminError('Netzwerkfehler beim Upload');
			if (progress) progress.style.display = 'none';
		});

		xhr.open('POST', form.getAttribute('action'), true);
		xhr.send(formData);
	}

	/**
	 * Upload-Modal schließen und Formular zurücksetzen.
	 */
	function closeUploadModal() {
		var modal = document.getElementById('mm-upload-modal');
		if (modal) modal.style.display = 'none';
	}

	// -----------------------------------------------------------------------
	// Grid-Item nach Upload voranstellen
	// -----------------------------------------------------------------------

	/**
	 * Neues Grid-Item am Anfang des Grids einfügen.
	 *
	 * @param {Object} data {id, titel, thumb}
	 */
	function prependGridItem(data) {
		var grid = document.getElementById('mm-grid');
		if (!grid) return;

		var item = document.createElement('div');
		item.className  = 'mm-grid-item mm-grid-item-new';
		item.dataset.id = data.id;

		var thumbHtml = data.thumb
			? '<img src="' + escapeHtml(data.thumb) + '" alt="" loading="lazy">'
			: '<span class="mm-grid-icon"><i class="fa fa-file"></i></span>';

		item.innerHTML = '<div class="mm-grid-thumb">' + thumbHtml + '</div>'
			+ '<div class="mm-grid-info">'
			+ '<span class="mm-grid-title">' + escapeHtml(data.titel) + '</span>'
			+ '</div>'
			+ '<div class="mm-grid-actions">'
			+ '<a href="./edit/?id=' + data.id + '" class="mm-btn-edit" title="Bearbeiten"><i class="fa fa-pencil"></i></a>'
			+ '<button type="button" class="mm-btn-delete" data-id="' + data.id + '" title="Löschen"><i class="fa fa-trash"></i></button>'
			+ '</div>';

		// Leere-Hinweis entfernen falls vorhanden
		var empty = grid.querySelector('.mm-empty');
		if (empty) empty.remove();

		grid.insertBefore(item, grid.firstChild);

		// Lösch-Button am neuen Item registrieren
		var deleteBtn = item.querySelector('.mm-btn-delete');
		if (deleteBtn) deleteBtn.addEventListener('click', handleDeleteClick);

		// Kurze Highlight-Animation
		requestAnimationFrame(function() {
			item.classList.add('mm-item-highlight');
			setTimeout(function() { item.classList.remove('mm-item-highlight'); }, 1500);
		});
	}

	// -----------------------------------------------------------------------
	// Löschen
	// -----------------------------------------------------------------------

	/**
	 * Alle Lösch-Buttons im Grid initialisieren.
	 */
	function initDeleteButtons() {
		document.querySelectorAll('.mm-btn-delete, .mm-btn-delete-single').forEach(function(btn) {
			btn.addEventListener('click', handleDeleteClick);
		});
	}

	/**
	 * Klick auf Lösch-Button verarbeiten.
	 *
	 * @param {Event} e
	 */
	function handleDeleteClick(e) {
		var btn    = e.currentTarget;
		var id     = btn.dataset.id;
		var csrfN  = btn.dataset.csrfName;
		var csrfV  = btn.dataset.csrfVal;

		if (!confirm('Dieses Medium wirklich löschen?')) return;

		// Ermittle AJAX-URL aus den globalen JS-Konfigurationsdaten
		var cfg     = window.InputfieldMedienManager || {};
		var ajaxUrl = cfg.ajaxUrl || './ajax/';

		var formData = new FormData();
		formData.append('action', 'delete');
		formData.append('id', id);
		if (csrfN) formData.append(csrfN, csrfV);

		var xhr = new XMLHttpRequest();
		xhr.addEventListener('load', function() {
			try {
				var resp = JSON.parse(xhr.responseText);
				if (resp.status === 'ok') {
					// Grid-Item entfernen
					var item = document.querySelector('.mm-grid-item[data-id="' + id + '"]');
					if (item) {
						item.style.opacity = '0';
						setTimeout(function() { item.remove(); }, 300);
					}
					// Auf Edit-Seite: zurück zur Übersicht
					if (btn.classList.contains('mm-btn-delete-single')) {
						window.location.href = '../';
					}
				} else {
					showAdminError('Löschen fehlgeschlagen');
				}
			} catch (err) {
				showAdminError('Ungültige Server-Antwort');
			}
		});

		xhr.open('POST', ajaxUrl, true);
		xhr.send(formData);
	}

	// -----------------------------------------------------------------------
	// Bildbearbeitung (Rotate / Resize)
	// -----------------------------------------------------------------------

	/**
	 * Rotate-Buttons initialisieren.
	 */
	function initImageEdit() {
		document.querySelectorAll('.mm-rotate-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var id      = btn.dataset.id;
				var degrees = btn.dataset.degrees;
				var csrfN   = btn.dataset.csrfName;
				var csrfV   = btn.dataset.csrfVal;

				var formData = new FormData();
				formData.append('action', 'rotate');
				formData.append('id', id);
				formData.append('degrees', degrees);
				if (csrfN) formData.append(csrfN, csrfV);

				ajaxPost('./imageedit/?id=' + id, formData, function(resp) {
					if (resp.status === 'ok') {
						var img = document.getElementById('mm-edit-img');
						if (img) img.src = resp.url + '?t=' + Date.now();
					}
				});
			});
		});

		// Resize-Button
		var resizeBtn = document.querySelector('.mm-resize-btn');
		if (resizeBtn) {
			resizeBtn.addEventListener('click', function() {
				var id    = resizeBtn.dataset.id;
				var csrfN = resizeBtn.dataset.csrfName;
				var csrfV = resizeBtn.dataset.csrfVal;
				var w     = document.getElementById('mm-resize-w');
				var h     = document.getElementById('mm-resize-h');

				if (!w || !h) return;

				var formData = new FormData();
				formData.append('action', 'resize');
				formData.append('id', id);
				formData.append('width', w.value);
				formData.append('height', h.value);
				if (csrfN) formData.append(csrfN, csrfV);

				ajaxPost('./imageedit/?id=' + id, formData, function(resp) {
					if (resp.status === 'ok') {
						var img = document.getElementById('mm-edit-img');
						if (img) img.src = resp.url + '?t=' + Date.now();
						if (w) w.value = resp.width;
						if (h) h.value = resp.height;
					}
				});
			});
		}
	}

	// -----------------------------------------------------------------------
	// Lazy Loading (Intersection Observer)
	// -----------------------------------------------------------------------

	/**
	 * Lazy Loading für Grid-Bilder aktivieren.
	 */
	function initLazyLoad() {
		if (!('IntersectionObserver' in window)) return;

		var observer = new IntersectionObserver(function(entries) {
			entries.forEach(function(entry) {
				if (entry.isIntersecting) {
					var img = entry.target;
					if (img.dataset.src) {
						img.src = img.dataset.src;
						delete img.dataset.src;
						observer.unobserve(img);
					}
				}
			});
		}, { rootMargin: '200px' });

		// Bilder mit loading="lazy" werden bereits nativ lazy geladen;
		// dieser Observer dient als Fallback und für ältere Browser.
		document.querySelectorAll('.mm-grid-thumb img[loading="lazy"]').forEach(function(img) {
			observer.observe(img);
		});
	}

	// -----------------------------------------------------------------------
	// Hilfsfunktionen
	// -----------------------------------------------------------------------

	/**
	 * AJAX POST absenden.
	 *
	 * @param {string}   url
	 * @param {FormData} formData
	 * @param {Function} callback  Wird mit geparster Antwort aufgerufen
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
		xhr.send(formData);
	}

	/**
	 * Fehlermeldung im Admin anzeigen.
	 *
	 * @param {string} msg
	 */
	function showAdminError(msg) {
		// ProcessWire-eigenes Notices-System nutzen falls vorhanden
		if (window.ProcessWire && ProcessWire.notices) {
			ProcessWire.notices.addError(msg);
		} else {
			alert(msg);
		}
	}

	/**
	 * HTML-Sonderzeichen escapen.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	// -----------------------------------------------------------------------
	// Initialisierung
	// -----------------------------------------------------------------------

	document.addEventListener('DOMContentLoaded', function() {
		initUploadModal();
		initDeleteButtons();
		initImageEdit();
		initLazyLoad();
	});

})();
