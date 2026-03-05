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

    // Formatear end_date
    $end_date = get_field('end_date', $pid);
    if ($end_date && ($state_lower === 'en obras' || $state_lower === 'finalizado')) {
        $end_date = viable_format_date_es($end_date);
    }

    $bid_date   = get_field('bid_date', $pid);
    $award_date = get_field('award_date', $pid);
    $start_date = get_field('start_date', $pid);

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
        'duration'            => get_field('duration', $pid),
        'bid_date_formatted'  => $bid_date ? viable_format_date_es($bid_date) : null,
        'award_date_formatted'=> $award_date ? viable_format_date_es($award_date) : null,
        'start_date_formatted'=> $start_date ? viable_format_date_es($start_date) : null,
        'tender_documents'    => get_field('tender_documents', $pid),
        'code'                => get_field('code', $pid),
        'roads_text'          => $roads_text,
        'regions_text'        => $regions_text,
        'image'               => get_field('image', $pid),
        'map'                 => get_field('map', $pid),
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
        $tender_documents = $d['tender_documents'];
        $code         = $d['code'];
        $roads_text   = $d['roads_text'];
        $regions_text = $d['regions_text'];
        $image        = $d['image'];
        $map          = $d['map'];

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

        return $sheet_html . $wrapped . $description_html;
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
        $tender_documents     = $d['tender_documents'];
        $code                 = $d['code'];
        $roads_text           = $d['roads_text'];
        $regions_text         = $d['regions_text'];
        $image                = $d['image'];
        $map                  = $d['map'];

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
                <a href="<?= esc_url($d['url']) ?>"><?= esc_html($d['name']) ?></a>
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
    $tender_documents = $d['tender_documents'];
    $code         = $d['code'];
    $roads_text   = $d['roads_text'];
    $regions_text = $d['regions_text'];
    $image        = $d['image'];
    $map          = $d['map'];

    // Buscar posts relacionados con este proyecto
    // ACF serializa el array como strings: s:N:"PID" → buscar '"PID"'
    $related_posts = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => 'related_projects',
                'value'   => '"' . $pid . '"',
                'compare' => 'LIKE'
            ],
            [
                'key'     => 'related_projects',
                'value'   => 'i:' . $pid . ';',
                'compare' => 'LIKE'
            ]
        ],
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => -1
    ]);

    ob_start();

    include(VIABLE_PATH . 'includes/project-sheet-template.php');

    ?>
    <div class="viable-post-content-wrapper">
        <?= $content ?>
    </div>
    <div style="clear: both;"></div>
    <?php

    // Mostrar posts relacionados
    if ($related_posts->have_posts()) {
        ?>
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
        <?php
        wp_reset_postdata();
    }

    return ob_get_clean();
}
