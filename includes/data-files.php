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
 * Aviso en wp-admin si los trazados no están en la ubicación nueva.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $path = viable_get_gpkg_path();
    $expected = viable_get_data_dir() . 'viable.gpkg';

    if (!file_exists($path)) {
        $msg = 'No se encontró el archivo de trazados. Subilo a <code>' . esc_html($expected) . '</code>.';
    } elseif (wp_normalize_path($path) === wp_normalize_path(VIABLE_PATH . 'assets/data/viable.gpkg')) {
        $msg = 'Los trazados todavía están dentro del plugin. Movelos a <code>' . esc_html($expected)
            . '</code> antes de actualizar el plugin con git, o se perderán.';
    } else {
        return;
    }

    echo '<div class="notice notice-warning"><p><strong>Obras:</strong> ' . $msg . '</p></div>';
});
