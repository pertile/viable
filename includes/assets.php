<?php

add_action('wp_enqueue_scripts', 'viable_enqueue_styles');

function viable_enqueue_styles() {
    wp_enqueue_style(
        'viable-styles',
        VIABLE_URL . 'assets/css/viable.css',
        [],
        '1.0'
    );
}
