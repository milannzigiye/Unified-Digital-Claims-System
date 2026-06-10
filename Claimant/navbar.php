<?php
// Tags: [CLAIMANT]
require_once dirname(__DIR__) . '/components/role_nav.php';

$userName = $claimant_name ?? ($_SESSION['full_name'] ?? 'Claimant');
$userPhoto = $photo ?? '../Images/logo.png';

render_role_nav([
    'role' => 'claimant',
    'user_name' => $userName,
    'photo' => $userPhoto,
    'base_path' => '..',
    'menu' => [
        ['href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['href' => 'form_v2.php', 'label' => 'Submit Claim', 'icon' => 'submit', 'active_on' => ['form.php', 'form_v2.php']],
        ['href' => 'claims.php', 'label' => 'My Claims', 'icon' => 'claims'],
        ['href' => 'messaging.php', 'label' => 'Messaging', 'icon' => 'messaging'],
        ['href' => 'profile.php', 'label' => 'Profile', 'icon' => 'profile'],
    ],
    'logout_href' => 'logout.php',
]);


