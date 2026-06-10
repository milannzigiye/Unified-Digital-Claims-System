<?php
include '../connect.php';
require_once '../security.php';
secure_session_start();

header('Content-Type: application/json; charset=UTF-8');

$identity = udcs_notifications_identity_from_session($conn, 'finance');
if (!$identity) {
    echo json_encode(['items' => [], 'unread_count' => 0, 'total_count' => 0, 'unopened_count' => 0, 'latest_id' => 0, 'last_opened_id' => 0]);
    exit();
}

echo json_encode(
    udcs_notifications_fetch_for_user(
        $conn,
        (int) $identity['user_id'],
        (string) $identity['email']
    ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
