/**
 * medienmanager-inputfield.js
 *
 * JavaScript für den InputfieldMedienManager (Modal-Picker).
 * Zuständig für: Modal öffnen/schließen, Medien auswählen/abwählen,
 * Filter und Suche im Modal, Bestätigung der Auswahl, Entfernen von Items.
 *
 * Abhängigkeiten: ProcessWire-eigenes UIkit (im Admin verfügbar).
 * Konfiguration: window.InputfieldMedienManager (von PHP via $config->js() befüllt)
 */

(function() {
	'use strict';

	// Globale Konfiguration aus PHP
	var cfg = window.InputfieldMedienManager || {};

	/**
	 * Config zur Laufzeit holen.
	 *
	 * Hintergrund: In Page-Edit-Forms wird die Inline-Config ggf. erst im Feld-HTML ausgegeben,
	 * nachdem dieses JS (im <head>) bereits geladen wurde. Dann wäre ein einmaliges cfg-Caching leer.
	 */
	function getCfg() {
		return window.InputfieldMedienManager || cfg || {};
	}

	/**
	 * Zustand pro aktiv geöffnetem Modal (nach Feldname).
	 * @type {Object.<string, {selectedIds: Set, currentFilters: Object, currentPage: number}>}
	 */
	var modalState = {};

	// -----------------------------------------------------------------------
	// Initialisierung
	// -----------------------------------------------------------------------

	document.addEventListener('DOMContentLoaded', function() {
		// Alle Inputfield-Wrapper auf der Seite initialisieren
		document.querySelectorAll('.mm-inputfield-wrap').forEach(initInputfield);
	});

	/**
	 * Einen einzelnen Inputfield-Wrapper initialisieren.
	 *
	 * @param {HTMLElement} wrap
	 */
	function parseAllowedTypes(wrap) {
		var raw = wrap.getAttribute('data-allowed-types') || '[]';
		try {
			var a = JSON.parse(raw);
			return Array.isArray(a) ? a : [];
		} catch (e) {
			return [];
		}
	}

	function initInputfield(wrap) {
		var fieldName  = wrap.dataset.name;
		if (!fieldName) return;

		// Vorauswahl aus bereits gesetzten Hidden-Inputs einlesen
		var initialIds = new Set();
		var initialIdsOrdered = [];
		wrap.querySelectorAll('.mm-hidden-inputs input').forEach(function(inp) {
			var id = parseInt(inp.value, 10);
			if (id > 0) {
				initialIds.add(id);
				initialIdsOrdered.push(id);
			}
		});

		// maxItems aus dem Wrap-Element lesen (0 = unbegrenzt)
		var maxItems = parseInt(wrap.dataset.max || '0', 10) || 0;
		if (maxItems === 1 && initialIdsOrdered.length > 1) {
			// Einzelauswahl: behalte nur das zuletzt gespeicherte (neueste gewinnt)
			initialIds.clear();
			initialIds.add(initialIdsOrdered[initialIdsOrdered.length - 1]);
		}

		var allowed = parseAllowedTypes(wrap);
		var defaultTyp = '';
		if (allowed.length === 1) {
			defaultTyp = allowed[0];
		}

		// Modal-Zustand initialisieren
		modalState[fieldName] = {
			selectedIds:    initialIds,
			currentFilters: { typ: defaultTyp, kategorie_id: '', q: '' },
			currentPage:    0,
			allowedTypes:   allowed,
		};

		// "Medien auswählen"-Button
		var openBtn = wrap.querySelector('.mm-open-modal');
		if (openBtn) {
			openBtn.addEventListener('click', function() {
				openModal(fieldName);
			});
		}

		// "×"-Buttons an bestehenden Chips
		wrap.querySelectorAll('.mm-remove-item').forEach(function(btn) {
			btn.addEventListener('click', function() {
				removeItem(fieldName, parseInt(btn.dataset.id, 10));
			});
		});
	}

	// -----------------------------------------------------------------------
	// Modal öffnen / schließen
	// -----------------------------------------------------------------------

	/**
	 * Modal für das angegebene Feld öffnen.
	 *
	 * @param {string} fieldName
	 */
	function openModal(fieldName) {
		var modal = document.getElementById('mm-modal-' + fieldName);
		if (!modal) return;

		modal.style.display = 'flex';

		var state = modalState[fieldName];
		var typSelect = modal.querySelector('.mm-filter-typ');
		if (state && typSelect) {
			if (state.allowedTypes && state.allowedTypes.length === 1) {
				typSelect.value = state.allowedTypes[0];
				state.currentFilters.typ = state.allowedTypes[0];
			} else {
				state.currentFilters.typ = typSelect.value || '';
			}
		}

		// Kategorien für den Filter laden (einmalig)
		var katSelect = modal.querySelector('.mm-filter-kategorie');
		if (katSelect && katSelect.options.length <= 1) {
			loadKategorien(katSelect);
		}

		// Initiales Grid laden
		loadPickerContent(fieldName, 0);

		// Filter-Ereignisse registrieren (nur einmalig)
		if (!modal.dataset.mmInit) {
			modal.dataset.mmInit = '1';
			initModalEvents(modal, fieldName);
		}
	}

	/**
	 * Modal für das angegebene Feld schließen.
	 *
	 * @param {string} fieldName
	 */
	function closeModal(fieldName) {
		var modal = document.getElementById('mm-modal-' + fieldName);
		if (modal) modal.style.display = 'none';
	}

	/**
	 * Alle Event-Listener innerhalb des Modals einmalig registrieren.
	 *
	 * @param {HTMLElement} modal
	 * @param {string}      fieldName
	 */
	function initModalEvents(modal, fieldName) {
		// Schließen-Buttons
		modal.querySelector('.mm-modal-close').addEventListener('click', function() {
			closeModal(fieldName);
		});
		modal.querySelector('.mm-modal-cancel').addEventListener('click', function() {
			closeModal(fieldName);
		});
		modal.querySelector('.mm-modal-backdrop').addEventListener('click', function() {
			closeModal(fieldName);
		});

		// "Übernehmen"-Button
		modal.querySelector('.mm-modal-confirm').addEventListener('click', function() {
			confirmSelection(fieldName);
		});

		// Typ-Filter
		var typSelect = modal.querySelector('.mm-filter-typ');
		if (typSelect) {
			typSelect.addEventListener('change', function() {
				modalState[fieldName].currentFilters.typ = typSelect.value;
				modalState[fieldName].currentPage = 0;
				loadPickerContent(fieldName, 0);
			});
		}

		// Kategorie-Filter
		var katSelect = modal.querySelector('.mm-filter-kategorie');
		if (katSelect) {
			katSelect.addEventListener('change', function() {
				modalState[fieldName].currentFilters.kategorie_id = katSelect.value;
				modalState[fieldName].currentPage = 0;
				loadPickerContent(fieldName, 0);
			});
		}

		// Suchfeld (debounced)
		var searchInput = modal.querySelector('.mm-filter-suche');
		if (searchInput) {
			var debounceTimer;
			searchInput.addEventListener('input', function() {
				clearTimeout(debounceTimer);
				debounceTimer = setTimeout(function() {
					modalState[fieldName].currentFilters.q = searchInput.value;
					modalState[fieldName].currentPage = 0;
					loadPickerContent(fieldName, 0);
				}, 350);
			});
		}

		// Keyboard: ESC schließt Modal
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				closeModal(fieldName);
			}
		});
	}

	// -----------------------------------------------------------------------
	// Picker-Inhalt laden
	// -----------------------------------------------------------------------

	/**
	 * Grid-Inhalt des Modals per AJAX laden.
	 *
	 * @param {string} fieldName
	 * @param {number} page      Seiten-Index (0-basiert)
	 */
	function loadPickerContent(fieldName, page) {
		var state    = modalState[fieldName];
		var modal    = document.getElementById('mm-modal-' + fieldName);
		var content  = modal ? modal.querySelector('.mm-picker-content') : null;
		if (!content) return;

		// Lade-Spinner anzeigen
		content.innerHTML = '<div class="mm-loading"><i class="fa fa-spinner fa-spin"></i> Wird geladen…</div>';

		var filters  = state.currentFilters;
		var params   = new URLSearchParams({
			action:       'modal-items',
			page:         page,
			typ:          filters.typ          || '',
			kategorie_id: filters.kategorie_id || '',
			q:            filters.q            || '',
		});
		if (state.allowedTypes && state.allowedTypes.length > 1) {
			params.set('allowed_types', state.allowedTypes.join(','));
		}

		var runtimeCfg = getCfg();
		var url = (runtimeCfg.ajaxUrl || './ajax/') + '?' + params.toString();
		var xhr = new XMLHttpRequest();

		xhr.addEventListener('load', function() {
			try {
				var resp = JSON.parse(xhr.responseText);
				if (resp.status === 'ok') {
					content.innerHTML = resp.html || '<p class="mm-empty">Keine Medien gefunden.</p>';
					state.currentPage = page;

					// Bereits gewählte Items markieren
					rehighlightSelected(fieldName);

					// Click-Handler für neue Items
					content.querySelectorAll('.mm-picker-item').forEach(function(item) {
						item.addEventListener('click', function() {
							togglePickerItem(fieldName, item);
						});
					});

					// Pagination rendern
					renderModalPagination(modal, fieldName, resp.total, resp.start, resp.limit);
				} else {
					content.innerHTML = '<p class="mm-error">Fehler beim Laden</p>';
				}
			} catch (err) {
				try {
					console.log('[MedienManager Picker] Ungültige Serverantwort', {
						status: xhr.status,
						url: url,
						responseHead: (xhr.responseText || '').slice(0, 300)
					});
				} catch(e) {}
				content.innerHTML = '<p class="mm-error">Ungültige Server-Antwort</p>';
			}
		});

		xhr.addEventListener('error', function() {
			content.innerHTML = '<p class="mm-error">Netzwerkfehler</p>';
		});

		xhr.open('GET', url, true);
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.send();
	}

	/**
	 * Kategorien für den Filter-Dropdown per AJAX laden.
	 *
	 * @param {HTMLSelectElement} select
	 */
	function loadKategorien(select) {
		var runtimeCfg = getCfg();
		var url = (runtimeCfg.ajaxUrl || './ajax/') + '?action=kategorien';
		var xhr = new XMLHttpRequest();

		xhr.open('GET', url, true);
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.addEventListener('load', function() {
			try {
				var resp = JSON.parse(xhr.responseText);
				if (resp.status === 'ok' && resp.kategorien) {
					resp.kategorien.forEach(function(kat) {
						var opt = document.createElement('option');
						opt.value       = kat.id;
						opt.textContent = kat.titel;
						select.appendChild(opt);
					});
				}
			} catch (err) {
				// Kein fataler Fehler — Filter bleibt ohne Kategorien
			}
		});

		xhr.send();
	}

	// -----------------------------------------------------------------------
	// Item-Auswahl
	// -----------------------------------------------------------------------

	/**
	 * Auswahl eines Picker-Items umschalten.
	 *
	 * @param {string}      fieldName
	 * @param {HTMLElement} itemEl
	 */
	function togglePickerItem(fieldName, itemEl) {
		var state   = modalState[fieldName];
		var id      = parseInt(itemEl.dataset.id, 10);
		var maxItems = parseInt(
			(document.querySelector('.mm-inputfield-wrap[data-name="' + fieldName + '"]') || {}).dataset || {},
			10
		);
		// maxItems aus dem Wrap-Element lesen
		var wrap = document.querySelector('.mm-inputfield-wrap[data-name="' + fieldName + '"]');
		maxItems = wrap ? parseInt(wrap.dataset.max, 10) : 0;

		if (state.selectedIds.has(id)) {
			// Abwählen
			state.selectedIds.delete(id);
			itemEl.classList.remove('mm-selected');
		} else {
			// Anwählen — maxItems beachten (0 = unbegrenzt)
			if (maxItems === 1) {
				// Einzelauswahl: andere Items abwählen
				state.selectedIds.clear();
				document.querySelectorAll('#mm-modal-' + fieldName + ' .mm-picker-item.mm-selected').forEach(function(el) {
					el.classList.remove('mm-selected');
				});
			} else if (maxItems > 1 && state.selectedIds.size >= maxItems) {
				// Maximale Anzahl erreicht
				return;
			}
			state.selectedIds.add(id);
			itemEl.classList.add('mm-selected');
		}

		updateSelectionCount(fieldName);
	}

	/**
	 * Zähler im Modal-Footer aktualisieren.
	 *
	 * @param {string} fieldName
	 */
	function updateSelectionCount(fieldName) {
		var state  = modalState[fieldName];
		var modal  = document.getElementById('mm-modal-' + fieldName);
		if (!modal) return;
		var counter = modal.querySelector('.mm-selection-count');
		if (counter) {
			counter.textContent = state.selectedIds.size + ' ausgewählt';
		}
	}

	/**
	 * Nach Seiten-Wechsel im Modal die bereits gewählten Items wieder markieren.
	 *
	 * @param {string} fieldName
	 */
	function rehighlightSelected(fieldName) {
		var state = modalState[fieldName];
		state.selectedIds.forEach(function(id) {
			var el = document.querySelector('#mm-modal-' + fieldName + ' .mm-picker-item[data-id="' + id + '"]');
			if (el) el.classList.add('mm-selected');
		});
		updateSelectionCount(fieldName);
	}

	// -----------------------------------------------------------------------
	// Auswahl bestätigen
	// -----------------------------------------------------------------------

	/**
	 * Ausgewählte Items in das Inputfield übernehmen.
	 *
	 * @param {string} fieldName
	 */
	function confirmSelection(fieldName) {
		var state = modalState[fieldName];
		var wrap  = document.querySelector('.mm-inputfield-wrap[data-name="' + fieldName + '"]');
		if (!wrap) return;

		var hiddenWrap   = wrap.querySelector('.mm-hidden-inputs');
		var chipsWrap    = wrap.querySelector('.mm-selected-items');

		// Bestehende Inputs und Chips leeren
		hiddenWrap.innerHTML = '';
		chipsWrap.innerHTML  = '';

		// Für jede gewählte ID: Hidden-Input + Chip erstellen
		// Thumbnail per AJAX laden
		state.selectedIds.forEach(function(id) {
			// Hidden Input sofort setzen
			var input       = document.createElement('input');
			input.type      = 'hidden';
			input.name      = fieldName + '[]';
			input.value     = id;
			hiddenWrap.appendChild(input);

			// Chip mit Lade-Platzhalter
			var chip        = document.createElement('div');
			chip.className  = 'mm-chip';
			chip.dataset.id = id;
			chip.innerHTML  = '<span class="mm-chip-loading"><i class="fa fa-spinner fa-spin"></i></span>'
				+ '<button type="button" class="mm-remove-item" data-id="' + id + '" title="Entfernen">&times;</button>';
			chipsWrap.appendChild(chip);

			// Entfernen-Button am Chip
			chip.querySelector('.mm-remove-item').addEventListener('click', function() {
				removeItem(fieldName, id);
			});

			// Thumbnail nachladen
			loadThumb(fieldName, id, chip);
		});

		closeModal(fieldName);
	}

	/**
	 * Thumbnail-Daten per AJAX laden und Chip aktualisieren.
	 *
	 * @param {string}      fieldName
	 * @param {number}      id
	 * @param {HTMLElement} chip
	 */
	function loadThumb(fieldName, id, chip) {
		var runtimeCfg = getCfg();
		var url = (runtimeCfg.ajaxUrl || './ajax/') + '?action=thumb&id=' + id;
		var xhr = new XMLHttpRequest();

		xhr.addEventListener('load', function() {
			try {
				var resp = JSON.parse(xhr.responseText);
				if (resp.status === 'ok') {
					var titleEl  = chip.querySelector('.mm-chip-loading');
					var removeEl = chip.querySelector('.mm-remove-item');

					var thumbHtml;
					if (resp.url) {
						var altAttr = resp.alt ? escapeHtml(resp.alt) : '';
						thumbHtml = '<img src="' + escapeHtml(resp.url) + '" alt="' + altAttr + '" loading="lazy">';
					} else {
						var iconMap = { video: 'fa-film', pdf: 'fa-file-pdf-o' };
						var icon    = iconMap[resp.typ] || 'fa-file';
						thumbHtml   = '<span class="mm-type-icon"><i class="fa ' + icon + '"></i></span>';
					}

					if (titleEl) titleEl.outerHTML = thumbHtml
						+ '<span class="mm-chip-title">' + escapeHtml(resp.titel || '') + '</span>';

					// Sicherstellen, dass Remove-Button noch da ist
					if (!chip.querySelector('.mm-remove-item')) {
						var btn       = document.createElement('button');
						btn.type      = 'button';
						btn.className = 'mm-remove-item';
						btn.dataset.id = id;
						btn.title     = 'Entfernen';
						btn.innerHTML = '&times;';
						btn.addEventListener('click', function() {
							removeItem(fieldName, id);
						});
						chip.appendChild(btn);
					}
				}
			} catch (err) {
				// Chip bleibt ohne Thumbnail — kein fataler Fehler
			}
		});

		xhr.open('GET', url, true);
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.send();
	}

	// -----------------------------------------------------------------------
	// Item aus Auswahl entfernen
	// -----------------------------------------------------------------------

	/**
	 * Gewähltes Item aus dem Inputfield und dem Modal-Zustand entfernen.
	 *
	 * @param {string} fieldName
	 * @param {number} id
	 */
	function removeItem(fieldName, id) {
		var state = modalState[fieldName];
		var wrap  = document.querySelector('.mm-inputfield-wrap[data-name="' + fieldName + '"]');
		if (!wrap) return;

		// Hidden-Input entfernen
		var inp = wrap.querySelector('.mm-hidden-inputs input[value="' + id + '"]');
		if (inp) inp.remove();

		// Chip entfernen
		var chip = wrap.querySelector('.mm-chip[data-id="' + id + '"]');
		if (chip) chip.remove();

		// Aus Zustand entfernen
		if (state) state.selectedIds.delete(id);

		// Modal-Item de-markieren falls Modal gerade geöffnet
		var modalItem = document.querySelector('#mm-modal-' + fieldName + ' .mm-picker-item[data-id="' + id + '"]');
		if (modalItem) modalItem.classList.remove('mm-selected');

		updateSelectionCount(fieldName);
	}

	// -----------------------------------------------------------------------
	// Modal-Pagination
	// -----------------------------------------------------------------------

	/**
	 * Einfache Pagination unterhalb des Picker-Grids rendern.
	 *
	 * @param {HTMLElement} modal
	 * @param {string}      fieldName
	 * @param {number}      total
	 * @param {number}      start
	 * @param {number}      limit
	 */
	function renderModalPagination(modal, fieldName, total, start, limit) {
		// Alte Pagination entfernen
		var oldPag = modal.querySelector('.mm-modal-pagination');
		if (oldPag) oldPag.remove();

		if (total <= limit) return;

		var pages   = Math.ceil(total / limit);
		var current = Math.floor(start / limit);
		var pag     = document.createElement('div');
		pag.className = 'mm-modal-pagination';

		for (var i = 0; i < pages; i++) {
			var btn       = document.createElement('button');
			btn.type      = 'button';
			btn.textContent = i + 1;
			btn.className = 'mm-pag-btn' + (i === current ? ' mm-pag-active' : '');
			btn.dataset.page = i;
			(function(pageIndex) {
				btn.addEventListener('click', function() {
					loadPickerContent(fieldName, pageIndex);
				});
			})(i);
			pag.appendChild(btn);
		}

		modal.querySelector('.mm-modal-body').appendChild(pag);
	}

	// -----------------------------------------------------------------------
	// Hilfsfunktionen
	// -----------------------------------------------------------------------

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

})();
