<?php

add_action('rest_api_init', 'viable_register_geojson_endpoint');

function viable_register_geojson_endpoint() {
    register_rest_route('viable/v1', '/geojson/(?P<code>[a-zA-Z0-9_-]+)', [
        'methods' => 'GET',
        'callback' => 'viable_get_filtered_geojson',
        'permission_callback' => '__return_true',
        'args' => [
            'code' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_string($param) && !empty($param);
                }
            ]
        ]
    ]);
    
    register_rest_route('viable/v1', '/category-projects/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'viable_get_category_projects',
        'permission_callback' => '__return_true',
        'args' => [
            'id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);
}

function viable_get_filtered_geojson($request) {
    $code = $request['code'];
    $gpkg_path = VIABLE_PATH . 'data/viable.gpkg';

    if (!file_exists($gpkg_path)) {
        return new WP_Error('not_found', 'GeoPackage file not found', ['status' => 404]);
    }

    try {
        // Abrir el geopackage como SQLite
        $db = new SQLite3($gpkg_path, SQLITE3_OPEN_READONLY);
        
        // Listar TODAS las tablas disponibles
        $all_tables_result = $db->query("SELECT table_name, data_type FROM gpkg_contents");
        $all_tables = [];
        while ($t = $all_tables_result->fetchArray(SQLITE3_ASSOC)) {
            $all_tables[] = $t['table_name'] . ' (' . $t['data_type'] . ')';
        }
        
        // Obtener el nombre de la tabla de features (usualmente es el primer layer)
        $tables_result = $db->query("SELECT table_name FROM gpkg_contents WHERE data_type = 'features' LIMIT 1");
        $table_row = $tables_result->fetchArray(SQLITE3_ASSOC);
        
        if (!$table_row) {
            $db->close();
            return new WP_Error('no_features', 'No feature tables found. Available tables: ' . implode(', ', $all_tables), ['status' => 404]);
        }
        
        $table_name = $table_row['table_name'];
        
        // DEBUG: Ver todas las columnas de la tabla
        $columns_result = $db->query("PRAGMA table_info({$table_name})");
        $columns = [];
        while ($col = $columns_result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $col['name'];
        }
        
        // DEBUG: Ver todos los valores de code únicos
        $codes_result = $db->query("SELECT DISTINCT code FROM {$table_name} LIMIT 20");
        $existing_codes = [];
        while ($code_row = $codes_result->fetchArray(SQLITE3_ASSOC)) {
            $existing_codes[] = $code_row['code'];
        }
        
        // DEBUG: Contar total de registros
        $count_result = $db->querySingle("SELECT COUNT(*) FROM {$table_name}");
        
        // Consultar features filtrados por code
        $stmt = $db->prepare("SELECT * FROM {$table_name} WHERE code = :code");
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $features = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Obtener la geometría en formato WKB y convertir a GeoJSON
            $geom_wkb = $row['geometry'] ?? $row['geom'] ?? null;
            
            if (!$geom_wkb) {
                continue;
            }
            
            // Decodificar WKB a coordenadas
            $geojson_geom = viable_wkb_to_geojson($geom_wkb);
            
            if (!$geojson_geom) {
                continue;
            }
            
            // Construir las propiedades (excluir la geometría)
            $properties = [];
            foreach ($row as $key => $value) {
                if ($key !== 'geom' && $key !== 'geometry' && $key !== 'fid') {
                    $properties[$key] = $value;
                }
            }
            
            $features[] = [
                'type' => 'Feature',
                'geometry' => $geojson_geom,
                'properties' => $properties
            ];
        }
        
        $db->close();
        
        if (empty($features)) {
            return new WP_Error('no_data', 
                "No features found for code '{$code}'. Debug info: Table='{$table_name}', Total rows={$count_result}, Columns=" . implode(',', $columns) . ", Existing codes=" . implode(',', array_slice($existing_codes, 0, 10)), 
                ['status' => 404]
            );
        }
        
        $geojson = [
            'type' => 'FeatureCollection',
            'features' => $features
        ];
        
        return rest_ensure_response($geojson);
        
    } catch (Exception $e) {
        return new WP_Error('error', $e->getMessage(), ['status' => 500]);
    }
}

function viable_wkb_to_geojson($wkb) {
    // GeoPackage usa un formato especial con header
    // Los primeros bytes son el header de GeoPackage, luego viene el WKB estándar
    
    if (empty($wkb)) {
        return null;
    }
    
    // Leer el header de GeoPackage Binary Format
    // Formato: GP (2 bytes) + version (1 byte) + flags (1 byte) + srid (4 bytes) + envelope (opcional) + WKB
    
    $data = $wkb;
    $offset = 0;
    
    // Verificar magic number "GP"
    if (substr($data, 0, 2) === 'GP') {
        // Es formato GeoPackage, saltar el header
        $version = ord($data[2]);
        $flags = ord($data[3]);
        $offset = 8; // GP (2) + version (1) + flags (1) + srid (4)
        
        // Verificar si hay envelope (depende de los flags)
        $envelope_type = ($flags >> 1) & 0x07;
        if ($envelope_type > 0) {
            // Saltar envelope (puede ser de diferentes tamaños)
            $envelope_sizes = [0, 32, 48, 48, 64];
            if ($envelope_type < count($envelope_sizes)) {
                $offset += $envelope_sizes[$envelope_type];
            }
        }
        
        // Ahora $offset apunta al WKB estándar
        $data = substr($data, $offset);
    }
    
    // Parsear WKB estándar
    return viable_parse_wkb_geometry($data);
}

function viable_parse_wkb_geometry($wkb) {
    if (strlen($wkb) < 5) {
        return null;
    }
    
    $offset = 0;
    
    // Byte order (1 = little endian, 0 = big endian)
    $byte_order = ord($wkb[$offset]);
    $offset += 1;
    
    $little_endian = ($byte_order === 1);
    
    // Tipo de geometría (4 bytes)
    $geom_type = viable_read_uint32($wkb, $offset, $little_endian);
    $offset += 4;
    
    // Enmascarar para obtener el tipo base (ignorar flags Z, M, SRID)
    $base_type = $geom_type & 0xFF;
    
    switch ($base_type) {
        case 2: // LineString
            return viable_parse_linestring($wkb, $offset, $little_endian);
            
        case 5: // MultiLineString
            return viable_parse_multilinestring($wkb, $offset, $little_endian);
            
        default:
            error_log("Tipo de geometría no soportado: $base_type");
            return null;
    }
}

function viable_parse_linestring($wkb, &$offset, $little_endian) {
    $num_points = viable_read_uint32($wkb, $offset, $little_endian);
    $offset += 4;
    
    $coordinates = [];
    for ($i = 0; $i < $num_points; $i++) {
        $x = viable_read_double($wkb, $offset, $little_endian);
        $offset += 8;
        $y = viable_read_double($wkb, $offset, $little_endian);
        $offset += 8;
        $coordinates[] = [$x, $y];
    }
    
    return [
        'type' => 'LineString',
        'coordinates' => $coordinates
    ];
}

function viable_parse_multilinestring($wkb, &$offset, $little_endian) {
    $num_linestrings = viable_read_uint32($wkb, $offset, $little_endian);
    $offset += 4;
    
    $coordinates = [];
    
    for ($i = 0; $i < $num_linestrings; $i++) {
        // Cada LineString tiene su propio byte order y tipo
        $byte_order = ord($wkb[$offset]);
        $offset += 1;
        $ls_little_endian = ($byte_order === 1);
        
        $geom_type = viable_read_uint32($wkb, $offset, $ls_little_endian);
        $offset += 4;
        
        // Leer puntos del LineString
        $num_points = viable_read_uint32($wkb, $offset, $ls_little_endian);
        $offset += 4;
        
        $line_coords = [];
        for ($j = 0; $j < $num_points; $j++) {
            $x = viable_read_double($wkb, $offset, $ls_little_endian);
            $offset += 8;
            $y = viable_read_double($wkb, $offset, $ls_little_endian);
            $offset += 8;
            $line_coords[] = [$x, $y];
        }
        
        $coordinates[] = $line_coords;
    }
    
    return [
        'type' => 'MultiLineString',
        'coordinates' => $coordinates
    ];
}

function viable_read_uint32($data, $offset, $little_endian) {
    $bytes = substr($data, $offset, 4);
    $format = $little_endian ? 'V' : 'N';
    $unpacked = unpack($format, $bytes);
    return $unpacked[1];
}

function viable_read_double($data, $offset, $little_endian) {
    $bytes = substr($data, $offset, 8);
    if (!$little_endian) {
        $bytes = strrev($bytes);
    }
    $unpacked = unpack('d', $bytes);
    return $unpacked[1];
}

function viable_get_category_projects($request) {
    $category_id = $request['id'];
    
    // Obtener todos los proyectos (sin filtrar por categoría primero)
    $args = [
        'post_type' => 'project',
        'post_status' => 'publish',
        'posts_per_page' => -1
    ];
    
    $projects = new WP_Query($args);
    
    if (!$projects->have_posts()) {
        return [
            'type' => 'FeatureCollection',
            'features' => [],
            'debug' => 'No projects found at all'
        ];
    }
    
    $gpkg_path = VIABLE_PATH . 'assets/data/viable.gpkg';
    
    if (!file_exists($gpkg_path)) {
        return new WP_Error('not_found', 'GeoPackage file not found', ['status' => 404]);
    }
    
    $db = new SQLite3($gpkg_path, SQLITE3_OPEN_READONLY);
    
    // Obtener nombre de la tabla
    $tables_result = $db->query("SELECT table_name FROM gpkg_contents WHERE data_type = 'features' LIMIT 1");
    $table_row = $tables_result->fetchArray(SQLITE3_ASSOC);
    
    if (!$table_row) {
        $db->close();
        return new WP_Error('no_features', 'No feature tables found', ['status' => 404]);
    }
    
    $table_name = $table_row['table_name'];
    $features = [];
    $debug_info = [];
    
    while ($projects->have_posts()) {
        $projects->the_post();
        $pid = get_the_ID();
        $code = get_field('code', $pid);
        $regions = get_field('regions', $pid);
        
        $debug_info[] = [
            'pid' => $pid,
            'title' => get_the_title($pid),
            'code' => $code,
            'regions' => $regions
        ];
        
        // Filtrar por categoría
        if ($regions) {
            $region_ids = is_array($regions) ? $regions : [$regions];
            $region_ids = array_map(function($r) {
                return is_object($r) ? $r->term_id : (is_array($r) ? $r['term_id'] : $r);
            }, $region_ids);
            
            if (!in_array($category_id, $region_ids)) {
                continue; // Skip projects not in this category
            }
        } else {
            continue; // Skip projects without regions
        }
        
        if (!$code) {
            continue; // Skip projects without code
        }
        
        // Buscar geometría en GeoPackage
        $stmt = $db->prepare("SELECT * FROM {$table_name} WHERE code = :code");
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $geometry_blob = $row['geometry'];
            $geometry = viable_wkb_to_geojson($geometry_blob);
            
            if ($geometry) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'code' => $code,
                        'name' => get_the_title($pid),
                        'url' => get_permalink($pid),
                        'state' => get_field('state', $pid),
                        'type' => get_field('type', $pid),
                        'tramo' => $row['tramo'] ?? '',
                        'estado' => $row['estado'] ?? '',
                        'tipo' => $row['tipo'] ?? ''
                    ],
                    'geometry' => $geometry
                ];
            }
        }
    }
    
    wp_reset_postdata();
    $db->close();
    
    return [
        'type' => 'FeatureCollection',
        'features' => $features,
        'debug' => $debug_info
    ];
}

