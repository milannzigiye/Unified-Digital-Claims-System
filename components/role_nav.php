<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/helpers.php';

if (!function_exists('bk_nav_icon_svg')) {
    function bk_nav_icon_svg(string $icon): string
    {
        return match ($icon) {
            'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="7.5" height="7.5" rx="1.8"/><rect x="13" y="3.5" width="7.5" height="4.8" rx="1.8"/><rect x="13" y="10.5" width="7.5" height="10" rx="1.8"/><rect x="3.5" y="13" width="7.5" height="7.5" rx="1.8"/></svg>',
            'submit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3.5H7A2.5 2.5 0 0 0 4.5 6v12A2.5 2.5 0 0 0 7 20.5h10A2.5 2.5 0 0 0 19.5 18V9"/><path d="M14 3.5v5.5h5.5"/><path d="M12 11.2v5.6"/><path d="M9.2 14h5.6"/></svg>',
            'claims' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="15" rx="2.4"/><path d="M7.3 9h9.4"/><path d="M7.3 12.5h9.4"/><path d="M7.3 16h5.2"/></svg>',
            'accounts' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="8.2" cy="9.2" r="2.9"/><circle cx="16.6" cy="8.4" r="2.3"/><path d="M3.8 19.5c0-2.8 2.2-5 5-5h1.1c2.8 0 5 2.2 5 5"/><path d="M15 14.9c2.2.1 4 1.9 4 4.1"/></svg>',
            'reports' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 19.5h15"/><rect x="6.2" y="11" width="2.9" height="6.5" rx="1"/><rect x="10.8" y="8.2" width="2.9" height="9.3" rx="1"/><rect x="15.4" y="5.5" width="2.9" height="12" rx="1"/></svg>',
            'activity' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 12h4l2-4 4 8 2-4h5"/></svg>',
            'messaging' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20.2 14.1a3 3 0 0 1-3 3H10l-4.2 3v-3a3 3 0 0 1-3-3V7.8a3 3 0 0 1 3-3h11.4a3 3 0 0 1 3 3z"/><path d="M7.8 9.4h8.4"/><path d="M7.8 12.5h5.2"/></svg>',
            'profile' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8.2" r="3.2"/><path d="M5 19.5c.8-3.2 3.4-5.1 7-5.1s6.2 1.9 7 5.1"/></svg>',
            'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 20H6.8A2.8 2.8 0 0 1 4 17.2V6.8A2.8 2.8 0 0 1 6.8 4H9"/><path d="M15.2 16.8 20 12l-4.8-4.8"/><path d="M10.5 12H20"/></svg>',
            default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="7.5"/><path d="M12 8.5v7"/><path d="M12 16.8h.01"/></svg>',
        };
    }
}

if (!function_exists('render_role_nav')) {
    function render_role_nav(array $config = []): void
    {
        $role = strtolower((string) ($config['role'] ?? ($_SESSION['role'] ?? 'user')));
        $userName = (string) ($config['user_name'] ?? ($_SESSION['full_name'] ?? 'User'));
        $photo = (string) ($config['photo'] ?? '../Images/logo.png');
        $basePath = (string) ($config['base_path'] ?? '..');
        $menu = $config['menu'] ?? [];
        $logoutHref = (string) ($config['logout_href'] ?? 'logout.php');
        $portalLabel = match ($role) {
            'admin' => 'Admin Portal',
            'legal' => 'Legal Portal',
            'finance' => 'Finance Portal',
            'claimant' => 'Claimant Portal',
            default => ucfirst($role) . ' Portal',
        };
        $notifCsrfToken = function_exists('udcs_csrf_get') ? udcs_csrf_get('notif_action') : '';

        $currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
        $pageTitle = ucwords(str_replace(['_', '.php'], [' ', ''], $currentPage));

        static $assetsPrinted = false;
        if (!$assetsPrinted) {
            $assetsPrinted = true;
            echo '<link rel="stylesheet" href="' . bk_e($basePath . '/assets/css/tokens.css') . '">';
            echo '<link rel="stylesheet" href="' . bk_e($basePath . '/assets/css/output.css') . '">';
            echo '<link rel="stylesheet" href="' . bk_e($basePath . '/assets/css/role-pages.css') . '">';
            echo '<link rel="stylesheet" href="' . bk_e($basePath . '/assets/css/app-overrides.css') . '">';
            echo '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>';
            ?>
            <style>
                :root {
                    --bk-sidebar-width: 320px;
                    --bk-topbar-height: 0px;
                    --bk-content-gap: 0.95rem;
                }

                .bk-app-sidebar {
                    position: fixed;
                    inset: 0 auto 0 0;
                    width: var(--bk-sidebar-width);
                    z-index: 1100;
                    background: linear-gradient(165deg, #023b7d 0%, #034ea2 62%, #0a5bb4 100%);
                    color: #ffffff;
                    box-shadow: 0 14px 40px rgba(3, 78, 162, 0.28);
                    transform: translateX(0);
                    transition: transform 0.25s ease;
                    overflow-x: hidden;
                    overflow-y: auto;
                }

                .bk-app-sidebar-inner {
                    display: flex;
                    height: 100%;
                    min-height: 100%;
                    flex-direction: column;
                    padding: 1.45rem 1.28rem 1.2rem;
                    overflow: hidden;
                }

                .bk-app-brand {
                    display: flex;
                    align-items: center;
                    gap: 0.72rem;
                    margin-bottom: 1.28rem;
                    padding: 0.15rem 0.3rem;
                    justify-content: space-between;
                }

                .bk-app-brand-main {
                    display: flex;
                    align-items: center;
                    gap: 0.72rem;
                    min-width: 0;
                }

                .bk-app-brand img {
                    width: 2.85rem;
                    height: 2.85rem;
                    border-radius: 0.82rem;
                    background: #ffffff;
                    object-fit: contain;
                    padding: 0.24rem;
                }

                .bk-app-brand strong {
                    display: block;
                    font-size: 1.2rem;
                    font-weight: 700;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                }

                .bk-app-brand-text {
                    display: flex;
                    align-items: center;
                    gap: 0.48rem;
                    flex-wrap: wrap;
                }

                .bk-app-sidebar-toggle {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 1.72rem;
                    height: 1.72rem;
                    flex: 0 0 auto;
                    border-radius: 999px;
                    border: 1px solid rgba(255, 255, 255, 0.18);
                    background: rgba(255, 255, 255, 0.05);
                    color: #ffffff;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(8, 20, 44, 0.14);
                    transition: background-color 0.16s ease, border-color 0.16s ease, transform 0.16s ease, box-shadow 0.16s ease, opacity 0.16s ease;
                }

                .bk-app-sidebar-toggle:hover {
                    background: rgba(255, 255, 255, 0.1);
                    border-color: rgba(255, 255, 255, 0.28);
                    box-shadow: 0 6px 14px rgba(8, 20, 44, 0.18);
                    transform: translateY(-1px) scale(1.02);
                }

                .bk-app-sidebar-toggle svg {
                    width: 0.78rem;
                    height: 0.78rem;
                }

                .bk-app-portal-tag {
                    display: inline-flex;
                    align-items: center;
                    border-radius: 999px;
                    border: 1px solid rgba(255, 255, 255, 0.34);
                    background: rgba(255, 255, 255, 0.16);
                    color: #ffffff;
                    font-size: 0.68rem;
                    font-weight: 700;
                    letter-spacing: 0.04em;
                    text-transform: uppercase;
                    padding: 0.2rem 0.58rem;
                }

                .bk-app-menu {
                    display: grid;
                    gap: 0.52rem;
                    margin-top: 0.55rem;
                    padding-bottom: 1rem;
                    flex: 1 1 auto;
                    min-height: 0;
                    align-content: start;
                    overflow-y: auto;
                    overflow-x: hidden;
                    padding-right: 0.24rem;
                }

                .bk-app-link {
                    display: flex;
                    align-items: center;
                    justify-content: flex-start;
                    color: rgba(255, 255, 255, 0.9);
                    text-decoration: none;
                    border-radius: 0.95rem;
                    padding: 0.86rem 0.95rem;
                    font-size: 1.03rem;
                    font-weight: 600;
                    line-height: 1.2;
                    transition: background-color 0.15s ease, transform 0.15s ease;
                }

                .bk-app-link-main {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.72rem;
                    min-width: 0;
                }

                .bk-app-link-icon {
                    display: inline-flex;
                    width: 2rem;
                    height: 2rem;
                    border-radius: 0.66rem;
                    align-items: center;
                    justify-content: center;
                    background: rgba(255, 255, 255, 0.11);
                    flex-shrink: 0;
                }

                .bk-app-link-icon svg {
                    width: 1.15rem;
                    height: 1.15rem;
                }

                .bk-app-link:hover {
                    background: rgba(255, 255, 255, 0.14);
                    color: #ffffff;
                    transform: translateX(2px);
                }

                .bk-app-link.is-active {
                    color: #ffffff;
                    border: 1px solid rgba(255, 255, 255, 0.34);
                    background: linear-gradient(90deg, rgba(245, 183, 0, 0.28), rgba(255, 255, 255, 0.06));
                }

                .bk-app-link.is-active .bk-app-link-icon {
                    background: rgba(255, 255, 255, 0.22);
                }

                .bk-app-sidebar-footer {
                    margin-top: auto;
                    padding-top: 1.18rem;
                    border-top: 1px solid rgba(255, 255, 255, 0.24);
                    flex-shrink: 0;
                }

                .bk-app-notif-launch {
                    display: inline-flex;
                    width: 100%;
                    align-items: center;
                    justify-content: space-between;
                    border-radius: 0.84rem;
                    border: 1px solid rgba(255, 255, 255, 0.22);
                    background: rgb(var(--bk-primary-rgb));
                    color: #ffffff;
                    font-size: 0.86rem;
                    font-weight: 700;
                    min-height: 2.65rem;
                    padding: 0 0.75rem;
                    margin-bottom: 0.62rem;
                    gap: 0.7rem;
                    box-shadow: 0 10px 22px rgba(3, 78, 162, 0.22);
                    transition: background-color 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
                }

                .bk-app-notif-launch:hover {
                    background: #023b7d;
                }

                .bk-app-notif-main {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.7rem;
                    min-width: 0;
                    flex: 1 1 auto;
                }

                .bk-app-notif-icon {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 2rem;
                    height: 2rem;
                    border-radius: 0.72rem;
                    background: rgba(255, 255, 255, 0.12);
                    border: 1px solid rgba(255, 255, 255, 0.18);
                    flex-shrink: 0;
                }

                .bk-app-notif-icon svg {
                    width: 1rem;
                    height: 1rem;
                }

                .bk-app-notif-copy {
                    display: grid;
                    gap: 0.05rem;
                    text-align: left;
                    min-width: 0;
                    flex: 1 1 auto;
                }

                .bk-app-notif-title {
                    display: block;
                    font-size: 0.86rem;
                    font-weight: 800;
                    line-height: 1.1;
                }

                .bk-app-notif-state {
                    display: block;
                    font-size: 0.7rem;
                    font-weight: 600;
                    color: rgba(255, 255, 255, 0.78);
                    line-height: 1.1;
                }

                .bk-app-notif-launch.has-unread {
                    border-color: rgba(255, 255, 255, 0.18);
                    background: rgb(var(--bk-danger-rgb));
                    color: #ffffff;
                    box-shadow: 0 12px 26px rgba(var(--bk-danger-rgb), 0.28);
                }

                .bk-app-notif-launch.has-unread:hover {
                    background: #b42318;
                }

                .bk-app-notif-launch.has-unread .bk-app-notif-state {
                    color: rgba(255, 255, 255, 0.9);
                }

                .bk-app-notif-launch.has-unread .bk-app-notif-icon {
                    background: rgba(255, 255, 255, 0.18);
                    border-color: rgba(255, 255, 255, 0.28);
                }

                .bk-app-notif-launch.has-unread .bk-app-badge {
                    background: rgba(255, 255, 255, 0.98);
                    border-color: rgba(255, 255, 255, 0.36);
                    color: rgb(var(--bk-danger-rgb));
                }

                .bk-app-logout {
                    display: inline-flex;
                    width: 100%;
                    align-items: center;
                    justify-content: center;
                    border-radius: 0.9rem;
                    border: 1px solid rgba(255, 255, 255, 0.3);
                    background: linear-gradient(145deg, rgba(2, 28, 66, 0.98), rgba(3, 78, 162, 0.96));
                    color: #ffffff;
                    text-decoration: none;
                    font-size: 0.95rem;
                    font-weight: 700;
                    min-height: 3.06rem;
                    transition: transform 0.16s ease, filter 0.16s ease;
                    box-shadow: 0 10px 24px rgba(3, 78, 162, 0.24);
                }

                .bk-app-logout:hover {
                    filter: brightness(1.05);
                    transform: translateY(-1px);
                    color: #ffffff;
                }

                .bk-app-top-btn {
                    border: 1px solid rgba(var(--bk-border-rgb), 1);
                    border-radius: 0.82rem;
                    background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.1), rgba(var(--bk-surface-rgb), 1));
                    color: rgb(var(--bk-text-rgb));
                    font-size: 0.9rem;
                    font-weight: 500;
                    min-height: 2.42rem;
                    min-width: 2.42rem;
                    padding: 0 0.82rem;
                    line-height: 1;
                }

                .bk-app-top-btn:hover {
                    background: rgba(var(--bk-primary-rgb), 0.08);
                }

                .bk-app-mark-read {
                    margin-top: 0;
                    min-height: 2rem;
                    padding: 0 0.78rem;
                    border-radius: 999px;
                    border-color: rgba(var(--bk-primary-rgb), 0.14);
                    background: rgba(var(--bk-primary-rgb), 0.07);
                    color: rgb(var(--bk-primary-rgb));
                    font-size: 0.72rem;
                    font-weight: 800;
                    box-shadow: none;
                    white-space: nowrap;
                }

                .bk-app-mark-read:hover:not(:disabled) {
                    background: rgba(var(--bk-primary-rgb), 0.11);
                    color: rgb(var(--bk-primary-rgb));
                }

                .bk-app-badge {
                    display: inline-flex;
                    min-width: 1.28rem;
                    height: 1.28rem;
                    align-items: center;
                    justify-content: center;
                    border-radius: 999px;
                    background: #ffffff;
                    border: 1px solid rgba(255, 255, 255, 0.32);
                    color: rgb(var(--bk-primary-rgb));
                    font-size: 0.7rem;
                    font-weight: 700;
                    margin-left: 0.3rem;
                    padding: 0 0.25rem;
                }

                .bk-app-badge.is-hidden {
                    display: none;
                }

                .main-content {
                    margin-left: calc(var(--bk-sidebar-width) + var(--bk-content-gap)) !important;
                    margin-right: var(--bk-content-gap) !important;
                    width: auto !important;
                }

                .bk-app-floating-menu-toggle {
                    display: none;
                    position: fixed;
                    top: 0.7rem;
                    left: 0.7rem;
                    z-index: 1125;
                    border: 1px solid rgba(var(--bk-border-rgb), 1);
                    border-radius: 0.82rem;
                    background: rgb(var(--bk-surface-rgb));
                    color: rgb(var(--bk-text-rgb));
                    min-height: 2.5rem;
                    padding: 0 0.82rem;
                    font-size: 0.88rem;
                    font-weight: 700;
                    box-shadow: 0 8px 18px rgba(3, 78, 162, 0.14);
                }

                .bk-notif-panel {
                    position: fixed;
                    top: 1rem;
                    left: calc(var(--bk-sidebar-width) + var(--bk-content-gap));
                    right: auto;
                    width: min(28rem, calc(100vw - var(--bk-sidebar-width) - 2rem));
                    max-height: calc(100vh - 2rem);
                    z-index: 1200;
                    display: flex;
                    flex-direction: column;
                    border-radius: 1.35rem;
                    border: 1px solid rgba(var(--bk-border-rgb), 1);
                    background: rgb(var(--bk-white-rgb));
                    opacity: 1;
                    backdrop-filter: none;
                    box-shadow: 0 24px 52px rgba(8, 20, 44, 0.16), 0 10px 28px rgba(8, 20, 44, 0.1);
                    overflow: hidden;
                }

                .bk-notif-head {
                    display: flex;
                    align-items: flex-start;
                    justify-content: space-between;
                    gap: 0.9rem;
                    padding: 1rem 1rem 0.95rem;
                    border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
                    background: rgb(var(--bk-white-rgb));
                }

                .bk-notif-head-main {
                    display: flex;
                    align-items: flex-start;
                    gap: 0.8rem;
                    min-width: 0;
                    flex: 1 1 auto;
                }

                .bk-notif-head-icon {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 2.55rem;
                    height: 2.55rem;
                    flex: 0 0 2.55rem;
                    border-radius: 0.92rem;
                    background: rgb(var(--bk-primary-rgb));
                    color: #ffffff;
                    box-shadow: 0 10px 22px rgba(var(--bk-primary-rgb), 0.2);
                }

                .bk-notif-head-icon svg {
                    width: 1.06rem;
                    height: 1.06rem;
                }

                .bk-notif-head-copy {
                    display: grid;
                    gap: 0.14rem;
                    min-width: 0;
                }

                .bk-notif-head-copy strong {
                    font-size: 0.98rem;
                    font-weight: 900;
                    color: rgb(var(--bk-text-rgb));
                    line-height: 1.15;
                    letter-spacing: 0.01em;
                }

                .bk-notif-head-copy span {
                    font-size: 0.74rem;
                    color: rgb(var(--bk-muted-rgb));
                    font-weight: 700;
                    line-height: 1.35;
                }

                .bk-notif-head-status {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 5.15rem;
                    min-height: 2.1rem;
                    padding: 0 0.8rem;
                    border-radius: 999px;
                    background: rgb(var(--bk-bg-rgb));
                    border: 1px solid rgba(var(--bk-border-rgb), 1);
                    color: rgb(var(--bk-primary-rgb));
                    font-size: 0.74rem;
                    font-weight: 900;
                    letter-spacing: 0.03em;
                    white-space: nowrap;
                }

                .bk-notif-list {
                    max-height: min(62vh, calc(100vh - 10.5rem));
                    overflow: auto;
                    padding: 0.9rem;
                    display: grid;
                    gap: 0.78rem;
                    background: rgb(var(--bk-white-rgb));
                }

                .bk-notif-item,
                .bk-notif-empty {
                    position: relative;
                    border: 1px solid rgba(var(--bk-border-rgb), 1);
                    border-radius: 1rem;
                    background: rgba(var(--bk-white-rgb), 1);
                    padding: 0.9rem 0.95rem;
                    font-size: 0.84rem;
                    box-shadow: 0 8px 18px rgba(8, 20, 44, 0.05);
                }

                .bk-notif-empty {
                    color: rgb(var(--bk-text-rgb));
                    font-weight: 700;
                    line-height: 1.5;
                }

                .bk-notif-item-head {
                    display: flex;
                    align-items: flex-start;
                    justify-content: space-between;
                    gap: 0.65rem;
                    margin-bottom: 0.45rem;
                }

                .bk-notif-item-status {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    padding: 0.22rem 0.56rem;
                    border-radius: 999px;
                    border: 1px solid rgba(var(--bk-border-rgb), 1);
                    background: rgba(var(--bk-bg-rgb), 0.8);
                    color: rgb(var(--bk-text-rgb));
                    font-size: 0.67rem;
                    font-weight: 900;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }

                .bk-notif-item.unread .bk-notif-item-status {
                    background: rgba(var(--bk-primary-rgb), 0.1);
                    border-color: rgba(var(--bk-primary-rgb), 0.18);
                    color: rgb(var(--bk-primary-rgb));
                }

                .bk-notif-item-message {
                    color: rgb(var(--bk-text-rgb));
                    line-height: 1.58;
                    font-size: 0.89rem;
                    font-weight: 750;
                    overflow-wrap: anywhere;
                }

                .bk-notif-item.unread {
                    background: rgb(var(--bk-white-rgb));
                    border-color: rgba(var(--bk-primary-rgb), 0.18);
                    box-shadow: inset 4px 0 0 rgb(var(--bk-primary-rgb)), 0 8px 18px rgba(var(--bk-primary-rgb), 0.08);
                }

                .bk-notif-meta {
                    color: rgb(var(--bk-muted-rgb));
                    font-size: 0.72rem;
                    margin-top: 0.45rem;
                    font-weight: 700;
                }

                .bk-notif-foot {
                    padding: 0.9rem 1rem 1rem;
                    border-top: 1px solid rgba(var(--bk-border-rgb), 1);
                    background: rgba(var(--bk-white-rgb), 1);
                }

                .bk-notif-foot button {
                    width: 100%;
                    min-height: 2.72rem;
                    border: 1px solid rgba(var(--bk-primary-rgb), 0.16);
                    border-radius: 0.92rem;
                    background: rgba(var(--bk-primary-rgb), 0.07);
                    color: rgb(var(--bk-primary-rgb));
                    font-size: 0.8rem;
                    font-weight: 900;
                    letter-spacing: 0.02em;
                    transition: background-color 0.16s ease, border-color 0.16s ease;
                }

                .bk-notif-foot button:hover:not(:disabled) {
                    background: rgba(var(--bk-primary-rgb), 0.11);
                    border-color: rgba(var(--bk-primary-rgb), 0.22);
                }

                .bk-notif-foot button:disabled {
                    color: rgb(var(--bk-muted-rgb));
                    background: rgba(var(--bk-bg-rgb), 0.84);
                    border-color: rgba(var(--bk-border-rgb), 1);
                    cursor: default;
                }

                .bk-app-overlay {
                    display: none;
                    position: fixed;
                    inset: 0;
                    z-index: 1090;
                    background: rgba(3, 78, 162, 0.56);
                }

                .bk-notif-item.unread::before {
                    content: "";
                    position: absolute;
                    top: 1rem;
                    right: 1rem;
                    width: 0.5rem;
                    height: 0.5rem;
                    border-radius: 999px;
                    background: rgb(var(--bk-primary-rgb));
                }

                body.bk-nav-collapsed {
                    --bk-sidebar-width: 106px;
                }

                body.bk-nav-collapsed .bk-app-sidebar-inner {
                    padding-inline: 0.72rem;
                }

                body.bk-nav-collapsed .bk-app-brand {
                    display: grid;
                    justify-items: center;
                    gap: 0.55rem;
                    padding-inline: 0;
                }

                body.bk-nav-collapsed .bk-app-brand-main {
                    display: grid;
                    justify-items: center;
                    gap: 0.5rem;
                }

                body.bk-nav-collapsed .bk-app-brand-text,
                body.bk-nav-collapsed .bk-app-link-main > span:last-child,
                body.bk-nav-collapsed .bk-app-notif-copy,
                body.bk-nav-collapsed .bk-app-logout .bk-app-link-main > span:last-child {
                    display: none !important;
                }

                body.bk-nav-collapsed .bk-app-menu {
                    padding-right: 0;
                }

                body.bk-nav-collapsed .bk-app-sidebar-footer {
                    display: grid;
                    justify-items: center;
                    gap: 0.62rem;
                    padding-top: 0.9rem;
                }

                body.bk-nav-collapsed .bk-app-link,
                body.bk-nav-collapsed .bk-app-logout {
                    justify-content: center;
                    padding-inline: 0.35rem;
                }

                body.bk-nav-collapsed .bk-app-link-main {
                    justify-content: center;
                    width: 100%;
                }

                body.bk-nav-collapsed .bk-app-notif-launch {
                    position: relative;
                    width: 3.25rem;
                    flex: 0 0 3.25rem;
                    height: 3.25rem;
                    min-height: 3.25rem;
                    padding: 0;
                    margin-bottom: 0;
                    border-radius: 1rem;
                    justify-content: center;
                    gap: 0;
                    box-sizing: border-box;
                }

                body.bk-nav-collapsed .bk-app-notif-main {
                    justify-content: center;
                    width: auto;
                    flex: 0 0 auto;
                }

                body.bk-nav-collapsed .bk-app-notif-icon {
                    width: 2.25rem;
                    height: 2.25rem;
                    border-radius: 0.82rem;
                    background: rgba(255, 255, 255, 0.2);
                    border-color: rgba(255, 255, 255, 0.32);
                    color: #ffffff;
                }

                body.bk-nav-collapsed .bk-app-notif-icon svg {
                    width: 1.18rem;
                    height: 1.18rem;
                }

                body.bk-nav-collapsed .bk-app-badge {
                    position: absolute;
                    top: 0.18rem;
                    right: 0.18rem;
                    margin-left: 0;
                    min-width: 1.14rem;
                    height: 1.14rem;
                    padding: 0 0.24rem;
                    box-shadow: 0 8px 14px rgba(0, 0, 0, 0.16);
                    font-size: 0.62rem;
                    line-height: 1;
                }

                body.bk-nav-collapsed .bk-app-logout {
                    width: 3.25rem;
                    min-height: 3.25rem;
                    padding: 0;
                    border-radius: 1rem;
                }

                body.bk-nav-collapsed .bk-notif-panel {
                    left: calc(var(--bk-sidebar-width) + 1rem);
                    right: auto;
                }

                @media (max-width: 991.98px) {
                    .bk-app-sidebar {
                        width: min(86vw, var(--bk-sidebar-width));
                        transform: translateX(-100%);
                    }

                    .bk-app-sidebar.open {
                        transform: translateX(0);
                    }

                    .bk-app-overlay.show {
                        display: block;
                    }

                    .bk-app-floating-menu-toggle {
                        display: inline-flex;
                    }

                    .bk-app-sidebar-toggle {
                        display: none;
                    }

                    .bk-notif-panel {
                        top: 0.75rem;
                        left: 0.65rem;
                        right: 0.65rem;
                        width: auto;
                        max-height: calc(100vh - 1.5rem);
                    }

                    .main-content,
                    .chat-container,
                    .profile-container,
                    .bk-chat-page,
                    .bk-profile-wrap {
                        margin-left: 0 !important;
                        width: 100% !important;
                    }
                }
            </style>
            <?php
        }
        ?>
        <aside class="bk-app-sidebar" id="bkAppSidebar" aria-label="Sidebar navigation">
            <div class="bk-app-sidebar-inner">
                <div class="bk-app-brand">
                    <div class="bk-app-brand-main">
                        <img src="<?php echo bk_e($basePath . '/Images/logo.png'); ?>" alt="BK logo">
                        <div class="bk-app-brand-text">
                            <strong>UDCS</strong>
                            <span class="bk-app-portal-tag"><?php echo bk_e($portalLabel); ?></span>
                        </div>
                    </div>
                    <button type="button" class="bk-app-sidebar-toggle" id="bkSidebarCollapseToggle" aria-label="Collapse sidebar" title="Collapse sidebar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M15 6l-6 6 6 6"></path>
                        </svg>
                    </button>
                </div>

                <nav class="bk-app-menu">
                    <?php foreach ($menu as $item): ?>
                        <?php
                        $href = (string) ($item['href'] ?? '#');
                        $label = (string) ($item['label'] ?? 'Link');
                        $menuKey = strtolower(trim($label));
                        $itemIcon = strtolower(trim((string) ($item['icon'] ?? '')));
                        $activeOn = $item['active_on'] ?? [];
                        if (!is_array($activeOn)) {
                            $activeOn = [$activeOn];
                        }
                        $activePages = [basename($href)];
                        foreach ($activeOn as $activeEntry) {
                            $activeEntry = trim((string) $activeEntry);
                            if ($activeEntry !== '') {
                                $activePages[] = basename($activeEntry);
                            }
                        }
                        $activePages = array_values(array_unique(array_filter($activePages)));
                        $allowedIcons = ['dashboard', 'submit', 'claims', 'accounts', 'reports', 'activity', 'messaging', 'profile', 'logout'];
                        $iconKey = in_array($itemIcon, $allowedIcons, true) ? $itemIcon : match ($menuKey) {
                            'dashboard' => 'dashboard',
                            'submit claim', 'submit a claim' => 'submit',
                            'my claims', 'claims' => 'claims',
                            'accounts', 'manage accounts' => 'accounts',
                            'reports', 'export reports' => 'reports',
                            'activity', 'activity trail' => 'activity',
                            'messaging', 'open messaging' => 'messaging',
                            'profile', 'profile settings' => 'profile',
                            default => 'dashboard',
                        };
                        $iconSvg = bk_nav_icon_svg($iconKey);
                        $active = !empty($item['active']) || in_array($currentPage, $activePages, true);
                        ?>
                        <a href="<?php echo bk_e($href); ?>" class="bk-app-link<?php echo $active ? ' is-active' : ''; ?>">
                            <span class="bk-app-link-main">
                                <span class="bk-app-link-icon" aria-hidden="true"><?php echo $iconSvg; ?></span>
                                <span><?php echo bk_e($label); ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="bk-app-sidebar-footer">
                    <button type="button" class="bk-app-notif-launch" id="bkNotifToggle" aria-label="Open notifications">
                        <span class="bk-app-notif-main">
                            <span class="bk-app-notif-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6.8 9.1a5.2 5.2 0 1 1 10.4 0c0 5.1 2 6.3 2 6.3H4.8s2-1.2 2-6.3" />
                                    <path d="M10 18.6a2.3 2.3 0 0 0 4 0" />
                                </svg>
                            </span>
                            <span class="bk-app-notif-copy">
                                <span class="bk-app-notif-title">Alerts</span>
                                <span class="bk-app-notif-state" id="bkNotifState">No new alerts</span>
                            </span>
                        </span>
                        <span id="bkNotifCount" class="bk-app-badge is-hidden">0</span>
                    </button>
                    <a href="<?php echo bk_e($logoutHref); ?>" class="bk-app-logout">
                        <span class="bk-app-link-main">
                            <span class="bk-app-link-icon" aria-hidden="true"><?php echo bk_nav_icon_svg('logout'); ?></span>
                            <span>Logout</span>
                        </span>
                    </a>
                </div>
            </div>
        </aside>

        <button type="button" class="bk-app-floating-menu-toggle" id="bkAppMenuToggle" aria-label="Toggle menu">Menu</button>
        <div class="bk-app-overlay" id="bkAppOverlay"></div>

        <section class="bk-notif-panel" id="bkNotifPanel" style="display:none;" aria-live="polite">
            <div class="bk-notif-head">
                <div class="bk-notif-head-main">
                    <span class="bk-notif-head-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6.8 9.1a5.2 5.2 0 1 1 10.4 0c0 5.1 2 6.3 2 6.3H4.8s2-1.2 2-6.3" />
                            <path d="M10 18.6a2.3 2.3 0 0 0 4 0" />
                        </svg>
                    </span>
                    <div class="bk-notif-head-copy">
                        <strong>Alerts</strong>
                        <span id="bkNotifPanelSubcopy">No alerts yet</span>
                    </div>
                </div>
                <span id="bkNotifPanelCount" class="bk-notif-head-status">0 unread</span>
            </div>
            <div id="bkNotifList" class="bk-notif-list">
                <div class="bk-notif-empty">Loading notifications...</div>
            </div>
            <div class="bk-notif-foot">
                <button type="button" id="bkMarkAllRead">Mark all as read</button>
            </div>
        </section>

        <script>
            (function () {
                var sidebar = document.getElementById('bkAppSidebar');
                var overlay = document.getElementById('bkAppOverlay');
                var menuToggle = document.getElementById('bkAppMenuToggle');
                var collapseToggle = document.getElementById('bkSidebarCollapseToggle');
                var notifToggle = document.getElementById('bkNotifToggle');
                var notifPanel = document.getElementById('bkNotifPanel');
                var notifList = document.getElementById('bkNotifList');
                var notifCount = document.getElementById('bkNotifCount');
                var notifState = document.getElementById('bkNotifState');
                var notifPanelCount = document.getElementById('bkNotifPanelCount');
                var notifPanelSubcopy = document.getElementById('bkNotifPanelSubcopy');
                var markAllBtn = document.getElementById('bkMarkAllRead');
                var notifCsrfToken = <?php echo json_encode($notifCsrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                var collapseStorageKey = 'udcsSidebarCollapsed';
                var lastUnreadCount = 0;
                var lastTotalCount = 0;
                var currentLatestNotifId = 0;
                var serverLastOpenedNotifId = 0;

                function isNotifPanelOpen() {
                    return !!(notifPanel && notifPanel.style.display !== 'none' && notifPanel.style.display !== '');
                }

                function applySidebarCollapse(collapsed) {
                    if (window.innerWidth < 992) {
                        document.body.classList.remove('bk-nav-collapsed');
                        return;
                    }
                    document.body.classList.toggle('bk-nav-collapsed', collapsed);
                    if (collapseToggle) {
                        collapseToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
                        collapseToggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
                        collapseToggle.style.transform = collapsed ? 'rotate(180deg)' : '';
                    }
                }

                function readSidebarCollapsePreference() {
                    try {
                        return localStorage.getItem(collapseStorageKey) === '1';
                    } catch (error) {
                        return false;
                    }
                }

                function saveSidebarCollapsePreference(collapsed) {
                    try {
                        localStorage.setItem(collapseStorageKey, collapsed ? '1' : '0');
                    } catch (error) {
                        // Ignore storage failures.
                    }
                }

                function closeSidebar() {
                    if (!sidebar || !overlay) return;
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                }

                function openSidebar() {
                    if (!sidebar || !overlay) return;
                    sidebar.classList.add('open');
                    overlay.classList.add('show');
                }

                if (menuToggle) {
                    menuToggle.addEventListener('click', function () {
                        if (sidebar.classList.contains('open')) {
                            closeSidebar();
                        } else {
                            openSidebar();
                        }
                    });
                }

                if (collapseToggle) {
                    collapseToggle.addEventListener('click', function () {
                        var collapsed = !document.body.classList.contains('bk-nav-collapsed');
                        saveSidebarCollapsePreference(collapsed);
                        applySidebarCollapse(collapsed);
                    });
                }

                if (overlay) {
                    overlay.addEventListener('click', function () {
                        closeSidebar();
                        if (notifPanel) notifPanel.style.display = 'none';
                    });
                }

                function formatTime(ts) {
                    if (!ts) return '';
                    var d = new Date(ts);
                    if (Number.isNaN(d.getTime())) return '';
                    return d.toLocaleString();
                }

                function parseJsonResponse(response) {
                    if (!response.ok) {
                        throw new Error('Request failed');
                    }
                    return response.json();
                }

                function applyNotificationState(unread, total, unopened) {
                    var unreadCount = Math.max(0, Number(unread) || 0);
                    var totalCount = Math.max(0, Number(total) || 0);
                    var unopenedCount = Math.max(0, Number(unopened) || 0);
                    if (notifCount) {
                        notifCount.textContent = String(unreadCount);
                        notifCount.classList.toggle('is-hidden', unreadCount <= 0);
                    }
                    if (notifPanelCount) {
                        notifPanelCount.textContent = unreadCount === 1 ? '1 unread' : (String(unreadCount) + ' unread');
                    }
                    if (notifState) {
                        if (unopenedCount > 0) {
                            notifState.textContent = unopenedCount === 1 ? '1 new alert' : (String(unopenedCount) + ' new alerts');
                        } else if (unreadCount > 0) {
                            notifState.textContent = unreadCount === 1 ? '1 unread in panel' : (String(unreadCount) + ' unread in panel');
                        } else if (totalCount > 0) {
                            notifState.textContent = 'All alerts checked';
                        } else {
                            notifState.textContent = 'No new alerts';
                        }
                    }
                    if (notifPanelSubcopy) {
                        if (totalCount > 0) {
                            notifPanelSubcopy.textContent = totalCount === 1
                                ? '1 alert in your activity feed'
                                : (String(totalCount) + ' alerts in your activity feed');
                        } else {
                            notifPanelSubcopy.textContent = 'No alerts yet';
                        }
                    }
                    if (notifToggle) {
                        notifToggle.classList.toggle('has-unread', unopenedCount > 0);
                        if (unopenedCount > 0) {
                            notifToggle.setAttribute('aria-label', unopenedCount === 1 ? 'Open notifications. 1 new alert.' : ('Open notifications. ' + unopenedCount + ' new alerts.'));
                        } else if (unreadCount > 0) {
                            notifToggle.setAttribute('aria-label', unreadCount === 1 ? 'Open notifications. 1 unread alert inside panel.' : ('Open notifications. ' + unreadCount + ' unread alerts inside panel.'));
                        } else {
                            notifToggle.setAttribute('aria-label', 'Open notifications');
                        }
                    }
                    if (markAllBtn) {
                        markAllBtn.disabled = unreadCount <= 0;
                    }
                }

                function renderNotifications(payload) {
                    var items = Array.isArray(payload) ? payload : (Array.isArray(payload && payload.items) ? payload.items : []);
                    if (!Array.isArray(items)) {
                        notifList.innerHTML = '<div class="bk-notif-empty">No notifications are available right now.</div>';
                        lastUnreadCount = 0;
                        lastTotalCount = 0;
                        currentLatestNotifId = 0;
                        serverLastOpenedNotifId = 0;
                        applyNotificationState(0, 0, 0);
                        return;
                    }

                    var unread = typeof (payload && payload.unread_count) !== 'undefined'
                        ? Math.max(0, Number(payload.unread_count) || 0)
                        : items.filter(function (n) { return String(n.status).toLowerCase() === 'unread'; }).length;
                    currentLatestNotifId = Math.max(0, Number(payload && payload.latest_id) || 0);
                    serverLastOpenedNotifId = Math.max(0, Number(payload && payload.last_opened_id) || 0);
                    lastUnreadCount = unread;
                    lastTotalCount = typeof (payload && payload.total_count) !== 'undefined'
                        ? Math.max(0, Number(payload.total_count) || 0)
                        : items.length;
                    var unopened = typeof (payload && payload.unopened_count) !== 'undefined'
                        ? Math.max(0, Number(payload.unopened_count) || 0)
                        : items.filter(function (n) { return !!(n && n.is_unopened); }).length;
                    applyNotificationState(unread, lastTotalCount, unopened);

                    if (!items.length) {
                        notifList.innerHTML = '<div class="bk-notif-empty">No notifications yet.</div>';
                        return;
                    }

                    notifList.innerHTML = items.map(function (n) {
                        var unreadClass = String(n.status).toLowerCase() === 'unread' ? ' unread' : '';
                        var msg = n.message ? String(n.message) : 'Notification';
                        var id = n.id ? String(n.id) : '';
                        var createdAt = formatTime(n.created_at);
                        var stateLabel = unreadClass ? 'Unread' : 'Read';
                        var markBtn = id && String(n.status).toLowerCase() === 'unread'
                            ? '<button type="button" class="bk-app-top-btn bk-app-mark-read" data-notif-id="' + id + '">Mark read</button>'
                            : '';

                        return '<article class="bk-notif-item' + unreadClass + '">' +
                            '<div class="bk-notif-item-head">' +
                                '<span class="bk-notif-item-status">' + stateLabel + '</span>' +
                                markBtn +
                            '</div>' +
                            '<div class="bk-notif-item-message">' + msg + '</div>' +
                            '<div class="bk-notif-meta">' + createdAt + '</div>' +
                            '</article>';
                    }).join('');
                }

                function markNotificationsOpened(latestId) {
                    return fetch('mark_notifications_opened.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'latest_id=' + encodeURIComponent(latestId || 0) + '&csrf_token=' + encodeURIComponent(notifCsrfToken)
                    }).then(parseJsonResponse);
                }

                function loadNotifications(markOpened) {
                    fetch('get_notifications.php')
                        .then(parseJsonResponse)
                        .then(function (payload) {
                            if (markOpened === true) {
                                var latestId = Math.max(0, Number(payload && payload.latest_id) || 0);
                                if (latestId > 0) {
                                    return markNotificationsOpened(latestId)
                                        .then(function () {
                                            payload.last_opened_id = latestId;
                                            payload.unopened_count = 0;
                                            return payload;
                                        })
                                        .catch(function () {
                                            return payload;
                                        });
                                }
                            }
                            return payload;
                        })
                        .then(function (payload) {
                            renderNotifications(payload);
                        })
                        .catch(function () {
                            notifList.innerHTML = '<div class="bk-notif-empty">We could not load notifications. Please try again.</div>';
                        });
                }

                function markRead(id, triggerBtn) {
                    if (triggerBtn) {
                        triggerBtn.disabled = true;
                    }
                    fetch('mark_notification_read.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(notifCsrfToken)
                    })
                        .then(parseJsonResponse)
                        .then(function (payload) {
                            if (!payload || payload.success !== true) {
                                throw new Error((payload && payload.message) ? payload.message : 'Unable to mark notification as read.');
                            }
                            loadNotifications(false);
                        })
                        .catch(function () {
                            loadNotifications(false);
                        })
                        .finally(function () {
                            if (triggerBtn) {
                                triggerBtn.disabled = false;
                            }
                        });
                }

                window.bkMarkRead = function (id) {
                    markRead(id, null);
                };

                if (notifList) {
                    notifList.addEventListener('click', function (event) {
                        var target = event.target;
                        if (!target) return;
                        var btn = target.closest('[data-notif-id]');
                        if (!btn) return;
                        var id = btn.getAttribute('data-notif-id');
                        if (!id) return;
                        markRead(id, btn);
                    });
                }

                if (markAllBtn) {
                    markAllBtn.addEventListener('click', function () {
                        markAllBtn.disabled = true;
                        fetch('mark_all_notifications_read.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'csrf_token=' + encodeURIComponent(notifCsrfToken)
                        })
                            .then(parseJsonResponse)
                            .then(function (payload) {
                                if (!payload || payload.success !== true) {
                                    throw new Error((payload && payload.message) ? payload.message : 'Unable to mark all notifications as read.');
                                }
                                loadNotifications(false);
                            })
                            .catch(function () {
                                loadNotifications(false);
                            })
                            .finally(function () {
                                markAllBtn.disabled = false;
                            });
                    });
                }

                if (notifToggle) {
                    notifToggle.addEventListener('click', function () {
                        if (!notifPanel) return;
                        if (notifPanel.style.display === 'none' || notifPanel.style.display === '') {
                            notifPanel.style.display = 'block';
                            loadNotifications(true);
                        } else {
                            notifPanel.style.display = 'none';
                        }
                    });
                }

                document.addEventListener('click', function (event) {
                    if (notifPanel && notifToggle) {
                        var insidePanel = notifPanel.contains(event.target);
                        var insideToggle = notifToggle.contains(event.target);
                        if (!insidePanel && !insideToggle) {
                            notifPanel.style.display = 'none';
                        }
                    }
                });

                applySidebarCollapse(readSidebarCollapsePreference());
                window.addEventListener('resize', function () {
                    applySidebarCollapse(readSidebarCollapsePreference());
                });
                loadNotifications();
                setInterval(function () {
                    loadNotifications(isNotifPanelOpen());
                }, 30000);
            })();
        </script>
        <script>
            (function () {
                var roleClass = 'bk-role-' + <?php echo json_encode($role); ?>;
                document.body.classList.add('bk-role-page');
                if (roleClass) {
                    document.body.classList.add(roleClass);
                }
            })();
        </script>
        <?php
    }
}



