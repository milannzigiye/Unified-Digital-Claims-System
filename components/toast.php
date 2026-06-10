<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_toast')) {
    function render_toast(string $message, array $attributes = []): void
    {
        $type = $attributes['type'] ?? 'info';
        $title = $attributes['title'] ?? '';
        $class = $attributes['class'] ?? '';

        $typeClass = match ($type) {
            'success' => 'ui-toast-success',
            'warning' => 'ui-toast-warning',
            'danger', 'error' => 'ui-toast-danger',
            default => 'ui-toast-info',
        };

        echo '<div x-data="{open:true}" x-show="open" x-transition class="' . bk_e(bk_class('ui-toast', $typeClass, $class)) . '" role="status">';
        if ($title !== '') {
            echo '<p class="mb-0.5 font-semibold text-bk-text">' . bk_e($title) . '</p>';
        }
        echo '<p class="text-sm text-bk-muted">' . bk_e($message) . '</p>';
        echo '<button type="button" class="mt-2 text-xs font-medium text-bk-primary" @click="open=false">Dismiss</button>';
        echo '</div>';
    }
}

if (!function_exists('render_toast_stack_start')) {
    function render_toast_stack_start(string $class = ''): void
    {
        echo '<div class="' . bk_e(bk_class('ui-toast-stack', $class)) . '">';
    }
}

if (!function_exists('render_toast_stack_end')) {
    function render_toast_stack_end(): void
    {
        echo '</div>';
    }
}

