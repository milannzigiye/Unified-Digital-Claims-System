<?php
include '../connect.php';
require_once '../security.php';
secure_session_start();

header('Content-Type: application/json; charset=UTF-8');

function respond_json(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(false, 'Invalid request method.');
}

if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'notif_action')) {
    respond_json(false, 'Session security check failed. Please refresh and try again.');
}

$identity = udcs_notifications_identity_from_session($conn, 'admin');
if (!$identity) {
    respond_json(false, 'Not authenticated.');
}

$affected = udcs_notifications_mark_read(
    $conn,
    (int) $identity['user_id'],
    (string) $identity['email'],
    (int) ($_POST['id'] ?? 0)
);

respond_json(true, 'Notification marked as read.', ['affected' => $affected]);
