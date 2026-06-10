<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_app_topbar')) {
    function render_app_topbar(string $pageTitle, array $attributes = []): void
    {
        $user = $attributes['user'] ?? 'User';
        $role = $attributes['role'] ?? 'user';
        $actions = $attributes['actions'] ?? '';

        echo '<header class="ui-navbar">';
        echo '<div class="mx-auto flex h-16 w-full items-center justify-between px-4 sm:px-6">';
        echo '<div>';
        echo '<h1 class="text-base font-semibold text-bk-text sm:text-lg">' . bk_e($pageTitle) . '</h1>';
        echo '<p class="text-xs text-bk-muted">' . bk_e($user) . ' • ' . bk_e(ucfirst((string) $role)) . '</p>';
        echo '</div>';
        if ($actions !== '') {
            echo '<div class="flex items-center gap-2">' . $actions . '</div>';
        }
        echo '</div>';
        echo '</header>';
    }
}

