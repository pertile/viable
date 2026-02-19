<?php

add_filter('the_content', 'viable_add_category_map', 1);

function viable_add_category_map($content) {
    
    if (!is_category()) {
        return $content;
    }
    
    $category_id = get_queried_object_id();
    
    if (!$category_id) {
        return $content;
    }
    
    // Obtener todos los posts de esta categoría
    $posts_query = new WP_Query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'cat' => $category_id,
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);
    
    // Obtener todos los proyectos con esta región
    $projects_query = new WP_Query([
        'post_type' => 'project',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    
    // Filtrar proyectos que tienen esta categoría en el campo regions
    $filtered_projects = [];
    if ($projects_query->have_posts()) {
        while ($projects_query->have_posts()) {
            $projects_query->the_post();
            $regions = get_field('regions');
            if ($regions) {
                $region_ids = is_array($regions) ? $regions : [$regions];
                $region_ids = array_map(function($r) {
                    return is_object($r) ? $r->term_id : (is_array($r) ? $r['term_id'] : $r);
                }, $region_ids);
                
                if (in_array($category_id, $region_ids)) {
                    $filtered_projects[] = [
                        'id' => get_the_ID(),
                        'title' => get_the_title(),
                        'url' => get_permalink()
                    ];
                }
            }
        }
        wp_reset_postdata();
    }
    
    ob_start();
    ?>
    
    <div id="viable-category-map" 
         data-category-id="<?= esc_attr($category_id) ?>" 
         data-rest-url="<?= esc_url(rest_url('viable/v1/category-projects/')) ?>"
         style="height: 500px; width: 100%; margin-bottom: 30px;">
    </div>
    
    <div class="viable-category-listings">
        <div class="viable-category-column viable-posts-column">
            <h3>Artículos</h3>
            <?php if ($posts_query->have_posts()): ?>
                <ul class="viable-category-list">
                    <?php while ($posts_query->have_posts()): $posts_query->the_post(); ?>
                        <li>
                            <a href="<?= get_permalink() ?>"><?= get_the_title() ?></a>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <p>No hay artículos en esta categoría.</p>
            <?php endif; ?>
            <?php wp_reset_postdata(); ?>
        </div>
        
        <div class="viable-category-column viable-projects-column">
            <h3>Proyectos</h3>
            <?php if (!empty($filtered_projects)): ?>
                <ul class="viable-category-list">
                    <?php foreach ($filtered_projects as $project): ?>
                        <li>
                            <a href="<?= esc_url($project['url']) ?>"><?= esc_html($project['title']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No hay proyectos en esta región.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php
    
    return ob_get_clean();
}
