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

add_action('wp_enqueue_scripts', function () {
    // Solo cargar en posts singulares que tengan proyectos relacionados
    if (!is_singular('post')) {
        return;
    }
    
    $projects = get_field('related_projects');
    if (!$projects) {
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
});

