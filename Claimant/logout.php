<?php
// Tags: [CLAIMANT] [AUTH]
require_once '../security.php';
secure_session_start();
include '../connect.php';
require_once dirname(__DIR__) . '/components/workflow.php';

bk_activity_log($conn, [
    'actor_id' => (int) ($_SESSION['user_id'] ?? 0),
    'actor_role' => (string) ($_SESSION['role'] ?? 'claimant'),
    'action_key' => 'logout',
    'action_label' => 'Logout',
    'details' => 'User signed out from claimant portal.',
]);

session_destroy();
header('Location:../index.php');
exit();
?>

