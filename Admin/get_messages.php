<?php
// Tags: [ADMIN] [MSG]
require_once '../security.php';
secure_session_start();
include '../connect.php';

if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
    exit();
}

$session_user_id = (int) ($_SESSION['user_id'] ?? 0);
$sender_id = $session_user_id > 0 ? $session_user_id : intval($_GET['sender_id'] ?? 0);
$receiver_id = intval($_GET['receiver_id'] ?? 0);
$mode = $_GET['mode'] ?? 'all';
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

if ($sender_id <= 0 || $receiver_id <= 0) {
    exit();
}

$sql = "SELECT m.*, 
               u1.full_name as sender_name, u1.photo as sender_photo,
               u2.full_name as receiver_name, u2.photo as receiver_photo
        FROM messages m
        JOIN users u1 ON m.sender_id = u1.id
        JOIN users u2 ON m.receiver_id = u2.id
        WHERE ((m.sender_id = ? AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = ?))";
$types = 'iiii';
$params = [$sender_id, $receiver_id, $receiver_id, $sender_id];

if ($mode === 'new') {
    $sql .= " AND m.id > ?";
    $types .= 'i';
    $params[] = $last_id;
}

$sql .= " ORDER BY m.timestamp ASC";
$stmt = mysqli_prepare($conn, $sql);
$result = false;
if ($stmt) {
    udcs_db_stmt_bind($stmt, $types, $params);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
    }
}

if (!$result) {
    if ($mode === 'new') {
        echo 'no-new-messages';
    }
    exit();
}

// For new messages mode, check if no new messages
if ($mode === 'new' && mysqli_num_rows($result) === 0) {
    echo 'no-new-messages';
    exit();
}

function formatTime($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' min ago';
    } elseif (date('Y-m-d') == date('Y-m-d', $time)) {
        return date('H:i', $time);
    } elseif (date('Y', $time) == date('Y')) {
        return date('M j, H:i', $time);
    } else {
        return date('Y-m-d H:i', $time);
    }
}

$output = '';
$has_messages = false;
while($message = $result->fetch_assoc()):
    $has_messages = true;
    $is_sent = $message['sender_id'] == $sender_id;
    $message_class = $is_sent ? 'sent' : 'received';
    $time = formatTime($message['timestamp']);
    
    $output .= '<div class="message ' . $message_class . '" data-message-id="' . $message['id'] . '">';
    $output .= '<div class="message-text">';
    $output .= nl2br(htmlspecialchars($message['message']));
    $output .= '</div>';
    $output .= '<div class="message-header">';
    $output .= '<span>' . date('g:i A', strtotime($message['timestamp'])) . '</span>';
    $output .= '</div>';
    $output .= '</div>';
endwhile;

if (!$has_messages && $mode === 'all') {
    echo '<div class="no-messages">No messages yet. Send the first message to start this conversation.</div>';
} else {
    echo $output;
}
?>

