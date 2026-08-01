<?php

add_action('init', 'viable_register_project_event_cpt');

function viable_register_project_event_cpt() {
    register_post_type('project_event', [
        'labels' => [
            'name' => 'Eventos',
            'singular_name' => 'Evento',
            'add_new_item' => 'Agregar nuevo evento',
            'edit_item' => 'Editar evento',
            'all_items' => 'Eventos',
        ],
        'public' => false,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'show_ui' => true,
        'show_in_menu' => 'edit.php?post_type=project',
        'query_var' => false,
        'supports' => ['title'],
        'has_archive' => false,
        'rewrite' => false,
        'show_in_rest' => true,
        'rest_base' => 'project-events',
    ]);
}

// Forzar flush de rewrite rules al activar el plugin
register_activation_hook(VIABLE_PATH . 'viable.php', 'viable_flush_project_event_rewrite_rules');

function viable_flush_project_event_rewrite_rules() {
    viable_register_project_event_cpt();
    flush_rewrite_rules();
}

/**
 * Autogenerar el título del evento a partir del proyecto y la fecha, para no
 * obligar a cargarlo a mano desde wp-admin ni desde la API.
 *
 * Se engancha después de que ACF ya guardó los campos: 'save_post_project_event'
 * para wp-admin (ACF ya corrió vía 'save_post' prioridad 10) y
 * 'rest_after_insert_project_event' para altas/ediciones por REST, ya que ahí
 * la integración REST de ACF actualiza los campos aparte y no dispara
 * 'acf/save_post'.
 */
add_action('save_post_project_event', 'viable_autogenerate_project_event_title_admin', 20);

function viable_autogenerate_project_event_title_admin($post_id) {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    viable_autogenerate_project_event_title($post_id);
}

add_action('rest_after_insert_project_event', 'viable_autogenerate_project_event_title_rest', 10);

function viable_autogenerate_project_event_title_rest($post) {
    viable_autogenerate_project_event_title($post->ID);
}

function viable_autogenerate_project_event_title($post_id) {
    if (!is_numeric($post_id) || get_post_type($post_id) !== 'project_event') {
        return;
    }

    $current_title = get_the_title($post_id);
    if ($current_title !== '' && strpos($current_title, 'Auto Draft') === false) {
        return;
    }

    $project = get_field('project', $post_id);
    $project_id = is_object($project) ? $project->ID : (is_numeric($project) ? (int) $project : 0);
    $project_name = $project_id ? get_the_title($project_id) : '';
    $date_part = get_field('event_date', $post_id) ?: date('d/m/Y');

    wp_update_post([
        'ID' => $post_id,
        'post_title' => trim(($project_name ?: 'Proyecto') . ' — ' . $date_part),
    ]);
}

/**
 * Columnas de admin: Proyecto, Fecha, Estado, Avance.
 */
add_filter('manage_project_event_posts_columns', 'viable_project_event_admin_columns');

function viable_project_event_admin_columns($columns) {
    $new_columns = [];
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'title') {
            $new_columns['project_event_project'] = 'Proyecto';
            $new_columns['project_event_date'] = 'Fecha';
            $new_columns['project_event_state'] = 'Estado';
            $new_columns['project_event_progress'] = 'Avance';
        }
    }
    return $new_columns;
}

add_action('manage_project_event_posts_custom_column', 'viable_project_event_admin_column_content', 10, 2);

function viable_project_event_admin_column_content($column, $post_id) {
    switch ($column) {
        case 'project_event_project':
            $project = get_field('project', $post_id);
            $project_id = is_object($project) ? $project->ID : (is_numeric($project) ? (int) $project : 0);
            echo $project_id ? esc_html(get_the_title($project_id)) : '—';
            break;
        case 'project_event_date':
            echo esc_html(get_field('event_date', $post_id) ?: '—');
            break;
        case 'project_event_state':
            echo esc_html(get_field('state', $post_id) ?: '—');
            break;
        case 'project_event_progress':
            $progress = get_field('progress', $post_id);
            echo ($progress === '' || $progress === null) ? '—' : esc_html($progress) . '%';
            break;
    }
}
