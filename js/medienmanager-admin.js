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
					showSelectedFile(e.dataTransfer.files[0]);
				}
			});

			fileInput.addEventListener('change', function() {
				if (fileInput.files.length) showSelectedFile(fileInput.files[0]);
			});
		}

		if (submitBtn) submitBtn.addEventListener('click', submitUpload);
	}

	function showSelectedFile(file) {
		var dropZone = document.getElementById('mm-drop-zone');
		if (!dropZone) return;
		var para = dropZone.querySelector('p');
		if (para) para.textContent = file.name;
	}

	function submitUpload() {
        var form = document.getElementById('mm-upload-form');
        var fileInput = document.getElementById('mm-file-input');
        if (!form || !fileInput.files.length) return;
    
        var files = Array.from(fileInput.files);
        var totalFiles = files.length;
        var completed = 0;
    
        // UI-Elemente greifen
        var progressContainer = document.querySelector('.mm-upload-progress'); // Existiert in deinem PHP
        var progressBar = document.querySelector('.mm-progress-fill');
        var progressText = document.querySelector('.mm-progress-text');
    
        if (progressContainer) progressContainer.style.display = 'flex';
    
        files.forEach(function(file) {
            var formData = new FormData();
            var csrf = getCsrf();
            if (csrf) formData.append(csrf.name, csrf.value);
            
            // Wichtig: 'mm_datei' muss zum Namen im PHP passen
            formData.append('mm_datei', file);
            formData.append('titel', file.name);
    
            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action'), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
            xhr.onload = function() {
                completed++;
                
                // Fortschritt berechnen (Anzahl fertiger Dateien)
                var percent = Math.round((completed / totalFiles) * 100);
                if (progressBar) progressBar.style.display = 'block';
                if (progressBar) progressBar.style.width = percent + '%';
                if (progressText) progressText.textContent = percent + '%';
    
                if (completed === totalFiles) {
                    // Ein kleiner Moment für das Auge, dann Refresh
                    setTimeout(function() {
                        location.reload();
                    }, 600);
                }
            };
    
            xhr.onerror = function() {
                showAdminError('Fehler beim Upload von: ' + file.name);
                completed++;
                if (completed === totalFiles) location.reload();
            };
    
            xhr.send(formData);
        });
    }

	function closeUploadModal() {
		var modal = document.getElementById('mm-upload-modal');
		if (modal) modal.style.display = 'none';
	}

	// -----------------------------------------------------------------------
	// Grid-Item nach Upload voranstellen
	// -----------------------------------------------------------------------

	function prependGridItem(data) {
		var grid = document.getElementById('mm-grid');
		if (!grid) return;

		var item        = document.createElement('div');
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
    
        var cfg = getCfg();
        // Sicherstellen, dass wir immer den /ajax/ Endpunkt treffen, 
        // egal ob wir auf der Grid- oder Edit-Seite sind.
        var ajaxUrl = cfg.ajaxUrl; 
    
        var formData = new FormData();
        formData.append('action', 'delete'); // Das muss in PHP ankommen!
        formData.append('id', id);
    
        var csrf = getCsrf();
        if (csrf) formData.append(csrf.name, csrf.value);
    
        // DEBUG: Schau in die Konsole, was hier steht
        console.log("Sende an: " + ajaxUrl, "Action: delete", "ID: " + id);
    
        ajaxPost(ajaxUrl, formData, function(resp) {
            if (resp.status === 'ok') {
                location.reload();
                // Erfolg: Item aus dem Grid entfernen
                var item = document.querySelector('.mm-grid-item[data-id="' + id + '"]');
                if (item) {
                    item.style.opacity = '0';
                    setTimeout(function() { item.remove(); }, 300);
                }
                if (btn.classList.contains('mm-btn-delete-single')) {
                    window.location.href = '../';
                }
            } else {
                // FEHLER: Hier die Nachricht vom Server nutzen
                // Wenn resp.message existiert, zeige sie an, sonst Standardtext
                var errorMsg = resp.message ? resp.message : 'Löschen fehlgeschlagen';
                showAdminError(errorMsg);
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

	function handleRotate(btn) {
		var id      = btn.dataset.id;
		var degrees = btn.dataset.degrees;
		var csrf    = getCsrf();
		var cfg     = getCfg();

		var formData = new FormData();
		formData.append('action', 'rotate');
		formData.append('id', id);
		formData.append('degrees', degrees);
		if (csrf) formData.append(csrf.name, csrf.value);

		ajaxPost(cfg.ajaxUrl + '../../imageedit/?id=' + id, formData, function(resp) {
			if (resp.status === 'ok') {
				var img = document.getElementById('mm-edit-img');
				if (img) img.src = resp.url + '?t=' + Date.now();
			}
		});
	}

	function handleResize(btn) {
		var id   = btn.dataset.id;
		var csrf = getCsrf();
		var cfg  = getCfg();
		var w    = document.getElementById('mm-resize-w');
		var h    = document.getElementById('mm-resize-h');
		if (!w || !h) return;

		var formData = new FormData();
		formData.append('action', 'resize');
		formData.append('id', id);
		formData.append('width', w.value);
		formData.append('height', h.value);
		if (csrf) formData.append(csrf.name, csrf.value);

		ajaxPost(cfg.ajaxUrl + '../../imageedit/?id=' + id, formData, function(resp) {
			if (resp.status === 'ok') {
				var img = document.getElementById('mm-edit-img');
				if (img) img.src = resp.url + '?t=' + Date.now();
				if (w) w.value = resp.width;
				if (h) h.value = resp.height;
			}
		});
	}

	// -----------------------------------------------------------------------
	// Lazy Loading
	// -----------------------------------------------------------------------

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

		document.querySelectorAll('.mm-grid-thumb img[loading="lazy"]').forEach(function(img) {
			observer.observe(img);
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