<?php

add_action('init', 'viable_register_project_cpt');

function viable_register_project_cpt() {
    register_post_type('project', [
        'labels' => [
            'name' => 'Projects',
            'singular_name' => 'Project',
            'add_new_item' => 'Add new project',
            'edit_item' => 'Edit project',
        ],
        'public' => true,
        'publicly_queryable' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'query_var' => true,
        'menu_icon' => 'dashicons-admin-site-alt3',
        'supports' => ['title', 'editor'],
        'has_archive' => true,
        'rewrite' => ['slug' => 'projects'],
        'show_in_rest' => true,
    ]);
}

// Forzar flush de rewrite rules al activar el plugin
register_activation_hook(VIABLE_PATH . 'viable.php', 'viable_flush_rewrite_rules');

function viable_flush_rewrite_rules() {
    viable_register_project_cpt();
    flush_rewrite_rules();
}

add_action('wp_enqueue_scripts', function () {
    // Solo cargar en posts singulares que tengan proyectos relacionados O en páginas de proyectos
    if (is_singular('post')) {
        $projects = get_field('related_projects');
        if (!$projects) {
            return;
        }
    } elseif (is_singular('project')) {
        $code = get_field('code');
        if (!$code) {
            return;
        }
    } elseif (!is_category()) {
        return;
    }
    
    wp_enqueue_style(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'
    );

    wp_enqueue_script(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        [],
        null,
        true
    );

    wp_enqueue_script(
        'viable-map',
        VIABLE_URL . 'viable-map.js',
        ['leaflet'],
        '1.0',
        true
    );
    
    // Cargar script específico para páginas de categoría
    if (is_category()) {
        wp_enqueue_script(
            'viable-category-map',
            VIABLE_URL . 'viable-category-map.js',
            ['leaflet'],
            '1.0',
            true
        );
    }
});

