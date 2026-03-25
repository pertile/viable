<?php

/**
 * Devuelve la categoría padre contenedora de regiones.
 * Busca primero por slug "regiones" y luego por nombre "Regiones".
 */
function viable_get_regions_parent_term() {
    $parent = get_term_by('slug', 'regiones', 'category');
    if ($parent && !is_wp_error($parent)) {
        return $parent;
    }

    $parent = get_term_by('name', 'Regiones', 'category');
    if ($parent && !is_wp_error($parent)) {
        return $parent;
    }

    return null;
}

/**
 * Devuelve únicamente las categorías hijas directas de "Regiones".
 */
function viable_get_region_categories($args = []) {
    $parent = viable_get_regions_parent_term();
    if (!$parent) {
        return [];
    }

    $defaults = [
        'taxonomy' => 'category',
        'hide_empty' => false,
        'parent' => (int) $parent->term_id,
        'orderby' => 'name',
        'order' => 'ASC',
    ];

    return get_categories(array_merge($defaults, $args));
}
