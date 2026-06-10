<?php
// Tags: [CLAIMANT] [MSG]
require_once '../security.php';
secure_session_start();
include '../connect.php';

require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/button.php';
require_once dirname(__DIR__) . '/components/alert.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'claimant') {
    header('Location: ../claimant-access.php');
    exit();
}

$sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $sessionEmail, 'claimant');
if (!$user_data) {
    header('Location: ../claimant-access.php');
    exit();
}

$user_email = $sessionEmail;
$current_user_id = (int) ($user_data['id'] ?? 0);
$messageCsrfToken = udcs_csrf_get('message_send');
$claimant_name = (string) ($user_data['full_name'] ?? 'Claimant');
$rawPhoto = (string) ($user_data['photo'] ?? ($user_data['profile_photo'] ?? ''));
$photo = $rawPhoto !== '' ? '../uploads/' . ltrim($rawPhoto, '/\\') : '../Images/logo.png';
$current_user_photo = $photo;

$users_query = "
    SELECT id, full_name AS fullName, role, email, phone, photo
    FROM users
    WHERE id != ?
      AND role NOT IN ('claimant', 'admin')
    ORDER BY role, full_name
";

$chat_users = [];
$stmt = $conn->prepare($users_query);
if ($stmt) {
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $users_result = $stmt->get_result();

    while ($row = $users_result->fetch_assoc()) {
        $chat_users[] = $row;
    }
}

$roleFilterCounts = [];
foreach ($chat_users as $chatUser) {
    $roleKey = strtolower(trim((string) ($chatUser['role'] ?? 'staff')));
    if ($roleKey === '') {
        $roleKey = 'staff';
    }
    $roleFilterCounts[$roleKey] = ($roleFilterCounts[$roleKey] ?? 0) + 1;
}
ksort($roleFilterCounts);
$roleFilterLabels = [
    'claimant' => 'Claimants',
    'legal' => 'Legal',
    'finance' => 'Finance',
    'admin' => 'Admin',
    'staff' => 'Staff',
];
function getInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }

    return substr($initials, 0, 2) ?: 'NA';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    render_head(
        'Messaging | UNIFIED DIGITAL CLAIMS SYSTEM',
        '..',
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">'
    );
    ?>
    <style>
        .chat-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.15rem;
            background:
                linear-gradient(140deg, rgba(var(--bk-primary-rgb), 0.18), rgba(var(--bk-primary-rgb), 0.03) 48%, rgba(var(--bk-surface-rgb), 0.99)),
                rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
        }

        .chat-hero::before {
            content: '';
            position: absolute;
            width: 16rem;
            height: 16rem;
            right: -4.6rem;
            top: -6.5rem;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(var(--bk-primary-rgb), 0.22), rgba(var(--bk-primary-rgb), 0));
            animation: float 7s ease-in-out infinite;
            pointer-events: none;
        }

        .chat-layout {
            display: grid;
            grid-template-columns: 296px minmax(0, 1fr);
            gap: 0.75rem;
            min-height: calc(100vh - 280px);
        }

        .chat-panel,
        .contact-panel {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
        }

        .contact-panel {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .contact-list {
            overflow-y: auto;
            padding: 0.48rem;
            display: grid;
            gap: 0.34rem;
        }

        .contact-item {
            width: 100%;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.85rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.44rem;
            text-align: left;
            display: flex;
            gap: 0.48rem;
            align-items: center;
            transition: border-color 0.15s ease, background-color 0.15s ease, transform 0.15s ease;
        }

        .contact-item:hover {
            border-color: rgba(var(--bk-primary-rgb), 0.7);
            background: rgba(var(--bk-primary-rgb), 0.08);
            transform: translateY(-1px);
        }

        .contact-item.active {
            border-color: rgba(var(--bk-primary-rgb), 0.9);
            background: rgba(var(--bk-primary-rgb), 0.16);
        }

        .avatar {
            width: 2.15rem;
            height: 2.15rem;
            border-radius: 999px;
            overflow: hidden;
            border: 2px solid rgba(var(--bk-primary-rgb), 0.35);
            flex-shrink: 0;
            background: rgba(var(--bk-primary-rgb), 0.12);
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar .initials {
            width: 100%;
            height: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.74rem;
            font-weight: 700;
            color: rgb(var(--bk-primary-rgb));
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.18rem 0.52rem;
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .role-legal {
            color: rgb(var(--bk-warning-rgb));
            background: rgba(var(--bk-warning-rgb), 0.13);
            border-color: rgba(var(--bk-warning-rgb), 0.28);
        }

        .role-finance {
            color: rgb(var(--bk-success-rgb));
            background: rgba(var(--bk-success-rgb), 0.14);
            border-color: rgba(var(--bk-success-rgb), 0.25);
        }

        .role-default {
            color: rgb(var(--bk-muted-rgb));
            background: rgba(var(--bk-muted-rgb), 0.14);
            border-color: rgba(var(--bk-muted-rgb), 0.25);
        }

        .chat-panel {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr) auto;
            min-height: 0;
        }

        .chat-header {
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            padding: 0.62rem 0.72rem;
        }

        .messages-container {
            overflow-y: auto;
            padding: 0.72rem;
            display: flex;
            flex-direction: column;
            gap: 0.46rem;
            min-height: 0;
            background:
                linear-gradient(180deg, rgba(var(--bk-bg-rgb), 0.6) 0%, rgba(var(--bk-surface-rgb), 1) 100%);
        }

        .messages-container .message {
            max-width: min(76%, 34rem);
            border-radius: 0.8rem;
            padding: 0.5rem 0.62rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            line-height: 1.4;
            box-shadow: 0 4px 14px rgba(3, 78, 162, 0.08);
            word-break: break-word;
        }

        .messages-container .message.sent {
            margin-left: auto;
            background: rgb(var(--bk-primary-rgb));
            border-color: rgba(var(--bk-primary-rgb), 0.9);
            color: #fff;
            border-bottom-right-radius: 0.35rem;
        }

        .messages-container .message.received {
            margin-right: auto;
            background: rgb(var(--bk-surface-rgb));
            color: rgb(var(--bk-text-rgb));
            font-weight: 500;
            border-bottom-left-radius: 0.35rem;
        }

        .messages-container .message-header {
            margin-top: 0.2rem;
            font-size: 0.7rem;
            opacity: 0.92;
            font-weight: 500;
        }

        .messages-container .message.received .message-header {
            color: rgb(var(--bk-muted-rgb));
        }

        .messages-container .no-messages,
        .no-chat-selected {
            border: 1px dashed rgba(var(--bk-border-rgb), 1);
            border-radius: 0.9rem;
            background: rgba(var(--bk-surface-rgb), 0.78);
            color: rgb(var(--bk-muted-rgb));
            text-align: center;
            padding: 0.82rem;
        }

        .input-area {
            border-top: 1px solid rgba(var(--bk-border-rgb), 1);
            padding: 0.55rem 0.72rem;
            display: none;
        }

        .message-input-wrap {
            display: flex;
            gap: 0.44rem;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            min-height: 2.2rem;
            max-height: 6.5rem;
            resize: none;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.75rem;
            background: rgb(var(--bk-surface-rgb));
            color: rgb(var(--bk-text-rgb));
            padding: 0.48rem 0.58rem;
            line-height: 1.38;
            font-size: 0.88rem;
        }

        .message-input:focus {
            outline: none;
            border-color: rgb(var(--bk-primary-rgb));
            box-shadow: 0 0 0 3px rgba(var(--bk-primary-rgb), 0.2);
        }

        .send-btn {
            width: 2.2rem;
            height: 2.2rem;
            border-radius: 999px;
            border: 0;
            background: rgb(var(--bk-primary-rgb));
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
        }

        .send-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-soft);
        }

        .send-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .message-feedback {
            margin-bottom: 0.5rem;
            border: 1px solid transparent;
            border-radius: 0.72rem;
            padding: 0.45rem 0.62rem;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .message-feedback.is-success {
            background: rgba(var(--bk-primary-rgb), 0.12);
            border-color: rgba(var(--bk-primary-rgb), 0.32);
            color: rgb(var(--bk-primary-rgb));
        }

        .message-feedback.is-error {
            background: rgba(var(--bk-danger-rgb), 0.12);
            border-color: rgba(var(--bk-danger-rgb), 0.32);
            color: rgb(var(--bk-danger-rgb));
        }

        @media (min-width: 1400px) {
            .chat-layout {
                grid-template-columns: 280px minmax(0, 1fr);
                gap: 0.62rem;
                min-height: calc(100vh - 292px);
            }

            .contact-list {
                padding: 0.4rem;
                gap: 0.28rem;
            }

            .contact-item {
                padding: 0.38rem;
                gap: 0.42rem;
            }

            .avatar {
                width: 2rem;
                height: 2rem;
            }

            .chat-header {
                padding: 0.56rem 0.62rem;
            }

            .messages-container {
                padding: 0.62rem;
                gap: 0.4rem;
            }

            .messages-container .message {
                max-width: min(72%, 33rem);
                padding: 0.45rem 0.56rem;
                border-radius: 0.74rem;
            }

            .input-area {
                padding: 0.48rem 0.62rem;
            }

            .message-input-wrap {
                gap: 0.38rem;
            }

            .message-input {
                min-height: 2.05rem;
                max-height: 6rem;
                padding: 0.42rem 0.52rem;
                font-size: 0.86rem;
            }

            .send-btn {
                width: 2.05rem;
                height: 2.05rem;
            }

            .message-feedback {
                margin-bottom: 0.4rem;
                padding: 0.4rem 0.54rem;
                font-size: 0.7rem;
            }
        }
        @media (max-width: 1100px) {
            .chat-layout {
                grid-template-columns: 1fr;
                min-height: auto;
            }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content px-4 pb-8 pt-4 sm:px-6 lg:px-8">
    <section class="chat-hero p-3 sm:p-4">
        <h1 class="mt-1 font-display text-2xl font-bold text-bk-text sm:text-3xl">Claimant Messaging</h1>
        <p class="mt-1 max-w-2xl text-sm text-bk-muted">
            Chat directly with legal and finance staff for claim clarifications and status coordination.
        </p>
    </section>

    <section class="chat-layout mt-4">
        <aside class="contact-panel">
            <header class="border-b border-bk-border px-3 py-2.5">
                <h2 class="font-display text-lg font-semibold text-bk-text">Support Team</h2>
                <p class="text-xs text-bk-muted">Select a user to open conversation.</p>
                <input id="contactSearch" type="text" class="ui-input mt-2 h-8" placeholder="Search by name or role">
                <div class="dm-filter-bar" aria-label="Filter contacts by role">
                    <button class="dm-filter active" type="button" data-role-filter="all">All <span><?php echo count($chat_users); ?></span></button>
                    <?php foreach ($roleFilterCounts as $roleKey => $roleCount): ?>
                        <button class="dm-filter" type="button" data-role-filter="<?php echo bk_e($roleKey); ?>">
                            <?php echo bk_e($roleFilterLabels[$roleKey] ?? ucfirst($roleKey)); ?> <span><?php echo (int) $roleCount; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </header>
            <div class="contact-list" id="contactList">
                <?php if (count($chat_users) === 0): ?>
                    <?php render_alert('No support users available right now.', ['type' => 'warning']); ?>
                <?php else: ?>
                    <?php foreach ($chat_users as $user): ?>
                        <?php
                        $displayName = (string) ($user['fullName'] ?? 'User');
                        $firstName = strtok($displayName, ' ') ?: $displayName;
                        $role = strtolower((string) ($user['role'] ?? ''));
                        $roleClass = $role === 'legal' ? 'role-legal' : ($role === 'finance' ? 'role-finance' : 'role-default');
                        $initials = getInitials($displayName);
                        $userPhotoPath = '';
                        ?>
                        <button
                            type="button"
                            class="contact-item"
                            data-user-id="<?php echo (int) ($user['id'] ?? 0); ?>"
                            data-user-name="<?php echo bk_e($displayName); ?>"
                            data-user-role="<?php echo bk_e($role); ?>"
                            data-user-initials="<?php echo bk_e($initials); ?>"
                            data-user-photo="<?php echo bk_e($userPhotoPath); ?>"
                        >
                            <span class="avatar">
                                <span class="initials"><?php echo bk_e($initials); ?></span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-bk-text"><?php echo bk_e($firstName); ?></span>
                                <span class="role-pill <?php echo bk_e($roleClass); ?>"><?php echo bk_e(ucfirst($role ?: 'staff')); ?></span>
                                <span class="dm-open-indicator"><i class="fa-regular fa-message"></i> Direct message</span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <section class="chat-panel">
            <header class="chat-header hidden" id="chat-header">
                <div class="flex items-center gap-3">
                    <span class="avatar" id="chat-user-avatar"><span class="initials">NA</span></span>
                    <div>
                        <h3 id="chat-user-name" class="text-sm font-semibold text-bk-text">Select a user</h3>
                        <p id="chat-user-role" class="text-xs text-bk-muted"></p>
                        <p class="chat-channel-note"><i class="fa-regular fa-message"></i><span>One-to-one direct message</span></p>
                    </div>
                </div>
            </header>

            <div class="messages-container" id="messages-container">
                <div class="no-chat-selected" id="no-chat-selected">
                    <p class="font-medium text-bk-text">No conversation selected</p>
                    <p class="mt-1 text-xs">Choose a role filter, then open a specific support officer. Messages are private direct conversations, not broadcasts.</p>
                </div>
            </div>

            <div class="input-area" id="input-area">
                <p id="message-feedback" class="message-feedback hidden" role="alert" aria-live="polite"></p>
                <div class="message-input-wrap">
                    <textarea id="message-input" class="message-input" rows="1" placeholder="Type your message..." autocomplete="off"></textarea>
                    <button id="send-button" class="send-btn" type="button" aria-label="Send message">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </section>
    </section>
</main>

<script>
(() => {
    let selectedUserId = null;
    let allMessagesLoaded = false;
    const currentUserId = <?php echo (int) $current_user_id; ?>;
    const messageCsrfToken = <?php echo json_encode($messageCsrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    const contactList = document.getElementById('contactList');
    const contactSearch = document.getElementById('contactSearch');
    const roleFilters = document.querySelectorAll('[data-role-filter]');
    let activeRoleFilter = 'all';
    const chatHeader = document.getElementById('chat-header');
    const chatUserAvatar = document.getElementById('chat-user-avatar');
    const chatUserName = document.getElementById('chat-user-name');
    const chatUserRole = document.getElementById('chat-user-role');
    const messagesContainer = document.getElementById('messages-container');
    const noChatSelected = document.getElementById('no-chat-selected');
    const inputArea = document.getElementById('input-area');
    const messageFeedback = document.getElementById('message-feedback');
    const messageInput = document.getElementById('message-input');
    const sendButton = document.getElementById('send-button');
    const preferredContactId = Number.parseInt(
        new URLSearchParams(window.location.search).get('contact_id') || '',
        10
    );

    function resizeTextarea() {
        messageInput.style.height = 'auto';
        messageInput.style.height = Math.min(messageInput.scrollHeight, 128) + 'px';
    }

    function setMessageFeedback(type, text) {
        if (!messageFeedback) return;
        messageFeedback.classList.remove('hidden', 'is-success', 'is-error');

        if (!text) {
            messageFeedback.classList.add('hidden');
            messageFeedback.textContent = '';
            return;
        }

        const stateClass = type === 'success' ? 'is-success' : 'is-error';
        messageFeedback.classList.add(stateClass);
        messageFeedback.textContent = text;
    }

    messageInput.addEventListener('input', resizeTextarea);
    messageInput.addEventListener('input', () => setMessageFeedback('', ''));

    messageInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    });

    sendButton.addEventListener('click', sendMessage);

    function setAvatar(container, photo, initials, name) {
        container.innerHTML = `<span class="initials">${initials}</span>`;
    }

    function selectContact(item) {
        document.querySelectorAll('.contact-item').forEach((node) => node.classList.remove('active'));
        item.classList.add('active');

        selectedUserId = item.dataset.userId || null;
        allMessagesLoaded = false;

        const userName = item.dataset.userName || 'Support User';
        const userRole = item.dataset.userRole || 'staff';
        const userPhoto = item.dataset.userPhoto || '';
        const userInitials = item.dataset.userInitials || 'NA';

        chatHeader.classList.remove('hidden');
        inputArea.style.display = 'block';
        setMessageFeedback('', '');
        if (noChatSelected) noChatSelected.style.display = 'none';

        chatUserName.textContent = userName;
        chatUserRole.textContent = userRole.charAt(0).toUpperCase() + userRole.slice(1);
        setAvatar(chatUserAvatar, userPhoto, userInitials, userName);

        messagesContainer.innerHTML = '<div class="no-messages">Loading messages...</div>';
        loadAllMessages(selectedUserId);
        messageInput.focus();
    }

    document.querySelectorAll('.contact-item').forEach((item) => {
        item.addEventListener('click', () => selectContact(item));
    });

    if (!Number.isNaN(preferredContactId)) {
        const preferredContact = document.querySelector(`.contact-item[data-user-id="${preferredContactId}"]`);
        if (preferredContact) {
            selectContact(preferredContact);
            preferredContact.scrollIntoView({ block: 'nearest' });
        }
    }

    function applyContactFilters() {
        const query = contactSearch ? contactSearch.value.trim().toLowerCase() : '';
        document.querySelectorAll('.contact-item').forEach((item) => {
            const name = (item.dataset.userName || '').toLowerCase();
            const role = (item.dataset.userRole || '').toLowerCase();
            const matchesSearch = query === '' || name.includes(query) || role.includes(query);
            const matchesRole = activeRoleFilter === 'all' || role === activeRoleFilter;
            item.style.display = matchesSearch && matchesRole ? '' : 'none';
        });
    }

    if (contactSearch) {
        contactSearch.addEventListener('input', applyContactFilters);
    }

    roleFilters.forEach((button) => {
        button.addEventListener('click', () => {
            roleFilters.forEach((node) => node.classList.remove('active'));
            button.classList.add('active');
            activeRoleFilter = button.dataset.roleFilter || 'all';
            applyContactFilters();
        });
    });
    function buildUrl(receiverId, mode, lastId = 0) {
        const params = new URLSearchParams({
            sender_id: String(currentUserId),
            receiver_id: String(receiverId),
            mode: mode,
        });
        if (mode === 'new') {
            params.set('last_id', String(lastId));
        }
        return `get_messages.php?${params.toString()}`;
    }

    async function loadAllMessages(receiverId) {
        if (!receiverId) return;

        try {
            const response = await fetch(buildUrl(receiverId, 'all'));
            const html = await response.text();
            messagesContainer.innerHTML = html;
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            allMessagesLoaded = true;
        } catch (error) {
            messagesContainer.innerHTML = '<div class="no-messages">We could not load this conversation. Please try again.</div>';
        }
    }

    async function loadNewMessages(receiverId) {
        if (!receiverId || !allMessagesLoaded) return;

        const lastMessage = messagesContainer.querySelector('.message:last-child');
        const lastMessageId = lastMessage ? Number.parseInt(lastMessage.dataset.messageId || '0', 10) : 0;

        try {
            const response = await fetch(buildUrl(receiverId, 'new', lastMessageId));
            const html = await response.text();
            if (html !== 'no-new-messages') {
                messagesContainer.insertAdjacentHTML('beforeend', html);
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        } catch (error) {
            // Keep silent to avoid chat interruption during transient failures.
        }
    }

    async function sendMessage() {
        const text = messageInput.value.trim();
        if (!text || !selectedUserId) return;

        const formData = new URLSearchParams({
            sender_id: String(currentUserId),
            receiver_id: String(selectedUserId),
            message: text,
            csrf_token: String(messageCsrfToken || ''),
        });

        const originalButton = sendButton.innerHTML;
        sendButton.disabled = true;
        sendButton.innerHTML = '<span class="ui-spinner" aria-hidden="true"></span>';

        try {
            const response = await fetch('send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString(),
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result || result.success !== true) {
                const msg = result && result.message
                    ? result.message
                    : 'Message could not be sent. Please try again.';
                throw new Error(msg);
            }

            messageInput.value = '';
            resizeTextarea();
            setMessageFeedback('success', 'Message sent successfully.');
            await loadNewMessages(selectedUserId);
        } catch (error) {
            setMessageFeedback('error', error.message || 'Message could not be sent. Please try again.');
        } finally {
            sendButton.disabled = false;
            sendButton.innerHTML = originalButton;
            messageInput.focus();
        }
    }

    setInterval(() => {
        if (selectedUserId && allMessagesLoaded) {
            loadNewMessages(selectedUserId);
        }
    }, 3000);
})();
</script>
</body>
</html>






