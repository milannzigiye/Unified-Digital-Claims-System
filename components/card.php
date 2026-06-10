<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_card')) {
    function render_card(string $content, array $attributes = []): void
    {
        $header = $attributes['header'] ?? '';
        $footer = $attributes['footer'] ?? '';
        $class = $attributes['class'] ?? '';

        echo '<section class="' . bk_e(bk_class('ui-card', $class)) . '">';

        if ($header !== '') {
            echo '<header class="ui-card-header">' . $header . '</header>';
        }

        echo '<div class="ui-card-body">' . $content . '</div>';

        if ($footer !== '') {
            echo '<footer class="ui-card-footer">' . $footer . '</footer>';
        }

        echo '</section>';
    }
}

