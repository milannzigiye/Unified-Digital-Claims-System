<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_checkbox')) {
    function render_checkbox(string $name, array $attributes = []): void
    {
        $id = $attributes['id'] ?? $name;
        $label = $attributes['label'] ?? '';
        $checked = (bool) ($attributes['checked'] ?? false);
        $required = (bool) ($attributes['required'] ?? false);
        $disabled = (bool) ($attributes['disabled'] ?? false);
        $value = $attributes['value'] ?? '1';
        $class = $attributes['class'] ?? '';
        $help = $attributes['help'] ?? '';

        echo '<div class="ui-field ' . bk_e($class) . '">';
        echo '<label class="ui-checkbox-wrapper" for="' . bk_e($id) . '">';

        echo '<input ' . bk_attrs([
            'id' => $id,
            'name' => $name,
            'type' => 'checkbox',
            'value' => $value,
            'checked' => $checked,
            'required' => $required,
            'disabled' => $disabled,
            'class' => 'ui-checkbox',
        ]) . '>';

        echo '<span class="ui-checkbox-label">' . bk_e($label) . '</span>';
        echo '</label>';

        if ($help !== '') {
            echo '<p class="ui-help">' . bk_e($help) . '</p>';
        }

        echo '</div>';
    }
}

