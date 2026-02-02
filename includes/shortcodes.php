<?php

// Shortcode para mostrar el infobox del proyecto en cualquier lugar
add_shortcode('viable_project_infobox', 'viable_project_infobox_shortcode');

function viable_project_infobox_shortcode($atts) {
    if (!is_singular('post')) {
        return '';
    }
    
    $projects = get_field('related_projects');
    if (!$projects) {
        return '';
    }
    
    $project = is_array($projects) ? $projects[0] : $projects;
    $pid = $project->ID;
    
    // Obtener todos los datos del proyecto
    $type        = get_field('type', $pid);
    $duplication_type = get_field('duplication_type', $pid);
    $name        = get_the_title($pid);
    $short_desc  = get_field('short_description', $pid);
    $length      = get_field('length', $pid);
    $state       = get_field('state', $pid);
    $progress    = get_field('progress', $pid);
    $progress_updated_on = get_field('progress_updated_on', $pid);
    $end_date    = get_field('end_date', $pid);
    $duration    = get_field('duration', $pid);
    $bid_date    = get_field('bid_date', $pid);
    $award_date  = get_field('award_date', $pid);
    $start_date  = get_field('start_date', $pid);
    $tender_documents = get_field('tender_documents', $pid);
    $code        = get_field('code', $pid);
    $roads       = get_field('roads', $pid);
    $regions     = get_field('regions', $pid);
    $image       = get_field('image', $pid);
    $map         = get_field('map', $pid);
    
    // Función helper para formatear fechas
    $format_date = function($date_str) {
        if (!$date_str) return null;
        $months_es = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
        ];
        $timestamp = strtotime($date_str);
        if ($timestamp) {
            $month_num = (int)date('n', $timestamp);
            $year = date('Y', $timestamp);
            return $months_es[$month_num] . ' ' . $year;
        }
        return $date_str;
    };
    
    // Formatear fechas
    $state_lower = strtolower($state);
    if ($end_date && ($state_lower === 'en construcción' || $state_lower === 'finalizado')) {
        $end_date = $format_date($end_date);
    }
    
    if ($bid_date) $bid_date_formatted = $format_date($bid_date);
    if ($award_date) $award_date_formatted = $format_date($award_date);
    if ($start_date) $start_date_formatted = $format_date($start_date);
    
    // Determinar tipo a mostrar
    if (strtolower($type) === 'duplicación' && $duplication_type) {
        $type_display = $duplication_type;
    } else {
        $type_display = $type;
    }
    
    // Procesar roads
    $roads_text = '';
    if ($roads && is_array($roads)) {
        $road_names = array_map(function($term) {
            if (is_object($term) && isset($term->name)) {
                return $term->name;
            } elseif (is_array($term) && isset($term['name'])) {
                return $term['name'];
            } elseif (is_numeric($term)) {
                $term_obj = get_term($term);
                return $term_obj && !is_wp_error($term_obj) ? $term_obj->name : '';
            }
            return $term;
        }, $roads);
        $roads_text = implode(', ', array_filter($road_names));
    } elseif ($roads) {
        $roads_text = is_numeric($roads) ? get_term($roads)->name : $roads;
    }
    
    // Procesar regions
    $regions_text = '';
    if ($regions && is_array($regions)) {
        $region_names = array_map(function($cat) {
            if (is_object($cat) && isset($cat->name)) {
                return $cat->name;
            } elseif (is_array($cat) && isset($cat['name'])) {
                return $cat['name'];
            } elseif (is_numeric($cat)) {
                $cat_obj = get_category($cat);
                return $cat_obj && !is_wp_error($cat_obj) ? $cat_obj->name : '';
            }
            return $cat;
        }, $regions);
        $regions_text = implode('; ', array_filter($region_names));
    } elseif ($regions) {
        $regions_text = is_numeric($regions) ? get_category($regions)->name : $regions;
    }
    
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
