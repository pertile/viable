<?php
/**
 * Planos georreferenciados por proyecto (archivos .pmtiles).
 *
 * Cada proyecto puede tener un archivo .pmtiles en el campo ACF "overlay_tiles".
 * El navegador lee las teselas directamente del archivo mediante peticiones HTTP
 * Range, así que no hace falta descomprimir nada en el servidor.
 *
 * Para generar el archivo a partir de una carpeta/zip de teselas XYZ:
 *   python tools/tiles-to-pmtiles.py teselas.zip -o proyecto.pmtiles
 */

/**
 * Registra el JS común de capas (fondos Mapa/Satélite + planos PMTiles).
 * Lo usan viable-map.js y viable-map-universal.js como dependencia.
 */
function viable_register_map_layers_script() {
    if (wp_script_is('viable-map-layers', 'registered')) {
        return;
    }

    wp_register_script(
        'pmtiles',
        'https://unpkg.com/pmtiles@4.4.1/dist/pmtiles.js',
        [],
        null,
        true
    );

    wp_register_script(
        'viable-map-layers',
        VIABLE_URL . 'viable-map-layers.js',
        ['leaflet', 'pmtiles'],
        file_exists(VIABLE_PATH . 'viable-map-layers.js') ? filemtime(VIABLE_PATH . 'viable-map-layers.js') : null,
        true
    );

    wp_add_inline_script(
        'viable-map-layers',
        'window.ViableMapConfig = ' . wp_json_encode(viable_map_client_config()) . ';',
        'before'
    );
}

/**
 * Permitir subir .pmtiles a la biblioteca de medios.
 */
add_filter('upload_mimes', function ($mimes) {
    $mimes['pmtiles'] = 'application/octet-stream';
    return $mimes;
});

/**
 * WordPress valida el contenido real del archivo; un .pmtiles se detecta como
 * binario genérico. Lo aceptamos solo si empieza con la firma "PMTiles".
 */
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename) {
    if (strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION)) !== 'pmtiles') {
        return $data;
    }

    $fh = @fopen($file, 'rb');
    $magic = $fh ? fread($fh, 7) : '';
    if ($fh) {
        fclose($fh);
    }

    if ($magic === 'PMTiles') {
        $data['ext'] = 'pmtiles';
        $data['type'] = 'application/octet-stream';
        $data['proper_filename'] = false;
    }

    return $data;
}, 10, 3);

/**
 * Lee el encabezado (127 bytes) y los metadatos de un archivo PMTiles v3.
 *
 * @return array|null ['min_zoom','max_zoom','bounds','tile_type','metadata']
 */
function viable_pmtiles_read_info($path) {
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    $cache[$path] = null;

    if (!$path || !is_readable($path)) {
        return null;
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        return null;
    }
    $hdr = fread($fh, 127);

    if (strlen($hdr) < 127 || substr($hdr, 0, 7) !== 'PMTiles' || ord($hdr[7]) !== 3) {
        fclose($fh);
        return null;
    }

    $u64 = function ($o) use ($hdr) {
        return unpack('P', substr($hdr, $o, 8))[1];
    };
    $i32 = function ($o) use ($hdr) {
        $v = unpack('V', substr($hdr, $o, 4))[1];
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    };

    $metadata = [];
    $meta_offset = $u64(24);
    $meta_length = $u64(32);
    if ($meta_length > 0 && $meta_length < 1048576) {
        fseek($fh, $meta_offset);
        $raw = fread($fh, $meta_length);
        // Compresión interna: 1 = ninguna, 2 = gzip
        if (ord($hdr[97]) === 2) {
            $raw = @gzdecode($raw);
        }
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    fclose($fh);

    $cache[$path] = [
        'tile_type' => ord($hdr[99]),
        'min_zoom'  => ord($hdr[100]),
        'max_zoom'  => ord($hdr[101]),
        // Formato Leaflet: [[sur, oeste], [norte, este]]
        'bounds'    => [
            [$i32(106) / 1e7, $i32(102) / 1e7],
            [$i32(114) / 1e7, $i32(110) / 1e7],
        ],
        'metadata'  => $metadata,
    ];

    return $cache[$path];
}

/**
 * Datos de la capa de planos de un proyecto, listos para el JS del mapa.
 *
 * @return array|null
 */
function viable_get_project_overlay($pid) {
    if (!function_exists('get_field')) {
        return null;
    }

    $attachment_id = (int) get_field('overlay_tiles', $pid, false);
    if (!$attachment_id) {
        return null;
    }

    $info = viable_pmtiles_read_info(get_attached_file($attachment_id));
    $url = wp_get_attachment_url($attachment_id);
    if (!$info || !$url) {
        return null;
    }

    $meta = $info['metadata'];
    $opacity = isset($meta['opacity']) && is_numeric($meta['opacity'])
        ? max(0, min(1, (float) $meta['opacity']))
        : 0.6;

    return [
        'code'        => (string) get_field('code', $pid),
        'name'        => get_the_title($pid),
        'url'         => $url,
        'bounds'      => $info['bounds'],
        'min_zoom'    => $info['min_zoom'],
        'max_zoom'    => $info['max_zoom'],
        'tile_type'   => $info['tile_type'],
        'attribution' => isset($meta['attribution']) ? wp_kses($meta['attribution'], ['a' => ['href' => []]]) : '',
        'opacity'     => $opacity,
    ];
}

/* ====================================================================
 * Endpoint de teselas sueltas (para JOSM, iD, QGIS, etc.)
 *
 *   GET /wp-json/viable/v1/tiles/{code}/{z}/{x}/{y}.png
 *
 * Lee la tesela pedida del archivo .pmtiles del proyecto y la devuelve como
 * imagen. Esquema XYZ (y desde arriba), igual que OpenStreetMap.
 * En JOSM:  tms[19]:https://sitio/wp-json/viable/v1/tiles/H13-3/{zoom}/{x}/{y}.png
 * ==================================================================== */

add_action('rest_api_init', function () {
    register_rest_route(
        'viable/v1',
        '/tiles/(?P<code>[a-zA-Z0-9_-]+)/(?P<z>\d{1,2})/(?P<x>\d{1,7})/(?P<y>\d{1,7})(?:\.(?:png|jpe?g|webp|avif))?',
        [
            'methods'             => 'GET',
            'callback'            => 'viable_serve_overlay_tile',
            'permission_callback' => '__return_true',
        ]
    );
});

/**
 * URL base del endpoint para un proyecto (sin el sufijo {z}/{x}/{y}.png).
 */
function viable_overlay_tiles_base_url($code) {
    return rest_url('viable/v1/tiles/' . rawurlencode($code));
}

/**
 * ID de proyecto publicado a partir de su código.
 */
function viable_find_published_project_by_code($code) {
    $ids = get_posts([
        'post_type'      => 'project',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'     => 'code',
            'value'   => $code,
            'compare' => '=',
        ]],
    ]);
    return $ids ? (int) $ids[0] : 0;
}

/**
 * ID de tesela PMTiles (curva de Hilbert) para z/x/y.
 * Réplica de zxy_to_tileid de la librería de referencia.
 */
function viable_pmtiles_zxy_to_tileid($z, $x, $y) {
    $acc = intdiv((1 << ($z * 2)) - 1, 3);
    for ($a = $z - 1; $a >= 0; $a--) {
        $s = 1 << $a;
        $rx = $s & $x;
        $ry = $s & $y;
        $acc += ((3 * $rx) ^ $ry) << $a;
        if ($ry === 0) {
            if ($rx !== 0) {
                $x = $s - 1 - $x;
                $y = $s - 1 - $y;
            }
            $t = $x;
            $x = $y;
            $y = $t;
        }
    }
    return $acc;
}

function viable_pmtiles_read_varint($buf, &$pos) {
    $result = 0;
    $shift = 0;
    $len = strlen($buf);
    while ($pos < $len && $shift <= 63) {
        $b = ord($buf[$pos++]);
        $result |= ($b & 0x7f) << $shift;
        if ($b < 0x80) {
            return $result;
        }
        $shift += 7;
    }
    throw new RuntimeException('Directorio PMTiles corrupto');
}

/**
 * Decodifica un directorio PMTiles en cuatro arreglos paralelos.
 */
function viable_pmtiles_parse_directory($buf) {
    $pos = 0;
    $n = viable_pmtiles_read_varint($buf, $pos);
    $ids = $runs = $lens = $offs = [];

    $last = 0;
    for ($i = 0; $i < $n; $i++) {
        $last += viable_pmtiles_read_varint($buf, $pos);
        $ids[$i] = $last;
    }
    for ($i = 0; $i < $n; $i++) {
        $runs[$i] = viable_pmtiles_read_varint($buf, $pos);
    }
    for ($i = 0; $i < $n; $i++) {
        $lens[$i] = viable_pmtiles_read_varint($buf, $pos);
    }
    for ($i = 0; $i < $n; $i++) {
        $v = viable_pmtiles_read_varint($buf, $pos);
        $offs[$i] = ($i > 0 && $v === 0) ? $offs[$i - 1] + $lens[$i - 1] : $v - 1;
    }

    return [$ids, $runs, $lens, $offs];
}

/**
 * Busca una tesela en un directorio. Devuelve [offset, length, run_length]
 * (run_length 0 = apunta a un directorio hoja) o null.
 */
function viable_pmtiles_find($dir, $tile_id) {
    list($ids, $runs, $lens, $offs) = $dir;
    $lo = 0;
    $hi = count($ids) - 1;
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($tile_id > $ids[$mid]) {
            $lo = $mid + 1;
        } elseif ($tile_id < $ids[$mid]) {
            $hi = $mid - 1;
        } else {
            return [$offs[$mid], $lens[$mid], $runs[$mid]];
        }
    }
    if ($hi >= 0) {
        if ($runs[$hi] === 0 || $tile_id - $ids[$hi] < $runs[$hi]) {
            return [$offs[$hi], $lens[$hi], $runs[$hi]];
        }
    }
    return null;
}

/**
 * Devuelve los bytes de la tesela z/x/y de un archivo .pmtiles, o null si no existe.
 */
function viable_pmtiles_get_tile($path, $z, $x, $y) {
    if ($z > 30 || $x >= (1 << $z) || $y >= (1 << $z)) {
        return null;
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return null;
    }

    try {
        $hdr = fread($fh, 127);
        if (strlen($hdr) < 127 || substr($hdr, 0, 7) !== 'PMTiles' || ord($hdr[7]) !== 3) {
            return null;
        }

        $u64 = function ($o) use ($hdr) {
            return unpack('P', substr($hdr, $o, 8))[1];
        };
        $internal_compression = ord($hdr[97]);
        $tile_compression = ord($hdr[98]);
        $leaf_offset = $u64(40);
        $data_offset = $u64(56);

        $dir_offset = $u64(8);
        $dir_length = $u64(16);
        $tile_id = viable_pmtiles_zxy_to_tileid($z, $x, $y);

        for ($depth = 0; $depth < 4; $depth++) {
            fseek($fh, $dir_offset);
            $raw = $dir_length > 0 ? fread($fh, $dir_length) : '';
            if ($internal_compression === 2) {
                $raw = @gzdecode($raw);
            } elseif ($internal_compression !== 1 && $internal_compression !== 0) {
                return null; // compresión no soportada (brotli/zstd)
            }
            if ($raw === false || $raw === '') {
                return null;
            }

            $entry = viable_pmtiles_find(viable_pmtiles_parse_directory($raw), $tile_id);
            if (!$entry) {
                return null;
            }

            if ($entry[2] > 0) {
                fseek($fh, $data_offset + $entry[0]);
                $data = $entry[1] > 0 ? fread($fh, $entry[1]) : '';
                if ($tile_compression === 2) {
                    $data = @gzdecode($data);
                }
                return ($data === false || $data === '') ? null : $data;
            }

            // Directorio hoja
            $dir_offset = $leaf_offset + $entry[0];
            $dir_length = $entry[1];
        }
        return null;
    } catch (RuntimeException $e) {
        return null;
    } finally {
        fclose($fh);
    }
}

/**
 * Callback del endpoint: envía la imagen directamente (no JSON).
 */
function viable_serve_overlay_tile($request) {
    $code = (string) $request['code'];
    $z = (int) $request['z'];
    $x = (int) $request['x'];
    $y = (int) $request['y'];

    $pid = viable_find_published_project_by_code($code);
    $attachment_id = $pid ? (int) get_field('overlay_tiles', $pid, false) : 0;
    $path = $attachment_id ? get_attached_file($attachment_id) : '';
    $info = $path ? viable_pmtiles_read_info($path) : null;

    if (!$info) {
        return new WP_Error('viable_no_overlay', 'El proyecto no tiene planos cargados.', ['status' => 404]);
    }

    $mtime = (int) @filemtime($path);
    $etag = '"' . md5($attachment_id . '-' . $mtime . '-' . $z . '/' . $x . '/' . $y) . '"';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=86400');
    header('ETag: ' . $etag);

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        status_header(304);
        exit;
    }

    $data = viable_pmtiles_get_tile($path, $z, $x, $y);
    if ($data === null) {
        // Fuera de la zona de los planos: los clientes de mapas lo toman como "sin tesela"
        status_header(404);
        exit;
    }

    $mimes = [2 => 'image/png', 3 => 'image/jpeg', 4 => 'image/webp', 5 => 'image/avif'];
    status_header(200);
    header('Content-Type: ' . ($mimes[$info['tile_type']] ?? 'application/octet-stream'));
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

/**
 * En wp-admin, mostrar bajo el campo "Planos" la URL lista para JOSM.
 */
add_filter('acf/prepare_field/name=overlay_tiles', function ($field) {
    global $post;
    if (!$post || $post->post_type !== 'project') {
        return $field;
    }

    $code = (string) get_field('code', $post->ID);
    $attachment_id = (int) get_field('overlay_tiles', $post->ID, false);
    $info = $attachment_id ? viable_pmtiles_read_info(get_attached_file($attachment_id)) : null;
    if ($code === '' || !$info) {
        return $field;
    }

    $josm = 'tms[' . (int) $info['max_zoom'] . ']:' . viable_overlay_tiles_base_url($code) . '/{zoom}/{x}/{y}.png';
    $field['instructions'] .= '<br><strong>URL para JOSM</strong> (Imágenes → Preferencias de imágenes → + TMS):'
        . '<br><code style="user-select:all;word-break:break-all">' . esc_html($josm) . '</code>';
    if (get_post_status($post->ID) !== 'publish') {
        $field['instructions'] .= '<br><em>Disponible cuando el proyecto esté publicado.</em>';
    }
    return $field;
});
