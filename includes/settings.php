<?php
/**
 * Configuración por sitio (Ajustes → Obras).
 *
 * El mismo código del plugin corre en varios sitios; lo que cambia entre ellos
 * (tipos de obra, si hay licitación, nombres de campos, unidad de longitud…)
 * se guarda en la opción `viable_settings` de cada WordPress.
 */

function viable_settings_defaults() {
    return [
        'project_types'   => "Pavimentación\nApertura\nDuplicación\nColectoras\nAmpliación carriles\nEnsanche\nPuente\nRotonda\nTúnel",
        'use_tender'      => 1,
        'roads_label'     => 'Rutas',
        'roads_required'  => 1,
        'regions_label'   => 'Regiones',
        'region_term_ids' => [], // vacío = subcategorías de "Regiones"
        'length_unit'     => 'km',
        'funding_options' => '',
        'surface_options' => '',
        'category_map'    => 1, // mapa y listados en las páginas de categoría
        'mapbox_token'    => '',
        'mapbox_style'    => '', // mapbox://styles/usuario/estilo
        'mapbox_label'    => 'Mapbox',
        'default_base'    => 'Mapa', // Mapa | Satélite | Mapbox
    ];
}

function viable_settings() {
    $saved = get_option('viable_settings', []);
    return array_merge(viable_settings_defaults(), is_array($saved) ? $saved : []);
}

function viable_setting($key) {
    $settings = viable_settings();
    return $settings[$key] ?? null;
}

/** Convierte un texto de "uno por línea" en una lista sin vacíos ni repetidos. */
function viable_lines_to_list($text) {
    $items = array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text));
    return array_values(array_unique(array_filter($items, 'strlen')));
}

function viable_get_project_types() {
    return viable_lines_to_list(viable_setting('project_types'));
}

function viable_get_funding_options() {
    return viable_lines_to_list(viable_setting('funding_options'));
}

function viable_get_surface_options() {
    return viable_lines_to_list(viable_setting('surface_options'));
}

function viable_uses_tender() {
    return (bool) viable_setting('use_tender');
}

/** Estados disponibles para elegir en el proyecto. */
function viable_get_states() {
    $states = ['Proyecto', 'En licitación', 'Adjudicado', 'En obras', 'Paralizado', 'Finalizado', 'Cancelado'];
    if (!viable_uses_tender()) {
        $states = array_values(array_diff($states, ['En licitación', 'Adjudicado']));
    }
    return $states;
}

/** Secuencia que se dibuja en la ficha (en minúsculas, sin Paralizado ni Cancelado). */
function viable_get_state_sequence() {
    $sequence = ['proyecto', 'en licitación', 'adjudicado', 'en obras', 'finalizado'];
    if (!viable_uses_tender()) {
        $sequence = array_values(array_diff($sequence, ['en licitación', 'adjudicado']));
    }
    return $sequence;
}

function viable_get_length_unit() {
    return viable_setting('length_unit') === 'm' ? 'm' : 'km';
}

/** "12,5 km" / "300 m" */
function viable_format_length($length) {
    if ($length === null || $length === '') {
        return '';
    }
    return $length . ' ' . viable_get_length_unit();
}

/**
 * Acepta "mapbox://styles/usuario/estilo", la URL de Mapbox Studio o "usuario/estilo".
 * Devuelve ['user' => …, 'id' => …] o null.
 */
function viable_parse_mapbox_style($style) {
    $style = trim((string) $style);
    if (preg_match('#^mapbox://styles/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)#', $style, $m)
        || preg_match('#api\.mapbox\.com/styles/v1/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)#', $style, $m)
        || preg_match('#studio\.mapbox\.com/styles/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)#', $style, $m)
        || preg_match('#^([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)$#', $style, $m)) {
        return ['user' => $m[1], 'id' => $m[2]];
    }
    return null;
}

/** Configuración de fondos para el JS de los mapas. */
function viable_map_client_config() {
    $mapbox = null;
    $style = viable_parse_mapbox_style(viable_setting('mapbox_style'));
    if ($style && viable_setting('mapbox_token')) {
        $mapbox = [
            'token' => viable_setting('mapbox_token'),
            'user'  => $style['user'],
            'style' => $style['id'],
            'label' => viable_setting('mapbox_label'),
        ];
    }
    $default = viable_setting('default_base');
    if ($default === 'Mapbox') {
        $default = $mapbox ? $mapbox['label'] : 'Mapa';
    }
    return ['mapbox' => $mapbox, 'defaultBase' => $default];
}

/* ====================================================================
 * Página de ajustes
 * ==================================================================== */

add_action('admin_init', function () {
    register_setting('viable_settings_group', 'viable_settings', [
        'type'              => 'array',
        'sanitize_callback' => 'viable_sanitize_settings',
    ]);
});

function viable_sanitize_settings($input) {
    $input = is_array($input) ? $input : [];
    // Partir de lo guardado para no perder claves que no vengan en el formulario
    $out = viable_settings();

    foreach (['project_types', 'funding_options', 'surface_options'] as $key) {
        if (isset($input[$key])) {
            $out[$key] = implode("\n", viable_lines_to_list(sanitize_textarea_field($input[$key])));
        }
    }
    foreach (['roads_label', 'regions_label'] as $key) {
        if (isset($input[$key])) {
            $value = sanitize_text_field($input[$key]);
            $out[$key] = $value !== '' ? $value : viable_settings_defaults()[$key];
        }
    }
    $ids = isset($input['region_term_ids']) && is_array($input['region_term_ids']) ? $input['region_term_ids'] : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out['region_term_ids'] = array_values(array_filter($ids, function ($id) {
        $term = get_term($id, 'category');
        return $term && !is_wp_error($term);
    }));

    $out['use_tender'] = empty($input['use_tender']) ? 0 : 1;
    $out['roads_required'] = empty($input['roads_required']) ? 0 : 1;
    $out['category_map'] = empty($input['category_map']) ? 0 : 1;

    $out['mapbox_token'] = isset($input['mapbox_token']) ? preg_replace('/[^A-Za-z0-9._-]/', '', $input['mapbox_token']) : $out['mapbox_token'];
    $out['mapbox_style'] = isset($input['mapbox_style']) ? sanitize_text_field($input['mapbox_style']) : $out['mapbox_style'];
    if ($out['mapbox_style'] !== '' && !viable_parse_mapbox_style($out['mapbox_style'])) {
        add_settings_error('viable_settings', 'viable_mapbox_style', 'El estilo de Mapbox no tiene el formato esperado (mapbox://styles/usuario/estilo).');
    }
    if ($out['mapbox_token'] !== '' && strpos($out['mapbox_token'], 'pk.') !== 0) {
        add_settings_error('viable_settings', 'viable_mapbox_token', 'El token de Mapbox tiene que ser público (empieza con "pk."). Los tokens secretos ("sk.") no deben usarse en una página web.');
        $out['mapbox_token'] = '';
    }
    if (isset($input['mapbox_label'])) {
        $label = sanitize_text_field($input['mapbox_label']);
        $out['mapbox_label'] = $label !== '' ? $label : 'Mapbox';
    }
    $out['default_base'] = in_array($input['default_base'] ?? '', ['Mapa', 'Satélite', 'Mapbox'], true) ? $input['default_base'] : 'Mapa';
    $out['length_unit'] = (isset($input['length_unit']) && $input['length_unit'] === 'm') ? 'm' : 'km';

    if (empty(viable_lines_to_list($out['project_types']))) {
        $out['project_types'] = viable_settings_defaults()['project_types'];
        add_settings_error('viable_settings', 'viable_types_empty', 'La lista de tipos no puede quedar vacía; se restauró la lista por defecto.');
    }

    return $out;
}

add_action('admin_menu', function () {
    add_options_page('Obras', 'Obras', 'manage_options', 'viable-settings', 'viable_render_settings_page');
});

function viable_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $s = viable_settings();
    $name = function ($key) {
        return 'viable_settings[' . $key . ']';
    };
    ?>
    <div class="wrap">
        <h1>Obras</h1>

        <?php viable_render_data_upload_notice(); ?>

        <h2>Archivo de trazados</h2>
        <?php viable_render_data_status(); ?>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="viable_upload_data">
            <?php wp_nonce_field('viable_upload_data'); ?>
            <p>
                <input type="file" name="viable_data_file" accept=".gpkg" required>
                <?php submit_button('Subir y reemplazar', 'secondary', 'submit', false); ?>
            </p>
            <p class="description">
                GeoPackage (<code>.gpkg</code>) con un campo <code>code</code> que coincida con el ID de cada proyecto.
                Se valida antes de reemplazar al actual, y el anterior queda como respaldo (<code>viable.gpkg.bak</code>).
                Tamaño máximo en este servidor: <?= esc_html(size_format(wp_max_upload_size())) ?>.
            </p>
        </form>

        <hr>

        <form method="post" action="options.php">
            <?php settings_fields('viable_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="viable-types">Tipos de obra</label></th>
                    <td>
                        <textarea id="viable-types" name="<?= esc_attr($name('project_types')) ?>" rows="8" class="regular-text"><?= esc_textarea($s['project_types']) ?></textarea>
                        <p class="description">Uno por línea. Si existe "Duplicación", se habilita el campo "Tipo de duplicación".</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Licitación</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?= esc_attr($name('use_tender')) ?>" value="1" <?php checked($s['use_tender'], 1); ?>>
                            Las obras pasan por licitación y adjudicación
                        </label>
                        <p class="description">Si se desactiva, se ocultan los estados "En licitación" y "Adjudicado", las fechas de licitación y adjudicación, y los pliegos. Los datos ya cargados no se borran.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viable-roads-label">Nombre del campo de vías</label></th>
                    <td>
                        <input id="viable-roads-label" type="text" class="regular-text" name="<?= esc_attr($name('roads_label')) ?>" value="<?= esc_attr($s['roads_label']) ?>">
                        <p><label>
                            <input type="checkbox" name="<?= esc_attr($name('roads_required')) ?>" value="1" <?php checked($s['roads_required'], 1); ?>>
                            Obligatorio
                        </label></p>
                        <p class="description">Por ejemplo "Rutas" o "Calles". Usa las etiquetas de WordPress.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viable-regions-label">Nombre del campo de regiones</label></th>
                    <td>
                        <input id="viable-regions-label" type="text" class="regular-text" name="<?= esc_attr($name('regions_label')) ?>" value="<?= esc_attr($s['regions_label']) ?>">
                        <p class="description">Por ejemplo "Regiones" o "Municipios".</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Categorías de regiones</th>
                    <td>
                        <?php
                        $selected = array_map('intval', (array) $s['region_term_ids']);
                        $all_categories = get_categories(['hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
                        ?>
                        <fieldset style="max-height:260px;overflow:auto;border:1px solid #dcdcde;padding:8px 12px;background:#fff;max-width:420px">
                            <?php foreach ($all_categories as $cat): ?>
                                <label style="display:block">
                                    <input type="checkbox" name="<?= esc_attr($name('region_term_ids')) ?>[]" value="<?= (int) $cat->term_id ?>" <?php checked(in_array((int) $cat->term_id, $selected, true)); ?>>
                                    <?= esc_html($cat->name) ?>
                                    <?php if ($cat->parent): ?><span class="description">(en <?= esc_html(get_cat_name($cat->parent)) ?>)</span><?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">Las categorías que se pueden elegir como región de un proyecto. Si no marcás ninguna, se usan las subcategorías de "Regiones".</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viable-mapbox-token">Mapbox</label></th>
                    <td>
                        <p><input id="viable-mapbox-token" type="text" class="large-text code" name="<?= esc_attr($name('mapbox_token')) ?>" value="<?= esc_attr($s['mapbox_token']) ?>" placeholder="pk.…" autocomplete="off"></p>
                        <p><input type="text" class="large-text code" name="<?= esc_attr($name('mapbox_style')) ?>" value="<?= esc_attr($s['mapbox_style']) ?>" placeholder="mapbox://styles/usuario/estilo"></p>
                        <p><input type="text" class="regular-text" name="<?= esc_attr($name('mapbox_label')) ?>" value="<?= esc_attr($s['mapbox_label']) ?>" placeholder="Nombre en el selector de fondos"></p>
                        <p class="description">
                            Token público (<code>pk.…</code>) y estilo de Mapbox Studio. Con los dos cargados, el estilo aparece como fondo en el selector.
                            El token queda visible en el código de la página: en Mapbox, restringilo a las URL de este sitio.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viable-default-base">Fondo por defecto</label></th>
                    <td>
                        <select id="viable-default-base" name="<?= esc_attr($name('default_base')) ?>">
                            <?php foreach (['Mapa' => 'Mapa (OpenStreetMap)', 'Satélite' => 'Satélite (Esri)', 'Mapbox' => 'Mapbox (estilo propio)'] as $value => $label): ?>
                                <option value="<?= esc_attr($value) ?>" <?php selected($s['default_base'], $value); ?>><?= esc_html($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Si cada visitante elige otro fondo, se le recuerda su elección. Los proyectos con planos arrancan en Satélite.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Páginas de categoría</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?= esc_attr($name('category_map')) ?>" value="1" <?php checked($s['category_map'], 1); ?>>
                            Mostrar el mapa de obras y los listados en las páginas de categoría
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Unidad de longitud</th>
                    <td>
                        <label><input type="radio" name="<?= esc_attr($name('length_unit')) ?>" value="km" <?php checked($s['length_unit'], 'km'); ?>> Kilómetros</label>
                        &nbsp;
                        <label><input type="radio" name="<?= esc_attr($name('length_unit')) ?>" value="m" <?php checked($s['length_unit'], 'm'); ?>> Metros</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viable-funding">Financiamiento</label></th>
                    <td>
                        <textarea id="viable-funding" name="<?= esc_attr($name('funding_options')) ?>" rows="5" class="regular-text"><?= esc_textarea($s['funding_options']) ?></textarea>
                        <p class="description">Opciones, una por línea (por ejemplo Nación, Provincia, Municipio). Vacío = el campo no se muestra.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viable-surface">Calzada</label></th>
                    <td>
                        <textarea id="viable-surface" name="<?= esc_attr($name('surface_options')) ?>" rows="5" class="regular-text"><?= esc_textarea($s['surface_options']) ?></textarea>
                        <p class="description">Tipos de calzada, uno por línea (por ejemplo Hormigón, Asfalto, Adoquín). Vacío = el campo no se muestra.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/* ====================================================================
 * Endpoint público con la configuración (para el panel client/)
 * ==================================================================== */

add_action('rest_api_init', function () {
    register_rest_route('viable/v1', '/config', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return rest_ensure_response([
                'project_types'   => viable_get_project_types(),
                'states'          => viable_get_states(),
                'use_tender'      => viable_uses_tender(),
                'roads_label'     => viable_setting('roads_label'),
                'roads_required'  => (bool) viable_setting('roads_required'),
                'regions_label'   => viable_setting('regions_label'),
                'length_unit'     => viable_get_length_unit(),
                'funding_options' => viable_get_funding_options(),
                'surface_options' => viable_get_surface_options(),
            ]);
        },
    ]);
});
