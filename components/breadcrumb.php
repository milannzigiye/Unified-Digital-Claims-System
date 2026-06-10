<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_breadcrumb')) {
    function render_breadcrumb(array $items = [], array $attributes = []): void
    {
        // Breadcrumbs were removed from portal headers to keep each page focused on its main title.
        return;
    }
}

