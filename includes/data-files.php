<?php
/**
 * Archivos de datos propios de cada sitio (trazados, etc.).
 *
 * Viven en wp-content/uploads/viable/ y NO dentro del plugin, para que las
 * actualizaciones por `git pull` nunca pisen los datos de un sitio.
 *
 * Orden de búsqueda del GeoPackage de trazados:
 *   1. Constante VIABLE_GPKG_PATH (definida en wp-config.php), si existe
 *   2. wp-content/uploads/viable/viable.gpkg
 *   3. assets/data/viable.gpkg dentro del plugin (ubicación anterior, solo transición)
 *
 * El archivo se puede reemplazar desde Ajustes → Obras o con tools/subir-trazados.sh.
 */

function viable_get_data_dir() {
    $uploads = wp_upload_dir(null, false);
    return trailingslashit($uploads['basedir']) . 'viable/';
}

function viable_get_gpkg_path() {
    if (defined('VIABLE_GPKG_PATH') && VIABLE_GPKG_PATH) {
        return apply_filters('viable_gpkg_path', VIABLE_GPKG_PATH);
    }

    $path = viable_get_data_dir() . 'viable.gpkg';
    $legacy = VIABLE_PATH . 'assets/data/viable.gpkg';

    if (!file_exists($path) && file_exists($legacy)) {
        $path = $legacy;
    }

    return apply_filters('viable_gpkg_path', $path);
}

/**
 * Revisa un GeoPackage y devuelve un resumen, o WP_Error si no sirve.
 *
 * @return array|WP_Error ['table', 'features', 'codes' => [...], 'without_code']
 */
function viable_inspect_gpkg($path) {
    if (!class_exists('SQLite3')) {
        return new WP_Error('viable_no_sqlite', 'El servidor no tiene la extensión SQLite3 de PHP.');
    }
    if (!$path || !is_readable($path)) {
        return new WP_Error('viable_gpkg_missing', 'No se encuentra el archivo.');
    }

    $fh = fopen($path, 'rb');
    $magic = $fh ? fread($fh, 16) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($magic !== "SQLite format 3\0") {
        return new WP_Error('viable_gpkg_invalid', 'El archivo no es un GeoPackage (no es una base SQLite).');
    }

    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);

        $table = $db->querySingle("SELECT table_name FROM gpkg_contents WHERE data_type = 'features' LIMIT 1");
        if (!$table) {
            $db->close();
            return new WP_Error('viable_gpkg_no_layer', 'El GeoPackage no tiene ninguna capa de geometrías.');
        }

        $columns = [];
        $res = $db->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
        while ($col = $res->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $col['name'];
        }
        if (!in_array('code', $columns, true)) {
            $db->close();
            return new WP_Error('viable_gpkg_no_code', 'La capa "' . $table . '" no tiene un campo "code". Campos: ' . implode(', ', $columns) . '.');
        }

        $quoted = '"' . str_replace('"', '""', $table) . '"';
        $features = (int) $db->querySingle("SELECT COUNT(*) FROM $quoted");
        $without_code = (int) $db->querySingle("SELECT COUNT(*) FROM $quoted WHERE code IS NULL OR TRIM(code) = ''");

        $codes = [];
        $res = $db->query("SELECT DISTINCT code FROM $quoted WHERE code IS NOT NULL AND TRIM(code) <> ''");
        while ($row = $res->fetchArray(SQLITE3_NUM)) {
            $codes[] = (string) $row[0];
        }
        $db->close();
    } catch (Exception $e) {
        return new WP_Error('viable_gpkg_error', 'No se pudo leer el GeoPackage: ' . $e->getMessage());
    }

    if ($features === 0) {
        return new WP_Error('viable_gpkg_empty', 'La capa "' . $table . '" está vacía.');
    }

    return [
        'table'        => $table,
        'features'     => $features,
        'codes'        => $codes,
        'without_code' => $without_code,
    ];
}

/**
 * Todas las filas del GeoPackage, con la geometría ya convertida a GeoJSON en 'geometry'.
 *
 * @return array
 */
function viable_read_gpkg_rows() {
    $path = viable_get_gpkg_path();
    if (!class_exists('SQLite3') || !file_exists($path)) {
        return [];
    }

    $rows = [];
    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $table = $db->querySingle("SELECT table_name FROM gpkg_contents WHERE data_type = 'features' LIMIT 1");
        if ($table) {
            $res = $db->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"');
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $blob = $row['geom'] ?? $row['geometry'] ?? null;
                unset($row['geom'], $row['geometry']);
                $row['geometry'] = $blob ? viable_wkb_to_geojson($blob) : null;
                $rows[] = $row;
            }
        }
        $db->close();
    } catch (Exception $e) {
        error_log('Viable: no se pudo leer el GeoPackage: ' . $e->getMessage());
        return [];
    }
    return $rows;
}

/** Longitud en metros de una geometría GeoJSON (LineString / MultiLineString). */
function viable_geometry_length_m($geometry) {
    if (!is_array($geometry) || empty($geometry['coordinates'])) {
        return 0.0;
    }
    $lines = $geometry['type'] === 'MultiLineString' ? $geometry['coordinates']
        : ($geometry['type'] === 'LineString' ? [$geometry['coordinates']] : []);

    $total = 0.0;
    foreach ($lines as $line) {
        for ($i = 1, $n = count($line); $i < $n; $i++) {
            list($lon1, $lat1) = $line[$i - 1];
            list($lon2, $lat2) = $line[$i];
            $dlat = deg2rad($lat2 - $lat1);
            $dlon = deg2rad($lon2 - $lon1);
            $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon / 2) ** 2;
            $total += 2 * 6371008.8 * asin(min(1, sqrt($a)));
        }
    }
    return $total;
}

/**
 * Estado de un tramo (una fila del GeoPackage): 'done' | 'active' | 'pending' | null.
 *   - Campo `surface` (georesistencia): construction → en ejecución; project/proposed/unpaved → pendiente;
 *     cualquier otra calzada → ejecutado.
 *   - Campo `estado` (viable.ar): Finalizado → ejecutado; En obras/Paralizado → en ejecución; otro → pendiente.
 */
function viable_feature_segment_status($row) {
    $surface = strtolower(trim((string) ($row['surface'] ?? '')));
    if ($surface !== '') {
        if ($surface === 'construction') {
            return 'active';
        }
        if (in_array($surface, ['project', 'proposed', 'unpaved'], true)) {
            return 'pending';
        }
        return 'done';
    }

    $estado = strtolower(remove_accents(trim((string) ($row['estado'] ?? ''))));
    if ($estado !== '') {
        if ($estado === 'finalizado') {
            return 'done';
        }
        if (in_array($estado, ['en obras', 'paralizado'], true)) {
            return 'active';
        }
        return 'pending';
    }

    return null;
}

/** Códigos de proyectos publicados. */
function viable_get_published_project_codes() {
    $ids = get_posts([
        'post_type'      => 'project',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);
    $codes = [];
    foreach ($ids as $id) {
        $code = trim((string) get_field('code', $id));
        if ($code !== '') {
            $codes[$code] = $id;
        }
    }
    return $codes;
}

/* ====================================================================
 * Subida desde Ajustes → Obras
 * ==================================================================== */

add_action('admin_post_viable_upload_data', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos.');
    }
    check_admin_referer('viable_upload_data');

    $back = admin_url('options-general.php?page=viable-settings');
    $fail = function ($msg) use ($back) {
        set_transient('viable_upload_result_' . get_current_user_id(), ['error' => $msg], 120);
        wp_safe_redirect($back);
        exit;
    };

    $file = $_FILES['viable_data_file'] ?? null;
    if (!$file || !empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $code = $file['error'] ?? 0;
        $fail($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'El archivo supera el tamaño máximo de subida del servidor (' . size_format(wp_max_upload_size()) . ').'
            : 'No se recibió el archivo.');
    }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'gpkg') {
        $fail('El archivo tiene que ser un GeoPackage (.gpkg).');
    }

    $info = viable_inspect_gpkg($file['tmp_name']);
    if (is_wp_error($info)) {
        $fail($info->get_error_message());
    }

    $dir = viable_get_data_dir();
    if (!wp_mkdir_p($dir)) {
        $fail('No se pudo crear la carpeta ' . $dir);
    }

    $target = $dir . 'viable.gpkg';
    $temp = $target . '.subiendo';
    if (!move_uploaded_file($file['tmp_name'], $temp)) {
        $fail('No se pudo guardar el archivo en ' . $dir);
    }
    if (file_exists($target)) {
        @copy($target, $target . '.bak');
    }
    if (!@rename($temp, $target)) {
        @unlink($temp);
        $fail('No se pudo reemplazar el archivo actual.');
    }

    set_transient('viable_upload_result_' . get_current_user_id(), [
        'ok' => sprintf('Trazados actualizados: %d geometrías, %d códigos.', $info['features'], count($info['codes'])),
    ], 120);
    wp_safe_redirect($back);
    exit;
});

function viable_render_data_upload_notice() {
    $key = 'viable_upload_result_' . get_current_user_id();
    $result = get_transient($key);
    if (!$result) {
        return;
    }
    delete_transient($key);
    $class = isset($result['ok']) ? 'notice-success' : 'notice-error';
    echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html($result['ok'] ?? $result['error']) . '</p></div>';
}

/** Estado del archivo actual y cruce con los proyectos publicados. */
function viable_render_data_status() {
    $path = viable_get_gpkg_path();

    if (!file_exists($path)) {
        echo '<p><strong>No hay archivo de trazados.</strong> Los mapas no van a mostrar geometrías hasta que subas uno.</p>';
        return;
    }

    $info = viable_inspect_gpkg($path);
    echo '<p><code>' . esc_html(wp_normalize_path($path)) . '</code> · '
        . esc_html(size_format(filesize($path))) . ' · actualizado el '
        . esc_html(wp_date('d/m/Y H:i', filemtime($path))) . '</p>';

    if (is_wp_error($info)) {
        echo '<p style="color:#b32d2e"><strong>Problema:</strong> ' . esc_html($info->get_error_message()) . '</p>';
        return;
    }

    $project_codes = viable_get_published_project_codes();
    $missing = array_diff(array_keys($project_codes), $info['codes']);
    $orphans = array_diff($info['codes'], array_keys($project_codes));

    echo '<ul style="list-style:disc;margin-left:1.5em">';
    echo '<li>Capa <code>' . esc_html($info['table']) . '</code>: ' . (int) $info['features'] . ' geometrías, ' . count($info['codes']) . ' códigos distintos.</li>';
    if ($info['without_code']) {
        echo '<li>' . (int) $info['without_code'] . ' geometrías sin código (no se muestran en ningún mapa).</li>';
    }
    echo '<li>' . count($missing) . ' de ' . count($project_codes) . ' proyectos publicados no tienen trazado'
        . ($missing ? ': ' . esc_html(implode(', ', array_slice($missing, 0, 30))) . (count($missing) > 30 ? '…' : '') : '') . '.</li>';
    if ($orphans) {
        echo '<li>' . count($orphans) . ' códigos del archivo no corresponden a ningún proyecto publicado: '
            . esc_html(implode(', ', array_slice($orphans, 0, 30))) . (count($orphans) > 30 ? '…' : '') . '.</li>';
    }
    echo '</ul>';
}

/**
 * Aviso en wp-admin si los trazados no están en la ubicación nueva.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $path = viable_get_gpkg_path();
    $settings_url = admin_url('options-general.php?page=viable-settings');

    if (!file_exists($path)) {
        $msg = 'No hay archivo de trazados. Subilo desde <a href="' . esc_url($settings_url) . '">Ajustes → Obras</a>.';
    } elseif (wp_normalize_path($path) === wp_normalize_path(VIABLE_PATH . 'assets/data/viable.gpkg')) {
        $msg = 'Los trazados todavía están dentro del plugin. Subilos desde <a href="' . esc_url($settings_url)
            . '">Ajustes → Obras</a> antes de actualizar el plugin con git, o se perderán.';
    } else {
        return;
    }

    echo '<div class="notice notice-warning"><p><strong>Obras:</strong> ' . $msg . '</p></div>';
});
