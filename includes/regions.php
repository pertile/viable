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
 * Devuelve todas las categorías descendientes de "Regiones"
 * (hijas, nietas y niveles inferiores).
 */
function viable_get_region_categories($args = []) {
    $parent = viable_get_regions_parent_term();
    if (!$parent) {
        return [];
    }

    $defaults = [
        'taxonomy' => 'category',
        'hide_empty' => false,
        'child_of' => (int) $parent->term_id,
        'orderby' => 'name',
        'order' => 'ASC',
    ];

    return get_categories(array_merge($defaults, $args));
}

/**
 * Devuelve regiones en orden jerárquico (preorden), incluyendo profundidad.
 * Cada item es ['term' => WP_Term, 'depth' => int].
 */
function viable_get_region_categories_hierarchy() {
    $parent = viable_get_regions_parent_term();
    if (!$parent) {
        return [];
    }

    $ordered = [];

    $walk = function($parentId, $depth) use (&$walk, &$ordered) {
        $children = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'parent' => (int) $parentId,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($children) || empty($children)) {
            return;
        }

        foreach ($children as $term) {
            $ordered[] = [
                'term' => $term,
                'depth' => (int) $depth,
            ];
            $walk($term->term_id, $depth + 1);
        }
    };

    $walk($parent->term_id, 0);

    return $ordered;
}
