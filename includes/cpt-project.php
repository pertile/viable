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

add_filter('rest_prepare_project', 'viable_hide_article_completion_flag_from_public_rest', 10, 3);

function viable_hide_article_completion_flag_from_public_rest($response, $post, $request) {
    if (current_user_can('edit_post', $post->ID) || !isset($response->data['acf'])) {
        return $response;
    }

    unset($response->data['acf']['article_completion_pending']);
    return $response;
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
    } else {
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
        file_exists(VIABLE_PATH . 'viable-map.js') ? filemtime(VIABLE_PATH . 'viable-map.js') : null,
        true
    );

});

/**
 * Verifica si un código de proyecto ya está en uso por OTRO proyecto.
 */
function viable_project_code_in_use($code, $exclude_id = 0) {
    $code = trim((string) $code);
    if ($code === '') {
        return false;
    }

    $args = [
        'post_type' => 'project',
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => 'code',
            'value' => $code,
            'compare' => '=',
        ]],
    ];
    if ($exclude_id) {
        $args['post__not_in'] = [(int) $exclude_id];
    }

    return !empty(get_posts($args));
}

function viable_project_state_number($state) {
    $state_numbers = [
        'Proyecto' => 0,
        'En licitación' => 10,
        'Adjudicado' => 20,
        'En obras' => 30,
        'Paralizado' => 40,
        'Finalizado' => 50,
        'Cancelado' => 60,
    ];

    return $state_numbers[(string) $state] ?? null;
}

function viable_project_state_progress_error($state, $progress) {
    if (!is_numeric($progress) || (float) $progress <= 0) {
        return false;
    }

    $state_number = viable_project_state_number($state);
    if ($state_number !== null && $state_number >= 30) {
        return false;
    }

    return new WP_Error(
        'viable_invalid_state_for_progress',
        'Con avance mayor a 0, el estado debe ser En obras, Paralizado, Finalizado o Cancelado.',
        ['status' => 400]
    );
}

function viable_validate_project_state_admin_save() {
    if (!isset($_POST['post_type']) || $_POST['post_type'] !== 'project') {
        return;
    }

    $acf_data = isset($_POST['acf']) ? $_POST['acf'] : [];
    $post_id = (int) ($_POST['post_ID'] ?? 0);
    $state = isset($acf_data['field_697e0812309f5'])
        ? $acf_data['field_697e0812309f5']
        : get_field('state', $post_id);
    $progress = isset($acf_data['field_697e086f309f6'])
        ? $acf_data['field_697e086f309f6']
        : get_field('progress', $post_id);

    $error = viable_project_state_progress_error($state, $progress);
    if ($error) {
        acf_add_validation_error('acf[field_697e0812309f5]', $error->get_error_message());
    }
}

add_action('acf/validate_save_post', 'viable_validate_project_state_admin_save');

/**
 * Validación de código único — wp-admin.
 */
add_action('acf/validate_save_post', 'viable_validate_project_code_admin_save');

function viable_validate_project_code_admin_save() {
    if (!isset($_POST['post_type']) || $_POST['post_type'] !== 'project') {
        return;
    }

    $acf_data = isset($_POST['acf']) ? $_POST['acf'] : [];
    $code = isset($acf_data['field_697e0502309ee']) ? $acf_data['field_697e0502309ee'] : '';
    $post_id = isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0;

    if (viable_project_code_in_use($code, $post_id)) {
        acf_add_validation_error('acf[field_697e0502309ee]', 'Ya existe otro proyecto con este código.');
    }
}

/**
 * Validación de código único — REST API.
 */
add_filter('rest_pre_insert_project', 'viable_validate_project_code_rest_save', 10, 2);
add_filter('rest_pre_insert_project', 'viable_validate_project_state_rest_save', 10, 2);

function viable_validate_project_code_rest_save($prepared_post, $request) {
    $acf = $request->get_param('acf');
    if (!is_array($acf) || !array_key_exists('code', $acf)) {
        return $prepared_post;
    }

    $post_id = isset($prepared_post->ID) ? (int) $prepared_post->ID : 0;

    if (viable_project_code_in_use($acf['code'], $post_id)) {
        return new WP_Error('viable_duplicate_code', 'Ya existe otro proyecto con el código "' . $acf['code'] . '".', ['status' => 400]);
    }

    return $prepared_post;
}

function viable_validate_project_state_rest_save($prepared_post, $request) {
    if (is_wp_error($prepared_post)) {
        return $prepared_post;
    }

    $acf = $request->get_param('acf');
    $post_id = isset($prepared_post->ID) ? (int) $prepared_post->ID : 0;
    $state = is_array($acf) && array_key_exists('state', $acf)
        ? $acf['state']
        : ($post_id ? get_field('state', $post_id) : 'Proyecto');
    $progress = is_array($acf) && array_key_exists('progress', $acf)
        ? $acf['progress']
        : ($post_id ? get_field('progress', $post_id) : 0);

    $error = viable_project_state_progress_error($state, $progress);
    return $error ?: $prepared_post;
}

