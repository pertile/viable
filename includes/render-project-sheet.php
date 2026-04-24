<?php

/**
 * Helper: normalizar el campo related_projects a un array de objetos WP_Post.
 * Funciona tanto con el formato antiguo (single) como el nuevo (multiple).
 */
function viable_get_related_projects($post_id = null) {
    $raw = get_field('related_projects', $post_id);
    if (!$raw) return [];

    // Si ya es un array de objetos
    if (is_array($raw)) {
        // Comprobar si es un array de posts o un solo post envuelto
        $first = reset($raw);
        if (is_object($first) && isset($first->ID)) {
            return $raw;
        }
        // Podría ser un array de IDs
        return array_filter(array_map('get_post', $raw));
    }

    // Si es un solo objeto
    if (is_object($raw) && isset($raw->ID)) {
        return [$raw];
    }

    // Si es un solo ID
    if (is_numeric($raw)) {
        $p = get_post($raw);
        return $p ? [$p] : [];
    }

    return [];
}

/**
 * Helper: formatear fecha a "mes año" en español.
 */
function viable_format_date_es($date_str) {
    if (!$date_str) return null;
    $months_es = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
    ];
    $timestamp = strtotime($date_str);
    if ($timestamp) {
        $month_num = (int)date('n', $timestamp);
        $year = date('Y', $timestamp);
        return $months_es[$month_num] . ' ' . $year;
    }
    return $date_str;
}

function viable_parse_date_dmy($date_str) {
    if (!$date_str) {
        return null;
    }

    $date = DateTime::createFromFormat('d/m/Y', trim((string) $date_str));
    if ($date instanceof DateTime) {
        $date->setTime(0, 0, 0);
        return $date;
    }

    $ts = strtotime((string) $date_str);
    if ($ts === false) {
        return null;
    }

    $date = new DateTime('@' . $ts);
    $date->setTimezone(wp_timezone());
    $date->setTime(0, 0, 0);
    return $date;
}

function viable_format_date_short($date) {
    if (!$date instanceof DateTime) {
        return null;
    }
    $months_es = [
        1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr',
        5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago',
        9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'
    ];

    $month_num = (int) $date->format('n');
    return $date->format('j') . ' ' . $months_es[$month_num] . ' ' . $date->format('Y');
}

function viable_get_last_date($dates) {
    $valid = array_filter($dates, function($d) {
        return $d instanceof DateTime;
    });

    if (empty($valid)) {
        return null;
    }

    usort($valid, function($a, $b) {
        return $a <=> $b;
    });

    return end($valid);
}

function viable_build_progress_timeline($start_date_raw, $duration_months, $end_date_raw, $progress, $progress_updated_on) {
    $start = viable_parse_date_dmy($start_date_raw);
    if (!$start) {
        return null;
    }

    $duration_months = is_numeric($duration_months) ? (int) $duration_months : 0;
    $progress_value = is_numeric($progress) ? (float) $progress : null;
    $updated = viable_parse_date_dmy($progress_updated_on);
    $end_date = viable_parse_date_dmy($end_date_raw);

    $planned_end = null;
    if ($duration_months > 0) {
        $planned_end = clone $start;
        $planned_end->modify('+' . $duration_months . ' months');
    }

    $projected_end = null;
    if ($updated && $progress_value !== null && $progress_value > 0) {
        $elapsed_days = (int) $start->diff($updated)->format('%r%a');
        if ($elapsed_days > 0) {
            $total_days = (int) round(($elapsed_days * 100) / $progress_value);
            if ($total_days > 0) {
                $projected_end = clone $start;
                $projected_end->modify('+' . $total_days . ' days');
            }
        }
    }

    $final_end = viable_get_last_date([$planned_end, $end_date, $projected_end]);

    $has_full_dates = false;
    $start_ts = $start->getTimestamp();
    $final_ts = $final_end ? $final_end->getTimestamp() : null;
    $span = ($final_ts && $updated && $final_ts > $start_ts) ? ($final_ts - $start_ts) : null;

    $to_pct = function($date) use ($start_ts, $span) {
        if (!$date instanceof DateTime || !$span) {
            return null;
        }
        $pct = (($date->getTimestamp() - $start_ts) / $span) * 100;
        return max(0, min(100, $pct));
    };

    $progress_bar_pct = null;
    if ($has_full_dates) {
        $progress_bar_pct = $to_pct($updated);
    } elseif ($progress_value !== null) {
        $progress_bar_pct = max(0, min(100, $progress_value));
    }

    $updated_pct = $to_pct($updated);
    if ($span && $updated_pct !== null) {
        $has_full_dates = true;
    }

    $planned_pct = $to_pct($planned_end);
    $completion_pct = $to_pct($end_date);

    $markers = [];
    if ($has_full_dates) {
        $markers[] = ['key' => 'start', 'label' => 'Inicio', 'date' => viable_format_date_short($start), 'pct' => 0, 'placement' => 'bottom'];

        if ($planned_end && $planned_pct !== null) {
            $markers[] = ['key' => 'planned', 'label' => 'Finalización según pliego', 'date' => viable_format_date_short($planned_end), 'pct' => $planned_pct, 'placement' => 'top', 'tier' => 1];
        }

        if ($end_date && $completion_pct !== null) {
            $markers[] = ['key' => 'completion', 'label' => 'Finalización prevista', 'date' => viable_format_date_short($end_date), 'pct' => $completion_pct, 'placement' => 'bottom', 'tier' => 1];
        }

        $markers[] = ['key' => 'updated', 'label' => 'Última actualización', 'date' => viable_format_date_short($updated), 'pct' => $updated_pct, 'current' => true, 'placement' => 'top', 'tier' => 2];
        $markers[] = ['key' => 'end', 'label' => 'Fin', 'date' => viable_format_date_short($final_end), 'pct' => 100, 'placement' => 'top'];
    }

    return [
        'start' => viable_format_date_short($start),
        'planned_end' => viable_format_date_short($planned_end),
        'completion_end' => viable_format_date_short($end_date),
        'final_end' => viable_format_date_short($final_end),
        'updated_on' => viable_format_date_short($updated),
        'progress' => $progress_value,
        'bar_end_pct' => $progress_bar_pct,
        'has_full_dates' => (bool) $has_full_dates,
        'markers' => $markers,
    ];
}

function viable_get_related_posts_by_project_ids($project_ids, $exclude_post_id = 0) {
    $project_ids = array_values(array_unique(array_filter(array_map('intval', (array) $project_ids))));
    if (empty($project_ids)) {
        return null;
    }

    $meta_or = [];
    foreach ($project_ids as $proj_id) {
        $meta_or[] = ['key' => 'related_projects', 'value' => '"' . $proj_id . '"', 'compare' => 'LIKE'];
        $meta_or[] = ['key' => 'related_projects', 'value' => 'i:' . $proj_id . ';', 'compare' => 'LIKE'];
    }

    return new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'post__not_in'   => $exclude_post_id ? [(int) $exclude_post_id] : [],
        'meta_query'     => array_merge(['relation' => 'OR'], $meta_or),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => -1,
    ]);
}

/**
 * Helper: obtener todos los datos de un proyecto como array asociativo.
 */
function viable_get_project_data($pid) {
    $type        = get_field('type', $pid);
    $duplication_type = get_field('duplication_type', $pid);
    $name        = get_the_title($pid);
    $state       = get_field('state', $pid);
    $state_lower = strtolower($state);

    // Determinar qué tipo mostrar
    if (strtolower($type) === 'duplicación' && $duplication_type) {
        $type_display = $duplication_type;
    } else {
        $type_display = $type;
    }

    // Mantener formato dd/mm/yyyy como retorna ACF
    $end_date_raw = get_field('end_date', $pid);
    $end_date = $end_date_raw;

    $bid_date   = get_field('bid_date', $pid);
    $award_date = get_field('award_date', $pid);
    $start_date = get_field('start_date', $pid);
    $duration   = get_field('duration', $pid);

    $parent_post = get_field('project_parent', $pid);
    $parent_id = 0;
    if (is_object($parent_post) && isset($parent_post->ID)) {
        $parent_id = (int) $parent_post->ID;
    } elseif (is_numeric($parent_post)) {
        $parent_id = (int) $parent_post;
    }
    if ($parent_id && get_post_type($parent_id) !== 'project') {
        $parent_id = 0;
    }

    $children = get_posts([
        'post_type' => 'project',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'meta_key' => 'project_parent',
        'meta_value' => $pid,
        'fields' => 'ids',
    ]);

    $sibling_ids = [];
    if ($parent_id) {
        $sibling_ids = get_posts([
            'post_type' => 'project',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => 'project_parent',
            'meta_value' => $parent_id,
            'fields' => 'ids',
        ]);
        $sibling_ids = array_values(array_diff(array_map('intval', $sibling_ids), [(int) $pid]));
    }

    $children_summary = array_map(function($child_id) {
        return [
            'id' => (int) $child_id,
            'title' => get_the_title($child_id),
            'url' => get_permalink($child_id),
            'state' => get_field('state', $child_id),
            'progress' => get_field('progress', $child_id),
            'code' => get_field('code', $child_id),
        ];
    }, $children);

    $map_codes = [];
    if (!empty($children_summary)) {
        foreach ($children_summary as $child) {
            if (!empty($child['code'])) {
                $map_codes[] = $child['code'];
            }
        }
    } else {
        $own_code = get_field('code', $pid);
        if ($own_code) {
            $map_codes[] = $own_code;
        }
        foreach ($sibling_ids as $sibling_id) {
            $sibling_code = get_field('code', $sibling_id);
            if ($sibling_code) {
                $map_codes[] = $sibling_code;
            }
        }
    }
    $map_codes = array_values(array_unique(array_filter($map_codes)));

    $timeline = viable_build_progress_timeline($start_date, $duration, $end_date_raw, get_field('progress', $pid), get_field('progress_updated_on', $pid));

    // Procesar roads (taxonomía)
    $roads = get_field('roads', $pid);
    $roads_text = '';
    if ($roads && is_array($roads)) {
        $road_names = array_map(function($term) {
            if (is_object($term) && isset($term->name)) return $term->name;
            if (is_array($term) && isset($term['name'])) return $term['name'];
            if (is_numeric($term)) {
                $t = get_term($term);
                return $t && !is_wp_error($t) ? $t->name : '';
            }
            return $term;
        }, $roads);
        $roads_text = implode(', ', array_filter($road_names));
    } elseif ($roads) {
        $roads_text = is_numeric($roads) ? get_term($roads)->name : $roads;
    }

    // Procesar regions (categorías)
    $regions = get_field('regions', $pid);
    $regions_text = '';
    if ($regions && is_array($regions)) {
        $region_names = array_map(function($cat) {
            if (is_object($cat) && isset($cat->name)) return $cat->name;
            if (is_array($cat) && isset($cat['name'])) return $cat['name'];
            if (is_numeric($cat)) {
                $c = get_category($cat);
                return $c && !is_wp_error($c) ? $c->name : '';
            }
            return $cat;
        }, $regions);
        $regions_text = implode('; ', array_filter($region_names));
    } elseif ($regions) {
        $regions_text = is_numeric($regions) ? get_category($regions)->name : $regions;
    }

    return [
        'pid'                 => $pid,
        'name'                => $name,
        'type'                => $type,
        'type_display'        => $type_display,
        'short_desc'          => get_field('short_description', $pid),
        'length'              => get_field('length', $pid),
        'state'               => $state,
        'state_lower'         => $state_lower,
        'progress'            => get_field('progress', $pid),
        'progress_updated_on' => get_field('progress_updated_on', $pid),
        'end_date'            => $end_date,
        'end_date_raw'        => $end_date_raw,
        'duration'            => $duration,
        'bid_date_formatted'  => $bid_date ? viable_format_date_es($bid_date) : null,
        'award_date_formatted'=> $award_date ? viable_format_date_es($award_date) : null,
        'start_date_formatted'=> $start_date ? viable_format_date_es($start_date) : null,
        'start_date_raw'      => $start_date,
        'tender_documents'    => get_field('tender_documents', $pid),
        'code'                => get_field('code', $pid),
        'roads_text'          => $roads_text,
        'regions_text'        => $regions_text,
        'image'               => get_field('image', $pid),
        'map'                 => get_field('map', $pid),
        'is_parent_project'   => !empty($children_summary),
        'parent_project_id'   => $parent_id,
        'parent_project_name' => $parent_id ? get_the_title($parent_id) : null,
        'parent_project_url'  => $parent_id ? get_permalink($parent_id) : null,
        'children_projects'   => $children_summary,
        'sibling_project_ids' => $sibling_ids,
        'map_codes'           => $map_codes,
        'timeline'            => $timeline,
        'description'         => wpautop(get_post_field('post_content', $pid)),
        'url'                 => get_permalink($pid),
    ];
}

// ========================================================================
// Renderizar contenido de un post con proyectos asociados
// ========================================================================
function viable_render_project_sheet($content) {

    if (!is_singular('post')) {
        return $content;
    }

    $projects = viable_get_related_projects();
    if (empty($projects)) {
        return $content;
    }

    $project_count = count($projects);

    // Configurar imagen destacada del post desde el primer proyecto
    if (!has_post_thumbnail()) {
        $first_image = get_field('image', $projects[0]->ID);
        if ($first_image) {
            $img_id = is_array($first_image) ? $first_image['ID'] : $first_image;
            set_post_thumbnail(get_the_ID(), $img_id);
        }
    }

    // ── CASO 1: Un solo proyecto → ficha lateral (infobox) ────────────
    if ($project_count === 1) {
        $d = viable_get_project_data($projects[0]->ID);

        // Extraer variables para el template
        $pid          = $d['pid'];
        $name         = $d['name'];
        $type_display = $d['type_display'];
        $short_desc   = $d['short_desc'];
        $length       = $d['length'];
        $state        = $d['state'];
        $state_lower  = $d['state_lower'];
        $progress     = $d['progress'];
        $progress_updated_on = $d['progress_updated_on'];
        $end_date     = $d['end_date'];
        $duration     = $d['duration'];
        $bid_date_formatted   = $d['bid_date_formatted'];
        $award_date_formatted = $d['award_date_formatted'];
        $start_date_formatted = $d['start_date_formatted'];
        $start_date_raw = $d['start_date_raw'];
        $tender_documents = $d['tender_documents'];
        $code         = $d['code'];
        $roads_text   = $d['roads_text'];
        $regions_text = $d['regions_text'];
        $image        = $d['image'];
        $map          = $d['map'];
        $is_parent_project = $d['is_parent_project'];
        $parent_project_id = $d['parent_project_id'];
        $parent_project_name = $d['parent_project_name'];
        $parent_project_url = $d['parent_project_url'];
        $children_projects = $d['children_projects'];
        $sibling_project_ids = $d['sibling_project_ids'];
        $map_codes = $d['map_codes'];
        $timeline = $d['timeline'];

        ob_start();
        include(VIABLE_PATH . 'includes/project-sheet-template.php');
        $sheet_html = ob_get_clean();

        // Sección "Sobre este proyecto"
        $description_html = '';
        if ($d['description']) {
            $description_html  = '<section class="viable-project-description">';
            $description_html .= '<h3>Sobre este proyecto</h3>';
            $description_html .= $d['description'];
            $description_html .= '</section>';
        }

        $wrapped = '<div class="viable-post-content-wrapper">' . $content . '</div>';
        $wrapped .= '<div style="clear: both;"></div>';

        // Incluir artículos vinculados al proyecto padre cuando aplique
        $related_ids = [$pid];
        if (!empty($parent_project_id)) {
            $related_ids[] = (int) $parent_project_id;
        }
        $related_posts = viable_get_related_posts_by_project_ids($related_ids, get_the_ID());

        $related_html = '';
        if ($related_posts && $related_posts->have_posts()) {
            ob_start();
            ?>
            <section class="related-posts-section">
                <h3>Artículos relacionados</h3>
                <div class="related-posts-list">
                    <?php while ($related_posts->have_posts()): $related_posts->the_post(); ?>
                        <article class="related-post-item">
                            <h4><a href="<?= get_permalink() ?>"><?= get_the_title() ?></a></h4>
                            <time class="post-date"><?= get_the_date('j \d\e F \d\e Y') ?></time>
                            <div class="post-excerpt"><?= get_the_excerpt() ?></div>
                        </article>
                    <?php endwhile; ?>
                </div>
            </section>
            <?php
            wp_reset_postdata();
            $related_html = ob_get_clean();
        }

        if ($related_html) {
            $related_html = '<div class="viable-below-sheet-full-width">' . $related_html . '</div>';
        }

        return $sheet_html . $wrapped . $description_html . $related_html;
    }

    // ── CASO 2: Múltiples proyectos → mapa + secciones colapsables ───
    // Recopilar codes para el mapa
    $codes = [];
    $project_data = [];
    foreach ($projects as $p) {
        $d = viable_get_project_data($p->ID);
        $project_data[] = $d;
        if ($d['code']) $codes[] = $d['code'];
    }

    ob_start();

    // Mapa con todos los proyectos (usa el shortcode universal)
    if (!empty($codes)) {
        echo do_shortcode('[viable_map codes="' . esc_attr(implode(',', $codes)) . '" height="400px"]');
    }

    // Contenido del post
    echo '<div class="viable-post-content-wrapper viable-multi-project">' . $content . '</div>';
    echo '<div style="clear: both;"></div>';

    // Secciones colapsables por proyecto
    foreach ($project_data as $idx => $d) {

        // ── Renderizar la ficha del proyecto (infobox) ──────────────────
        $pid                  = $d['pid'];
        $name                 = $d['name'];
        $type_display         = $d['type_display'];
        $short_desc           = $d['short_desc'];
        $length               = $d['length'];
        $state                = $d['state'];
        $state_lower          = $d['state_lower'];
        $progress             = $d['progress'];
        $progress_updated_on  = $d['progress_updated_on'];
        $end_date             = $d['end_date'];
        $duration             = $d['duration'];
        $bid_date_formatted   = $d['bid_date_formatted'];
        $award_date_formatted = $d['award_date_formatted'];
        $start_date_formatted = $d['start_date_formatted'];
        $start_date_raw       = $d['start_date_raw'];
        $tender_documents     = $d['tender_documents'];
        $code                 = $d['code'];
        $roads_text           = $d['roads_text'];
        $regions_text         = $d['regions_text'];
        $image                = $d['image'];
        $map                  = $d['map'];
        $is_parent_project    = $d['is_parent_project'];
        $parent_project_id    = $d['parent_project_id'];
        $parent_project_name  = $d['parent_project_name'];
        $parent_project_url   = $d['parent_project_url'];
        $children_projects    = $d['children_projects'];
        $sibling_project_ids  = $d['sibling_project_ids'];
        $map_codes            = $d['map_codes'];
        $timeline             = $d['timeline'];

        ob_start();
        include(VIABLE_PATH . 'includes/project-sheet-template.php');
        $sheet_html = ob_get_clean();

        // ── Dividir descripción en preview + resto ──────────────────────
        $preview      = '';
        $rest_content = '';
        $full_desc    = $d['description'];

        if ($d['short_desc']) {
            // short_desc visible siempre; toda la descripción va al bloque expandible
            $preview      = '<p>' . esc_html($d['short_desc']) . '</p>';
            $rest_content = $full_desc;
        } elseif ($full_desc) {
            // Sin short_desc: primeros 2 <p> como preview, el resto (incluyendo imágenes) colapsado
            preg_match_all('/<p[^>]*>.*?<\/p>/is', $full_desc, $p_matches, PREG_OFFSET_CAPTURE);
            $p_tags = $p_matches[0] ?? [];

            if (count($p_tags) >= 2) {
                $preview      = $p_tags[0][0] . "\n" . $p_tags[1][0];
                $cut_at       = $p_tags[1][1] + strlen($p_tags[1][0]);
                $rest_content = substr($full_desc, $cut_at);
            } elseif (count($p_tags) === 1) {
                $preview      = $p_tags[0][0];
                $rest_content = '';
            } else {
                $rest_content = $full_desc;
            }
        }

        // La sección expandible incluye la ficha + el resto del contenido
        $rest = $sheet_html . '<div class="viable-project-full-content">' . $rest_content . '</div>';

        $section_id = 'viable-proj-desc-' . $idx;
        ?>
        <section class="viable-project-description viable-project-collapsible">
            <h3>
                <a href="<?= esc_url($d['url']) ?>"><?= esc_html($d['type_display'] ? $d['type_display'] . ': ' . $d['name'] : $d['name']) ?></a>
            </h3>
            <?php if ($d['type_display'] || $d['length'] || $d['state']): ?>
                <p class="project-collapsible-meta">
                    <?php if ($d['type_display']): ?>
                        <span class="project-type-tag"><?= esc_html($d['type_display']) ?></span>
                    <?php endif; ?>
                    <?php if ($d['length']): ?>
                        <span class="project-length-tag"><?= esc_html($d['length']) ?> km</span>
                    <?php endif; ?>
                    <?php if ($d['state']): ?>
                        <span class="project-state-tag state-tag-<?= esc_attr(sanitize_title($d['state'])) ?>"><?= esc_html($d['state']) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($preview): ?>
                <div class="project-desc-preview"><?= $preview ?></div>
            <?php endif; ?>
            <div class="project-desc-full" id="<?= $section_id ?>" style="display: none;">
                <?= $rest ?>
                <div style="clear: both;"></div>
            </div>
            <a class="viable-ver-mas-btn" href="#" data-target="<?= $section_id ?>"
               onclick="event.preventDefault(); var t=this, el=document.getElementById(t.dataset.target); if(el.style.display==='none'){el.style.display='block';t.textContent='Ver menos';}else{el.style.display='none';t.textContent='Ver más';}">
                Ver más
            </a>
        </section>
        <?php
    }

    return ob_get_clean();
}

add_filter('the_content', 'viable_render_project_sheet', 5);

// ========================================================================
// Filtro para el extracto: usar short_description del primer proyecto
// ========================================================================
add_filter('get_the_excerpt', 'viable_project_excerpt', 10, 2);

function viable_project_excerpt($excerpt, $post = null) {
    if (!$post) $post = get_post();
    if (!$post) return $excerpt;

    if ($post->post_type === 'post') {
        $projects = viable_get_related_projects($post->ID);
        if (!empty($projects)) {
            $short_desc = get_field('short_description', $projects[0]->ID);
            if ($short_desc) return $short_desc;
        }
    }

    if ($post->post_type === 'project') {
        $short_desc = get_field('short_description', $post->ID);
        if ($short_desc) return $short_desc;
    }

    return $excerpt;
}

// ========================================================================
// Configurar imagen destacada desde el primer proyecto
// ========================================================================
add_action('wp', 'viable_set_post_thumbnail');

function viable_set_post_thumbnail() {
    if (!is_singular('post')) return;
    if (has_post_thumbnail()) return;

    $projects = viable_get_related_projects();
    if (empty($projects)) return;

    $image = get_field('image', $projects[0]->ID);
    if ($image) {
        $image_id = is_array($image) ? $image['ID'] : $image;
        if ($image_id) set_post_thumbnail(get_the_ID(), $image_id);
    }
}

// ========================================================================
// Renderizar página individual de proyecto (sin cambios conceptuales)
// ========================================================================
add_filter('the_content', 'viable_render_single_project', 5);

function viable_render_single_project($content) {

    if (!is_singular('project')) {
        return $content;
    }

    $pid = get_the_ID();
    $d = viable_get_project_data($pid);

    // Extraer variables para el template
    $name         = $d['name'];
    $type_display = $d['type_display'];
    $short_desc   = $d['short_desc'];
    $length       = $d['length'];
    $state        = $d['state'];
    $state_lower  = $d['state_lower'];
    $progress     = $d['progress'];
    $progress_updated_on = $d['progress_updated_on'];
    $end_date     = $d['end_date'];
    $duration     = $d['duration'];
    $bid_date_formatted   = $d['bid_date_formatted'];
    $award_date_formatted = $d['award_date_formatted'];
    $start_date_formatted = $d['start_date_formatted'];
    $start_date_raw = $d['start_date_raw'];
    $tender_documents = $d['tender_documents'];
    $code         = $d['code'];
    $roads_text   = $d['roads_text'];
    $regions_text = $d['regions_text'];
    $image        = $d['image'];
    $map          = $d['map'];
    $is_parent_project = $d['is_parent_project'];
    $parent_project_id = $d['parent_project_id'];
    $parent_project_name = $d['parent_project_name'];
    $parent_project_url = $d['parent_project_url'];
    $children_projects = $d['children_projects'];
    $sibling_project_ids = $d['sibling_project_ids'];
    $map_codes = $d['map_codes'];
    $timeline = $d['timeline'];

    $related_ids = [$pid];
    if (!empty($parent_project_id)) {
        $related_ids[] = (int) $parent_project_id;
    }
    $related_posts = viable_get_related_posts_by_project_ids($related_ids);

    ob_start();

    include(VIABLE_PATH . 'includes/project-sheet-template.php');

    ?>
    <div class="viable-post-content-wrapper">
        <?= $content ?>
    </div>
    <div style="clear: both;"></div>
    <?php

    // Mostrar posts relacionados
    if ($related_posts && $related_posts->have_posts()) {
        ?>
        <div class="viable-below-sheet-full-width">
            <section class="related-posts-section">
                <h3>Artículos relacionados</h3>
                <div class="related-posts-list">
                    <?php while ($related_posts->have_posts()): $related_posts->the_post(); ?>
                        <article class="related-post-item">
                            <h4><a href="<?= get_permalink() ?>"><?= get_the_title() ?></a></h4>
                            <time class="post-date"><?= get_the_date('j \d\e F \d\e Y') ?></time>
                            <?php if (has_excerpt()): ?>
                                <div class="post-excerpt"><?= get_the_excerpt() ?></div>
                            <?php else: ?>
                                <div class="post-excerpt"><?= wp_trim_words(get_the_content(), 30) ?></div>
                            <?php endif; ?>
                        </article>
                    <?php endwhile; ?>
                </div>
            </section>
        </div>
        <?php
        wp_reset_postdata();
    }

    return ob_get_clean();
}
