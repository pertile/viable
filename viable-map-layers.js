/**
 * viable-map-layers.js
 *
 * Capas comunes para todos los mapas de Viable:
 *   - Fondos intercambiables: Mapa (OpenStreetMap) y Satélite (Esri World Imagery)
 *   - Planos georreferenciados por proyecto, leídos de archivos .pmtiles
 *   - Control de opacidad para los planos
 *
 * Uso:
 *   const layers = ViableMapLayers.setup(map);
 *   layers.setOverlays(overlays);   // array que devuelve la API (o [] para quitar)
 *
 * Requiere Leaflet y la librería pmtiles (global `pmtiles`).
 */
(function () {
  'use strict';

  const MAX_ZOOM = 20;
  const PREF_KEY = 'viable-map-base';
  const PLANOS_PANE = 'viablePlanos';
  const MIME_BY_TILE_TYPE = { 2: 'image/png', 3: 'image/jpeg', 4: 'image/webp', 5: 'image/avif' };

  const ESRI_IMAGERY = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
  const ESRI_LABELS = 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}';

  /* ── Preferencia de fondo (por visitante) ─────────────────────── */
  function readPref() {
    try { return window.localStorage.getItem(PREF_KEY); } catch (e) { return null; }
  }
  function writePref(value) {
    try { window.localStorage.setItem(PREF_KEY, value); } catch (e) { /* sin almacenamiento */ }
  }

  /* ── Fondos ───────────────────────────────────────────────────── */
  function getConfig() {
    return window.ViableMapConfig || {};
  }

  /** Estilo de Mapbox Studio como teselas raster (Static Tiles API), sin mapbox-gl. */
  function createMapboxLayer(mb) {
    const url = 'https://api.mapbox.com/styles/v1/' + encodeURIComponent(mb.user) + '/' + encodeURIComponent(mb.style) +
      '/tiles/512/{z}/{x}/{y}' + (L.Browser.retina ? '@2x' : '') + '?access_token=' + encodeURIComponent(mb.token);
    return L.tileLayer(url, {
      tileSize: 512,
      zoomOffset: -1,
      maxZoom: MAX_ZOOM,
      attribution: '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> ' +
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    });
  }

  function createBaseLayers() {
    const common = { maxZoom: MAX_ZOOM, maxNativeZoom: 19 };
    const layers = {};
    const mb = getConfig().mapbox;
    if (mb && mb.token && mb.user && mb.style) {
      layers[mb.label || 'Mapbox'] = createMapboxLayer(mb);
    }
    return Object.assign(layers, {
      'Mapa': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', Object.assign({}, common, {
        attribution: '&copy; colaboradores de <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
      })),
      'Satélite': L.layerGroup([
        L.tileLayer(ESRI_IMAGERY, Object.assign({}, common, {
          attribution: 'Imágenes &copy; Esri, Maxar, Earthstar Geographics y colaboradores'
        })),
        L.tileLayer(ESRI_LABELS, common)
      ])
    });
  }

  /* ── Planos desde PMTiles ─────────────────────────────────────── */
  const archives = new Map();
  function getArchive(url) {
    if (!archives.has(url)) archives.set(url, new pmtiles.PMTiles(url));
    return archives.get(url);
  }

  const PlanosLayer = L.GridLayer.extend({
    createTile: function (coords, done) {
      const tile = document.createElement('img');
      tile.alt = '';
      tile.setAttribute('role', 'presentation');

      const controller = new AbortController();
      tile._viableAbort = controller;
      const mime = MIME_BY_TILE_TYPE[this.options.tileType] || 'image/png';

      getArchive(this.options.url)
        .getZxy(coords.z, coords.x, coords.y, controller.signal)
        .then(function (resp) {
          if (!resp || !resp.data) {
            // No hay tesela en esa posición: dejarla vacía
            tile.style.visibility = 'hidden';
            done(null, tile);
            return;
          }
          const src = URL.createObjectURL(new Blob([resp.data], { type: mime }));
          tile.onload = function () { URL.revokeObjectURL(src); done(null, tile); };
          tile.onerror = function () { URL.revokeObjectURL(src); done(new Error('Tesela inválida'), tile); };
          tile.src = src;
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          done(err, tile);
        });

      return tile;
    },

    // Cancelar descargas de teselas que salen de pantalla
    _removeTile: function (key) {
      const t = this._tiles[key];
      if (t && t.el && t.el._viableAbort) t.el._viableAbort.abort();
      L.GridLayer.prototype._removeTile.call(this, key);
    }
  });

  function createPlanosLayer(overlay) {
    return new PlanosLayer({
      url: overlay.url,
      tileType: overlay.tile_type,
      pane: PLANOS_PANE,
      bounds: L.latLngBounds(overlay.bounds),
      minZoom: overlay.min_zoom,
      minNativeZoom: overlay.min_zoom,
      maxNativeZoom: overlay.max_zoom,
      maxZoom: MAX_ZOOM,
      opacity: typeof overlay.opacity === 'number' ? overlay.opacity : 0.6,
      attribution: overlay.attribution || ''
    });
  }

  /* ── Control de opacidad ──────────────────────────────────────── */
  function createOpacityControl(onChange) {
    const ctrl = L.control({ position: 'bottomright' });
    ctrl.onAdd = function () {
      const div = L.DomUtil.create('div', 'viable-planos-opacity');
      const label = document.createElement('label');
      const text = document.createElement('span');
      text.textContent = 'Planos';
      const input = document.createElement('input');
      input.type = 'range';
      input.min = '0';
      input.max = '100';
      input.step = '5';
      input.setAttribute('aria-label', 'Opacidad de los planos');
      const value = document.createElement('span');
      value.className = 'viable-planos-opacity-value';
      label.appendChild(text);
      label.appendChild(input);
      label.appendChild(value);
      div.appendChild(label);

      input.addEventListener('input', function () {
        value.textContent = input.value + '%';
        onChange(input.value / 100);
      });

      ctrl.setValue = function (v) {
        input.value = String(Math.round(v * 100));
        value.textContent = input.value + '%';
      };

      L.DomEvent.disableClickPropagation(div);
      L.DomEvent.disableScrollPropagation(div);
      return div;
    };
    return ctrl;
  }

  /* ── API pública ──────────────────────────────────────────────── */
  function setup(map, options) {
    options = options || {};

    if (!map.getPane(PLANOS_PANE)) {
      const pane = map.createPane(PLANOS_PANE);
      pane.style.zIndex = 250; // sobre los fondos (200), debajo de las líneas (400)
      pane.style.pointerEvents = 'none';
    }

    const bases = createBaseLayers();
    const pref = readPref();
    // Orden: elección del visitante > pedido del mapa (p. ej. planos → Satélite) > ajuste del sitio > Mapa
    const candidates = [pref, options.defaultBase, getConfig().defaultBase, 'Mapa'];
    const initial = candidates.find(function (name) { return name && bases[name]; });
    bases[initial].addTo(map);

    const control = L.control.layers(bases, {}, { position: 'topleft', collapsed: true }).addTo(map);
    map.on('baselayerchange', function (e) { writePref(e.name); });

    let planos = [];
    let userOpacity = null;
    let opacityShown = false;

    const opacityCtrl = createOpacityControl(function (v) {
      userOpacity = v;
      planos.forEach(function (l) { l.setOpacity(v); });
    });

    function refreshOpacityControl() {
      const zoom = map.getZoom();
      const visible = planos.filter(function (l) {
        return map.hasLayer(l) && zoom >= l.options.minZoom;
      });
      if (visible.length && !opacityShown) {
        opacityCtrl.addTo(map);
        opacityShown = true;
      } else if (!visible.length && opacityShown) {
        map.removeControl(opacityCtrl);
        opacityShown = false;
      }
      if (opacityShown) {
        opacityCtrl.setValue(userOpacity !== null ? userOpacity : visible[0].options.opacity);
      }
    }

    map.on('zoomend overlayadd overlayremove', refreshOpacityControl);

    function setOverlays(overlays) {
      planos.forEach(function (l) {
        control.removeLayer(l);
        map.removeLayer(l);
      });
      planos = [];

      if (typeof pmtiles === 'undefined') {
        if (overlays && overlays.length) console.warn('Viable Map: no se cargó la librería pmtiles; se omiten los planos.');
        refreshOpacityControl();
        return;
      }

      (overlays || []).forEach(function (ov) {
        if (!ov || !ov.url || !ov.bounds) return;
        const layer = createPlanosLayer(ov);
        if (userOpacity !== null) layer.setOpacity(userOpacity);
        control.addOverlay(layer, 'Planos: ' + (ov.code || ov.name || ''));
        layer.addTo(map);
        planos.push(layer);
      });

      refreshOpacityControl();
    }

    return { control: control, setOverlays: setOverlays };
  }

  /* ── Estado de tramos (ejecutado / en ejecución / pendiente) ───── */
  const SEGMENT_LABELS = { done: 'Ejecutado', active: 'En ejecución', pending: 'Pendiente' };
  const SEGMENT_ACTIVE_COLOR = '#ff6d00';
  const SEGMENT_PENDING_COLOR = '#7f8c8d';

  /**
   * Códigos de obra cuyos tramos están en estados distintos. Solo esas obras se
   * dibujan por tramo; si todo está en el mismo estado, el mapa se ve como siempre.
   */
  function mixedSegmentCodes(features, fallbackCode) {
    const byCode = {};
    (features || []).forEach(function (f) {
      const p = f.properties || {};
      if (!p.segment) return;
      const code = p.code || fallbackCode || '';
      (byCode[code] = byCode[code] || new Set()).add(p.segment);
    });
    return new Set(Object.keys(byCode).filter(function (c) { return byCode[c].size > 1; }));
  }

  /** Estilo de la línea de un tramo (se mezcla sobre el estilo normal). */
  function segmentStyle(status, baseColor) {
    if (status === 'active') return { color: SEGMENT_ACTIVE_COLOR, weight: 6, opacity: 1, dashArray: null };
    if (status === 'pending') return { color: SEGMENT_PENDING_COLOR, weight: 3, opacity: 0.85, dashArray: '4 8' };
    return { color: baseColor };
  }

  function segmentLabel(status) {
    return SEGMENT_LABELS[status] || '';
  }

  /** Leyenda de estados de tramo. */
  function createSegmentLegend(baseColor, position) {
    const ctrl = L.control({ position: position || 'bottomleft' });
    ctrl.onAdd = function () {
      const div = L.DomUtil.create('div', 'viable-segment-legend');
      [['done', baseColor, ''], ['active', SEGMENT_ACTIVE_COLOR, ''], ['pending', SEGMENT_PENDING_COLOR, '4 4']].forEach(function (item) {
        const row = document.createElement('div');
        row.className = 'viable-segment-legend-row';
        row.innerHTML = '<svg width="26" height="10" aria-hidden="true"><line x1="1" y1="5" x2="25" y2="5" stroke="' + item[1] +
          '" stroke-width="' + (item[0] === 'active' ? 5 : 3) + '" stroke-dasharray="' + item[2] + '" stroke-linecap="round"/></svg>';
        const text = document.createElement('span');
        text.textContent = SEGMENT_LABELS[item[0]];
        row.appendChild(text);
        div.appendChild(row);
      });
      return div;
    };
    return ctrl;
  }

  /** Lee el atributo data-overlays (JSON) de un contenedor */
  function parseOverlays(el) {
    try {
      const data = JSON.parse(el.dataset.overlays || '[]');
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  window.ViableMapLayers = {
    setup: setup,
    parseOverlays: parseOverlays,
    mixedSegmentCodes: mixedSegmentCodes,
    segmentStyle: segmentStyle,
    segmentLabel: segmentLabel,
    createSegmentLegend: createSegmentLegend,
    MAX_ZOOM: MAX_ZOOM
  };
})();
