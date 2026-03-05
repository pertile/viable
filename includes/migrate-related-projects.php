<?php
/**
 * Migración: related_projects de single → multiple.
 *
 * Cuando el campo ACF post_object pasa de multiple=0 a multiple=1 el formato
 * interno cambia: antes se guardaba un solo post ID, ahora se espera un array
 * serializado de IDs.
 *
 * Ejecutar UNA VEZ visitando:
 *   /wp-admin/admin.php?viable_migrate_projects=1
 *
 * Solo administradores pueden ejecutarlo. Al finalizar muestra un resumen y
 * luego se puede eliminar este archivo.
 */

add_action('admin_init', 'viable_migrate_related_projects');

function viable_migrate_related_projects() {
    if (empty($_GET['viable_migrate_projects']) || $_GET['viable_migrate_projects'] !== '1') {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die('No tenés permiso para ejecutar esta migración.');
    }

    global $wpdb;

    // Buscar todos los posts con related_projects que NO sean ya un array serializado
    $rows = $wpdb->get_results(
        "SELECT post_id, meta_value
         FROM {$wpdb->postmeta}
         WHERE meta_key = 'related_projects'
         AND meta_value != ''
         AND meta_value IS NOT NULL"
    );

    $migrated = 0;
    $skipped  = 0;
    $errors   = 0;

    foreach ($rows as $row) {
        $value = $row->meta_value;

        // Si ya es un array serializado, saltar
        if (is_serialized($value)) {
            $skipped++;
            continue;
        }

        // Si es un ID numérico simple → convertir a array serializado (strings, como ACF nativo)
        if (is_numeric($value)) {
            $new_value = serialize([(string) $value]);
            $result = $wpdb->update(
                $wpdb->postmeta,
                ['meta_value' => $new_value],
                ['post_id' => $row->post_id, 'meta_key' => 'related_projects', 'meta_value' => $value]
            );
            if ($result !== false) {
                $migrated++;
            } else {
                $errors++;
            }
        } else {
            $skipped++;
        }
    }

    // Limpiar caché de ACF
    wp_cache_flush();

    wp_die(
        "<h2>Migración completada</h2>
         <ul>
           <li><strong>Registros migrados:</strong> {$migrated}</li>
           <li><strong>Ya estaban bien (saltados):</strong> {$skipped}</li>
           <li><strong>Errores:</strong> {$errors}</li>
           <li><strong>Total revisados:</strong> " . count($rows) . "</li>
         </ul>
         <p>Podés eliminar el archivo <code>includes/migrate-related-projects.php</code> y su <code>require_once</code> en <code>viable.php</code>.</p>
         <p><a href='" . admin_url() . "'>Volver al escritorio</a></p>",
        'Viable – Migración related_projects'
    );
}
