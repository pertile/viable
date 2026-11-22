<?php
/**
 * Ajusta los campos ACF del proyecto según la configuración del sitio (Ajustes → Obras).
 * Los campos se identifican por su clave para no afectar a otros grupos.
 */

/** Campos nuevos: Financiamiento y Calzada (sus opciones vienen de los ajustes). */
add_action('acf/include_fields', function () {
    if (!function_exists('acf_add_local_field')) {
        return;
    }

    foreach ([
        ['field_viable_funding', 'Financiamiento', 'funding'],
        ['field_viable_surface', 'Calzada', 'surface'],
    ] as [$key, $label, $name]) {
        acf_add_local_field([
            'key'           => $key,
            'label'         => $label,
            'name'          => $name,
            'type'          => 'select',
            'parent'        => 'group_697e05025e85d',
            'choices'       => [],
            'allow_null'    => 1,
            'multiple'      => 0,
            'ui'            => 0,
            'return_format' => 'value',
            'required'      => 0,
        ]);
    }
}, 20);

/** Ubicar Financiamiento y Calzada después de Longitud en el formulario. */
add_filter('acf/load_fields', function ($fields, $parent) {
    if (($parent['key'] ?? '') !== 'group_697e05025e85d') {
        return $fields;
    }
    $moved = [];
    $rest = [];
    foreach ($fields as $f) {
        if (in_array($f['key'] ?? '', ['field_viable_funding', 'field_viable_surface'], true)) {
            $moved[] = $f;
        } else {
            $rest[] = $f;
        }
    }
    if (!$moved) {
        return $fields;
    }
    $out = [];
    foreach ($rest as $f) {
        $out[] = $f;
        if (($f['key'] ?? '') === 'field_697e08d0309f8') {
            array_push($out, ...$moved);
            $moved = [];
        }
    }
    return array_merge($out, $moved); // si no se encontró Longitud, quedan al final
}, 10, 2);

/** Arma choices de un select a partir de una lista. */
function viable_acf_choices($list) {
    return $list ? array_combine($list, $list) : [];
}

/**
 * En la pantalla de edición, si el valor guardado ya no está en la lista
 * (por ejemplo, se quitó un tipo en los ajustes), se agrega para no perderlo al guardar.
 */
function viable_acf_keep_current_value($field) {
    $value = $field['value'] ?? '';
    if (is_string($value) && $value !== '' && !isset($field['choices'][$value])) {
        $field['choices'][$value] = $value . ' (ya no está en la lista)';
    }
    return $field;
}

// Tipo de obra
add_filter('acf/load_field/key=field_697e075f309f2', function ($field) {
    $types = viable_get_project_types();
    $field['choices'] = viable_acf_choices($types);
    if (!in_array($field['default_value'], $types, true)) {
        $field['default_value'] = $types[0] ?? '';
    }
    return $field;
});
add_filter('acf/prepare_field/key=field_697e075f309f2', 'viable_acf_keep_current_value');

// Estado (sin "En licitación"/"Adjudicado" si el sitio no usa licitación)
add_filter('acf/load_field/key=field_697e0812309f5', function ($field) {
    $field['choices'] = viable_acf_choices(viable_get_states());
    return $field;
});
add_filter('acf/prepare_field/key=field_697e0812309f5', 'viable_acf_keep_current_value');

// Rutas / Calles: nombre y obligatoriedad
add_filter('acf/load_field/key=field_697e09da309fd', function ($field) {
    $field['label'] = viable_setting('roads_label');
    $field['required'] = viable_setting('roads_required') ? 1 : 0;
    return $field;
});

// Regiones / Municipios: nombre
add_filter('acf/load_field/key=field_697e0b20309fe', function ($field) {
    $field['label'] = viable_setting('regions_label');
    return $field;
});

// Longitud: unidad
add_filter('acf/load_field/key=field_697e08d0309f8', function ($field) {
    $field['label'] = 'Longitud (' . viable_get_length_unit() . ')';
    return $field;
});

// Licitación: fechas y pliegos se ocultan si el sitio no la usa (los datos se conservan)
foreach (['field_697e0931309fb', 'field_697e0961309fc', 'field_697e0800309f4'] as $viable_tender_key) {
    add_filter('acf/prepare_field/key=' . $viable_tender_key, function ($field) {
        return viable_uses_tender() ? $field : false;
    });
}
unset($viable_tender_key);

// Financiamiento y Calzada: opciones desde los ajustes; sin opciones, el campo no se muestra
add_filter('acf/load_field/key=field_viable_funding', function ($field) {
    $field['choices'] = viable_acf_choices(viable_get_funding_options());
    return $field;
});
add_filter('acf/load_field/key=field_viable_surface', function ($field) {
    $field['choices'] = viable_acf_choices(viable_get_surface_options());
    return $field;
});
foreach (['field_viable_funding' => 'viable_get_funding_options', 'field_viable_surface' => 'viable_get_surface_options'] as $viable_key => $viable_options_fn) {
    add_filter('acf/prepare_field/key=' . $viable_key, function ($field) use ($viable_options_fn) {
        if (!call_user_func($viable_options_fn) && empty($field['value'])) {
            return false;
        }
        return viable_acf_keep_current_value($field);
    });
}
unset($viable_key, $viable_options_fn);
