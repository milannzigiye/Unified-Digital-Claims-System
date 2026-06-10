<?php
// Tags: [FINANCE] [ROUTE]
include '../connect.php';
require_once '../security.php';
secure_session_start();

// Reports view was merged into Claims. Keep this route for old bookmarks.
if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'finance') {
    header('Location: ../login.php');
    exit();
}

$query = $_GET;
$query['notice'] = 'reports_merged';
header('Location: claims.php?' . http_build_query($query));
exit();

