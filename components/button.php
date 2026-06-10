<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_button')) {
    function render_button(string $text, array $attributes = []): void
    {
        $variant = $attributes['variant'] ?? 'primary';
        $size = $attributes['size'] ?? 'md';
        $href = $attributes['href'] ?? null;
        $loading = (bool) ($attributes['loading'] ?? false);
        $disabled = (bool) ($attributes['disabled'] ?? false);
        $icon = $attributes['icon'] ?? '';
        $iconPosition = $attributes['icon_position'] ?? 'left';
        $class = $attributes['class'] ?? '';

        $sizeClass = match ($size) {
            'sm' => 'ui-btn-sm',
            'lg' => 'ui-btn-lg',
            default => 'ui-btn-md',
        };

        $variantClass = match ($variant) {
            'secondary' => 'ui-btn-secondary',
            'ghost' => 'ui-btn-ghost',
            default => 'ui-btn-primary',
        };

        $classes = bk_class('ui-btn', $sizeClass, $variantClass, $class);

        $attrs = [
            'class' => $classes,
            'aria-busy' => $loading ? 'true' : null,
        ];

        foreach (['id', 'name', 'type', 'onclick', 'title', 'target', 'rel'] as $key) {
            if (isset($attributes[$key])) {
                $attrs[$key] = $attributes[$key];
            }
        }

        if (!isset($attrs['type'])) {
            $attrs['type'] = 'button';
        }

        if ($loading || $disabled) {
            $attrs['disabled'] = true;
        }

        $content = '';

        if ($loading) {
            $content .= '<span class="ui-spinner" aria-hidden="true"></span>';
        }

        if ($icon !== '' && $iconPosition === 'left') {
            $content .= '<span aria-hidden="true">' . $icon . '</span>';
        }

        $content .= '<span>' . bk_e($text) . '</span>';

        if ($icon !== '' && $iconPosition === 'right') {
            $content .= '<span aria-hidden="true">' . $icon . '</span>';
        }

        if ($href && !$disabled && !$loading) {
            $attrs['href'] = $href;
            unset($attrs['type'], $attrs['disabled']);
            echo '<a ' . bk_attrs($attrs) . '>' . $content . '</a>';
            return;
        }

        echo '<button ' . bk_attrs($attrs) . '>' . $content . '</button>';
    }
}

