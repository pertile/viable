document.addEventListener('DOMContentLoaded', () => {
  const mapElements = Array.from(document.querySelectorAll('.viable-map-single'));
  if (!mapElements.length) return;

  mapElements.forEach(async (el) => {

  const projectCode = el.dataset.code;
  const restBase = (el.dataset.restUrl || '').replace(/\/$/, '');
  const siblingCodes = (el.dataset.siblingCodes || '')
    .split(',')
    .map((v) => v.trim())
    .filter(Boolean);

  if (!projectCode) {
    console.error('Viable Map: Missing project code');
    return;
  }

  const uniqueCodes = [...new Set([projectCode, ...siblingCodes])];
  const useUniversalEndpoint = uniqueCodes.length > 1;
  const universalBase = restBase ? restBase.replace(/\/geojson$/, '/map-projects') : `${window.location.origin}/wp-json/viable/v1/map-projects`;

  // Construir URL del endpoint REST API (usa rest_url para soportar subdirectorios)
  const apiUrl = useUniversalEndpoint
    ? `${universalBase}?codes=${encodeURIComponent(uniqueCodes.join(','))}`
    : (restBase
      ? `${restBase}/${encodeURIComponent(projectCode)}`
      : `${window.location.origin}/wp-json/viable/v1/geojson/${encodeURIComponent(projectCode)}`);

  try {
    // Cargar geojson filtrado desde el servidor
    const response = await fetch(apiUrl);
    
    if (!response.ok) {
      const error = await response.json();
      console.warn('Viable Map:', error.message || 'No data found');
      el.innerHTML = '<p style="padding: 1rem; text-align: center; color: #999;">No hay datos de trazado disponibles</p>';
      return;
    }

    const responseData = await response.json();
    const geojsonData = useUniversalEndpoint
      ? { features: responseData.features || [] }
      : responseData;

    if (!geojsonData.features || geojsonData.features.length === 0) {
      console.warn('Viable Map: No features found for code:', projectCode);
      el.innerHTML = '<p style="padding: 1rem; text-align: center; color: #999;">No hay datos de trazado disponibles</p>';
      return;
    }

    // Crear el mapa
    const map = L.map(el).setView([-34.6, -58.4], 10);

    // Agregar capa de tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Colores para diferentes tramos
    const colors = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#34495e'];
    
    const layers = [];

    function drawFeatures(targetMap) {
      const featureLayers = [];
      geojsonData.features.forEach((feature, index) => {
        const code = feature.properties && feature.properties.code ? feature.properties.code : projectCode;
        const isPrimary = code === projectCode;
        const color = isPrimary ? colors[index % colors.length] : '#a6adb4';
        const tramo = feature.properties.tramo || `Tramo ${index + 1}`;

        // Capa de contorno blanco (debajo)
        L.geoJSON(feature, {
          style: {
            color: '#ffffff',
            weight: 8,
            opacity: 0.7,
            lineCap: 'round',
            lineJoin: 'round'
          }
        }).addTo(targetMap);

        // Capa coloreada (encima)
        const layer = L.geoJSON(feature, {
          style: {
            color: color,
            weight: isPrimary ? 4 : 3,
            opacity: isPrimary ? 0.95 : 0.6,
            lineCap: 'round',
            lineJoin: 'round'
          }
        }).bindPopup(`<strong>${tramo}</strong>`).addTo(targetMap);

        featureLayers.push(layer);
      });
      return featureLayers;
    }
    
    // Dibujar en el mapa del infobox
    layers.push(...drawFeatures(map));

    // Ajustar el zoom para que quepan todos los features
    const group = L.featureGroup(layers);
    map.fitBounds(group.getBounds(), { padding: [20, 20] });

    // Botón para expandir el mapa
    const expandBtn = document.createElement('button');
    expandBtn.className = 'viable-map-expand-btn';
    expandBtn.title = 'Ampliar mapa';
    expandBtn.innerHTML = '&#x26F6;'; // ⛶
    el.style.position = 'relative';
    el.appendChild(expandBtn);

    expandBtn.addEventListener('click', (e) => {
      e.stopPropagation();

      // Crear overlay modal
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

      // Inicializar mapa grande
      const bigMap = L.map(mapDiv).setView([-34.6, -58.4], 10);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '\u00a9 OpenStreetMap contributors'
      }).addTo(bigMap);

      const bigLayers = drawFeatures(bigMap);
      const bigGroup = L.featureGroup(bigLayers);
      bigMap.fitBounds(bigGroup.getBounds(), { padding: [30, 30] });

      // Cerrar modal
      const closeModal = () => {
        bigMap.remove();
        document.body.removeChild(overlay);
      };
      closeBtn.addEventListener('click', closeModal);
      overlay.addEventListener('click', (ev) => {
        if (ev.target === overlay) closeModal();
      });
      document.addEventListener('keydown', function onKey(ev) {
        if (ev.key === 'Escape') { closeModal(); document.removeEventListener('keydown', onKey); }
      });
    });

  } catch (error) {
    console.error('Viable Map Error:', error);
    el.innerHTML = '<p style="padding: 1rem; text-align: center; color: #dc3545;">Error al cargar el mapa</p>';
  }
  });
});
