<?php
/**
 * Export GeoJSON: trazados del GeoPackage + datos de las fichas publicadas.
 *
 *   GET /wp-json/viable/v1/export.geojson
 *
 * Pensado para herramientas externas que antes leían un GeoJSON propio (por
 * ejemplo el Pavimentómetro de georesistencia). Por eso, además de nombres
 * propios del plugin, incluye las propiedades con los nombres de siempre:
 * title, Obra, TipoObra, FechaPavimento, start, length, municipality,
 * Financista, short_description, link_finished, link_progress, surface.
 *
 * Reglas:
 *   - Una feature por geometría del GeoPackage cuyo código tenga ficha publicada.
 *   - length (metros): la longitud de la ficha va en la primera geometría de cada
 *     obra y 0 en las demás, para que sumar por obra dé el total. Si la ficha no
 *     tiene longitud, cada geometría lleva su largo calculado sobre el trazado.
 *   - FechaPavimento solo en obras Finalizadas (en las demás la fecha es prevista).
 *   - link_finished / link_progress: la nota más reciente vinculada a la obra,
 *     o la ficha si no hay notas.
 *
 * La respuesta se guarda en caché hasta que cambie el GeoPackage o cualquier entrada.
 */

add_action('rest_api_init', function () {
    register_rest_route('viable/v1', '/export\.geojson', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $response = rest_ensure_response(viable_build_export_geojson());
            $response->header('Cache-Control', 'public, max-age=300');
            $response->header('Access-Control-Allow-Origin', '*');
            return $response;
        },
    ]);
});

/** 'Ymd' (formato interno de ACF) → 'YYYY-MM-DDT00:00:00Z' */
function viable_export_iso_date($ymd) {
    $ymd = preg_replace('/\D/', '', (string) $ymd);
    return strlen($ymd) === 8
        ? substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2) . 'T00:00:00Z'
        : null;
}

function viable_build_export_geojson() {
    $empty = ['type' => 'FeatureCollection', 'features' => []];
    $path = viable_get_gpkg_path();
    if (!file_exists($path)) {
        return $empty;
    }

    // Una sola entrada de caché; se invalida si cambia el GeoPackage o cualquier entrada/meta
    $cache_key = md5($path . '|' . filemtime($path) . '|' . wp_cache_get_last_changed('posts'));
    $cached = get_transient('viable_export_geojson');
    if (is_array($cached) && ($cached['key'] ?? '') === $cache_key) {
        return $cached['data'];
    }

    // Fichas publicadas por código
    $projects = [];
    foreach (get_posts(['post_type' => 'project', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']) as $pid) {
        $code = trim((string) get_field('code', $pid, false));
        if ($code !== '') {
            $projects[$code] = (int) $pid;
        }
    }
    if (!$projects) {
        return $empty;
    }

    // Nota más reciente de cada ficha (una sola consulta para todas)
    $latest_note = [];
    $notes = get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [['key' => 'related_projects', 'compare' => 'EXISTS']],
    ]);
    foreach ($notes as $nid) {
        $related = get_post_meta($nid, 'related_projects', true);
        $related = is_array($related) ? $related : ($related ? [$related] : []);
        foreach ($related as $rp) {
            $rp = (int) $rp;
            if ($rp && !isset($latest_note[$rp])) {
                $latest_note[$rp] = get_permalink($nid);
            }
        }
    }

    $meters_per_unit = viable_get_length_unit() === 'km' ? 1000 : 1;
    $base = [];
    $seen = [];
    $features = [];

    foreach (viable_read_gpkg_rows() as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        if (!isset($projects[$code]) || empty($row['geometry'])) {
            continue;
        }
        $pid = $projects[$code];

        if (!isset($base[$pid])) {
            $state = (string) get_field('state', $pid, false);
            $title = get_post_field('post_title', $pid, 'raw');
            $url = get_permalink($pid);
            $note = $latest_note[$pid] ?? $url;

            $region_name = null;
            $regions = get_field('regions', $pid, false);
            foreach ((array) $regions as $rid) {
                $term = $rid ? get_term((int) $rid, 'category') : null;
                if ($term && !is_wp_error($term)) {
                    $region_name = $term->name;
                    break;
                }
            }

            $length = str_replace(',', '.', (string) get_field('length', $pid, false));

            $base[$pid] = [
                'props' => [
                    'code'              => $code,
                    'title'             => $title,
                    'Obra'              => $title,
                    'TipoObra'          => (string) get_field('type', $pid, false),
                    'state'             => $state,
                    'short_description' => (string) get_field('short_description', $pid, false),
                    'FechaPavimento'    => $state === 'Finalizado' ? viable_export_iso_date(get_field('end_date', $pid, false)) : null,
                    'start'             => viable_export_iso_date(get_field('start_date', $pid, false)),
                    'municipality'      => $region_name,
                    'Financista'        => (string) get_field('funding', $pid, false) ?: null,
                    'link_finished'     => $state === 'Finalizado' ? $note : null,
                    'link_progress'     => in_array($state, ['En obras', 'Paralizado'], true) ? $note : null,
                    'url'               => $url,
                ],
                'length_m' => is_numeric($length) ? round((float) $length * $meters_per_unit) : null,
            ];
        }

        $first = !isset($seen[$code]);
        $seen[$code] = true;
        $length_m = $base[$pid]['length_m'];

        $features[] = [
            'type'       => 'Feature',
            'geometry'   => $row['geometry'],
            'properties' => $base[$pid]['props'] + [
                'surface' => isset($row['surface']) && $row['surface'] !== '' ? $row['surface'] : null,
                'segment' => viable_feature_segment_status($row),
                'length'  => $length_m !== null ? ($first ? $length_m : 0) : round(viable_geometry_length_m($row['geometry'])),
            ],
        ];
    }

    $result = ['type' => 'FeatureCollection', 'features' => $features];
    set_transient('viable_export_geojson', ['key' => $cache_key, 'data' => $result], DAY_IN_SECONDS);
    return $result;
}
