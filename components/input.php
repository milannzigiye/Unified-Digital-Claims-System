<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_input')) {
    function render_input(string $name, array $attributes = []): void
    {
        $id = $attributes['id'] ?? $name;
        $label = $attributes['label'] ?? '';
        $type = $attributes['type'] ?? 'text';
        $value = $attributes['value'] ?? '';
        $placeholder = $attributes['placeholder'] ?? '';
        $required = (bool) ($attributes['required'] ?? false);
        $disabled = (bool) ($attributes['disabled'] ?? false);
        $readonly = (bool) ($attributes['readonly'] ?? false);
        $error = $attributes['error'] ?? '';
        $help = $attributes['help'] ?? '';
        $size = $attributes['size'] ?? 'md';
        $class = $attributes['class'] ?? '';

        $sizeClass = match ($size) {
            'sm' => 'ui-input-sm',
            'lg' => 'ui-input-lg',
            default => '',
        };

        $inputClass = bk_class('ui-input', $sizeClass, $error ? 'is-invalid' : '', $class);
        $describedBy = [];

        if ($help !== '') {
            $describedBy[] = $id . '_help';
        }

        if ($error !== '') {
            $describedBy[] = $id . '_error';
        }

        echo '<div class="ui-field">';

        if ($label !== '') {
            echo '<label class="ui-label" for="' . bk_e($id) . '">' . bk_e($label);
            if ($required) {
                echo ' <span class="text-bk-danger" aria-hidden="true">*</span>';
            }
            echo '</label>';
        }

        $attrs = [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'placeholder' => $placeholder,
            'class' => $inputClass,
            'required' => $required,
            'disabled' => $disabled,
            'readonly' => $readonly,
            'aria-invalid' => $error !== '' ? 'true' : null,
            'aria-describedby' => count($describedBy) ? implode(' ', $describedBy) : null,
        ];

        foreach (['autocomplete', 'min', 'max', 'step', 'pattern'] as $key) {
            if (isset($attributes[$key])) {
                $attrs[$key] = $attributes[$key];
            }
        }

        echo '<input ' . bk_attrs($attrs) . '>';

        if ($help !== '') {
            echo '<p id="' . bk_e($id . '_help') . '" class="ui-help">' . bk_e($help) . '</p>';
        }

        if ($error !== '') {
            echo '<p id="' . bk_e($id . '_error') . '" class="ui-error" role="alert">' . bk_e($error) . '</p>';
        }

        echo '</div>';
    }
}

