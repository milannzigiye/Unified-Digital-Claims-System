<?php
// Tags: [ADMIN]
require_once dirname(__DIR__) . '/components/role_nav.php';

$userName = $admin_name ?? ($_SESSION['full_name'] ?? 'Administrator');
$userPhoto = $photo ?? '../Images/logo.png';

render_role_nav([
    'role' => 'admin',
    'user_name' => $userName,
    'photo' => $userPhoto,
    'base_path' => '..',
    'menu' => [
        ['href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['href' => 'accounts.php', 'label' => 'Accounts', 'icon' => 'accounts'],
        ['href' => 'claims.php', 'label' => 'Claims', 'icon' => 'claims'],
        ['href' => 'activity.php', 'label' => 'Activity', 'icon' => 'activity'],
        ['href' => 'messaging.php', 'label' => 'Messaging', 'icon' => 'messaging'],
        ['href' => 'profile.php', 'label' => 'Profile', 'icon' => 'profile'],
    ],
    'logout_href' => 'logout.php',
]);

