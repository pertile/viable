<?php

add_action('wp_enqueue_scripts', 'viable_enqueue_styles');

function viable_enqueue_styles() {
    wp_enqueue_style(
        'viable-styles',
        VIABLE_URL . 'assets/css/viable.css',
        [],
        file_exists(VIABLE_PATH . 'assets/css/viable.css') ? filemtime(VIABLE_PATH . 'assets/css/viable.css') : null
    );
}
