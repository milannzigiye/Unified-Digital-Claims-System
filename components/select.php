<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_select')) {
    function render_select(string $name, array $options = [], array $attributes = []): void
    {
        $id = $attributes['id'] ?? $name;
        $label = $attributes['label'] ?? '';
        $value = (string) ($attributes['value'] ?? '');
        $required = (bool) ($attributes['required'] ?? false);
        $disabled = (bool) ($attributes['disabled'] ?? false);
        $error = $attributes['error'] ?? '';
        $help = $attributes['help'] ?? '';
        $size = $attributes['size'] ?? 'md';
        $placeholder = $attributes['placeholder'] ?? 'Select an option';
        $class = $attributes['class'] ?? '';

        $sizeClass = match ($size) {
            'sm' => 'ui-select-sm',
            'lg' => 'ui-select-lg',
            default => '',
        };

        $selectClass = bk_class('ui-select', $sizeClass, $error ? 'is-invalid' : '', $class);
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
            'class' => $selectClass,
            'required' => $required,
            'disabled' => $disabled,
            'aria-invalid' => $error !== '' ? 'true' : null,
            'aria-describedby' => count($describedBy) ? implode(' ', $describedBy) : null,
        ];

        echo '<select ' . bk_attrs($attrs) . '>';
        echo '<option value="">' . bk_e($placeholder) . '</option>';

        foreach ($options as $optValue => $optLabel) {
            $selected = ((string) $optValue === $value) ? ' selected' : '';
            echo '<option value="' . bk_e((string) $optValue) . '"' . $selected . '>' . bk_e((string) $optLabel) . '</option>';
        }

        echo '</select>';

        if ($help !== '') {
            echo '<p id="' . bk_e($id . '_help') . '" class="ui-help">' . bk_e($help) . '</p>';
        }

        if ($error !== '') {
            echo '<p id="' . bk_e($id . '_error') . '" class="ui-error" role="alert">' . bk_e($error) . '</p>';
        }

        echo '</div>';
    }
}

