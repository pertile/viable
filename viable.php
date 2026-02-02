<?php
/**
 * Plugin Name: Obras en Rutas
 * Description: Gestión de obras viales con fichas dinámicas y mapas.
 * Version: 0.1.0
 * Author: Federico Pértile
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VIABLE_PATH', plugin_dir_path(__FILE__));
define('VIABLE_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/acf/project-fields.php';
require_once VIABLE_PATH . 'includes/cpt-project.php';
require_once VIABLE_PATH . 'includes/shortcodes.php';
require_once VIABLE_PATH . 'includes/assets.php';
require_once VIABLE_PATH . 'includes/render-project-sheet.php';
require_once VIABLE_PATH . 'includes/api-geojson.php';
require_once VIABLE_PATH . 'includes/category-map.php';