<?php
// Tags: [CLAIMANT] [LEGACY] [REDIRECT]
require_once '../security.php';
secure_session_start();

$target = 'form_v2.php';
$query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target);
exit();
