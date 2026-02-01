document.addEventListener('DOMContentLoaded', async () => {
  const el = document.getElementById('viable-map');
  if (!el) return;

  const projectCode = el.dataset.code;

  if (!projectCode) {
    console.error('Viable Map: Missing project code');
    return;
  }

  // Construir URL del endpoint REST API
  const apiUrl = `${window.location.origin}/wp-json/viable/v1/geojson/${encodeURIComponent(projectCode)}`;

  try {
    // Cargar geojson filtrado desde el servidor
    const response = await fetch(apiUrl);
    
    if (!response.ok) {
      const error = await response.json();
      console.warn('Viable Map:', error.message || 'No data found');
      el.innerHTML = '<p style="padding: 1rem; text-align: center; color: #999;">No hay datos de trazado disponibles</p>';
      return;
    }

    const geojsonData = await response.json();

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
    
    // Dibujar cada feature con un color diferente
    geojsonData.features.forEach((feature, index) => {
      const color = colors[index % colors.length];
      const tramo = feature.properties.tramo || `Tramo ${index + 1}`;
      
      const layer = L.geoJSON(feature, {
        style: {
          color: color,
          weight: 4,
          opacity: 0.8
        }
      }).bindPopup(`<strong>${tramo}</strong>`).addTo(map);
      
      layers.push(layer);
    });

    // Ajustar el zoom para que quepan todos los features
    const group = L.featureGroup(layers);
    map.fitBounds(group.getBounds(), { padding: [20, 20] });

  } catch (error) {
    console.error('Viable Map Error:', error);
    el.innerHTML = '<p style="padding: 1rem; text-align: center; color: #dc3545;">Error al cargar el mapa</p>';
  }
});
