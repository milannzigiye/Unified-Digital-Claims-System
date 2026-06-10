<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_alert')) {
    function render_alert(string $message, array $attributes = []): void
    {
        $type = $attributes['type'] ?? 'info';
        $title = $attributes['title'] ?? '';
        $dismissible = (bool) ($attributes['dismissible'] ?? false);
        $class = $attributes['class'] ?? '';

        $variant = match ($type) {
            'success' => 'udcs-alert--success',
            'warning' => 'udcs-alert--warning',
            'danger', 'error' => 'udcs-alert--danger',
            default => 'udcs-alert--info',
        };

        echo '<div x-data="{open:true}" x-show="open" x-transition.opacity.duration.180ms class="' . bk_e(bk_class('udcs-alert', $variant, $class)) . '" role="alert" aria-live="polite">';
        echo '<div class="udcs-alert-body">';

        if ($title !== '') {
            echo '<p class="udcs-alert-title">' . bk_e($title) . '</p>';
        }

        echo '<p class="udcs-alert-text">' . bk_e($message) . '</p>';
        echo '</div>';

        if ($dismissible) {
            echo '<button type="button" class="udcs-alert-dismiss" @click="open=false" aria-label="Dismiss alert">';
            echo '&times;';
            echo '</button>';
        }

        echo '</div>';
    }
}
