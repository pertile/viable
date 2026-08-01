<?php

/**
 * Permite filtrar la colección de eventos por proyecto:
 * GET /wp-json/wp/v2/project-events?project=<ID>
 */
add_filter('rest_project_event_query', 'viable_filter_project_events_by_project', 10, 2);

function viable_filter_project_events_by_project($args, $request) {
    $project_param = $request->get_param('project');
    if ($project_param) {
        $args['meta_key'] = 'project';
        $args['meta_value'] = (int) $project_param;
    }
    return $args;
}

/**
 * Endpoint liviano de búsqueda de proyectos por código o título,
 * pensado para el autocomplete del cliente local.
 */
add_action('rest_api_init', 'viable_register_projects_lookup_endpoint');

function viable_register_projects_lookup_endpoint() {
    register_rest_route('viable/v1', '/projects-lookup', [
        'methods' => 'GET',
        'callback' => 'viable_projects_lookup',
        'permission_callback' => '__return_true',
        'args' => [
            'q' => ['required' => false, 'type' => 'string'],
        ],
    ]);
}

function viable_projects_lookup($request) {
    $q = trim((string) $request->get_param('q'));

    if ($q === '') {
        $ids = get_posts([
            'post_type' => 'project',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);
    } else {
        $by_title = get_posts([
            'post_type' => 'project',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            's' => $q,
            'fields' => 'ids',
        ]);

        $by_code = get_posts([
            'post_type' => 'project',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'code',
                    'value' => $q,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        $ids = array_slice(array_values(array_unique(array_merge($by_title, $by_code))), 0, 20);
    }

    $results = [];
    foreach ($ids as $id) {
        $results[] = [
            'id' => $id,
            'code' => get_field('code', $id),
            'title' => get_the_title($id),
            'state' => get_field('state', $id),
            'progress' => get_field('progress', $id),
            'end_date' => get_field('end_date', $id),
        ];
    }

    return rest_ensure_response($results);
}

/**
 * CORS acotado a las rutas que usa el cliente local (eventos de proyecto
 * y el namespace propio viable/v1), habilitando únicamente orígenes locales
 * conocidos en vez de abrir la API entera.
 */
if (!defined('VIABLE_LOCAL_APP_ORIGINS')) {
    define('VIABLE_LOCAL_APP_ORIGINS', [
        'http://localhost:5500',
        'http://127.0.0.1:5500',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ]);
}

add_filter('rest_pre_serve_request', 'viable_add_cors_headers_for_events_api', 10, 4);

function viable_add_cors_headers_for_events_api($served, $result, $request, $server) {
    $route = $request->get_route();
    $is_relevant_route = (strpos($route, '/wp/v2/project-events') === 0) || (strpos($route, '/viable/v1/') === 0);

    if (!$is_relevant_route) {
        return $served;
    }

    $origin = get_http_origin();
    if ($origin && in_array($origin, VIABLE_LOCAL_APP_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
        header('Vary: Origin');
    }

    return $served;
}

add_action('rest_api_init', function () {
    add_filter('rest_pre_dispatch', function ($result, $server, $request) {
        if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
            return $result;
        }
        $route = $request->get_route();
        $is_relevant_route = (strpos($route, '/wp/v2/project-events') === 0) || (strpos($route, '/viable/v1/') === 0);
        if (!$is_relevant_route) {
            return $result;
        }
        return new WP_REST_Response(null, 200);
    }, 10, 3);
});
