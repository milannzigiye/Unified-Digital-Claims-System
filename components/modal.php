<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_modal')) {
    function render_modal(string $id, string $title, string $content, array $attributes = []): void
    {
        $openVar = $attributes['open_var'] ?? 'openModal';
        $closeLabel = $attributes['close_label'] ?? 'Close';

        echo '<div x-show="' . bk_e($openVar) . '" x-cloak>'; 
        echo '<div class="ui-modal-backdrop" @click="' . bk_e($openVar) . '=false"></div>';
        echo '<section class="ui-modal-panel" role="dialog" aria-modal="true" aria-labelledby="' . bk_e($id . '_title') . '">';
        echo '<header class="flex items-center justify-between border-b border-bk-border px-5 py-4">';
        echo '<h2 id="' . bk_e($id . '_title') . '" class="font-display text-lg font-semibold text-bk-text">' . bk_e($title) . '</h2>';
        echo '<button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" @click="' . bk_e($openVar) . '=false">' . bk_e($closeLabel) . '</button>';
        echo '</header>';
        echo '<div class="px-5 py-4">' . $content . '</div>';
        echo '</section>';
        echo '</div>';
    }
}

