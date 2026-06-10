<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_navbar')) {
    function render_navbar(array $items = [], array $attributes = []): void
    {
        $brand = $attributes['brand'] ?? 'Bank of Kigali';
        $brandHref = $attributes['brand_href'] ?? '#';
        $class = $attributes['class'] ?? '';

        echo '<header x-data="{open:false}" class="' . bk_e(bk_class('ui-navbar', $class)) . '">';
        echo '<div class="mx-auto flex h-[72px] w-full max-w-7xl items-center justify-between px-4 sm:px-6">';
        echo '<a href="' . bk_e($brandHref) . '" class="font-display text-xl font-semibold text-bk-text">' . bk_e($brand) . '</a>';

        echo '<button type="button" class="ui-btn ui-btn-sm ui-btn-ghost md:hidden" @click="open=!open" :aria-expanded="open.toString()" aria-controls="mobile-nav">Menu</button>';

        echo '<nav class="hidden items-center gap-5 md:flex" aria-label="Primary navigation">';
        foreach ($items as $item) {
            $label = $item['label'] ?? '';
            $href = $item['href'] ?? '#';
            $active = !empty($item['active']);
            $linkClass = $active ? 'font-semibold text-bk-text' : 'text-bk-muted hover:text-bk-text';
            echo '<a href="' . bk_e($href) . '" class="text-base ' . bk_e($linkClass) . '">' . bk_e($label) . '</a>';
        }
        echo '</nav>';

        echo '</div>';

        echo '<nav id="mobile-nav" x-show="open" x-transition class="border-t border-bk-border bg-bk-surface px-4 py-3 md:hidden" aria-label="Mobile navigation">';
        echo '<div class="flex flex-col gap-2">';
        foreach ($items as $item) {
            $label = $item['label'] ?? '';
            $href = $item['href'] ?? '#';
            echo '<a href="' . bk_e($href) . '" class="rounded-app px-2 py-2 text-sm text-bk-text hover:bg-bk-primary/10">' . bk_e($label) . '</a>';
        }
        echo '</div>';
        echo '</nav>';

        echo '</header>';
    }
}

