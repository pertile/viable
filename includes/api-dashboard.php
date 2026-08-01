<?php

add_action('rest_api_init', 'viable_register_dashboard_endpoints');

function viable_register_dashboard_endpoints() {
    register_rest_route('viable/v1', '/regions', [
        'methods' => 'GET',
        'callback' => 'viable_get_regions_for_filter',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('viable/v1', '/projects-dashboard', [
        'methods' => 'GET',
        'callback' => 'viable_get_projects_dashboard',
        'permission_callback' => '__return_true',
        'args' => [
            'states' => ['required' => false, 'type' => 'string'],
            'region' => ['required' => false, 'type' => 'string'],
            'code' => ['required' => false, 'type' => 'string'],
        ],
    ]);

    register_rest_route('viable/v1', '/projects-bulk-verify', [
        'methods' => 'POST',
        'callback' => 'viable_bulk_verify_projects',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ]);
}

function viable_get_regions_for_filter() {
    $hierarchy = viable_get_region_categories_hierarchy();

    $out = [];
    foreach ($hierarchy as $item) {
        $out[] = [
            'id' => $item['term']->term_id,
            'name' => $item['term']->name,
            'depth' => $item['depth'],
        ];
    }

    return rest_ensure_response($out);
}

/**
 * Devuelve el evento más reciente (por event_date) de un proyecto, o null si no tiene.
 */
function viable_get_latest_project_event($project_id) {
    $event_ids = get_posts([
        'post_type' => 'project_event',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => 'project',
        'meta_value' => (int) $project_id,
    ]);

    $latest = null;
    foreach ($event_ids as $event_id) {
        $date = viable_parse_date_dmy(get_field('event_date', $event_id));
        if (!$date) {
            continue;
        }
        if (!$latest || $date > $latest['date']) {
            $latest = [
                'date' => $date,
                'description' => get_field('description', $event_id),
                'summary' => get_field('summary', $event_id),
                'url' => get_field('url', $event_id),
            ];
        }
    }

    return $latest;
}

/**
 * Listado de proyectos para el dashboard del cliente local, con filtros por
 * estado (lista separada por comas) y región (id de categoría), incluyendo
 * el último evento de cada proyecto.
 */
/**
 * Normaliza el valor del campo ACF "regions" (mezcla de WP_Term, arrays o ids
 * según el contexto) a una lista simple de term_id enteros.
 */
function viable_normalize_region_ids($regions) {
    $region_ids = [];
    foreach ((array) $regions as $r) {
        if (is_object($r) && isset($r->term_id)) {
            $region_ids[] = (int) $r->term_id;
        } elseif (is_array($r) && isset($r['term_id'])) {
            $region_ids[] = (int) $r['term_id'];
        } elseif (is_numeric($r)) {
            $region_ids[] = (int) $r;
        }
    }
    return $region_ids;
}

function viable_get_projects_dashboard($request) {
    $states_param = $request->get_param('states');
    $region_param = $request->get_param('region');
    $code_param = trim((string) $request->get_param('code'));

    $args = [
        'post_type' => 'project',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'meta_value',
        'meta_key' => 'code',
        'order' => 'ASC',
        'fields' => 'ids',
    ];

    $meta_query = [];

    if ($states_param) {
        $states = array_values(array_filter(array_map('trim', explode(',', $states_param))));
        if ($states) {
            $meta_query[] = [
                'key' => 'state',
                'value' => $states,
                'compare' => 'IN',
            ];
        }
    }

    if ($code_param !== '') {
        $meta_query[] = [
            'key' => 'code',
            'value' => $code_param,
            'compare' => 'LIKE',
        ];
    }

    if (count($meta_query) > 1) {
        $meta_query['relation'] = 'AND';
    }
    if ($meta_query) {
        $args['meta_query'] = $meta_query;
    }

    $project_ids = get_posts($args);
    $region_id = $region_param ? (int) $region_param : 0;

    $results = [];
    foreach ($project_ids as $id) {
        $regions = get_field('regions', $id);
        $region_ids = viable_normalize_region_ids($regions);

        if ($region_id && !in_array($region_id, $region_ids, true)) {
            continue;
        }

        $region_names = [];
        foreach ($region_ids as $rid) {
            $term = get_term($rid);
            if ($term && !is_wp_error($term)) {
                $region_names[] = $term->name;
            }
        }

        $latest_event = viable_get_latest_project_event($id);

        $results[] = [
            'id' => $id,
            'code' => get_field('code', $id),
            'title' => get_the_title($id),
            'state' => get_field('state', $id),
            'progress' => get_field('progress', $id),
            'regions' => $region_names,
            'last_verified_on' => get_field('last_verified_on', $id) ?: null,
            'last_event' => $latest_event ? [
                'date' => $latest_event['date']->format('d/m/Y'),
                'description' => $latest_event['description'],
                'summary' => $latest_event['summary'],
                'url' => $latest_event['url'],
            ] : null,
        ];
    }

    return rest_ensure_response($results);
}

/**
 * Actualiza en lote el campo "última constatación" de varios proyectos.
 * Body: { "project_ids": [1,2,3], "date": "2026-07-31" }
 */
function viable_bulk_verify_projects($request) {
    $body = $request->get_json_params();
    $project_ids = (!empty($body['project_ids']) && is_array($body['project_ids']))
        ? array_map('intval', $body['project_ids'])
        : [];

    if (empty($project_ids)) {
        return new WP_Error('viable_no_projects', 'No se indicaron proyectos.', ['status' => 400]);
    }

    $date = !empty($body['date']) ? sanitize_text_field($body['date']) : gmdate('Y-m-d');
    $ts = strtotime($date);
    if ($ts === false) {
        return new WP_Error('viable_invalid_date', 'Fecha inválida.', ['status' => 400]);
    }
    $iso_date = gmdate('Y-m-d', $ts);

    $updated = 0;
    foreach ($project_ids as $id) {
        if (get_post_type($id) !== 'project') {
            continue;
        }
        update_field('last_verified_on', $iso_date, $id);
        $updated++;
    }

    return rest_ensure_response(['updated' => $updated, 'date' => $iso_date]);
}
