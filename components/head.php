<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('render_head')) {
    function render_head(string $title = 'UNIFIED DIGITAL CLAIMS SYSTEM', string $relativeRoot = '.', string $extra = ''): void
    {
        $base = rtrim($relativeRoot, '/\\');
        if ($base === '') {
            $base = '.';
        }

        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . bk_e($title) . '</title>';
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">';
        echo '<link rel="stylesheet" href="' . bk_e($base . '/assets/css/tokens.css') . '">';
        echo '<link rel="stylesheet" href="' . bk_e($base . '/assets/css/output.css') . '">';
        echo '<link rel="stylesheet" href="' . bk_e($base . '/assets/css/app-overrides.css') . '">';
        echo '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>';

        if ($extra !== '') {
            echo $extra;
        }
    }
}



