<?php
// Tags: [LEGAL]
require_once dirname(__DIR__) . '/components/role_nav.php';

$userName = $claimant_name ?? ($full_name ?? ($_SESSION['full_name'] ?? 'Legal User'));
$userPhoto = $photo ?? ($user_photo ?? '../Images/logo.png');

render_role_nav([
    'role' => 'legal',
    'user_name' => $userName,
    'photo' => $userPhoto,
    'base_path' => '..',
    'menu' => [
        ['href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['href' => 'claims.php', 'label' => 'Claims', 'icon' => 'claims'],
        ['href' => 'messaging.php', 'label' => 'Messaging', 'icon' => 'messaging'],
        ['href' => 'profile.php', 'label' => 'Profile', 'icon' => 'profile'],
    ],
    'logout_href' => 'logout.php',
]);


