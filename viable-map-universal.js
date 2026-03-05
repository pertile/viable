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
      let popupHtml = `<strong>${feature.properties.name}</strong>`;
      if (feature.properties.tramo) {
        popupHtml += `<br><em>${feature.properties.tramo}</em>`;
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
        // Buscar nombre amigable del último fetch
        const label = legend._labelMap ? (legend._labelMap[code] || code) : code;
        html += `<div class="legend-item"><span class="legend-swatch" style="background:${color}"></span> ${label}</div>`;
      });
      div.innerHTML = html;
      return div;
    };
    return legend;
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
  function addExpandButton(container, features, colorMap) {
    const btn = document.createElement('button');
    btn.className = 'viable-map-expand-btn';
    btn.title = 'Ampliar mapa';
    btn.innerHTML = '&#x26F6;';
    container.appendChild(btn);

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openModal(features, colorMap);
    });
  }

  function openModal(features, colorMap) {
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

    const bigLayers = drawFeatures(bigMap, features, colorMap);

    // Leyenda en el modal
    const legendCtrl = addLegend(bigMap, colorMap);
    legendCtrl._labelMap = buildLabelMap(features);
    legendCtrl.addTo(bigMap);

    if (bigLayers.length) {
      const group = L.featureGroup(bigLayers);
      bigMap.fitBounds(group.getBounds(), { padding: [30, 30] });
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
    const map = {};
    features.forEach(f => { if (!map[f.properties.code]) map[f.properties.code] = f.properties.name; });
    return map;
  }

  /* ── Filters panel ─────────────────────────────────────────── */
  function createFiltersPanel(container, options, onApply) {
    const panel = document.createElement('div');
    panel.className = 'viable-map-filters';

    function makeSelect(label, name, items, valueFn, textFn) {
      const wrap = document.createElement('div');
      wrap.className = 'filter-group';
      const lbl = document.createElement('label');
      lbl.textContent = label;
      const sel = document.createElement('select');
      sel.name = name;
      sel.innerHTML = '<option value="">Todos</option>';
      items.forEach(item => {
        const opt = document.createElement('option');
        opt.value = valueFn(item);
        opt.textContent = textFn(item);
        sel.appendChild(opt);
      });
      wrap.appendChild(lbl);
      wrap.appendChild(sel);
      return wrap;
    }

    if (options.categories && options.categories.length) {
      panel.appendChild(makeSelect('Región', 'category', options.categories, c => c.id, c => c.name));
    }
    if (options.types && options.types.length) {
      panel.appendChild(makeSelect('Tipo', 'type', options.types, t => t, t => t));
    }
    if (options.states && options.states.length) {
      panel.appendChild(makeSelect('Estado', 'state', options.states, s => s, s => s));
    }

    const applyBtn = document.createElement('button');
    applyBtn.className = 'filter-apply';
    applyBtn.textContent = 'Filtrar';
    panel.appendChild(applyBtn);

    applyBtn.addEventListener('click', () => {
      const selects = panel.querySelectorAll('select');
      const filters = {};
      selects.forEach(s => { filters[s.name] = s.value; });
      onApply(filters);
    });

    // También filtrar al cambiar los selects directamente
    panel.querySelectorAll('select').forEach(sel => {
      sel.addEventListener('change', () => {
        const selects = panel.querySelectorAll('select');
        const filters = {};
        selects.forEach(s => { filters[s.name] = s.value; });
        onApply(filters);
      });
    });

    container.parentNode.insertBefore(panel, container);
    return panel;
  }

  /* ── Inicializar cada instancia ─────────────────────────────── */

  function initMapInstance(el) {
    const restUrl   = el.dataset.restUrl;
    const showLegend  = el.dataset.legend !== 'false';
    const showFilters = el.dataset.filters === 'true';
    const showExpand  = el.dataset.expand !== 'false';

    // Parámetros iniciales (del shortcode)
    const baseParams = {
      codes:    el.dataset.codes    || '',
      category: el.dataset.category || '',
      type:     el.dataset.type     || '',
      state:    el.dataset.state    || ''
    };

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
    let expandBtn = null;

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

        // Botón expandir (solo una vez)
        if (showExpand && !expandBtn) {
          expandBtn = true; // flag
          addExpandButton(el, features, colorMap);
        }

        // Actualizar referencia del expandBtn para nuevas features
        if (showExpand && expandBtn) {
          // Reemplazar listener del botón existente
          const existingBtn = el.querySelector('.viable-map-expand-btn');
          if (existingBtn) {
            const newBtn = existingBtn.cloneNode(true);
            existingBtn.parentNode.replaceChild(newBtn, existingBtn);
            newBtn.addEventListener('click', (e) => {
              e.stopPropagation();
              openModal(currentFeatures, currentColorMap);
            });
          }
        }

      } catch (err) {
        console.error('Viable Map Universal Error:', err);
      }
    }

    // Filtros interactivos
    if (showFilters) {
      let filterOptions = {};
      try {
        filterOptions = JSON.parse(el.dataset.filterOptions || '{}');
      } catch (e) { /* ignore */ }

      createFiltersPanel(el, filterOptions, (filters) => {
        // Mezclar con los parámetros base (los del shortcode son defaults)
        const merged = { ...baseParams };
        Object.entries(filters).forEach(([k, v]) => {
          if (v) merged[k] = v; // el filtro del usuario sobreescribe
        });
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
