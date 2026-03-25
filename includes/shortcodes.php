<?php

// Shortcode para mostrar el infobox del proyecto en cualquier lugar
add_shortcode('viable_project_infobox', 'viable_project_infobox_shortcode');

function viable_project_infobox_shortcode($atts) {
    if (!is_singular('post')) {
        return '';
    }

    $projects = viable_get_related_projects();
    if (empty($projects)) {
        return '';
    }

    // Solo mostrar infobox si hay un solo proyecto
    if (count($projects) > 1) {
        return '';
    }

    $d = viable_get_project_data($projects[0]->ID);

    // Extraer variables para el template
    $pid          = $d['pid'];
    $name         = $d['name'];
    $type_display = $d['type_display'];
    $short_desc   = $d['short_desc'];
    $length       = $d['length'];
    $state        = $d['state'];
    $state_lower  = $d['state_lower'];
    $progress     = $d['progress'];
    $progress_updated_on = $d['progress_updated_on'];
    $end_date     = $d['end_date'];
    $duration     = $d['duration'];
    $bid_date_formatted   = $d['bid_date_formatted'];
    $award_date_formatted = $d['award_date_formatted'];
    $start_date_formatted = $d['start_date_formatted'];
    $tender_documents = $d['tender_documents'];
    $code         = $d['code'];
    $roads_text   = $d['roads_text'];
    $regions_text = $d['regions_text'];
    $image        = $d['image'];
    $map          = $d['map'];

    ob_start();
    include(VIABLE_PATH . 'includes/project-sheet-template.php');
    return ob_get_clean();
}

// Widget para mostrar el infobox en la sidebar
add_action('widgets_init', 'viable_register_widget');

function viable_register_widget() {
    register_widget('Viable_Project_Widget');
}

class Viable_Project_Widget extends WP_Widget {
    
    public function __construct() {
        parent::__construct(
            'viable_project_widget',
            'Ficha de Proyecto',
            ['description' => 'Muestra la ficha del proyecto relacionado en posts']
        );
    }
    
    public function widget($args, $instance) {
        if (!is_singular('post')) {
            return;
        }
        
        $projects = get_field('related_projects');
        if (!$projects) {
            return;
        }
        
        echo $args['before_widget'];
        echo do_shortcode('[viable_project_infobox]');
        echo $args['after_widget'];
    }
    
    public function form($instance) {
        ?>
        <p>Este widget muestra automáticamente la ficha del proyecto relacionado en posts individuales.</p>
        <?php
    }
}

// ====================================================================
// Shortcode [viable_map] — mapa flexible con filtros y leyenda
// ====================================================================
add_shortcode('viable_map', 'viable_map_shortcode');

/**
 * Uso:
 *   [viable_map]                               → todos los proyectos
 *   [viable_map codes="A1,B2"]                  → proyectos específicos
 *   [viable_map category="5"]                   → por categoría (ID o slug)
 *   [viable_map type="Duplicación"]             → por tipo de obra
 *   [viable_map state="En obras"]               → por estado
 *   [viable_map filters="true"]                 → panel de filtros interactivos
 *   [viable_map legend="false"]                 → sin leyenda
 *   [viable_map height="600px"]                 → altura personalizada
 *   [viable_map expand="false"]                 → sin botón ampliar
 *
 * Atributos combinables: [viable_map category="5" state="En obras" legend="true" filters="true"]
 */
function viable_map_shortcode($atts) {
    $atts = shortcode_atts([
        'codes'    => '',
        'category' => '',
        'type'     => '',
        'state'    => '',
        'legend'   => 'true',
        'filters'  => '',
        'height'   => '500px',
        'expand'   => 'true',
        'list'     => 'false',
    ], $atts, 'viable_map');

    // Normalizar 'true' al conjunto completo de filtros
    $filters_val = $atts['filters'];
    if ($filters_val === 'true' || $filters_val === '1') {
        $filters_val = 'state,type,category';
    }
    // Ignorar 'false' / '0'
    if ($filters_val === 'false' || $filters_val === '0') {
        $filters_val = '';
    }
    $enabled_filters = $filters_val
        ? array_filter(array_map('trim', explode(',', $filters_val)))
        : [];

    // Enqueue Leaflet + JS universal
    wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
    wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], null, true);
    wp_enqueue_script('viable-map-universal', VIABLE_URL . 'viable-map-universal.js', ['leaflet'], '1.1', true);

    // Datos para los filtros (solo los solicitados)
    $filter_data = [];
    if (!empty($enabled_filters)) {
        if (in_array('type', $enabled_filters)) {
            $filter_data['types'] = [
                'Pavimentación', 'Apertura', 'Duplicación', 'Calzada adicional',
                'Ampliación de calzada', 'Puente', 'Rotonda', 'Túnel'
            ];
        }
        if (in_array('state', $enabled_filters)) {
            $filter_data['states'] = [
                'Proyecto', 'En licitación', 'Adjudicado', 'En obras', 'Paralizado', 'Finalizado'
            ];
        }
        if (in_array('category', $enabled_filters)) {
            $categories = get_categories(['hide_empty' => false]);
            $filter_data['categories'] = array_map(function($cat) {
                return ['id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug];
            }, $categories);
        }
    }

    // ID único para múltiples mapas en la misma página
    static $map_counter = 0;
    $map_counter++;
    $map_id = 'viable-map-universal-' . $map_counter;

    ob_start();
    ?>
    <div id="<?= esc_attr($map_id) ?>"
         class="viable-map-universal"
         data-rest-url="<?= esc_url(rest_url('viable/v1/map-projects')) ?>"
         data-codes="<?= esc_attr($atts['codes']) ?>"
         data-category="<?= esc_attr($atts['category']) ?>"
         data-type="<?= esc_attr($atts['type']) ?>"
         data-state="<?= esc_attr($atts['state']) ?>"
         data-legend="<?= esc_attr($atts['legend']) ?>"
         data-filters="<?= esc_attr($filters_val) ?>"
         data-expand="<?= esc_attr($atts['expand']) ?>"
         data-list="<?= esc_attr($atts['list']) ?>"
         <?php if (!empty($filter_data)): ?>
         data-filter-options="<?= esc_attr(wp_json_encode($filter_data)) ?>"
         <?php endif; ?>
         style="height: <?= esc_attr($atts['height']) ?>;">
    </div>
    <?php
    return ob_get_clean();
}
