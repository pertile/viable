<?php
/**
 * Reemplaza los mapas Mapbox embebidos a mano en las notas por la ficha del plugin.
 * Se corre con WP-CLI en el servidor:
 *
 *   wp eval-file wp-content/plugins/viable/tools/reemplazar-mapas-mapbox.php [opciones]
 *
 * Opciones (palabras sueltas):
 *   (ninguna)   Análisis: lista qué encontró en cada nota, no cambia nada.
 *   detalle     Muestra una línea por nota.
 *   aplicar     Quita el bloque del mapa y vincula las obras a la nota.
 *   ID          Uno o más IDs de nota para limitar el alcance (p. ej. para probar con una).
 *
 * Cómo interpreta cada bloque:
 *   - Lee las obras del 'line-color' (['match', ['get', 'Obra'], [nombres], color, …]) o, si no
 *     está, del 'filter'. Los nombres se buscan en el campo Obra (o title) del GeoPackage.
 *   - Las obras en rojo (#ff0000) son las de la nota → "Obras" (ficha).
 *     Las de otros colores son contexto → "Obras de referencia en el mapa" (en gris).
 *     Si no hay ninguna en rojo, la primera es la de la nota.
 *
 * Antes de aplicar: wp db export (respaldo). Los cambios guardan revisión de la nota.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Correr con: wp eval-file …\n");
}

$opts = isset($args) ? (array) $args : [];
$apply = in_array('aplicar', $opts, true);
$verbose = in_array('detalle', $opts, true);
$only_ids = array_values(array_filter(array_map('intval', $opts)));

$F_RELATED = 'field_697e0e66a6994';
$F_CONTEXT = 'field_viable_context_projects';
$RED = ['#ff0000', '#f00', 'red'];

/* ── Nombres del GeoPackage → códigos → fichas ─────────────────────── */

$norm = function ($s) {
    return strtolower(remove_accents(trim(preg_replace('/\s+/', ' ', (string) $s))));
};

$codes_by_name = ['obra' => [], 'title' => []];
foreach (viable_read_gpkg_rows() as $row) {
    $code = trim((string) ($row['code'] ?? ''));
    if ($code === '') {
        continue;
    }
    foreach (['obra' => 'Obra', 'title' => 'title'] as $key => $col) {
        if (!empty($row[$col])) {
            $codes_by_name[$key][$norm($row[$col])][$code] = true;
        }
    }
}
if (!$codes_by_name['obra'] && !$codes_by_name['title']) {
    WP_CLI::error('El GeoPackage no tiene los campos Obra/title: hace falta para identificar las obras de cada mapa.');
}

$project_by_code = [];
foreach (get_posts(['post_type' => 'project', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']) as $pid) {
    $c = trim((string) get_field('code', $pid, false));
    if ($c !== '') {
        $project_by_code[$c] = (int) $pid;
    }
}

/* ── Análisis de un bloque ─────────────────────────────────────────── */

// El bloque va desde <script src=".../mapbox-gl-js/..."> hasta el </script> que sigue a "new mapboxgl.Map"
$BLOCK_RE = '~(?:<!--\s*wp:html\s*-->\s*)?<script[^>]*mapbox-gl-js/[^>]*>\s*</script>.*?new\s+mapboxgl\.Map.*?</script>(?:\s*<!--\s*/wp:html\s*-->)?~is';

$parse_names = function ($list) {
    preg_match_all('/\'([^\']*)\'|"([^"]*)"/', $list, $m, PREG_SET_ORDER);
    return array_values(array_filter(array_map(function ($x) {
        return $x[2] ?? $x[1];
    }, $m), 'strlen'));
};

$analyze = function ($block) use ($parse_names, $RED) {
    $key = 'Obra';
    $groups = []; // [ ['names' => [...], 'color' => '#...'], … ]

    if (preg_match('/[\'"]line-color[\'"]\s*:\s*\[\s*[\'"]match[\'"]\s*,\s*\[\s*[\'"]get[\'"]\s*,\s*[\'"](\w+)[\'"]\s*\]\s*,(.*?)\]\s*,\s*[\'"]line-width/is', $block, $m)) {
        $key = $m[1];
        preg_match_all('/\[([^\[\]]*)\]\s*,\s*[\'"](#?[0-9a-z]+)[\'"]/i', $m[2], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $p) {
            $groups[] = ['names' => $parse_names($p[1]), 'color' => strtolower($p[2])];
        }
    }
    if (!$groups && preg_match('/[\'"]filter[\'"]\s*:\s*\[\s*[\'"]match[\'"]\s*,\s*\[\s*[\'"]get[\'"]\s*,\s*[\'"](\w+)[\'"]\s*\]\s*,\s*\[([^\]]*)\]/is', $block, $m)) {
        $key = $m[1];
        $groups[] = ['names' => $parse_names($m[2]), 'color' => ''];
    }
    if (!$groups) {
        return null;
    }

    $main = [];
    $context = [];
    $has_red = (bool) array_filter($groups, function ($g) use ($RED) {
        return in_array($g['color'], $RED, true);
    });
    foreach ($groups as $i => $g) {
        $is_main = $has_red ? in_array($g['color'], $RED, true) : $i === 0;
        foreach ($g['names'] as $n) {
            if ($is_main) {
                $main[] = $n;
            } else {
                $context[] = $n;
            }
        }
    }
    return ['key' => $key, 'main' => array_values(array_unique($main)), 'context' => array_values(array_unique(array_diff($context, $main)))];
};

$resolve = function ($names, $key) use ($codes_by_name, $project_by_code, $norm) {
    $index = strtolower($key) === 'title' ? 'title' : 'obra';
    $ids = [];
    $missing = [];
    foreach ($names as $n) {
        $codes = array_keys($codes_by_name[$index][$norm($n)] ?? []);
        $found = array_values(array_filter(array_map(function ($c) use ($project_by_code) {
            return $project_by_code[$c] ?? 0;
        }, $codes)));
        if ($found) {
            $ids = array_merge($ids, $found);
        } else {
            $missing[] = $n;
        }
    }
    return [array_values(array_unique($ids)), $missing];
};

/* ── Recorrer notas ────────────────────────────────────────────────── */

global $wpdb;
$query = "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','future','draft','private') AND post_content LIKE %s";
$params = ['%mapbox-gl-js%'];
if ($only_ids) {
    $query .= ' AND ID IN (' . implode(',', $only_ids) . ')';
}
$posts = $wpdb->get_results($wpdb->prepare($query, $params));

$elementor = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
    '%mapbox-gl-js%'
));

$stats = ['ok' => 0, 'sin_obras' => 0, 'faltan' => 0, 'varios_bloques' => 0, 'aplicadas' => 0];
$missing_names = [];

foreach ($posts as $post) {
    $content = get_post_field('post_content', $post->ID, 'raw');
    $n = preg_match_all($BLOCK_RE, $content, $blocks);
    if (!$n) {
        $stats['sin_obras']++;
        WP_CLI::warning("#{$post->ID} {$post->post_title}: menciona mapbox-gl-js pero el bloque no tiene la forma esperada");
        continue;
    }
    if ($n > 1) {
        $stats['varios_bloques']++;
    }

    $main_ids = [];
    $context_ids = [];
    $problem = false;
    foreach ($blocks[0] as $block) {
        $a = $analyze($block);
        if (!$a) {
            $problem = true;
            WP_CLI::warning("#{$post->ID} {$post->post_title}: no se encontró la lista de obras en el bloque");
            continue;
        }
        list($ids, $miss) = $resolve($a['main'], $a['key']);
        list($cids, $cmiss) = $resolve($a['context'], $a['key']);
        $main_ids = array_merge($main_ids, $ids);
        $context_ids = array_merge($context_ids, $cids);
        foreach (array_merge($miss, $cmiss) as $m) {
            $missing_names[$m][] = $post->ID;
            $problem = true;
        }
    }
    $main_ids = array_values(array_unique($main_ids));
    $context_ids = array_values(array_diff(array_unique($context_ids), $main_ids));

    if (!$main_ids) {
        $stats['sin_obras']++;
        WP_CLI::warning("#{$post->ID} {$post->post_title}: no se identificó ninguna obra; no se toca");
        continue;
    }
    $problem ? $stats['faltan']++ : $stats['ok']++;

    if ($verbose) {
        $label = function ($ids) {
            return implode(', ', array_map(function ($id) {
                return get_field('code', $id, false);
            }, $ids)) ?: '—';
        };
        WP_CLI::log(sprintf('#%d %s → obras: %s | referencia: %s%s', $post->ID, $post->post_title, $label($main_ids), $label($context_ids), $problem ? ' | INCOMPLETO' : ''));
    }

    if (!$apply || $problem) {
        continue;
    }

    // Quitar el/los bloques y vincular
    $new_content = preg_replace($BLOCK_RE, '', $content);
    $new_content = preg_replace("/\n{3,}/", "\n\n", $new_content);
    $result = wp_update_post(['ID' => $post->ID, 'post_content' => $new_content], true);
    if (is_wp_error($result)) {
        WP_CLI::warning("#{$post->ID}: no se pudo guardar (" . $result->get_error_message() . ')');
        continue;
    }

    $current = get_field($F_RELATED, $post->ID, false);
    $current = is_array($current) ? array_map('intval', $current) : ($current ? [(int) $current] : []);
    update_field($F_RELATED, array_values(array_unique(array_merge($current, $main_ids))), $post->ID);

    $current_ctx = get_field($F_CONTEXT, $post->ID, false);
    $current_ctx = is_array($current_ctx) ? array_map('intval', $current_ctx) : [];
    $ctx = array_values(array_diff(array_unique(array_merge($current_ctx, $context_ids)), $main_ids));
    if ($ctx) {
        update_field($F_CONTEXT, $ctx, $post->ID);
    }
    $stats['aplicadas']++;
}

/* ── Resumen ───────────────────────────────────────────────────────── */

WP_CLI::log(sprintf(
    'Notas con mapa Mapbox: %d. Listas para reemplazar: %d. Con obras sin identificar: %d. Sin interpretar: %d. Con más de un mapa: %d.',
    count($posts), $stats['ok'], $stats['faltan'], $stats['sin_obras'], $stats['varios_bloques']
));
if ($elementor) {
    WP_CLI::warning("$elementor entradas tienen el mapa dentro de Elementor: esas no se tocan, hay que revisarlas a mano.");
}
if ($missing_names) {
    WP_CLI::log('Nombres de obra que no están en el GeoPackage (las notas que los usan no se tocan):');
    foreach ($missing_names as $name => $ids) {
        WP_CLI::log("  \"$name\" → notas " . implode(', ', array_unique($ids)));
    }
}
if ($apply) {
    WP_CLI::success("Notas actualizadas: {$stats['aplicadas']}.");
} else {
    WP_CLI::success('Análisis: no se cambió nada. Para aplicar, agregá "aplicar" (probá primero con un ID).');
}
