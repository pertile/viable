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
