<?php
// Tags: [CLAIMANT] [MSG]
require_once '../security.php';
include '../connect.php';
require_once dirname(__DIR__) . '/components/workflow.php';
secure_session_start();

header('Content-Type: application/json; charset=UTF-8');

function respond_json(bool $success, string $message): void
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
    ]);
    exit();
}

function resolve_display_name(mysqli $conn, int $userId, string $fallback): string
{
    $stmt = mysqli_prepare($conn, 'SELECT full_name, role FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return $fallback;
    }

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $name = $fallback;

    if ($result && ($row = mysqli_fetch_assoc($result))) {
        $fullName = trim((string) ($row['full_name'] ?? ''));
        $role = trim((string) ($row['role'] ?? ''));
        if ($fullName !== '') {
            $name = $fullName;
        } elseif ($role !== '') {
            $name = ucfirst($role);
        }
    }

    mysqli_stmt_close($stmt);
    return $name;
}

if (!isset($_SESSION['email']) || (($_SESSION['role'] ?? '') !== 'claimant')) {
    respond_json(false, 'You are not authorized to send this message.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(false, 'Invalid request method.');
}

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (!udcs_csrf_validate($csrfToken, 'message_send')) {
    respond_json(false, 'Session security check failed. Please refresh and try again.');
}

$senderId = (int) ($_POST['sender_id'] ?? 0);
$receiverId = (int) ($_POST['receiver_id'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));

if ($senderId <= 0 || $receiverId <= 0 || $message === '') {
    respond_json(false, 'Please select a contact and enter a message.');
}

$checkStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE id = ? AND email = ? AND role = ? LIMIT 1');
if (!$checkStmt) {
    respond_json(false, 'Unable to validate sender account.');
}

$email = (string) ($_SESSION['email'] ?? '');
$role = 'claimant';
mysqli_stmt_bind_param($checkStmt, 'iss', $senderId, $email, $role);
mysqli_stmt_execute($checkStmt);
$checkRes = mysqli_stmt_get_result($checkStmt);
mysqli_stmt_close($checkStmt);

if (!$checkRes || mysqli_num_rows($checkRes) === 0) {
    respond_json(false, 'Unable to validate sender account.');
}

$msgStmt = mysqli_prepare($conn, 'INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)');
if (!$msgStmt) {
    respond_json(false, 'Message could not be sent at this time.');
}

mysqli_stmt_bind_param($msgStmt, 'iis', $senderId, $receiverId, $message);
if (!mysqli_stmt_execute($msgStmt)) {
    mysqli_stmt_close($msgStmt);
    respond_json(false, 'Message could not be sent at this time.');
}
mysqli_stmt_close($msgStmt);

$senderName = resolve_display_name($conn, $senderId, 'Claimant');
$receiverName = resolve_display_name($conn, $receiverId, 'Support Team');

$receiverNotifMsg = 'New message from ' . $senderName . '.';
$senderNotifMsg = 'You sent a message to ' . $receiverName . '.';
udcs_db_insert_notification($conn, (string) $receiverId, (string) $senderId, $receiverNotifMsg);
udcs_db_insert_notification($conn, (string) $senderId, (string) $receiverId, $senderNotifMsg);

$preview = substr($message, 0, 120);
bk_activity_log($conn, [
    'actor_id' => $senderId,
    'actor_role' => 'claimant',
    'action_key' => 'message_sent',
    'action_label' => 'Direct Message Sent',
    'details' => 'Claimant sent a direct message to ' . $receiverName . '.',
    'meta' => [
        'receiver_id' => $receiverId,
        'receiver_name' => $receiverName,
        'message_preview' => $preview,
    ],
]);

respond_json(true, 'Message sent successfully.');
?>
