<?php
/**
 * Marca como "Obra en ruta" las obras vinculadas a notas de una categoría
 * (por defecto "rutas-provinciales"). Se corre con WP-CLI en el servidor:
 *
 *   wp eval-file wp-content/plugins/viable/tools/marcar-obras-en-ruta.php [opciones]
 *
 * Opciones (palabras sueltas):
 *   (ninguna)        Simulación: lista qué obras marcaría, no cambia nada.
 *   aplicar          Marca las obras.
 *   <slug>           Otra categoría (por ejemplo: rutas-nacionales). Se pueden poner varias.
 *
 * Una obra marcada muestra su mapa con el norte arriba y no se incluye en el
 * export GeoJSON (Pavimentómetro). Para desmarcar una, se hace desde su ficha.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Correr con: wp eval-file …\n");
}

$opts = isset($args) ? (array) $args : [];
$apply = in_array('aplicar', $opts, true);
$slugs = array_values(array_diff($opts, ['aplicar']));
if (!$slugs) {
    $slugs = ['rutas-provinciales'];
}

$term_ids = [];
foreach ($slugs as $slug) {
    $term = get_term_by('slug', $slug, 'category');
    if (!$term) {
        WP_CLI::error("No existe la categoría \"$slug\".");
    }
    $term_ids[] = (int) $term->term_id;
}

$notes = get_posts([
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'category__in'   => $term_ids,
]);

$projects = [];
foreach ($notes as $nid) {
    $related = get_field('related_projects', $nid, false);
    $related = is_array($related) ? $related : ($related ? [$related] : []);
    foreach ($related as $pid) {
        $pid = (int) $pid;
        if ($pid && get_post_type($pid) === 'project') {
            $projects[$pid][] = $nid;
        }
    }
}

$to_mark = 0;
foreach ($projects as $pid => $note_ids) {
    $already = viable_is_road_project($pid);
    WP_CLI::log(sprintf(
        '%s %s — %s (notas: %s)',
        $already ? '[ya marcada]' : '[marcar]   ',
        get_field('code', $pid, false),
        get_the_title($pid),
        implode(', ', array_unique($note_ids))
    ));
    if ($already) {
        continue;
    }
    $to_mark++;
    if ($apply) {
        update_field('field_viable_road_project', 1, $pid);
    }
}

WP_CLI::log(sprintf('Notas en %s: %d. Obras vinculadas: %d. A marcar: %d.', implode(', ', $slugs), count($notes), count($projects), $to_mark));
if (!$notes || !$projects) {
    WP_CLI::warning('No hay obras vinculadas a esas notas todavía. Si recién importaste, primero vinculá las notas (reemplazar-mapas-mapbox.php o el "vincular" del importador).');
}
WP_CLI::success($apply ? "Marcadas: $to_mark." : 'Simulación: no se cambió nada. Para marcar, agregá "aplicar".');
