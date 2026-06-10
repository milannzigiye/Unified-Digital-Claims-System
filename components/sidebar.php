<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_sidebar')) {
    function render_sidebar(array $items = [], array $attributes = []): void
    {
        $title = $attributes['title'] ?? 'UNIFIED DIGITAL CLAIMS SYSTEM';
        $subtitle = $attributes['subtitle'] ?? '';
        $class = $attributes['class'] ?? '';

        echo '<aside class="' . bk_e(bk_class('ui-sidebar', 'w-full max-w-xs p-4', $class)) . '">';
        echo '<div class="mb-6">';
        echo '<p class="font-display text-lg font-semibold text-bk-text">' . bk_e($title) . '</p>';
        if ($subtitle !== '') {
            echo '<p class="text-sm text-bk-muted">' . bk_e($subtitle) . '</p>';
        }
        echo '</div>';

        echo '<nav aria-label="Sidebar navigation" class="space-y-1">';
        foreach ($items as $item) {
            $label = $item['label'] ?? '';
            $href = $item['href'] ?? '#';
            $active = !empty($item['active']);
            $classes = $active
                ? 'block rounded-app bg-bk-primary/15 px-3 py-2 text-sm font-semibold text-bk-text'
                : 'block rounded-app px-3 py-2 text-sm text-bk-muted hover:bg-bk-primary/10 hover:text-bk-text';
            echo '<a href="' . bk_e($href) . '" class="' . bk_e($classes) . '">' . bk_e($label) . '</a>';
        }
        echo '</nav>';
        echo '</aside>';
    }
}



