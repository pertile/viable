<?php

/**
 * IDs de categorías marcadas como regiones en Ajustes → Obras (vacío = usar "Regiones").
 */
function viable_get_region_term_ids() {
    $ids = viable_setting('region_term_ids');
    return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
}

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
    // Categorías elegidas explícitamente en Ajustes → Obras
    $ids = viable_get_region_term_ids();
    if ($ids) {
        return get_categories(array_merge([
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'include'    => $ids,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ], $args));
    }

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
    // Categorías elegidas explícitamente: lista plana, por nombre
    if (viable_get_region_term_ids()) {
        return array_map(function ($term) {
            return ['term' => $term, 'depth' => 0];
        }, viable_get_region_categories());
    }

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
