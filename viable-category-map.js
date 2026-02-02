/**
 * Mapa de proyectos por categoría
 * Muestra todos los proyectos de una categoría en un mapa interactivo
 */

document.addEventListener('DOMContentLoaded', function() {
    const mapContainer = document.getElementById('viable-category-map');
    
    if (!mapContainer) {
        return;
    }
    
    const categoryId = mapContainer.dataset.categoryId;
    
    if (!categoryId) {
        console.error('No category ID provided for map');
        return;
    }
    
    // Inicializar el mapa
    const map = L.map('viable-category-map').setView([-34.6037, -58.3816], 7);
    
    // Agregar capa base de OpenStreetMap
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    
    // Cargar proyectos de la categoría
    fetch(`/wp-json/viable/v1/category-projects/${categoryId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(geojson => {
            if (!geojson.features || geojson.features.length === 0) {
                mapContainer.innerHTML = '<p>No hay proyectos con información geográfica en esta categoría.</p>';
                return;
            }
            
            // Colores por estado
            const stateColors = {
                'proyecto': '#3388ff',
                'en licitación': '#ff7f00',
                'adjudicado': '#ffcc00',
                'en construcción': '#00cc44',
                'finalizado': '#888888',
                'paralizado': '#cc0000'
            };
            
            // Agregar GeoJSON al mapa
            const geoJsonLayer = L.geoJSON(geojson, {
                style: function(feature) {
                    const state = feature.properties.state ? feature.properties.state.toLowerCase() : 'proyecto';
                    return {
                        color: stateColors[state] || '#3388ff',
                        weight: 3,
                        opacity: 0.8
                    };
                },
                onEachFeature: function(feature, layer) {
                    const props = feature.properties;
                    
                    // Crear popup con información del proyecto
                    const popupContent = `
                        <div class="project-popup">
                            <h4>${props.name}</h4>
                            ${props.state ? `<p><strong>Estado:</strong> ${props.state}</p>` : ''}
                            ${props.type ? `<p><strong>Tipo:</strong> ${props.type}</p>` : ''}
                            ${props.tramo ? `<p><strong>Tramo:</strong> ${props.tramo}</p>` : ''}
                            <p><a href="${props.url}" class="button">Ver proyecto →</a></p>
                        </div>
                    `;
                    
                    layer.bindPopup(popupContent);
                    
                    // Click abre popup y permite navegar
                    layer.on('click', function() {
                        layer.openPopup();
                    });
                }
            }).addTo(map);
            
            // Ajustar vista al contenido
            map.fitBounds(geoJsonLayer.getBounds(), {
                padding: [50, 50]
            });
        })
        .catch(error => {
            console.error('Error loading category projects:', error);
            mapContainer.innerHTML = '<p>Error al cargar los proyectos del mapa.</p>';
        });
});
