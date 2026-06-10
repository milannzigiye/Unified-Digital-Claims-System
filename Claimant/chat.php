<?php
// Tags: [CLAIMANT] [MSG]
require_once '../security.php';
secure_session_start();
require_once '../connect.php';
require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';

// Security check
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'claimant')) {
    header('Location: ../claimant-access.php?error=unauthorized');
    exit();
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? 'User';

// Get all chat rooms for current user
$chat_types = [];

// 1. Claim-related chats
$claim_chats_query = "SELECT 
    cr.*,
    c.id as claim_id,
    c.claim_type,
    COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
    u.full_name as claimant_name,
    CONCAT('CL-', LPAD(c.id, 6, '0')) as claim_number,
    COALESCE(ca.asset_classes, '') AS asset_classes,
    'claim' as chat_type_group
FROM chat_rooms cr
JOIN claims c ON cr.claim_id = c.id
JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
LEFT JOIN (
    SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
    FROM claim_assets
    GROUP BY claim_id
) ca ON ca.claim_id = c.id
WHERE cr.id IN (SELECT room_id FROM chat_participants WHERE user_id = ?)
AND cr.chat_type = 'claim'
ORDER BY cr.last_message_at DESC";
$claim_chats_stmt = mysqli_prepare($conn, $claim_chats_query);
$claim_chats_result = false;
if ($claim_chats_stmt) {
    mysqli_stmt_bind_param($claim_chats_stmt, 'i', $user_id);
    if (mysqli_stmt_execute($claim_chats_stmt)) {
        $claim_chats_result = mysqli_stmt_get_result($claim_chats_stmt);
    }
}
if ($claim_chats_result) {
    while ($row = mysqli_fetch_assoc($claim_chats_result)) {
        $row['display_name'] = "Claim: " . $row['claim_number'] . " - " . $row['claimant_name'];
        $row['icon'] = 'file-invoice-dollar';
        $row['asset_summary'] = udcs_claim_asset_summary_label((string) ($row['asset_classes'] ?? ''), (string) ($row['claim_type'] ?? ''));
        $row['status_label'] = udcs_claim_status_label((string) ($row['effective_status'] ?? ''));
        $row['description'] = $row['asset_summary'] . ' - Status: ' . $row['status_label'];
        $chat_types['claims'][] = $row;
    }
}

// 2. Direct messages (user-to-user chats)
$direct_chats_query = "SELECT 
    cr.*,
    u.full_name as other_user_name,
    u.role as other_user_role,
    u.email as other_user_email,
    'direct' as chat_type_group
FROM chat_rooms cr
JOIN chat_participants cp1 ON cr.id = cp1.room_id
JOIN chat_participants cp2 ON cr.id = cp2.room_id
JOIN users u ON cp2.user_id = u.id
WHERE cp1.user_id = ?
AND cp2.user_id != ?
AND cr.chat_type = 'direct'
AND (SELECT COUNT(*) FROM chat_participants WHERE room_id = cr.id) = 2
ORDER BY cr.last_message_at DESC";

$direct_chats_stmt = mysqli_prepare($conn, $direct_chats_query);
$direct_chats_result = false;
if ($direct_chats_stmt) {
    mysqli_stmt_bind_param($direct_chats_stmt, 'ii', $user_id, $user_id);
    if (mysqli_stmt_execute($direct_chats_stmt)) {
        $direct_chats_result = mysqli_stmt_get_result($direct_chats_stmt);
    }
}
if ($direct_chats_result) {
    while ($row = mysqli_fetch_assoc($direct_chats_result)) {
        $row['display_name'] = $row['other_user_name'];
        $row['icon'] = 'user';
        $row['description'] = ucfirst($row['other_user_role']);
        $chat_types['direct'][] = $row;
    }
}

// 3. Group chats
$group_chats_query = "SELECT 
    cr.*,
    'group' as chat_type_group,
    (SELECT COUNT(*) FROM chat_participants WHERE room_id = cr.id) as member_count
FROM chat_rooms cr
WHERE cr.id IN (SELECT room_id FROM chat_participants WHERE user_id = ?)
AND cr.chat_type = 'group'
ORDER BY cr.last_message_at DESC";

$group_chats_stmt = mysqli_prepare($conn, $group_chats_query);
$group_chats_result = false;
if ($group_chats_stmt) {
    mysqli_stmt_bind_param($group_chats_stmt, 'i', $user_id);
    if (mysqli_stmt_execute($group_chats_stmt)) {
        $group_chats_result = mysqli_stmt_get_result($group_chats_stmt);
    }
}
if ($group_chats_result) {
    while ($row = mysqli_fetch_assoc($group_chats_result)) {
        $row['display_name'] = $row['chat_title'] ?: 'Group Chat';
        $row['icon'] = 'users';
        $row['description'] = $row['member_count'] . " members";
        $chat_types['group'][] = $row;
    }
}

// Get all staff members for starting new chats
$staff_query = "SELECT id, full_name, role, email, role 
               FROM users 
               WHERE id != ? 
               AND role IN ('admin', 'legal', 'finance')
               ORDER BY role, full_name";

$staff_stmt = mysqli_prepare($conn, $staff_query);
$staff_result = false;
if ($staff_stmt) {
    mysqli_stmt_bind_param($staff_stmt, 'i', $user_id);
    if (mysqli_stmt_execute($staff_stmt)) {
        $staff_result = mysqli_stmt_get_result($staff_stmt);
    }
}
$staff_members = [];
if ($staff_result) {
    while ($row = mysqli_fetch_assoc($staff_result)) {
        $staff_members[] = $row;
    }
}

// Get selected chat room
$selected_room_id = isset($_GET['room']) ? (int) $_GET['room'] : null;
$selected_chat = null;

// Find selected chat from all chat types
foreach ($chat_types as $type => $chats) {
    if (!empty($chats)) {
        foreach ($chats as $chat) {
            if ($chat['id'] == $selected_room_id) {
                $selected_chat = $chat;
                $selected_chat['type_group'] = $type;
                break 2;
            }
        }
    }
}

// If no chat selected, show first available chat
if (($selected_room_id === null || $selected_room_id <= 0) && !empty($chat_types)) {
    foreach ($chat_types as $type => $chats) {
        if (!empty($chats)) {
            $selected_chat = $chats[0];
            $selected_chat['type_group'] = $type;
            $selected_room_id = $selected_chat['id'];
            break;
        }
    }
}

// Get chat participants for selected room
$participants = [];
if ($selected_room_id) {
    $participants_query = "SELECT 
        u.*, 
        cp.joined_at, 
        cp.last_seen,
        cp.is_admin,
        CASE 
            WHEN u.id = ? THEN 'You'
            ELSE u.full_name
        END as display_name
    FROM chat_participants cp 
    JOIN users u ON cp.user_id = u.id 
    WHERE cp.room_id = ? 
    ORDER BY 
        CASE WHEN u.id = ? THEN 0 ELSE 1 END,
        u.role DESC, 
        u.full_name ASC";

    $participants_stmt = mysqli_prepare($conn, $participants_query);
    $participants_result = false;
    if ($participants_stmt) {
        mysqli_stmt_bind_param($participants_stmt, 'iii', $user_id, $selected_room_id, $user_id);
        if (mysqli_stmt_execute($participants_stmt)) {
            $participants_result = mysqli_stmt_get_result($participants_stmt);
        }
    }
    if ($participants_result) {
        while ($row = mysqli_fetch_assoc($participants_result)) {
            $participants[] = $row;
        }
    }
}

// Get chat messages for selected room
$messages = [];
if ($selected_room_id) {
    $messages_query = "SELECT 
        cm.*, 
        u.full_name as sender_name, 
        u.role as sender_role,
        u.email as sender_email
    FROM chat_messages cm 
    LEFT JOIN users u ON cm.sender_id = u.id 
    WHERE cm.room_id = ? 
    ORDER BY cm.created_at ASC";

    $messages_stmt = mysqli_prepare($conn, $messages_query);
    $messages_result = false;
    if ($messages_stmt) {
        mysqli_stmt_bind_param($messages_stmt, 'i', $selected_room_id);
        if (mysqli_stmt_execute($messages_stmt)) {
            $messages_result = mysqli_stmt_get_result($messages_stmt);
        }
    }
    if ($messages_result) {
        while ($row = mysqli_fetch_assoc($messages_result)) {
            $messages[] = $row;
        }
    }
    
    // Mark messages as read
    $mark_read_stmt = mysqli_prepare(
        $conn,
        "UPDATE chat_messages
         SET is_read = TRUE, read_at = NOW()
         WHERE room_id = ? AND sender_id != ? AND is_read = FALSE"
    );
    if ($mark_read_stmt) {
        mysqli_stmt_bind_param($mark_read_stmt, 'ii', $selected_room_id, $user_id);
        mysqli_stmt_execute($mark_read_stmt);
    }
    
    // Update last seen
    $update_last_seen_stmt = mysqli_prepare(
        $conn,
        "UPDATE chat_participants
         SET last_seen = NOW()
         WHERE room_id = ? AND user_id = ?"
    );
    if ($update_last_seen_stmt) {
        mysqli_stmt_bind_param($update_last_seen_stmt, 'ii', $selected_room_id, $user_id);
        mysqli_stmt_execute($update_last_seen_stmt);
    }
}

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('UNIFIED DIGITAL CLAIMS SYSTEM | Secure Chat', '..', $headExtra); ?>
    <style>
        :root {
            --bk-primary: #034EA2;
            --bk-secondary: #0A5BB4;
            --bk-accent: #2B7ACD;
            --bk-success: #1060B6;
            --bk-warning: #2279D0;
            --bk-danger: #103E82;
            --bk-light: #F4F7FC;
            --bk-dark: #111827;
            --bk-white: #FFFFFF;
            --chat-bg: #E1EBF8;
            --user-msg: #EFF5FC;
            --staff-msg: #FFFFFF;
            --system-msg: #E4EDF9;
            --admin-color: #0A5BB4;
            --legal-color: #2B7ACD;
            --finance-color: #1060B6;
            --user-color: #5E84B8;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: var(--app-font), Inter, system-ui, sans-serif;
        }
        
        body {
            background-color: var(--chat-bg);
            color: var(--bk-dark);
            display: flex;
            min-height: 100vh;
        }
        
        /* Chat Container */
        .chat-container {
            display: flex;
            width: 100%;
            max-width: 1600px;
            margin: 20px auto;
            height: calc(100vh - 40px);
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(3, 78, 162, 0.12);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }
        
        /* Sidebar */
        .chat-sidebar {
            width: 350px;
            background: linear-gradient(180deg, var(--bk-primary) 0%, #083F85 100%);
            color: white;
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-header {
            padding: 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-header h2 {
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        
        .sidebar-header p {
            opacity: 0.8;
            font-size: 14px;
        }
        
        /* User Profile */
        .user-profile {
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, 
                <?php 
                switch($user_role) {
                    case 'admin': echo '#0A5BB4'; break;
                    case 'legal': echo '#2B7ACD'; break;
                    case 'finance': echo '#1060B6'; break;
                    default: echo '#2279D0'; break;
                }
                ?>, 
                <?php 
                switch($user_role) {
                    case 'admin': echo '#034EA2'; break;
                    case 'legal': echo '#0B67C6'; break;
                    case 'finance': echo '#0F62B8'; break;
                    default: echo '#5E84B8'; break;
                }
                ?>);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
            border: 3px solid rgba(255, 255, 255, 0.3);
            font-size: 18px;
        }
        
        .user-info h3 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .user-info p {
            font-size: 13px;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .online-status {
            width: 8px;
            height: 8px;
            background: var(--bk-success);
            border-radius: 50%;
            display: inline-block;
            animation: pulse 2s infinite;
        }
        
        /* Chat Tabs */
        .chat-tabs {
            display: flex;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .chat-tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        
        .chat-tab:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .chat-tab.active {
            color: white;
            border-bottom-color: var(--bk-warning);
            background: rgba(255, 255, 255, 0.05);
        }
        
        /* New Chat Button */
        .new-chat-btn {
            margin: 15px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px dashed rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .new-chat-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
        
        /* Chat Lists */
        .chat-lists {
            flex: 1;
            overflow-y: auto;
            padding: 15px 0;
        }
        
        .chat-list-section {
            display: none;
        }
        
        .chat-list-section.active {
            display: block;
        }
        
        .chat-list-section h3 {
            padding: 0 20px 15px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.7;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            cursor: pointer;
            transition: background 0.3s ease;
            border-left: 4px solid transparent;
            position: relative;
        }
        
        .chat-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .chat-item.active {
            background: rgba(255, 255, 255, 0.15);
            border-left-color: var(--bk-warning);
        }
        
        .chat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 18px;
        }
        
        .chat-info {
            flex: 1;
            min-width: 0;
        }
        
        .chat-info h4 {
            font-size: 14px;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chat-info p {
            font-size: 12px;
            opacity: 0.8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chat-meta {
            text-align: right;
            margin-left: 10px;
        }
        
        .chat-time {
            font-size: 11px;
            opacity: 0.7;
            margin-bottom: 5px;
        }
        
        .unread-badge {
            background: var(--bk-danger);
            color: white;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
            padding: 0 6px;
        }
        
        /* Chat Area */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--chat-bg);
        }
        
        /* Chat Header */
        .chat-header {
            background: white;
            padding: 20px 30px;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.6);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(3, 78, 162, 0.08);
        }
        
        .chat-info-main {
            flex: 1;
        }
        
        .chat-info-main h2 {
            font-size: 20px;
            color: var(--bk-primary);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chat-info-main p {
            color: rgb(var(--bk-muted-rgb));
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .chat-type-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-claim { background: var(--bk-primary); color: white; }
        .badge-direct { background: var(--bk-accent); color: white; }
        .badge-group { background: var(--bk-warning); color: rgb(var(--bk-text-rgb)); }
        
        .chat-actions {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--bk-light);
            border: none;
            color: var(--bk-primary);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .action-btn:hover {
            background: var(--bk-primary);
            color: white;
            transform: translateY(-2px);
        }
        
        /* Messages Container */
        .messages-container {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        /* Message Bubbles */
        .message {
            display: flex;
            max-width: 70%;
            animation: slideIn 0.3s ease;
        }
        
        .message.user {
            align-self: flex-end;
        }
        
        .message.other {
            align-self: flex-start;
        }
        
        .message.system {
            align-self: center;
            max-width: 90%;
            background: var(--system-msg);
            border: 1px solid rgba(var(--bk-warning-rgb), 0.32);
            padding: 12px 20px;
            border-radius: 15px;
            font-size: 14px;
            color: var(--bk-dark);
        }
        
        .message-bubble {
            padding: 15px 20px;
            border-radius: 18px;
            position: relative;
            box-shadow: 0 2px 8px rgba(3, 78, 162, 0.10);
            word-wrap: break-word;
            line-height: 1.5;
            max-width: 100%;
        }
        
        .user .message-bubble {
            background: var(--user-msg);
            border-bottom-right-radius: 5px;
            color: var(--bk-dark);
        }
        
        .other .message-bubble {
            background: var(--staff-msg);
            border-bottom-left-radius: 5px;
            color: var(--bk-dark);
            border: 1px solid rgba(var(--bk-border-rgb), 0.6);
        }
        
        .message-sender {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .sender-role {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 8px;
            background: rgba(var(--bk-bg-rgb), 0.8);
            color: rgb(var(--bk-muted-rgb));
        }
        
        .message-content {
            font-size: 15px;
            margin-bottom: 8px;
        }
        
        .message-time {
            font-size: 11px;
            opacity: 0.7;
            text-align: right;
        }
        
        .other .message-time {
            text-align: left;
        }
        
        .message-status {
            position: absolute;
            bottom: 5px;
            right: 10px;
            font-size: 12px;
            color: var(--bk-success);
        }
        
        /* Role Colors */
        .role-admin { color: var(--admin-color); }
        .role-legal { color: var(--legal-color); }
        .role-finance { color: var(--finance-color); }
        .role-user { color: var(--user-color); }
        
        .badge-admin { background: var(--admin-color); color: white; }
        .badge-legal { background: var(--legal-color); color: white; }
        .badge-finance { background: var(--finance-color); color: white; }
        .badge-user { background: var(--user-color); color: white; }
        
        /* No Chats Message */
        .no-chats {
            text-align: center;
            padding: 40px 20px;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .no-chats i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .no-chats h3 {
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        /* New Chat Modal */
        .new-chat-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(3, 78, 162, 0.68);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .new-chat-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(3, 78, 162, 0.20);
            animation: modalSlideIn 0.3s ease;
        }
        
        .new-chat-header {
            padding: 25px;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.6);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .new-chat-header h3 {
            font-size: 22px;
            color: var(--bk-primary);
        }
        
        .new-chat-close {
            background: none;
            border: none;
            font-size: 24px;
            color: rgb(var(--bk-muted-rgb));
            cursor: pointer;
        }
        
        .new-chat-body {
            padding: 25px;
            overflow-y: auto;
            max-height: calc(80vh - 140px);
        }
        
        .new-chat-tabs {
            display: flex;
            margin-bottom: 25px;
            border-bottom: 2px solid rgba(var(--bk-border-rgb), 0.6);
        }
        
        .new-chat-tab {
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 15px;
            font-weight: 500;
            color: rgb(var(--bk-muted-rgb));
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .new-chat-tab:hover {
            color: var(--bk-primary);
        }
        
        .new-chat-tab.active {
            color: var(--bk-primary);
            border-bottom-color: var(--bk-primary);
        }
        
        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .staff-card {
            border: 1px solid rgba(var(--bk-border-rgb), 0.6);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .staff-card:hover {
            border-color: var(--bk-primary);
            background: rgba(var(--bk-bg-rgb), 0.58);
            transform: translateY(-2px);
        }
        
        .staff-card.selected {
            border-color: var(--bk-primary);
            background: rgba(0, 86, 163, 0.05);
        }
        
        .staff-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
            font-size: 18px;
        }
        
        .staff-info h4 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .staff-info p {
            font-size: 13px;
            color: rgb(var(--bk-muted-rgb));
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .new-chat-actions {
            padding: 25px;
            border-top: 1px solid rgba(var(--bk-border-rgb), 0.6);
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }
        
        .btn-cancel {
            padding: 12px 25px;
            background: rgba(var(--bk-bg-rgb), 0.58);
            border: 1px solid rgba(var(--bk-border-rgb), 0.75);
            border-radius: 10px;
            color: rgb(var(--bk-muted-rgb));
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-cancel:hover {
            background: rgba(var(--bk-bg-rgb), 0.7);
        }
        
        .btn-start-chat {
            padding: 12px 25px;
            background: var(--bk-primary);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-start-chat:hover {
            background: var(--bk-secondary);
        }
        
        .btn-start-chat:disabled {
            background: rgba(var(--bk-border-rgb), 0.85);
            cursor: not-allowed;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0% { opacity: 0.6; }
            50% { opacity: 1; }
            100% { opacity: 0.6; }
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .chat-container {
                flex-direction: column;
                height: calc(100vh - 20px);
                margin: 10px;
            }
            
            .chat-sidebar {
                width: 100%;
                height: 300px;
            }
            
            .chat-tabs {
                flex-wrap: wrap;
            }
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(3, 78, 162, 0.08);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: rgba(0, 86, 163, 0.3);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 86, 163, 0.5);
        }
    </style>
</head>
<body>
    <!-- Chat Container -->
    <div class="chat-container">
        <!-- Sidebar -->
        <div class="chat-sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-comments"></i> BK Team Chat</h2>
                <p>Secure communication for your team</p>
            </div>
            
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user_name, 0, 2)); ?>
                </div>
                <div class="user-info">
                    <h3><?php echo htmlspecialchars($user_name); ?></h3>
                    <p><span class="online-status"></span> Online | <?php echo ucfirst($user_role); ?></p>
                </div>
            </div>
            
            <!-- Chat Tabs -->
            <div class="chat-tabs">
                <button class="chat-tab active" data-tab="all">All Chats</button>
                <button class="chat-tab" data-tab="direct">Direct</button>
                <button class="chat-tab" data-tab="claims">Claims</button>
                <button class="chat-tab" data-tab="groups">Groups</button>
            </div>
            
            <!-- New Chat Button -->
            <button class="new-chat-btn" id="newChatBtn">
                <i class="fas fa-plus-circle"></i> New Chat
            </button>
            
            <!-- Chat Lists -->
            <div class="chat-lists">
                <!-- All Chats -->
                <div class="chat-list-section active" id="tab-all">
                    <?php 
                    $all_chats = [];
                    foreach ($chat_types as $type_chats) {
                        if (!empty($type_chats)) {
                            $all_chats = array_merge($all_chats, $type_chats);
                        }
                    }
                    
                    if (empty($all_chats)): ?>
                    <div class="no-chats">
                        <i class="fas fa-comments"></i>
                        <h3>No chats yet</h3>
                        <p>Start a conversation with your team</p>
                    </div>
                    <?php else: 
                        usort($all_chats, function($a, $b) {
                            $a_time = $a['last_message_at'] ? strtotime($a['last_message_at']) : 0;
                            $b_time = $b['last_message_at'] ? strtotime($b['last_message_at']) : 0;
                            return $b_time - $a_time;
                        });
                        
                        foreach ($all_chats as $chat): 
                            $is_active = $selected_room_id == $chat['id'];
                            // Get unread count for this chat
                            $unread = 0;
                            $unread_stmt = mysqli_prepare(
                                $conn,
                                "SELECT COUNT(*) AS unread
                                 FROM chat_messages
                                 WHERE room_id = ? AND sender_id != ? AND is_read = FALSE"
                            );
                            if ($unread_stmt) {
                                $room_id = (int) ($chat['id'] ?? 0);
                                mysqli_stmt_bind_param($unread_stmt, 'ii', $room_id, $user_id);
                                if (mysqli_stmt_execute($unread_stmt)) {
                                    $unread_result = mysqli_stmt_get_result($unread_stmt);
                                    if ($unread_result) {
                                        $unread_row = mysqli_fetch_assoc($unread_result);
                                        $unread = (int) ($unread_row['unread'] ?? 0);
                                    }
                                }
                                mysqli_stmt_close($unread_stmt);
                            }
                    ?>
                    <div class="chat-item <?php echo $is_active ? 'active' : ''; ?>" 
                         data-room="<?php echo $chat['id']; ?>"
                         onclick="selectRoom(<?php echo $chat['id']; ?>)">
                        <div class="chat-icon">
                            <i class="fas fa-<?php echo $chat['icon']; ?>"></i>
                        </div>
                        <div class="chat-info">
                            <h4><?php echo htmlspecialchars($chat['display_name']); ?></h4>
                            <p><?php echo htmlspecialchars($chat['description'] ?? ''); ?></p>
                        </div>
                        <div class="chat-meta">
                            <?php if ($chat['last_message_at']): ?>
                            <div class="chat-time">
                                <?php echo $chat['last_message_at']; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($unread > 0): ?>
                            <div class="unread-badge"><?php echo $unread; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                
                <!-- Direct Messages -->
                <div class="chat-list-section" id="tab-direct">
                    <?php if (empty($chat_types['direct'])): ?>
                    <div class="no-chats">
                        <i class="fas fa-user"></i>
                        <h3>No direct messages</h3>
                        <p>Start a private conversation</p>
                    </div>
                    <?php else: foreach ($chat_types['direct'] as $chat): 
                        $is_active = $selected_room_id == $chat['id'];
                    ?>
                    <div class="chat-item <?php echo $is_active ? 'active' : ''; ?>" 
                         data-room="<?php echo $chat['id']; ?>"
                         onclick="selectRoom(<?php echo $chat['id']; ?>)">
                        <div class="chat-icon" style="background: <?php 
                            echo $chat['other_user_role'] == 'admin' ? 'rgba(10, 91, 180, 0.2)' : 
                                 ($chat['other_user_role'] == 'legal' ? 'rgba(43, 122, 205, 0.2)' : 
                                 ($chat['other_user_role'] == 'finance' ? 'rgba(16, 96, 182, 0.2)' : 'rgba(94, 132, 184, 0.2)')); 
                            ?>; color: <?php 
                            echo $chat['other_user_role'] == 'admin' ? '#0A5BB4' : 
                                 ($chat['other_user_role'] == 'legal' ? '#2B7ACD' : 
                                 ($chat['other_user_role'] == 'finance' ? '#1060B6' : '#5E84B8')); 
                            ?>;">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="chat-info">
                            <h4><?php echo htmlspecialchars($chat['display_name']); ?></h4>
                            <p><?php echo htmlspecialchars($chat['description']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                
                <!-- Claims -->
                <div class="chat-list-section" id="tab-claims">
                    <?php if (empty($chat_types['claims'])): ?>
                    <div class="no-chats">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <h3>No claim chats</h3>
                        <p>Open a claim to start chatting</p>
                    </div>
                    <?php else: foreach ($chat_types['claims'] as $chat): 
                        $is_active = $selected_room_id == $chat['id'];
                    ?>
                    <div class="chat-item <?php echo $is_active ? 'active' : ''; ?>" 
                         data-room="<?php echo $chat['id']; ?>"
                         onclick="selectRoom(<?php echo $chat['id']; ?>)">
                        <div class="chat-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="chat-info">
                            <h4><?php echo htmlspecialchars($chat['display_name']); ?></h4>
                            <p><?php echo htmlspecialchars($chat['description']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                
                <!-- Groups -->
                <div class="chat-list-section" id="tab-groups">
                    <?php if (empty($chat_types['group'])): ?>
                    <div class="no-chats">
                        <i class="fas fa-users"></i>
                        <h3>No group chats</h3>
                        <p>Create a group with your team</p>
                    </div>
                    <?php else: foreach ($chat_types['group'] as $chat): 
                        $is_active = $selected_room_id == $chat['id'];
                    ?>
                    <div class="chat-item <?php echo $is_active ? 'active' : ''; ?>" 
                         data-room="<?php echo $chat['id']; ?>"
                         onclick="selectRoom(<?php echo $chat['id']; ?>)">
                        <div class="chat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="chat-info">
                            <h4><?php echo htmlspecialchars($chat['display_name']); ?></h4>
                            <p><?php echo htmlspecialchars($chat['description']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Chat Area -->
        <div class="chat-area">
            <!-- Chat Header -->
            <div class="chat-header">
                <div class="chat-info-main">
                    <?php if ($selected_chat): ?>
                    <h2>
                        <?php if ($selected_chat['type_group'] == 'direct'): ?>
                        <i class="fas fa-user"></i>
                        <?php elseif ($selected_chat['type_group'] == 'claim'): ?>
                        <i class="fas fa-file-invoice-dollar"></i>
                        <?php else: ?>
                        <i class="fas fa-users"></i>
                        <?php endif; ?>
                        
                        <?php echo htmlspecialchars($selected_chat['display_name']); ?>
                        
                        <span class="chat-type-badge badge-<?php echo $selected_chat['type_group']; ?>">
                            <?php echo ucfirst($selected_chat['type_group']); ?>
                        </span>
                    </h2>
                    
                    <p>
                        <?php if ($selected_chat['type_group'] == 'direct'): ?>
                        <span class="sender-role badge-<?php echo $selected_chat['other_user_role']; ?>">
                            <?php echo ucfirst($selected_chat['other_user_role']); ?>
                        </span>
                        <?php echo htmlspecialchars($selected_chat['other_user_email']); ?>
                        <?php elseif ($selected_chat['type_group'] == 'claim'): ?>
                        Claim ID: <?php echo htmlspecialchars($selected_chat['claim_number']); ?> &bull;
                        BK Assets: <?php echo htmlspecialchars((string) ($selected_chat['asset_summary'] ?? udcs_claim_asset_summary_label('', (string) ($selected_chat['claim_type'] ?? '')))); ?> &bull;
                        Status: <?php echo htmlspecialchars((string) ($selected_chat['status_label'] ?? udcs_claim_status_label((string) ($selected_chat['effective_status'] ?? '')))); ?>
                        <?php else: ?>
                        <?php echo $selected_chat['chat_description'] ? htmlspecialchars($selected_chat['chat_description']) : 'Group conversation'; ?>
                        <?php endif; ?>
                    </p>
                    <?php else: ?>
                    <h2>Welcome to Team Chat</h2>
                    <p>Select a chat or start a new conversation</p>
                    <?php endif; ?>
                </div>
                
                <div class="chat-actions">
                    <?php if ($selected_chat): ?>
                    <button class="action-btn" title="Participants" onclick="showParticipants()">
                        <i class="fas fa-users"></i>
                    </button>
                    <?php if ($selected_chat['type_group'] == 'claim'): ?>
                    <button class="action-btn" title="View Claim" 
                            onclick="window.open('review_claim.php?id=<?php echo $selected_chat['claim_id']; ?>', '_blank')">
                        <i class="fas fa-external-link-alt"></i>
                    </button>
                    <?php endif; ?>
                    <button class="action-btn" title="Download Chat" onclick="downloadChat()">
                        <i class="fas fa-download"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Messages Container -->
            <div class="messages-container" id="messagesContainer">
                <?php if ($selected_room_id && !empty($messages)): 
                    $current_date = null;
                    foreach ($messages as $message): 
                        $message_date = date('Y-m-d', strtotime($message['created_at']));
                        if ($current_date != $message_date) {
                            $current_date = $message_date;
                            $date_display = date('Y-m-d') == $message_date ? 'Today' : date('F j, Y', strtotime($message_date));
                ?>
                <div class="date-separator">
                    <span><?php echo $date_display; ?></span>
                </div>
                <?php } ?>
                
                <div class="message <?php 
                    echo $message['sender_id'] == 0 ? 'system' : 
                         ($message['sender_id'] == $user_id ? 'user' : 'other'); 
                ?>">
                    <?php if ($message['message_type'] == 'system'): ?>
                    <div class="message system">
                        <i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($message['message']); ?>
                    </div>
                    <?php else: ?>
                    <div class="message-bubble">
                        <?php if ($message['sender_id'] != $user_id && $message['sender_name']): ?>
                        <div class="message-sender">
                            <span class="role-<?php echo $message['sender_role']; ?>">
                                <?php echo htmlspecialchars($message['sender_name']); ?>
                            </span>
                            <span class="sender-role badge-<?php echo $message['sender_role']; ?>">
                                <?php echo ucfirst($message['sender_role']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="message-content">
                            <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                        </div>
                        
                        <?php if ($message['file_path']): ?>
                        <div class="message-attachments">
                            <div class="attachment" onclick="downloadFile('<?php echo $message['id']; ?>')">
                                <div class="attachment-icon">
                                    <i class="fas fa-<?php echo pathinfo($message['file_name'], PATHINFO_EXTENSION) == 'pdf' ? 'file-pdf' : 'file-image'; ?>"></i>
                                </div>
                                <div class="attachment-info">
                                    <h5><?php echo htmlspecialchars($message['file_name']); ?></h5>
                                    <p><?php echo formatFileSize($message['file_size']); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="message-time">
                            <?php echo date('H:i', strtotime($message['created_at'])); ?>
                            <?php if ($message['sender_id'] == $user_id): ?>
                            <span class="message-status">
                                <i class="fas fa-<?php echo $message['is_read'] ? 'check-double' : 'check'; ?>"></i>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                
                <?php else: ?>
                <div style="text-align: center; padding: 50px; color: rgb(var(--bk-muted-rgb));">
                    <i class="fas fa-comments" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
                    <h3><?php echo $selected_chat ? 'Start the conversation' : 'Select a chat'; ?></h3>
                    <p><?php echo $selected_chat ? 'Send your first message' : 'Choose from the sidebar'; ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Input Area -->
            <?php if ($selected_room_id): ?>
            <div class="input-area">
                <div class="input-tools">
                    <button class="tool-btn" title="Attach File" id="attachBtn">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <button class="tool-btn" title="Quick Responses" onclick="showQuickResponses()">
                        <i class="fas fa-bolt"></i>
                    </button>
                </div>
                
                <div class="message-input-container">
                    <textarea 
                        class="message-input" 
                        id="messageInput" 
                        placeholder="Type your message here... (Press Shift+Enter for new line)"
                        rows="1"
                    ></textarea>
                    <button class="send-btn" id="sendBtn" onclick="sendMessage()" disabled>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- New Chat Modal -->
    <div class="new-chat-modal" id="newChatModal">
        <div class="new-chat-content">
            <div class="new-chat-header">
                <h3><i class="fas fa-plus-circle"></i> Start New Chat</h3>
                <button class="new-chat-close" onclick="closeNewChatModal()">&times;</button>
            </div>
            
            <div class="new-chat-body">
                <div class="new-chat-tabs">
                    <button class="new-chat-tab active" data-tab="direct">Direct Message</button>
                    <button class="new-chat-tab" data-tab="group">Group Chat</button>
                    <button class="new-chat-tab" data-tab="claims">Claim Chat</button>
                </div>
                
                <!-- Direct Message Tab -->
                <div class="new-chat-tab-content active" id="tab-direct-content">
                    <h4 style="margin-bottom: 20px; color: var(--bk-primary);">Select a team member to chat with:</h4>
                    
                    <div class="staff-grid" id="staffGrid">
                        <?php foreach ($staff_members as $staff): ?>
                        <div class="staff-card" data-user-id="<?php echo $staff['id']; ?>"
                             onclick="selectStaff(this, <?php echo $staff['id']; ?>)">
                            <div class="staff-avatar" style="background: <?php 
                                echo $staff['role'] == 'admin' ? '#0A5BB4' : 
                                     ($staff['role'] == 'legal' ? '#2B7ACD' : 
                                     ($staff['role'] == 'finance' ? '#1060B6' : '#5E84B8')); 
                            ?>;">
                                <?php echo strtoupper(substr($staff['full_name'], 0, 2)); ?>
                            </div>
                            <div class="staff-info">
                                <h4><?php echo htmlspecialchars($staff['full_name']); ?></h4>
                                <p>
                                    <span class="sender-role badge-<?php echo $staff['role']; ?>">
                                        <?php echo ucfirst($staff['role']); ?>
                                    </span>
                                    <?php echo htmlspecialchars($staff['email']); ?>
                                </p>
                                <?php if ($staff['department']): ?>
                                <p style="font-size: 12px; color: rgba(var(--bk-muted-rgb), 0.8); margin-top: 3px;">
                                    <?php echo htmlspecialchars($staff['department']); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Group Chat Tab -->
                <div class="new-chat-tab-content" id="tab-group-content">
                    <div style="margin-bottom: 20px;">
                        <input type="text" id="groupName" placeholder="Group name" 
                               style="width: 100%; padding: 12px; border: 1px solid rgba(var(--bk-border-rgb), 0.75); border-radius: 8px; font-size: 16px; margin-bottom: 15px;">
                        <textarea id="groupDescription" placeholder="Group description (optional)" 
                                  style="width: 100%; padding: 12px; border: 1px solid rgba(var(--bk-border-rgb), 0.75); border-radius: 8px; font-size: 14px; min-height: 80px;"></textarea>
                    </div>
                    
                    <h4 style="margin-bottom: 15px; color: var(--bk-primary);">Select team members:</h4>
                    <div class="staff-grid" id="groupStaffGrid">
                        <?php foreach ($staff_members as $staff): ?>
                        <div class="staff-card group-staff" data-user-id="<?php echo $staff['id']; ?>"
                             onclick="toggleGroupStaff(this, <?php echo $staff['id']; ?>)">
                            <div class="staff-avatar" style="background: <?php 
                                echo $staff['role'] == 'admin' ? '#0A5BB4' : 
                                     ($staff['role'] == 'legal' ? '#2B7ACD' : 
                                     ($staff['role'] == 'finance' ? '#1060B6' : '#5E84B8')); 
                            ?>;">
                                <?php echo strtoupper(substr($staff['full_name'], 0, 2)); ?>
                            </div>
                            <div class="staff-info">
                                <h4><?php echo htmlspecialchars($staff['full_name']); ?></h4>
                                <p>
                                    <span class="sender-role badge-<?php echo $staff['role']; ?>">
                                        <?php echo ucfirst($staff['role']); ?>
                                    </span>
                                </p>
                            </div>
                            <input type="checkbox" style="display: none;">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Claim Chat Tab -->
                <div class="new-chat-tab-content" id="tab-claims-content">
                    <h4 style="margin-bottom: 20px; color: var(--bk-primary);">Select a claim:</h4>
                    
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid rgba(var(--bk-border-rgb), 0.75); border-radius: 8px;">
                        <?php
                        $all_claims_query = "SELECT c.*, u.full_name as claimant_name,
                                                    COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
                                                    COALESCE(ca.asset_classes, '') AS asset_classes
                                             FROM claims c
                                             JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
                                             LEFT JOIN (
                                                 SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
                                                 FROM claim_assets
                                                 GROUP BY claim_id
                                             ) ca ON ca.claim_id = c.id
                                             WHERE COALESCE(c.claimant_user_id, c.claimant_id) = ?
                                               AND COALESCE(NULLIF(c.status, ''), c.claim_status) NOT IN ('Closed', 'Disbursed')
                                             ORDER BY c.submitted_at DESC";
                        $all_claims_stmt = mysqli_prepare($conn, $all_claims_query);
                        $all_claims_result = false;
                        if ($all_claims_stmt) {
                            mysqli_stmt_bind_param($all_claims_stmt, 'i', $user_id);
                            if (mysqli_stmt_execute($all_claims_stmt)) {
                                $all_claims_result = mysqli_stmt_get_result($all_claims_stmt);
                            }
                        }
                        
                        if ($all_claims_result && mysqli_num_rows($all_claims_result) > 0):
                            while ($claim = mysqli_fetch_assoc($all_claims_result)):
                        ?>
                        <div class="staff-card" data-claim-id="<?php echo $claim['id']; ?>"
                             onclick="selectClaimForChat(this, <?php echo $claim['id']; ?>)">
                            <div class="chat-icon" style="margin-right: 0; margin-left: 10px;">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <div class="chat-info" style="flex: 1;">
                                <h4>CL-<?php echo str_pad($claim['id'], 6, '0', STR_PAD_LEFT); ?></h4>
                                <p><?php echo htmlspecialchars($claim['claimant_name']); ?> &bull; <?php echo htmlspecialchars(udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''))); ?></p>
                                <p style="font-size: 12px; color: rgba(var(--bk-muted-rgb), 0.8); margin-top: 3px;">
                                    Status: <?php echo htmlspecialchars(udcs_claim_status_label((string) ($claim['effective_status'] ?? ''))); ?>
                                </p>
                            </div>
                        </div>
                        <?php endwhile; else: ?>
                        <div style="text-align: center; padding: 40px; color: rgb(var(--bk-muted-rgb));">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px; opacity: 0.3;"></i>
                            <p>No active claims found</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="new-chat-actions">
                <button class="btn-cancel" onclick="closeNewChatModal()">Cancel</button>
                <button class="btn-start-chat" id="startChatBtn" onclick="startNewChat()" disabled>
                    <i class="fas fa-comment"></i> Start Chat
                </button>
            </div>
        </div>
    </div>

    <!-- File Upload Modal and Quick Responses Modal -->
    <!-- (Keep the existing modals from previous code) -->

    <script>
        // Global variables
        const currentUserId = <?php echo $user_id; ?>;
        const currentUserName = "<?php echo addslashes($user_name); ?>";
        const currentUserRole = "<?php echo $user_role; ?>";
        const selectedRoomId = <?php echo $selected_room_id ?? 'null'; ?>;
        
        // State for new chat modal
        let selectedStaffId = null;
        let selectedClaimId = null;
        let selectedGroupMembers = [];
        
        // Initialize chat
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            if (selectedRoomId) {
                startChatPolling();
                autoResizeTextarea();
            }
            
            // Setup tab switching
            document.querySelectorAll('.chat-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.dataset.tab;
                    
                    // Update active tab
                    document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show corresponding chat list
                    document.querySelectorAll('.chat-list-section').forEach(section => {
                        section.classList.remove('active');
                    });
                    document.getElementById(`tab-${tabId}`).classList.add('active');
                });
            });
            
            // Setup new chat modal tabs
            document.querySelectorAll('.new-chat-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.dataset.tab;
                    
                    // Update active tab
                    document.querySelectorAll('.new-chat-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show corresponding content
                    document.querySelectorAll('.new-chat-tab-content').forEach(content => {
                        content.classList.remove('active');
                    });
                    document.getElementById(`tab-${tabId}-content`).classList.add('active');
                    
                    // Reset selections
                    selectedStaffId = null;
                    selectedClaimId = null;
                    selectedGroupMembers = [];
                    updateStartChatButton();
                });
            });
            
            // New chat button
            document.getElementById('newChatBtn').addEventListener('click', function() {
                openNewChatModal();
            });
        });
        
        // Open new chat modal
        function openNewChatModal() {
            document.getElementById('newChatModal').style.display = 'flex';
        }
        
        // Close new chat modal
        function closeNewChatModal() {
            document.getElementById('newChatModal').style.display = 'none';
            // Reset selections
            selectedStaffId = null;
            selectedClaimId = null;
            selectedGroupMembers = [];
            updateStartChatButton();
        }
        
        // Select staff for direct message
        function selectStaff(card, staffId) {
            // Clear previous selection
            document.querySelectorAll('#staffGrid .staff-card').forEach(c => {
                c.classList.remove('selected');
            });
            
            // Select new staff
            card.classList.add('selected');
            selectedStaffId = staffId;
            updateStartChatButton();
        }
        
        // Select claim for chat
        function selectClaimForChat(card, claimId) {
            // Clear previous selection
            document.querySelectorAll('#tab-claims-content .staff-card').forEach(c => {
                c.classList.remove('selected');
            });
            
            // Select new claim
            card.classList.add('selected');
            selectedClaimId = claimId;
            updateStartChatButton();
        }
        
        // Toggle staff selection for group
        function toggleGroupStaff(card, staffId) {
            card.classList.toggle('selected');
            
            if (card.classList.contains('selected')) {
                selectedGroupMembers.push(staffId);
            } else {
                selectedGroupMembers = selectedGroupMembers.filter(id => id !== staffId);
            }
            
            updateStartChatButton();
        }
        
        // Update start chat button state
        function updateStartChatButton() {
            const startBtn = document.getElementById('startChatBtn');
            const activeTab = document.querySelector('.new-chat-tab.active').dataset.tab;
            
            let isValid = false;
            
            if (activeTab === 'direct' && selectedStaffId) {
                isValid = true;
            } else if (activeTab === 'group' && selectedGroupMembers.length > 0) {
                isValid = true;
            } else if (activeTab === 'claims' && selectedClaimId) {
                isValid = true;
            }
            
            startBtn.disabled = !isValid;
        }
        
        // Start new chat
        function startNewChat() {
            const activeTab = document.querySelector('.new-chat-tab.active').dataset.tab;
            let formData = new FormData();
            
            if (activeTab === 'direct' && selectedStaffId) {
                formData.append('action', 'create_direct_chat');
                formData.append('other_user_id', selectedStaffId);
            } else if (activeTab === 'group' && selectedGroupMembers.length > 0) {
                const groupName = document.getElementById('groupName').value;
                const groupDesc = document.getElementById('groupDescription').value;
                
                if (!groupName.trim()) {
                    alert('Please enter a group name before continuing.');
                    return;
                }
                
                formData.append('action', 'create_group_chat');
                formData.append('group_name', groupName);
                formData.append('group_description', groupDesc);
                formData.append('member_ids', JSON.stringify(selectedGroupMembers));
            } else if (activeTab === 'claims' && selectedClaimId) {
                formData.append('action', 'create_claim_chat');
                formData.append('claim_id', selectedClaimId);
            } else {
                return;
            }
            
            fetch('chat_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close modal and redirect to new chat
                    closeNewChatModal();
                    window.location.href = `chat.php?room=${data.room_id}`;
                } else {
                    alert((data.message || 'We could not create this chat right now. Please try again.'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('We could not create this chat right now. Please try again.');
            });
        }
        
        // Select chat room
        function selectRoom(roomId) {
            window.location.href = `chat.php?room=${roomId}`;
        }
        
        // Send message (updated from previous)
        function sendMessage() {
            const content = document.getElementById('messageInput').value.trim();
            if (!content || !selectedRoomId) return;
            
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('room_id', selectedRoomId);
            formData.append('message', content);
            
            fetch('chat_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Clear input
                    document.getElementById('messageInput').value = '';
                    document.getElementById('messageInput').style.height = 'auto';
                    document.getElementById('sendBtn').disabled = true;
                    
                    // Refresh messages
                    loadMessages();
                } else {
                    alert(data.message || 'We could not send your message right now. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('We could not send your message right now. Please try again.');
            });
        }
        
        // Load messages (updated from previous)
        function loadMessages() {
            if (!selectedRoomId) return;
            
            fetch(`chat_ajax.php?action=get_messages&room_id=${selectedRoomId}&last_update=${Date.now()}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.messages) {
                        updateMessagesDisplay(data.messages);
                    }
                })
                .catch(error => console.error('Error loading messages:', error));
        }
        
        // Start chat polling (updated from previous)
        function startChatPolling() {
            // Clear existing interval if any
            if (window.chatInterval) clearInterval(window.chatInterval);
            
            window.chatInterval = setInterval(() => {
                loadMessages();
                
                // Update unread counts
                fetch('chat_ajax.php?action=get_unread_counts')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            updateUnreadBadges(data.counts);
                        }
                    });
            }, 3000);
        }
        
        // Helper function to format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Helper function for time ago
        function timeAgo(timestamp) {
            const seconds = Math.floor((new Date() - new Date(timestamp)) / 1000);
            
            let interval = Math.floor(seconds / 31536000);
            if (interval >= 1) return interval + "y ago";
            
            interval = Math.floor(seconds / 2592000);
            if (interval >= 1) return interval + "mo ago";
            
            interval = Math.floor(seconds / 86400);
            if (interval >= 1) return interval + "d ago";
            
            interval = Math.floor(seconds / 3600);
            if (interval >= 1) return interval + "h ago";
            
            interval = Math.floor(seconds / 60);
            if (interval >= 1) return interval + "m ago";
            
            return "Just now";
        }
    </script>
</body>
</html>






