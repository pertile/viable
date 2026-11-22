<?php
/**
 * Importa las obras de georesistencia desde el GeoPackage de trazados y crea
 * una ficha (proyecto) por cada código. Se corre con WP-CLI en el servidor:
 *
 *   wp eval-file wp-content/plugins/viable/tools/importar-georesistencia.php [opciones]
 *
 * Opciones (palabras sueltas, en cualquier orden):
 *   (ninguna)    Simulación: muestra qué haría, no cambia nada.
 *   aplicar      Crea las fichas que faltan.
 *   actualizar   Con "aplicar": también sobrescribe las fichas que ya existen.
 *   borrador     Con "aplicar": crea las fichas nuevas como borrador.
 *   vincular     Con "aplicar": agrega cada obra a las notas de link_finished / link_progress
 *                (ojo: esas notas empiezan a mostrar la ficha y el mapa del plugin).
 *
 * Requisitos: ACF y el plugin activos, Ajustes → Obras configurado (sobre todo
 * las categorías de municipios) y el GeoPackage subido.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Correr con: wp eval-file …\n");
}

$opts = array_map('strtolower', isset($args) ? (array) $args : []);
$apply  = in_array('aplicar', $opts, true);
$update = in_array('actualizar', $opts, true);
$draft  = in_array('borrador', $opts, true);
$link   = in_array('vincular', $opts, true);

/* ── Correspondencias ──────────────────────────────────────────────── */

$TYPE_MAP = [
    'pavimento'      => 'Pavimento',
    'pavimentación'  => 'Pavimento',
    'ampliación'     => 'Ampliación',
    'reconstrucción' => 'Reconstrucción',
];
$SURFACE_MAP = [
    'concrete'      => 'Hormigón',
    'concerete'     => 'Hormigón',
    'asphalt'       => 'Asfalto',
    'paving_stones' => 'Adoquín',
    'paved'         => 'Pavimentada',
    'unpaved'       => 'De tierra',
];
$FUNDING_MAP = [
    'provincia/vecinos' => 'Provincia y vecinos',
];

// Claves de campos ACF (por clave, para no confundir con campos de otros grupos)
$F = [
    'code'     => 'field_697e0502309ee',
    'summary'  => 'field_697e0733309f0',
    'type'     => 'field_697e075f309f2',
    'state'    => 'field_697e0812309f5',
    'length'   => 'field_697e08d0309f8',
    'start'    => 'field_697e08fd309fa',
    'end'      => 'field_697e088d309f7',
    'regions'  => 'field_697e0b20309fe',
    'funding'  => 'field_viable_funding',
    'surface'  => 'field_viable_surface',
    'related'  => 'field_697e0e66a6994',
];

/* ── Helpers ───────────────────────────────────────────────────────── */

$norm = function ($s) {
    return strtolower(remove_accents(trim((string) $s)));
};
$val = function ($row, $key) {
    $v = $row[$key] ?? null;
    return ($v === null || (is_string($v) && trim($v) === '')) ? null : (is_string($v) ? trim($v) : $v);
};
// "2016-12-22T00:00:00Z" → "20161222" (solo la parte de fecha, sin correr husos)
$to_ymd = function ($v) {
    if (!$v || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $v, $m)) {
        return null;
    }
    return $m[1] . $m[2] . $m[3];
};

/* ── Leer el GeoPackage (solo atributos) ───────────────────────────── */

if (!function_exists('get_field') || !function_exists('viable_get_gpkg_path')) {
    WP_CLI::error('ACF o el plugin viable no están activos.');
}
$gpkg = viable_get_gpkg_path();
$info = viable_inspect_gpkg($gpkg);
if (is_wp_error($info)) {
    WP_CLI::error($info->get_error_message());
}

$db = new SQLite3($gpkg, SQLITE3_OPEN_READONLY);
$table = '"' . str_replace('"', '""', $info['table']) . '"';
$res = $db->query("SELECT * FROM $table WHERE code IS NOT NULL AND TRIM(code) <> '' ORDER BY code, fid");
$obras = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    unset($row['geom'], $row['geometry']);
    $obras[trim($row['code'])][] = $row;
}
$db->close();

/* ── Municipios → categorías configuradas como regiones ────────────── */

$region_by_name = [];
foreach (viable_get_region_categories() as $term) {
    $region_by_name[$norm($term->name)] = (int) $term->term_id;
}
if (!$region_by_name) {
    WP_CLI::warning('No hay categorías de regiones configuradas en Ajustes → Obras: los municipios no se van a asignar.');
}

$allowed_types = viable_get_project_types();
$allowed_funding = viable_get_funding_options();
$allowed_surface = viable_get_surface_options();

/* ── Armar cada ficha ──────────────────────────────────────────────── */

$today = wp_date('Ymd');
$plan = [];
$problems = [];
$stats = ['estado' => [], 'tipo' => [], 'financiamiento' => [], 'calzada' => [], 'municipio' => []];

foreach ($obras as $code => $rows) {
    $r = $rows[0];

    if (!$val($r, 'title') && !$val($r, 'Obra')) {
        $problems[] = "$code: sin título ni nombre de obra; se omite (conviene borrar la fila en QGIS)";
        continue;
    }

    $title = $val($r, 'title') ?: $val($r, 'Obra');

    $type_raw = $val($r, 'TipoObra');
    $type = $type_raw ? ($TYPE_MAP[mb_strtolower($type_raw)] ?? $type_raw) : 'Pavimento';
    if (!in_array($type, $allowed_types, true)) {
        $problems[] = "$code: tipo \"$type\" no está en la lista de Ajustes → Obras";
    }

    $surface_raw = $val($r, 'surface');
    $surface = $surface_raw ? ($SURFACE_MAP[strtolower($surface_raw)] ?? null) : null;
    if ($surface && $allowed_surface && !in_array($surface, $allowed_surface, true)) {
        $problems[] = "$code: calzada \"$surface\" no está en la lista de Ajustes → Obras";
    }

    $end = $to_ymd($val($r, 'FechaPavimento'));
    $start = $to_ymd($val($r, 'start'));
    if ($surface_raw === 'project') {
        $state = 'Proyecto';
    } elseif ($surface_raw === 'construction') {
        $state = 'En obras';
    } elseif ($end) {
        $state = $end > $today ? 'En obras' : 'Finalizado';
    } elseif ($start) {
        $state = 'En obras';
    } else {
        $state = 'Proyecto';
        $problems[] = "$code ($title): sin fechas ni estado; queda como Proyecto, revisar";
    }

    $funding_raw = $val($r, 'Financista');
    $funding = $funding_raw ? ($FUNDING_MAP[mb_strtolower($funding_raw)] ?? $funding_raw) : null;
    if ($funding && $allowed_funding && !in_array($funding, $allowed_funding, true)) {
        $problems[] = "$code: financiamiento \"$funding\" no está en la lista de Ajustes → Obras";
    }

    $muni = $val($r, 'municipality');
    $region_id = $muni ? ($region_by_name[$norm($muni)] ?? null) : null;
    if ($muni && !$region_id) {
        $problems[] = "$code: municipio \"$muni\" no coincide con ninguna categoría de región";
    }

    $length = $val($r, 'length');
    $length = is_numeric($length) ? (string) round((float) $length) : '';

    $links = [];
    foreach ($rows as $row) {
        foreach (['link_finished', 'link_progress'] as $k) {
            if ($url = $val($row, $k)) {
                $links[$url] = true;
            }
        }
    }

    $plan[$code] = compact('title', 'type', 'state', 'end', 'start', 'length', 'funding', 'surface', 'region_id', 'muni') + [
        'summary' => $val($r, 'short_description') ?: '',
        'links'   => array_keys($links),
    ];

    $stats['estado'][$state] = ($stats['estado'][$state] ?? 0) + 1;
    $stats['tipo'][$type] = ($stats['tipo'][$type] ?? 0) + 1;
    $stats['financiamiento'][$funding ?: '(vacío)'] = ($stats['financiamiento'][$funding ?: '(vacío)'] ?? 0) + 1;
    $stats['calzada'][$surface ?: '(vacío)'] = ($stats['calzada'][$surface ?: '(vacío)'] ?? 0) + 1;
    $stats['municipio'][$muni ?: '(vacío)'] = ($stats['municipio'][$muni ?: '(vacío)'] ?? 0) + 1;
}

/* ── Fichas existentes y notas a vincular ──────────────────────────── */

$existing = [];
foreach (get_posts(['post_type' => 'project', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']) as $pid) {
    $c = trim((string) get_field('code', $pid));
    if ($c !== '') {
        $existing[$c] = (int) $pid;
    }
}

$note_ids = [];
$unresolved = [];
foreach ($plan as $code => $p) {
    foreach ($p['links'] as $url) {
        $post_id = url_to_postid($url);
        if ($post_id && get_post_type($post_id) === 'post') {
            $note_ids[$post_id][] = $code;
        } else {
            $unresolved[$url] = true;
        }
    }
}

/* ── Resumen ───────────────────────────────────────────────────────── */

$new_codes = array_diff(array_keys($plan), array_keys($existing));
WP_CLI::log(sprintf('GeoPackage: %s (%d geometrías, %d obras)', $gpkg, $info['features'], count($plan)));
WP_CLI::log(sprintf('Fichas: %d nuevas, %d ya existen%s', count($new_codes), count($plan) - count($new_codes), $update ? ' (se actualizan)' : ' (no se tocan)'));
foreach ($stats as $label => $counts) {
    arsort($counts);
    WP_CLI::log(ucfirst($label) . ': ' . implode(', ', array_map(function ($k, $v) {
        return "$k $v";
    }, array_keys($counts), $counts)));
}
WP_CLI::log(sprintf('Notas: %d se vinculan a alguna obra; %d links no se encontraron en el sitio', count($note_ids), count($unresolved)));
foreach ($problems as $p) {
    WP_CLI::warning($p);
}
foreach (array_slice(array_keys($unresolved), 0, 15) as $url) {
    WP_CLI::warning("Link sin nota: $url");
}
if (count($unresolved) > 15) {
    WP_CLI::warning('… y ' . (count($unresolved) - 15) . ' links más sin nota');
}

if (!$apply) {
    WP_CLI::success('Simulación: no se cambió nada. Para crear las fichas, agregá "aplicar".');
    return;
}

/* ── Aplicar ───────────────────────────────────────────────────────── */

$created = $updated = 0;
foreach ($plan as $code => $p) {
    $pid = $existing[$code] ?? 0;
    if ($pid && !$update) {
        continue;
    }

    if (!$pid) {
        $pid = wp_insert_post([
            'post_type'    => 'project',
            'post_status'  => $draft ? 'draft' : 'publish',
            'post_title'   => $p['title'],
            'post_content' => '',
        ], true);
        if (is_wp_error($pid)) {
            WP_CLI::warning("$code: no se pudo crear (" . $pid->get_error_message() . ')');
            continue;
        }
        $existing[$code] = $pid;
        $created++;
    } else {
        wp_update_post(['ID' => $pid, 'post_title' => $p['title']]);
        $updated++;
    }

    update_field($F['code'], $code, $pid);
    update_field($F['summary'], $p['summary'], $pid);
    update_field($F['type'], $p['type'], $pid);
    update_field($F['state'], $p['state'], $pid);
    update_field($F['length'], $p['length'], $pid);
    update_field($F['start'], $p['start'] ?: '', $pid);
    update_field($F['end'], $p['end'] ?: '', $pid);
    update_field($F['funding'], $p['funding'] ?: '', $pid);
    update_field($F['surface'], $p['surface'] ?: '', $pid);
    update_field($F['regions'], $p['region_id'] ? [$p['region_id']] : [], $pid);
}
WP_CLI::log("Fichas creadas: $created, actualizadas: $updated");

if ($link) {
    $linked = 0;
    foreach ($note_ids as $post_id => $codes) {
        $current = get_field($F['related'], $post_id, false);
        $current = is_array($current) ? array_map('intval', $current) : ($current ? [(int) $current] : []);
        $add = array_values(array_filter(array_map(function ($c) use ($existing) {
            return $existing[$c] ?? 0;
        }, $codes)));
        $merged = array_values(array_unique(array_merge($current, $add)));
        if ($merged !== $current) {
            update_field($F['related'], $merged, $post_id);
            $linked++;
        }
    }
    WP_CLI::log("Notas vinculadas: $linked");
}

WP_CLI::success('Listo.');
