/**
 * viable-map-universal.js
 *
 * Maneja todos los contenedores .viable-map-universal generados por [viable_map].
 * Soporta:
 *   - Múltiples proyectos con colores distintos por proyecto
 *   - Filtros interactivos (categoría, tipo, estado)
 *   - Leyenda de colores
 *   - Botón de expandir a modal
 *   - Contorno blanco en las líneas
 */

(function () {
  'use strict';

  /* ── Paleta de colores por proyecto ─────────────────────────── */
  const PROJECT_COLORS = [
    '#e74c3c', '#3498db', '#2ecc71', '#f39c12',
    '#9b59b6', '#1abc9c', '#e67e22', '#34495e',
    '#d35400', '#27ae60', '#2980b9', '#8e44ad',
    '#c0392b', '#16a085', '#f1c40f', '#7f8c8d'
  ];

  /* ── Colores por estado (para cuando la leyenda es por estado) */
  const STATE_COLORS = {
    'proyecto':       '#3388ff',
    'en licitación':  '#ff7f00',
    'adjudicado':     '#ffcc00',
    'en obras':       '#00cc44',
    'paralizado':     '#cc0000',
    'finalizado':     '#888888'
  };

  /* ── Helpers ────────────────────────────────────────────────── */

  /** Asigna un color a cada project code de forma estable */
  function buildColorMap(features) {
    const codes = [...new Set(features.map(f => f.properties.code))];
    const map = {};
    codes.forEach((code, i) => {
      map[code] = PROJECT_COLORS[i % PROJECT_COLORS.length];
    });
    return map;
  }

  /** Dibuja features en un mapa Leaflet. Devuelve { layers, colorMap } */
  function drawFeatures(targetMap, features, colorMap) {
    const layers = [];

    features.forEach(feature => {
      const color = colorMap[feature.properties.code] || '#3388ff';
      const tramo = feature.properties.tramo
        ? feature.properties.tramo
        : feature.properties.name;

      // Contorno blanco
      L.geoJSON(feature, {
        style: {
          color: '#ffffff',
          weight: 8,
          opacity: 0.7,
          lineCap: 'round',
          lineJoin: 'round'
        }
      }).addTo(targetMap);

      // Capa coloreada
      const layer = L.geoJSON(feature, {
        style: {
          color: color,
          weight: 4,
          opacity: 0.95,
          lineCap: 'round',
          lineJoin: 'round'
        }
      }).addTo(targetMap);

      // Popup
      const pTitle = feature.properties.type_display
        ? `${feature.properties.type_display}: ${feature.properties.name}`
        : feature.properties.name;
      let popupHtml = `<strong>${pTitle}</strong>`;
      if (feature.properties.short_description) {
        popupHtml += `<br><em>${feature.properties.short_description}</em>`;
      }
      if (feature.properties.state) {
        popupHtml += `<br>Estado: ${feature.properties.state}`;
      }
      if (feature.properties.url) {
        popupHtml += `<br><a href="${feature.properties.url}">Ver proyecto →</a>`;
      }
      layer.bindPopup(popupHtml);

      layers.push(layer);
    });

    return layers;
  }

  /** Crea la leyenda Leaflet */
  function addLegend(map, colorMap) {
    const legend = L.control({ position: 'bottomleft' });
    legend.onAdd = function () {
      const div = L.DomUtil.create('div', 'viable-map-legend');
      const entries = Object.entries(colorMap);
      if (entries.length === 0) return div;

      let html = '<strong>Proyectos</strong>';
      entries.forEach(([code, color]) => {
        const label = legend._labelMap ? (legend._labelMap[code] || code) : code;
        html += `<div class="legend-item"><span class="legend-swatch" style="background:${color}"></span> ${label}</div>`;
      });
      div.innerHTML = html;
      return div;
    };
    return legend;
  }

  function buildLabelMapFull(features) {
    const map = {};
    features.forEach(f => {
      if (map[f.properties.code]) return;
      const p = f.properties;
      map[p.code] = p.type_display ? `${p.type_display}: ${p.name}` : p.name;
    });
    return map;
  }

  /** Construye la URL de la API con los filtros actuales */
  function buildApiUrl(baseUrl, params) {
    const url = new URL(baseUrl, window.location.origin);
    Object.entries(params).forEach(([k, v]) => {
      if (v) url.searchParams.set(k, v);
    });
    return url.toString();
  }

  /* ── Expand modal (reutilizable) ───────────────────────────── */
  function addExpandButton(container, features, colorMap, filterOptions, baseParams, restUrl) {
    const btn = document.createElement('button');
    btn.className = 'viable-map-expand-btn';
    btn.title = 'Ampliar mapa';
    btn.innerHTML = '&#x26F6;';
    container.appendChild(btn);

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openModal(features, colorMap, filterOptions, baseParams, restUrl);
    });
  }

  function openModal(features, colorMap, filterOptions, baseParams, restUrl) {
    const overlay = document.createElement('div');
    overlay.className = 'viable-map-modal-overlay';

    const modal = document.createElement('div');
    modal.className = 'viable-map-modal';

    const closeBtn = document.createElement('button');
    closeBtn.className = 'viable-map-modal-close';
    closeBtn.innerHTML = '&times;';
    closeBtn.title = 'Cerrar';

    const mapDiv = document.createElement('div');
    mapDiv.className = 'viable-map-modal-map';

    modal.appendChild(closeBtn);
    modal.appendChild(mapDiv);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    const bigMap = L.map(mapDiv).setView([-34.6, -58.4], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '\u00a9 OpenStreetMap contributors'
    }).addTo(bigMap);

    let modalFeatures = features;
    let modalColorMap = colorMap;
    let modalLayers = [];
    let modalLegend = null;

    function renderModalFeatures(feat, cmap) {
      modalLayers.forEach(l => bigMap.removeLayer(l));
      if (modalLegend) { bigMap.removeControl(modalLegend); modalLegend = null; }
      modalLayers = drawFeatures(bigMap, feat, cmap);
      const lc = addLegend(bigMap, cmap);
      lc._labelMap = buildLabelMap(feat);
      lc.addTo(bigMap);
      modalLegend = lc;
      if (modalLayers.length) {
        bigMap.fitBounds(L.featureGroup(modalLayers).getBounds(), { padding: [30, 30] });
      }
    }

    renderModalFeatures(modalFeatures, modalColorMap);

    // Filtros en el modal (si los hay)
    if (filterOptions && Object.keys(filterOptions).length && restUrl) {
      const modalFiltersWrap = document.createElement('div');
      modalFiltersWrap.className = 'viable-map-modal-filters';
      modal.insertBefore(modalFiltersWrap, mapDiv);

      createFiltersPanel(modalFiltersWrap, filterOptions, async (filters) => {
        const merged = { ...baseParams };
        Object.entries(filters).forEach(([k, v]) => { if (v) merged[k] = v; });
        const url = buildApiUrl(restUrl, merged);
        try {
          const resp = await fetch(url);
          if (!resp.ok) return;
          const gj = await resp.json();
          const feat = gj.features || [];
          const cmap = buildColorMap(feat);
          renderModalFeatures(feat, cmap);
        } catch(e) { console.error(e); }
      });
    }

    const closeModal = () => {
      bigMap.remove();
      document.body.removeChild(overlay);
    };
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', (ev) => { if (ev.target === overlay) closeModal(); });
    document.addEventListener('keydown', function onKey(ev) {
      if (ev.key === 'Escape') { closeModal(); document.removeEventListener('keydown', onKey); }
    });
  }

  function buildLabelMap(features) {
    return buildLabelMapFull(features);
  }

  /* ── Multi-select dropdown ──────────────────────────────────── */
  function makeMultiSelect(labelText, name, items, valueFn, textFn, onChange) {
    const wrap = document.createElement('div');
    wrap.className = 'filter-group';
    wrap.dataset.filterName = name;

    const lbl = document.createElement('div');
    lbl.className = 'filter-group-label';
    lbl.textContent = labelText;
    wrap.appendChild(lbl);

    const trigger = document.createElement('div');
    trigger.className = 'multiselect-trigger';
    trigger.setAttribute('tabindex', '0');
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-expanded', 'false');

    const triggerText = document.createElement('span');
    triggerText.className = 'multiselect-text';
    triggerText.textContent = 'Todas';
    const triggerArrow = document.createElement('span');
    triggerArrow.className = 'multiselect-arrow';
    triggerArrow.innerHTML = '&#9662;';
    trigger.appendChild(triggerText);
    trigger.appendChild(triggerArrow);
    wrap.appendChild(trigger);

    const dropdown = document.createElement('div');
    dropdown.className = 'multiselect-dropdown';

    const search = document.createElement('input');
    search.type = 'text';
    search.className = 'multiselect-search';
    search.placeholder = 'Buscar...';
    dropdown.appendChild(search);

    const optionsWrap = document.createElement('div');
    optionsWrap.className = 'multiselect-options';
    dropdown.appendChild(optionsWrap);

    wrap.appendChild(dropdown);

    function normalizeText(v) {
      return String(v || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
    }

    function makeOption(text, value) {
      const optLbl = document.createElement('label');
      optLbl.className = 'multiselect-option';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = value;
      const txt = document.createElement('span');
      txt.className = 'multiselect-option-text';
      txt.textContent = text;
      optLbl.appendChild(cb);
      optLbl.appendChild(txt);
      optionsWrap.appendChild(optLbl);
      return cb;
    }

    const allCb = makeOption('(Todas)', '__all__');
    allCb.checked = true;
    const itemCbs = items.map(item => makeOption(textFn(item), String(valueFn(item))));

    function getValues() {
      if (allCb.checked) return '';
      return itemCbs.filter(cb => cb.checked).map(cb => cb.value).join(',');
    }

    function updateTrigger() {
      const checked = itemCbs.filter(cb => cb.checked);
      if (allCb.checked || checked.length === 0) {
        if (!allCb.checked) allCb.checked = true;
        triggerText.textContent = 'Todas';
      } else if (checked.length === 1) {
        triggerText.textContent = checked[0].closest('label').textContent.trim();
      } else {
        triggerText.textContent = checked.length + ' seleccionadas';
      }
    }

    allCb.addEventListener('change', () => {
      if (allCb.checked) itemCbs.forEach(cb => { cb.checked = false; });
      updateTrigger();
      onChange(getValues());
    });
    itemCbs.forEach(cb => {
      cb.addEventListener('change', () => {
        if (cb.checked) allCb.checked = false;
        updateTrigger();
        onChange(getValues());
      });
    });

    search.addEventListener('input', () => {
      const q = normalizeText(search.value.trim());
      itemCbs.forEach(cb => {
        const labelTextContent = normalizeText(cb.closest('label').textContent);
        cb.closest('label').style.display = !q || labelTextContent.includes(q) ? '' : 'none';
      });
      allCb.closest('label').style.display = !q ? '' : 'none';
    });

    let open = false;
    function closeDropdown() {
      open = false;
      dropdown.classList.remove('multiselect-open');
      trigger.setAttribute('aria-expanded', 'false');
      search.value = '';
      itemCbs.forEach(cb => { cb.closest('label').style.display = ''; });
      allCb.closest('label').style.display = '';
    }
    trigger.addEventListener('click', e => {
      e.stopPropagation();
      open = !open;
      dropdown.classList.toggle('multiselect-open', open);
      trigger.setAttribute('aria-expanded', String(open));
      if (open) search.focus();
    });
    trigger.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); trigger.click(); }
      if (e.key === 'Escape') closeDropdown();
    });
    document.addEventListener('click', e => {
      if (!wrap.contains(e.target)) closeDropdown();
    });

    return wrap;
  }

  /* ── Filters panel ──────────────────────────────────────────── */
  function createFiltersPanel(parentContainer, options, onApply) {
    const panel = document.createElement('div');
    panel.className = 'viable-map-filters';
    const current = { category: '', type: '', state: '' };
    function apply() { onApply({ ...current }); }

    if (options.categories && options.categories.length) {
      panel.appendChild(makeMultiSelect('Región', 'category', options.categories,
        c => c.id, c => c.name, v => { current.category = v; apply(); }));
    }
    if (options.types && options.types.length) {
      panel.appendChild(makeMultiSelect('Tipo de obra', 'type', options.types,
        t => t, t => t, v => { current.type = v; apply(); }));
    }
    if (options.states && options.states.length) {
      panel.appendChild(makeMultiSelect('Estado', 'state', options.states,
        s => s, s => s, v => { current.state = v; apply(); }));
    }

    parentContainer.appendChild(panel);
    return panel;
  }

  /* ── Inicializar cada instancia ─────────────────────────────── */

  function initMapInstance(el) {
    const restUrl     = el.dataset.restUrl;
    const showLegend  = el.dataset.legend !== 'false';
    const filtersVal  = el.dataset.filters || '';
    const showFilters = filtersVal !== '' && filtersVal !== 'false';
    const showExpand  = el.dataset.expand !== 'false';
    const showList    = el.dataset.list === 'true';

    let filterOptions = {};
    if (showFilters) {
      try { filterOptions = JSON.parse(el.dataset.filterOptions || '{}'); } catch(e) {}
    }

    // Parámetros iniciales (del shortcode)
    const baseParams = {
      codes:    el.dataset.codes    || '',
      category: el.dataset.category || '',
      type:     el.dataset.type     || '',
      state:    el.dataset.state    || ''
    };

    // Contenedor para el listado (si se solicitó)
    let listContainer = null;
    if (showList) {
      listContainer = document.createElement('div');
      listContainer.className = 'viable-project-list';
      el.parentNode.insertBefore(listContainer, el.nextSibling);
    }

    // Crear mapa Leaflet
    const map = L.map(el).setView([-34.6, -58.4], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '\u00a9 OpenStreetMap contributors'
    }).addTo(map);

    // Estado mutable
    let currentLayers = [];
    let currentLegend = null;
    let currentFeatures = [];
    let currentColorMap = {};

    /** Carga (o recarga) los datos según params */
    async function loadData(params) {
      // Limpiar capas previas
      currentLayers.forEach(l => map.removeLayer(l));
      currentLayers = [];
      if (currentLegend) { map.removeControl(currentLegend); currentLegend = null; }

      const url = buildApiUrl(restUrl, params);

      try {
        const resp = await fetch(url);
        if (!resp.ok) {
          el.classList.add('viable-map-empty');
          return;
        }
        el.classList.remove('viable-map-empty');

        const geojson = await resp.json();
        const features = geojson.features || [];
        currentFeatures = features;

        if (features.length === 0) {
          el.classList.add('viable-map-empty');
          if (showList && listContainer) listContainer.innerHTML = '';
          return;
        }

        const colorMap = buildColorMap(features);
        currentColorMap = colorMap;

        const layers = drawFeatures(map, features, colorMap);
        currentLayers = layers;

        // Ajustar bounds
        if (layers.length) {
          const group = L.featureGroup(layers);
          map.fitBounds(group.getBounds(), { padding: [20, 20] });
        }

        // Leyenda
        if (showLegend) {
          const legendCtrl = addLegend(map, colorMap);
          legendCtrl._labelMap = buildLabelMap(features);
          legendCtrl.addTo(map);
          currentLegend = legendCtrl;
        }

        // Botón expandir: siempre remplazar para actualizar las features actuales
        if (showExpand) {
          const existingBtn = el.querySelector('.viable-map-expand-btn');
          if (existingBtn) existingBtn.remove();
          addExpandButton(el, currentFeatures, currentColorMap, filterOptions, baseParams, restUrl);
        }

        // Listado de proyectos
        if (showList && listContainer) {
          renderProjectList(listContainer, currentFeatures);
        }

      } catch (err) {
        console.error('Viable Map Universal Error:', err);
      }
    }

    // Filtros interactivos
    if (showFilters) {
      const filtersWrap = document.createElement('div');
      el.parentNode.insertBefore(filtersWrap, el);
      createFiltersPanel(filtersWrap, filterOptions, (filters) => {
        const merged = { ...baseParams };
        Object.entries(filters).forEach(([k, v]) => { if (v) merged[k] = v; });
        loadData(merged);
      });
    }

    // Carga inicial
    loadData(baseParams);
  }

  /* ── Boot ───────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.viable-map-universal').forEach(initMapInstance);
  });

})();
