<?php

/**
 * Recalcula los campos del proyecto (avance, estado, fecha de finalización)
 * a partir de todos sus eventos, tomando para cada campo el valor del evento
 * con la fecha más reciente que lo tenga cargado.
 */
function viable_recompute_project_from_events($project_id, $exclude_event_id = 0) {
    $project_id = (int) $project_id;
    if (!$project_id || get_post_type($project_id) !== 'project') {
        return;
    }

    $args = [
        'post_type' => 'project_event',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => 'project',
        'meta_value' => $project_id,
    ];
    if ($exclude_event_id) {
        $args['post__not_in'] = [(int) $exclude_event_id];
    }

    $event_ids = get_posts($args);

    $latest_progress = null;
    $latest_state = null;

    foreach ($event_ids as $event_id) {
        $event_date = viable_parse_date_dmy(get_field('event_date', $event_id));
        if (!$event_date) {
            continue;
        }

        $progress = get_field('progress', $event_id);
        if ($progress !== '' && $progress !== null && (!$latest_progress || $event_date > $latest_progress['date'])) {
            $latest_progress = ['date' => $event_date, 'value' => $progress];
        }

        $state = get_field('state', $event_id);
        if (!empty($state) && (!$latest_state || $event_date > $latest_state['date'])) {
            $latest_state = ['date' => $event_date, 'value' => $state];
        }
    }

    // OJO: escribir en formato ISO (Y-m-d), no en el "d/m/Y" de display. ACF
    // no normaliza el valor al guardar un date_picker (no hay update_value
    // propio para ese tipo de campo): lo persiste tal cual se lo pasemos. Si
    // se graba "d/m/Y", al releerlo con get_field() ACF lo reinterpreta con
    // strtotime() asumiendo formato americano m/d/Y, así que un día > 12
    // (ej. "20/07/2026") se toma como mes inválido y termina devolviendo la
    // fecha de hoy. El ISO es el único formato que strtotime() interpreta
    // siempre sin ambigüedad, sea cual sea el día/mes.
    if ($latest_state) {
        $state_to_save = $latest_state['value'];
    } else {
        $state_to_save = get_field('state', $project_id);
    }

    if ($latest_progress) {
        $progress_to_save = $latest_progress['value'];
    } else {
        $progress_to_save = get_field('progress', $project_id);
    }

    if (!viable_project_state_progress_error($state_to_save, $progress_to_save)) {
        if ($latest_progress) {
            update_field('progress', $latest_progress['value'], $project_id);
            update_field('progress_updated_on', $latest_progress['date']->format('Y-m-d'), $project_id);
        }

        if ($latest_state) {
            update_field('state', $latest_state['value'], $project_id);
            if ($latest_state['value'] === 'Finalizado') {
                update_field('end_date', $latest_state['date']->format('Y-m-d'), $project_id);
            }
        }
    }
}

function viable_project_event_state_progress_error($acf) {
    $acf = is_array($acf) ? $acf : [];
    $project = $acf['project'] ?? 0;
    $project_id = is_object($project) && isset($project->ID)
        ? (int) $project->ID
        : (is_numeric($project) ? (int) $project : 0);

    if (!$project_id || get_post_type($project_id) !== 'project') {
        return false;
    }

    $state = array_key_exists('state', $acf) && $acf['state'] !== ''
        ? $acf['state']
        : get_field('state', $project_id);
    $progress = array_key_exists('progress', $acf) && $acf['progress'] !== ''
        ? $acf['progress']
        : get_field('progress', $project_id);

    return viable_project_state_progress_error($state, $progress);
}

add_action('acf/validate_save_post', 'viable_validate_project_event_state_progress_admin_save');

function viable_validate_project_event_state_progress_admin_save() {
    if (!isset($_POST['post_type']) || $_POST['post_type'] !== 'project_event') {
        return;
    }

    $acf_data = isset($_POST['acf']) ? $_POST['acf'] : [];
    $acf = [
        'project' => $acf_data['field_project_event_project'] ?? 0,
        'state' => $acf_data['field_project_event_state'] ?? '',
        'progress' => $acf_data['field_project_event_progress'] ?? '',
    ];
    $error = viable_project_event_state_progress_error($acf);
    if ($error) {
        acf_add_validation_error('acf[field_project_event_state]', $error->get_error_message());
    }
}

add_filter('rest_pre_insert_project_event', 'viable_validate_project_event_state_progress_rest_save', 10, 2);

function viable_validate_project_event_state_progress_rest_save($prepared_post, $request) {
    $error = viable_project_event_state_progress_error($request->get_param('acf'));
    return $error ?: $prepared_post;
}

function viable_get_project_event_project_id($event_id) {
    $project = get_field('project', $event_id);
    if (is_object($project) && isset($project->ID)) {
        return (int) $project->ID;
    }
    if (is_numeric($project)) {
        return (int) $project;
    }
    return 0;
}

/**
 * Recalcular al guardar un evento.
 *
 * OJO: la integración REST de ACF actualiza los campos llamando a
 * acf_update_value() directamente (ver ACF_Rest_Api::update_fields()) y NO
 * dispara la acción 'acf/save_post' — esa acción solo se dispara en el flujo
 * clásico de guardado por formulario (wp-admin). Por eso hace falta enganchar
 * dos caminos distintos:
 *  - 'save_post_project_event' (después de que ACF ya guardó vía 'save_post'
 *    con prioridad 10) para wp-admin.
 *  - 'rest_after_insert_project_event' (se dispara después de que el
 *    controlador REST ya actualizó los campos "adicionales", incluido 'acf')
 *    para altas/ediciones por API.
 */
add_action('save_post_project_event', 'viable_sync_project_event_on_admin_save', 20);

function viable_sync_project_event_on_admin_save($post_id) {
    // El alta/edición por REST también dispara 'save_post_project_event', pero
    // en ese momento ACF todavía no guardó los campos: se maneja aparte más
    // abajo, vía 'rest_after_insert_project_event'.
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    viable_sync_project_event_by_id($post_id);
}

add_action('rest_after_insert_project_event', 'viable_sync_project_event_on_rest_save', 20);

function viable_sync_project_event_on_rest_save($post) {
    viable_sync_project_event_by_id($post->ID);
}

function viable_sync_project_event_by_id($post_id) {
    if (!is_numeric($post_id) || get_post_type($post_id) !== 'project_event') {
        return;
    }

    $project_id = viable_get_project_event_project_id($post_id);
    if ($project_id) {
        viable_recompute_project_from_events($project_id);
    }
}

// Recalcular al borrar o mandar a la papelera un evento.
add_action('before_delete_post', 'viable_sync_project_event_on_removal');
add_action('wp_trash_post', 'viable_sync_project_event_on_removal');

function viable_sync_project_event_on_removal($post_id) {
    if (get_post_type($post_id) !== 'project_event') {
        return;
    }

    $project_id = viable_get_project_event_project_id($post_id);
    if ($project_id) {
        viable_recompute_project_from_events($project_id, $post_id);
    }
}

// Recalcular al restaurar un evento desde la papelera.
add_action('untrashed_post', 'viable_sync_project_event_on_untrash');

function viable_sync_project_event_on_untrash($post_id) {
    if (get_post_type($post_id) !== 'project_event') {
        return;
    }

    $project_id = viable_get_project_event_project_id($post_id);
    if ($project_id) {
        viable_recompute_project_from_events($project_id);
    }
}

/**
 * Validación "URL o texto descriptivo obligatorio" — wp-admin.
 */
add_action('acf/validate_save_post', 'viable_validate_project_event_admin_save');

function viable_validate_project_event_admin_save() {
    if (!isset($_POST['post_type']) || $_POST['post_type'] !== 'project_event') {
        return;
    }

    $acf_data = isset($_POST['acf']) ? $_POST['acf'] : [];
    $url = isset($acf_data['field_project_event_url']) ? trim((string) $acf_data['field_project_event_url']) : '';
    $description = isset($acf_data['field_project_event_description']) ? trim((string) $acf_data['field_project_event_description']) : '';

    if ($url === '' && $description === '') {
        acf_add_validation_error('acf[field_project_event_url]', 'Cargá una URL o un texto descriptivo.');
        acf_add_validation_error('acf[field_project_event_description]', 'Cargá una URL o un texto descriptivo.');
    }
}

/**
 * Validación "URL o texto descriptivo obligatorio" — REST API.
 */
add_filter('rest_pre_insert_project_event', 'viable_validate_project_event_rest_save', 10, 2);

function viable_validate_project_event_rest_save($prepared_post, $request) {
    $acf = $request->get_param('acf');

    if ($request->get_method() === 'POST') {
        $url = is_array($acf) && !empty($acf['url']) ? trim((string) $acf['url']) : '';
        $description = is_array($acf) && !empty($acf['description']) ? trim((string) $acf['description']) : '';

        if ($url === '' && $description === '') {
            return new WP_Error('viable_missing_url_or_description', 'Tenés que indicar una URL o un texto descriptivo para el evento.', ['status' => 400]);
        }

        return $prepared_post;
    }

    // Update: solo validar si el request toca alguno de los dos campos.
    if (is_array($acf) && (array_key_exists('url', $acf) || array_key_exists('description', $acf))) {
        $post_id = isset($prepared_post->ID) ? $prepared_post->ID : 0;
        $final_url = array_key_exists('url', $acf) ? trim((string) $acf['url']) : trim((string) get_field('url', $post_id));
        $final_description = array_key_exists('description', $acf) ? trim((string) $acf['description']) : trim((string) get_field('description', $post_id));

        if ($final_url === '' && $final_description === '') {
            return new WP_Error('viable_missing_url_or_description', 'Tenés que indicar una URL o un texto descriptivo para el evento.', ['status' => 400]);
        }
    }

    return $prepared_post;
}
