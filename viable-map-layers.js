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
  function createBaseLayers() {
    const common = { maxZoom: MAX_ZOOM, maxNativeZoom: 19 };
    return {
      'Mapa': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', Object.assign({}, common, {
        attribution: '&copy; colaboradores de <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
      })),
      'Satélite': L.layerGroup([
        L.tileLayer(ESRI_IMAGERY, Object.assign({}, common, {
          attribution: 'Imágenes &copy; Esri, Maxar, Earthstar Geographics y colaboradores'
        })),
        L.tileLayer(ESRI_LABELS, common)
      ])
    };
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
    const initial = bases[pref] ? pref : (options.defaultBase || 'Mapa');
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

  /** Lee el atributo data-overlays (JSON) de un contenedor */
  function parseOverlays(el) {
    try {
      const data = JSON.parse(el.dataset.overlays || '[]');
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  window.ViableMapLayers = { setup: setup, parseOverlays: parseOverlays, MAX_ZOOM: MAX_ZOOM };
})();
