<?php
/**
 * Agrupa obras (tramos) bajo una ficha principal, creándola si no existe.
 * Se corre con WP-CLI en el servidor:
 *
 *   wp eval-file wp-content/plugins/viable/tools/agrupar-obras.php \
 *     padre=GR-308 "titulo=RP13 Charadai - Villa Ángela" hijos=GR-304,GR-305,GR-306,GR-307 ruta [aplicar]
 *
 * Parámetros:
 *   padre=CÓDIGO     Código de la ficha principal (se crea si no existe; no necesita trazado).
 *   titulo=TEXTO     Título de la ficha principal (solo si hay que crearla).
 *   hijos=A,B,C      Códigos de los tramos; tienen que tener ficha (importador).
 *   ruta             Marca principal y tramos como "Obra en ruta" (mapa sin rotación, fuera del Pavimentómetro).
 *   aplicar          Sin esto, solo muestra qué haría.
 *
 * La ficha principal muestra la lista de tramos con su estado y un mapa con todos.
 * Tipo y estado de la principal se copian del primer tramo; después se editan en wp-admin.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Correr con: wp eval-file …\n");
}

$params = [];
$flags = [];
foreach (isset($args) ? (array) $args : [] as $a) {
    if (strpos($a, '=') !== false) {
        list($k, $v) = explode('=', $a, 2);
        $params[strtolower(trim($k))] = trim($v);
    } else {
        $flags[] = strtolower(trim($a));
    }
}
$apply = in_array('aplicar', $flags, true);
$road = in_array('ruta', $flags, true);
$parent_code = $params['padre'] ?? '';
$title = $params['titulo'] ?? '';
$child_codes = array_values(array_filter(array_map('trim', explode(',', $params['hijos'] ?? ''))));

if ($parent_code === '' || !$child_codes) {
    WP_CLI::error('Faltan parámetros. Ejemplo: padre=GR-308 "titulo=RP13 Charadai - Villa Ángela" hijos=GR-304,GR-305 ruta');
}

$F = [
    'code'    => 'field_697e0502309ee',
    'summary' => 'field_697e0733309f0',
    'type'    => 'field_697e075f309f2',
    'state'   => 'field_697e0812309f5',
    'regions' => 'field_697e0b20309fe',
    'parent'  => 'field_68094b5a_project_parent',
    'road'    => 'field_viable_road_project',
];

$by_code = [];
foreach (get_posts(['post_type' => 'project', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']) as $pid) {
    $c = trim((string) get_field('code', $pid, false));
    if ($c !== '') {
        $by_code[$c] = (int) $pid;
    }
}

$children = [];
foreach ($child_codes as $c) {
    if (empty($by_code[$c])) {
        WP_CLI::error("El tramo $c no tiene ficha. Creala primero con el importador (o update_obras_resistencia.py --fichas).");
    }
    if ($c === $parent_code) {
        WP_CLI::error("$c no puede ser principal y tramo a la vez.");
    }
    $children[$c] = $by_code[$c];
}

$parent_id = $by_code[$parent_code] ?? 0;
$first = reset($children);
if ($parent_id) {
    WP_CLI::log("Ficha principal: $parent_code — " . get_the_title($parent_id) . " (ya existe, #$parent_id)");
} else {
    if ($title === '') {
        WP_CLI::error("La ficha $parent_code no existe: indicá titulo=… para crearla.");
    }
    WP_CLI::log("Ficha principal: $parent_code — $title (se crea)");
}
foreach ($children as $c => $pid) {
    $current = (int) get_field('project_parent', $pid, false);
    WP_CLI::log(sprintf('  Tramo %s — %s%s', $c, get_the_title($pid), $current && $current !== $parent_id ? " (hoy depende de #$current; se cambia)" : ''));
}
if ($road) {
    WP_CLI::log('Se marcan principal y tramos como "Obra en ruta".');
}

if (!$apply) {
    WP_CLI::success('Simulación: no se cambió nada. Para aplicar, agregá "aplicar".');
    return;
}

if (!$parent_id) {
    $parent_id = wp_insert_post([
        'post_type'    => 'project',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => '',
    ], true);
    if (is_wp_error($parent_id)) {
        WP_CLI::error('No se pudo crear la ficha principal: ' . $parent_id->get_error_message());
    }
    update_field($F['code'], $parent_code, $parent_id);
    update_field($F['type'], get_field('type', $first, false), $parent_id);
    update_field($F['state'], get_field('state', $first, false) ?: 'Proyecto', $parent_id);
    $regions = get_field('regions', $first, false);
    if ($regions) {
        update_field($F['regions'], (array) $regions, $parent_id);
    }
}

foreach ($children as $pid) {
    update_field($F['parent'], $parent_id, $pid);
    if ($road) {
        update_field($F['road'], 1, $pid);
    }
}
if ($road) {
    update_field($F['road'], 1, $parent_id);
}

WP_CLI::success('Listo: ' . count($children) . " tramos bajo $parent_code (#$parent_id). Revisá tipo, estado y resumen de la ficha principal en wp-admin.");
