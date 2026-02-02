<?php

function viable_render_project_sheet($content) {

    if (!is_singular('post')) {
        return $content;
    }

    $projects = get_field('related_projects');
    if (!$projects) {
        return '<!-- VIABLE DEBUG: No hay proyectos relacionados -->' . $content;
    }

    // Por ahora asumimos UNO
    $project = is_array($projects) ? $projects[0] : $projects;
    $pid = $project->ID;
    
    // DEBUG: Ver TODOS los campos disponibles
    $all_fields = get_fields($pid);
    $debug = "<!-- VIABLE DEBUG:\n";
    $debug .= "Project ID: $pid\n";
    $debug .= "Project Type: " . get_post_type($pid) . "\n";
    $debug .= "Project Title: " . get_the_title($pid) . "\n";
    $debug .= "ALL FIELDS:\n" . print_r($all_fields, true) . "\n";
    $debug .= "-->\n";

    $type        = get_field('type', $pid);
    $duplication_type = get_field('duplication_type', $pid);
    $name        = get_the_title($pid);
    $short_desc  = get_field('short_description', $pid);
    $length      = get_field('length', $pid);
    $state       = get_field('state', $pid);
    $progress    = get_field('progress', $pid);
    $progress_updated_on = get_field('progress_updated_on', $pid);
    $end_date    = get_field('end_date', $pid);
    $duration    = get_field('duration', $pid);
    $bid_date    = get_field('bid_date', $pid);
    $award_date  = get_field('award_date', $pid);
    $start_date  = get_field('start_date', $pid);
    $tender_documents = get_field('tender_documents', $pid);
    $code        = get_field('code', $pid);
    $roads       = get_field('roads', $pid);
    $regions     = get_field('regions', $pid);
    $image       = get_field('image', $pid);
    $map         = get_field('map', $pid);
    $description = wpautop(get_post_field('post_content', $pid));
    
    // Función helper para formatear fechas
    $format_date = function($date_str) {
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
    };
    
    // Formatear end_date
    $state_lower = strtolower($state);
    if ($end_date && ($state_lower === 'en obras' || $state_lower === 'finalizado')) {
        $end_date = $format_date($end_date);
    }
    
    // Formatear fechas "desde"
    if ($bid_date) $bid_date_formatted = $format_date($bid_date);
    if ($award_date) $award_date_formatted = $format_date($award_date);
    if ($start_date) $start_date_formatted = $format_date($start_date);
    
    // Determinar qué tipo mostrar
    if (strtolower($type) === 'duplicación' && $duplication_type) {
        $type_display = $duplication_type;
    } else {
        $type_display = $type;
    }
    
    // Procesar roads (taxonomía)
    $roads_text = '';
    if ($roads && is_array($roads)) {
        $road_names = array_map(function($term) {
            if (is_object($term) && isset($term->name)) {
                return $term->name;
            } elseif (is_array($term) && isset($term['name'])) {
                return $term['name'];
            } elseif (is_numeric($term)) {
                $term_obj = get_term($term);
                return $term_obj && !is_wp_error($term_obj) ? $term_obj->name : '';
            }
            return $term;
        }, $roads);
        $roads_text = implode(', ', array_filter($road_names));
    } elseif ($roads) {
        $roads_text = is_numeric($roads) ? get_term($roads)->name : $roads;
    }
    
    // Procesar regions (categorías)
    $regions_text = '';
    if ($regions && is_array($regions)) {
        $region_names = array_map(function($cat) {
            if (is_object($cat) && isset($cat->name)) {
                return $cat->name;
            } elseif (is_array($cat) && isset($cat['name'])) {
                return $cat['name'];
            } elseif (is_numeric($cat)) {
                $cat_obj = get_category($cat);
                return $cat_obj && !is_wp_error($cat_obj) ? $cat_obj->name : '';
            }
            return $cat;
        }, $regions);
        $regions_text = implode('; ', array_filter($region_names));
    } elseif ($regions) {
        $regions_text = is_numeric($regions) ? get_category($regions)->name : $regions;
    }
    
    ob_start();
    echo $debug;
    ?>
    <section class="viable-project-sheet state-<?= esc_attr($state) ?>">

        <h2><?= $type_display ? esc_html("$type_display – $name") : esc_html($name) ?></h2>

        <?php if ($short_desc): ?>
            <p class="project-summary"><?= esc_html($short_desc) ?></p>
        <?php endif; ?>
        
        <?php if ($length): ?>
            <p class="project-length">Longitud: <strong><?= esc_html($length) ?> km</strong></p>
        <?php endif; ?>

        <div class="project-info-box">
            <?php if ($roads_text): ?>
                <div class="info-item">
                    <span class="info-label">Rutas</span>
                    <span class="info-value"><?= esc_html($roads_text) ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($regions_text): ?>
                <div class="info-item">
                    <span class="info-label">Regiones</span>
                    <span class="info-value"><?= esc_html($regions_text) ?></span>
                </div>
            <?php endif; ?>
            
            <?php 
            $state_lower = strtolower($state);
            if (($state_lower === 'finalizado' && $end_date) || ($state_lower === 'en obras' && $end_date)): 
            ?>
                <div class="info-item">
                    <span class="info-label"><?= $state_lower === 'finalizado' ? 'Finalización' : 'Finalización prevista' ?></span>
                    <span class="info-value"><?= esc_html($end_date) ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (($state_lower === 'en licitación' || $state_lower === 'adjudicado') && $duration): ?>
                <div class="info-item">
                    <span class="info-label">Plazo</span>
                    <span class="info-value"><?= esc_html($duration) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($state === 'paralizado' || $state === 'Paralizado'): ?>
            <div class="state-paralizado-alert">
                <span class="alert-icon">⚠️</span>
                <strong>Proyecto Paralizado</strong>
            </div>
        <?php else: ?>
            <div class="state-sequence" data-expanded="false">
                <span class="state-label">Estado:</span>
                <div class="state-current-view">
                    <span class="state-arrow-left" onclick="document.querySelector('.state-sequence').dataset.expanded = 'true'; this.parentElement.style.display = 'none'; this.parentElement.nextElementSibling.style.display = 'flex';">←</span>
                    <span class="state-step current"><?= esc_html(ucfirst($state_lower)) ?></span>
                    <span class="state-arrow-right" onclick="document.querySelector('.state-sequence').dataset.expanded = 'true'; this.parentElement.style.display = 'none'; this.parentElement.nextElementSibling.style.display = 'flex';">→</span>
                </div>
                <div class="state-full-view" style="display: none;">
                    <?php
                    $states = ['proyecto', 'en licitación', 'adjudicado', 'en obras', 'finalizado'];
                    $current_index = array_search($state_lower, $states);
                    
                    foreach ($states as $index => $s) {
                        $class = 'state-step';
                        if ($index <= $current_index) {
                            $class .= ' active';
                        }
                        if ($index === $current_index) {
                            $class .= ' current';
                        }
                        echo '<span class="' . $class . '">' . esc_html(ucfirst($s)) . '</span>';
                        if ($index < count($states) - 1) {
                            echo '<span class="state-arrow">→</span>';
                        }
                    }
                    ?>
                    <button class="state-collapse" onclick="this.parentElement.style.display = 'none'; this.parentElement.previousElementSibling.style.display = 'flex';">×</button>
                </div>
            </div>
        <?php endif; ?>
        
        <?php
        // Determinar la fecha "desde" fuera del div de estado
        $desde_date = null;
        $desde_label = '';
        if ($state_lower === 'en licitación' && isset($bid_date_formatted)) {
            $desde_date = $bid_date_formatted;
            $desde_label = 'Desde';
        } elseif ($state_lower === 'adjudicado' && isset($award_date_formatted)) {
            $desde_date = $award_date_formatted;
            $desde_label = 'Desde';
        } elseif ($state_lower === 'en obras' && isset($start_date_formatted)) {
            $desde_date = $start_date_formatted;
            $desde_label = 'Desde';
        }
        ?>
        <?php if ($desde_date): ?>
            <div class="state-since"><?= esc_html($desde_label) ?>: <?= esc_html($desde_date) ?></div>
        <?php endif; ?>

        <?php if ($state === 'En obras' && $progress !== null): ?>
            <div class="progress-bar-container">
                <div class="progress-bar-label">
                    <?= intval($progress) ?>%<?php if ($progress_updated_on): ?> (<?= esc_html($progress_updated_on) ?>)<?php endif; ?>
                </div>
                <div class="progress-bar">
                    <div class="progress-bar-fill" style="width:<?= intval($progress) ?>%"></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tender_documents): ?>
            <div class="tender-documents-box">
                <h4>Pliegos de licitación</h4>
                <?= wpautop($tender_documents) ?>
            </div>
        <?php endif; ?>

        <?php if ($code): ?>
            <div id="viable-map" 
                 data-code="<?= esc_attr($code) ?>" 
                 style="height: 300px; width: 100%;">
            </div>
        <?php elseif ($map): ?>
            <img src="<?= esc_url($map['url']) ?>" alt="">
        <?php elseif ($image): ?>
            <img src="<?= esc_url($image['url']) ?>" alt="">
        <?php endif; ?>

    </section>
    <?php

    $sheet_html = ob_get_clean();
    
    // Agregar descripción después del contenido del post
    $description_html = '';
    if ($description) {
        $description_html = '<section class="viable-project-description">';
        $description_html .= '<h3>Sobre este proyecto</h3>';
        $description_html .= $description;
        $description_html .= '</section>';
    }
    
    return $sheet_html . $content . $description_html;
}

add_filter('the_content', 'viable_render_project_sheet', 5);

// Renderizar página individual de proyecto
add_filter('the_content', 'viable_render_single_project', 5);

function viable_render_single_project($content) {
    
    if (!is_singular('project')) {
        return $content;
    }
    
    $pid = get_the_ID();
    
    // Reutilizar la misma lógica de obtención de datos
    $type        = get_field('type', $pid);
    $duplication_type = get_field('duplication_type', $pid);
    $name        = get_the_title($pid);
    $short_desc  = get_field('short_description', $pid);
    $length      = get_field('length', $pid);
    $state       = get_field('state', $pid);
    $progress    = get_field('progress', $pid);
    $progress_updated_on = get_field('progress_updated_on', $pid);
    $end_date    = get_field('end_date', $pid);
    $duration    = get_field('duration', $pid);
    $bid_date    = get_field('bid_date', $pid);
    $award_date  = get_field('award_date', $pid);
    $start_date  = get_field('start_date', $pid);
    $tender_documents = get_field('tender_documents', $pid);
    $code        = get_field('code', $pid);
    $roads       = get_field('roads', $pid);
    $regions     = get_field('regions', $pid);
    $image       = get_field('image', $pid);
    $map         = get_field('map', $pid);
    
    // Función helper para formatear fechas
    $format_date = function($date_str) {
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
    };
    
    // Formatear end_date
    $state_lower = strtolower($state);
    if ($end_date && ($state_lower === 'en obras' || $state_lower === 'finalizado')) {
        $end_date = $format_date($end_date);
    }
    
    // Formatear fechas "desde"
    if ($bid_date) $bid_date_formatted = $format_date($bid_date);
    if ($award_date) $award_date_formatted = $format_date($award_date);
    if ($start_date) $start_date_formatted = $format_date($start_date);
    
    // Determinar qué tipo mostrar
    if (strtolower($type) === 'duplicación' && $duplication_type) {
        $type_display = $duplication_type;
    } else {
        $type_display = $type;
    }
    
    // Procesar roads
    $roads_text = '';
    if ($roads && is_array($roads)) {
        $road_names = array_map(function($term) {
            if (is_object($term) && isset($term->name)) {
                return $term->name;
            } elseif (is_array($term) && isset($term['name'])) {
                return $term['name'];
            } elseif (is_numeric($term)) {
                $term_obj = get_term($term);
                return $term_obj && !is_wp_error($term_obj) ? $term_obj->name : '';
            }
            return $term;
        }, $roads);
        $roads_text = implode(', ', array_filter($road_names));
    } elseif ($roads) {
        $roads_text = is_numeric($roads) ? get_term($roads)->name : $roads;
    }
    
    // Procesar regions
    $regions_text = '';
    if ($regions && is_array($regions)) {
        $region_names = array_map(function($cat) {
            if (is_object($cat) && isset($cat->name)) {
                return $cat->name;
            } elseif (is_array($cat) && isset($cat['name'])) {
                return $cat['name'];
            } elseif (is_numeric($cat)) {
                $cat_obj = get_category($cat);
                return $cat_obj && !is_wp_error($cat_obj) ? $cat_obj->name : '';
            }
            return $cat;
        }, $regions);
        $regions_text = implode('; ', array_filter($region_names));
    } elseif ($regions) {
        $regions_text = is_numeric($regions) ? get_category($regions)->name : $regions;
    }
    
    // Buscar posts relacionados con este proyecto
    // ACF guarda relationships como valores individuales (una fila por relación)
    $related_posts = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'meta_query' => [
            [
                'key' => 'related_projects',
                'value' => $pid,
                'compare' => '='
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC',
        'posts_per_page' => -1
    ]);
    
    ob_start();
    
    // Incluir la misma ficha pero sin el contenido del post
    include(VIABLE_PATH . 'includes/project-sheet-template.php');
    
    ?>
    <div class="project-content">
        <?= $content ?>
    </div>
    <?php
    
    // DEBUG: Verificar posts relacionados
    echo "<!-- DEBUG: Project ID = $pid -->";
    echo "<!-- DEBUG: Found posts = " . $related_posts->found_posts . " -->";
    
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
