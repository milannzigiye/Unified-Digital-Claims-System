<?php
declare(strict_types=1);

// SECTION: Start a hardened session with secure cookie settings.
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // SECTION: Detect HTTPS so secure cookies are enabled when possible.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    // SECTION: Lock down default PHP session behavior.
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '1800');

    // SECTION: Apply cookie policy used by every session.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// SECTION: Return (and create if needed) a CSRF token bound to a logical scope.
function udcs_csrf_get(string $scope = 'default'): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }

    $scopeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($scope))) ?: 'default';

    if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    $token = (string) ($_SESSION['csrf_tokens'][$scopeKey] ?? '');
    if ($token === '' || strlen($token) < 32) {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $token = hash('sha256', uniqid('udcs_csrf_', true) . mt_rand());
        }
        $_SESSION['csrf_tokens'][$scopeKey] = $token;
    }

    return $token;
}

// SECTION: Validate CSRF token for a scope using constant-time comparison.
function udcs_csrf_validate(?string $token, string $scope = 'default'): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }

    $scopeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($scope))) ?: 'default';
    $expected = (string) ($_SESSION['csrf_tokens'][$scopeKey] ?? '');
    $provided = trim((string) $token);

    if ($expected === '' || $provided === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}

// SECTION: Enforce the password policy used by authentication entry points.
function udcs_password_meets_policy(string $password): bool
{
    if (strlen($password) < 8) {
        return false;
    }

    return preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/\d/', $password) === 1;
}

// SECTION: Return a stable key for login-attempt throttling.
function udcs_auth_throttle_bucket_key(string $scope, string $email = ''): string
{
    $scopeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($scope))) ?: 'default';
    $emailKey = strtolower(trim($email));
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

    return hash('sha256', implode('|', [$scopeKey, $emailKey, $remoteAddress, $userAgent]));
}

// SECTION: Return the current throttle bucket after cleaning expired state.
function udcs_auth_throttle_get_bucket(string $scope, string $email = ''): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }

    if (!isset($_SESSION['auth_attempt_guard']) || !is_array($_SESSION['auth_attempt_guard'])) {
        $_SESSION['auth_attempt_guard'] = [];
    }

    $bucketKey = udcs_auth_throttle_bucket_key($scope, $email);
    $bucket = $_SESSION['auth_attempt_guard'][$bucketKey] ?? null;
    if (!is_array($bucket)) {
        $bucket = [
            'first_attempt_at' => 0,
            'attempts' => 0,
            'lock_expires_at' => 0,
        ];
    }

    $now = time();
    $lockExpiresAt = (int) ($bucket['lock_expires_at'] ?? 0);
    $firstAttemptAt = (int) ($bucket['first_attempt_at'] ?? 0);

    if ($lockExpiresAt > 0 && $lockExpiresAt <= $now) {
        $bucket = [
            'first_attempt_at' => 0,
            'attempts' => 0,
            'lock_expires_at' => 0,
        ];
    } elseif ($firstAttemptAt > 0 && ($now - $firstAttemptAt) > 900) {
        $bucket['first_attempt_at'] = 0;
        $bucket['attempts'] = 0;
    }

    $_SESSION['auth_attempt_guard'][$bucketKey] = $bucket;
    return $bucket;
}

// SECTION: Check whether a login scope is rate-limited and report the retry delay.
function udcs_auth_throttle_is_limited(string $scope, string $email = '', int &$retryAfterSeconds = 0): bool
{
    $bucket = udcs_auth_throttle_get_bucket($scope, $email);
    $lockExpiresAt = (int) ($bucket['lock_expires_at'] ?? 0);
    $retryAfterSeconds = max(0, $lockExpiresAt - time());

    return $retryAfterSeconds > 0;
}

// SECTION: Record a failed login attempt and lock the scope after repeated failures.
function udcs_auth_throttle_record_failure(
    string $scope,
    string $email = '',
    int $maxAttempts = 5,
    int $windowSeconds = 900,
    int $lockoutSeconds = 600
): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }

    $bucketKey = udcs_auth_throttle_bucket_key($scope, $email);
    $bucket = udcs_auth_throttle_get_bucket($scope, $email);
    $now = time();
    $firstAttemptAt = (int) ($bucket['first_attempt_at'] ?? 0);

    if ($firstAttemptAt <= 0 || ($now - $firstAttemptAt) > $windowSeconds) {
        $bucket['first_attempt_at'] = $now;
        $bucket['attempts'] = 0;
        $bucket['lock_expires_at'] = 0;
    }

    $bucket['attempts'] = (int) ($bucket['attempts'] ?? 0) + 1;

    if ((int) $bucket['attempts'] >= $maxAttempts) {
        $bucket['lock_expires_at'] = $now + $lockoutSeconds;
    }

    $_SESSION['auth_attempt_guard'][$bucketKey] = $bucket;

    return [
        'attempts' => (int) $bucket['attempts'],
        'retry_after' => max(0, ((int) ($bucket['lock_expires_at'] ?? 0)) - $now),
        'locked' => ((int) ($bucket['lock_expires_at'] ?? 0)) > $now,
    ];
}

// SECTION: Clear failed-login state after a successful password check.
function udcs_auth_throttle_clear(string $scope, string $email = ''): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }

    if (!isset($_SESSION['auth_attempt_guard']) || !is_array($_SESSION['auth_attempt_guard'])) {
        return;
    }

    $bucketKey = udcs_auth_throttle_bucket_key($scope, $email);
    unset($_SESSION['auth_attempt_guard'][$bucketKey]);
}

// SECTION: Return a user-facing lockout message.
function udcs_auth_throttle_message(int $retryAfterSeconds): string
{
    $seconds = max(1, $retryAfterSeconds);
    $minutes = (int) ceil($seconds / 60);

    if ($minutes <= 1) {
        return 'Too many sign-in attempts. Please wait about 1 minute and try again.';
    }

    return 'Too many sign-in attempts. Please wait about ' . $minutes . ' minutes and try again.';
}

// SECTION: Regenerate the session and persist the authenticated user consistently.
function udcs_auth_log_in_user(array $user): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) ($user['id'] ?? 0);
    $_SESSION['email'] = (string) ($user['email'] ?? '');
    $_SESSION['role'] = (string) ($user['role'] ?? '');
    $_SESSION['full_name'] = (string) ($user['full_name'] ?? '');
}

// SECTION: Create a one-time action token for sensitive follow-up actions.
function udcs_action_token_issue(string $scope, array $payload, int $ttlSeconds = 300): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }

    $scopeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($scope))) ?: 'default';
    $ttl = max(30, min(3600, $ttlSeconds));

    if (!isset($_SESSION['action_tokens']) || !is_array($_SESSION['action_tokens'])) {
        $_SESSION['action_tokens'] = [];
    }
    if (!isset($_SESSION['action_tokens'][$scopeKey]) || !is_array($_SESSION['action_tokens'][$scopeKey])) {
        $_SESSION['action_tokens'][$scopeKey] = [];
    }

    try {
        $token = bin2hex(random_bytes(24));
    } catch (Throwable $e) {
        $token = hash('sha256', uniqid('udcs_action_', true) . mt_rand());
    }

    $_SESSION['action_tokens'][$scopeKey][$token] = [
        'expires_at' => time() + $ttl,
        'payload' => $payload,
    ];

    return $token;
}

// SECTION: Consume and invalidate a one-time action token.
function udcs_action_token_consume(string $scope, ?string $token): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        secure_session_start();
    }

    $scopeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim($scope))) ?: 'default';
    $tokenKey = trim((string) $token);
    if ($tokenKey === '') {
        return null;
    }

    $bucket = $_SESSION['action_tokens'][$scopeKey] ?? null;
    if (!is_array($bucket)) {
        return null;
    }

    $now = time();
    foreach ($bucket as $storedToken => $entry) {
        $expiresAt = (int) (($entry['expires_at'] ?? 0));
        if ($expiresAt <= 0 || $expiresAt < $now) {
            unset($_SESSION['action_tokens'][$scopeKey][$storedToken]);
        }
    }

    $entry = $_SESSION['action_tokens'][$scopeKey][$tokenKey] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    unset($_SESSION['action_tokens'][$scopeKey][$tokenKey]);

    $expiresAt = (int) (($entry['expires_at'] ?? 0));
    if ($expiresAt <= 0 || $expiresAt < $now) {
        return null;
    }

    $payload = $entry['payload'] ?? null;
    return is_array($payload) ? $payload : null;
}

// SECTION: Fetch one user row by email and optional role using a prepared statement.
function udcs_db_fetch_user_by_email_role(mysqli $conn, string $email, ?string $role = null): ?array
{
    $emailValue = trim($email);
    $roleValue = trim((string) $role);
    if ($emailValue === '') {
        return null;
    }

    if ($roleValue !== '') {
        $stmt = mysqli_prepare($conn, 'SELECT * FROM users WHERE email = ? AND role = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $emailValue, $roleValue);
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT * FROM users WHERE email = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 's', $emailValue);
    }

    if (!mysqli_stmt_execute($stmt)) {
        return null;
    }

    $result = mysqli_stmt_get_result($stmt);
    if (!$result || mysqli_num_rows($result) === 0) {
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    return is_array($row) ? $row : null;
}

// SECTION: Fetch one user id by email and optional role using a prepared statement.
function udcs_db_fetch_user_id_by_email_role(mysqli $conn, string $email, ?string $role = null): int
{
    $emailValue = trim($email);
    $roleValue = trim((string) $role);
    if ($emailValue === '') {
        return 0;
    }

    if ($roleValue !== '') {
        $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? AND role = ? LIMIT 1');
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $emailValue, $roleValue);
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1');
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 's', $emailValue);
    }

    if (!mysqli_stmt_execute($stmt)) {
        return 0;
    }

    $result = mysqli_stmt_get_result($stmt);
    if (!$result || mysqli_num_rows($result) === 0) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    return (int) ($row['id'] ?? 0);
}

// SECTION: Insert one in-app notification using a prepared statement.
function udcs_db_insert_notification(
    mysqli $conn,
    string $receiver,
    string $sender,
    string $message,
    string $status = 'unread'
): bool {
    udcs_notifications_ensure_schema($conn);

    $receiverValue = trim($receiver);
    $senderValue = trim($sender);
    $messageValue = trim($message);
    $statusValue = trim($status) !== '' ? trim($status) : 'unread';

    if ($receiverValue === '' || $senderValue === '' || $messageValue === '') {
        return false;
    }

    $receiverUserId = udcs_notifications_resolve_user_id($conn, $receiverValue);
    $senderUserId = udcs_notifications_resolve_user_id($conn, $senderValue);

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO notifications (receiver, receiver_user_id, sender, sender_user_id, message, status, created_at) VALUES (?, NULLIF(?, 0), ?, NULLIF(?, 0), ?, ?, NOW())'
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'sisiss', $receiverValue, $receiverUserId, $senderValue, $senderUserId, $messageValue, $statusValue);
    return mysqli_stmt_execute($stmt);
}

function udcs_notifications_db_has_column(mysqli $conn, string $table, string $column): bool
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
    $ok = mysqli_stmt_execute($stmt);
    $result = $ok ? mysqli_stmt_get_result($stmt) : false;
    mysqli_stmt_close($stmt);
    return $result !== false && mysqli_num_rows($result) > 0;
}

function udcs_notifications_db_has_index(mysqli $conn, string $table, string $index): bool
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ss', $table, $index);
    $ok = mysqli_stmt_execute($stmt);
    $result = $ok ? mysqli_stmt_get_result($stmt) : false;
    mysqli_stmt_close($stmt);
    return $result !== false && mysqli_num_rows($result) > 0;
}

function udcs_notifications_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    @mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            receiver VARCHAR(255) NOT NULL,
            receiver_user_id INT NULL,
            sender VARCHAR(255) NOT NULL,
            sender_user_id INT NULL,
            message TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unread',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!udcs_notifications_db_has_column($conn, 'notifications', 'receiver_user_id')) {
        @mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN receiver_user_id INT NULL AFTER receiver");
    }
    if (!udcs_notifications_db_has_column($conn, 'notifications', 'sender_user_id')) {
        @mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN sender_user_id INT NULL AFTER sender");
    }
    if (!udcs_notifications_db_has_column($conn, 'users', 'last_notification_opened_id')) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN last_notification_opened_id INT NOT NULL DEFAULT 0");
    }

    if (!udcs_notifications_db_has_index($conn, 'notifications', 'idx_notifications_receiver_user')) {
        @mysqli_query($conn, "ALTER TABLE notifications ADD INDEX idx_notifications_receiver_user (receiver_user_id)");
    }
    if (!udcs_notifications_db_has_index($conn, 'notifications', 'idx_notifications_sender_user')) {
        @mysqli_query($conn, "ALTER TABLE notifications ADD INDEX idx_notifications_sender_user (sender_user_id)");
    }
    if (!udcs_notifications_db_has_index($conn, 'notifications', 'idx_notifications_created')) {
        @mysqli_query($conn, "ALTER TABLE notifications ADD INDEX idx_notifications_created (created_at)");
    }
}

function udcs_notifications_resolve_user_id(mysqli $conn, string $value): int
{
    $raw = trim($value);
    if ($raw === '') {
        return 0;
    }

    if (preg_match('/^\d+$/', $raw) === 1) {
        $userId = (int) $raw;
        $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                mysqli_stmt_close($stmt);
                if ($result && mysqli_num_rows($result) > 0) {
                    return $userId;
                }
            } else {
                mysqli_stmt_close($stmt);
            }
        }
    }

    $stmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 's', $raw);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return 0;
    }
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    if (!$result || mysqli_num_rows($result) === 0) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return (int) ($row['id'] ?? 0);
}

function udcs_notifications_identity_from_session(mysqli $conn, string $requiredRole): ?array
{
    $role = strtolower(trim($requiredRole));
    $sessionRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    $email = trim((string) ($_SESSION['email'] ?? ''));
    if ($role === '' || $sessionRole !== $role || $email === '') {
        return null;
    }

    $userId = udcs_db_fetch_user_id_by_email_role($conn, $email, $role);
    if ($userId <= 0) {
        return null;
    }

    return [
        'user_id' => $userId,
        'email' => $email,
        'role' => $role,
    ];
}

function udcs_notifications_fetch_for_user(mysqli $conn, int $userId, string $email, int $limit = 100): array
{
    udcs_notifications_ensure_schema($conn);

    $userId = (int) $userId;
    $email = trim($email);
    if ($userId <= 0 || $email === '') {
        return [
            'items' => [],
            'unread_count' => 0,
            'total_count' => 0,
            'unopened_count' => 0,
            'latest_id' => 0,
            'last_opened_id' => 0,
        ];
    }

    $idString = (string) $userId;
    $limit = max(1, min(250, $limit));
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            n.*,
            viewer.last_notification_opened_id,
            COALESCE(sender_by_id.full_name, sender_by_email.full_name, sender_by_legacy_id.full_name, '') AS sender_name
         FROM notifications n
         INNER JOIN users viewer ON viewer.id = ?
         LEFT JOIN users sender_by_id ON sender_by_id.id = n.sender_user_id
         LEFT JOIN users sender_by_email ON LOWER(TRIM(sender_by_email.email)) = LOWER(TRIM(n.sender))
         LEFT JOIN users sender_by_legacy_id ON CAST(sender_by_legacy_id.id AS CHAR) = CAST(n.sender AS CHAR)
         WHERE n.receiver_user_id = ?
            OR n.receiver = ?
            OR n.receiver = ?
         ORDER BY n.id DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [
            'items' => [],
            'unread_count' => 0,
            'total_count' => 0,
            'unopened_count' => 0,
            'latest_id' => 0,
            'last_opened_id' => 0,
        ];
    }

    mysqli_stmt_bind_param($stmt, 'iissi', $userId, $userId, $idString, $email, $limit);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [
            'items' => [],
            'unread_count' => 0,
            'total_count' => 0,
            'unopened_count' => 0,
            'latest_id' => 0,
            'last_opened_id' => 0,
        ];
    }

    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    if (!$result) {
        return [
            'items' => [],
            'unread_count' => 0,
            'total_count' => 0,
            'unopened_count' => 0,
            'latest_id' => 0,
            'last_opened_id' => 0,
        ];
    }

    $items = [];
    $unreadCount = 0;
    $unopenedCount = 0;
    $latestId = 0;
    $lastOpenedId = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        $rowId = (int) ($row['id'] ?? 0);
        $latestId = max($latestId, $rowId);
        $lastOpenedId = max($lastOpenedId, (int) ($row['last_notification_opened_id'] ?? 0));

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($status === 'unread') {
            $unreadCount++;
        }

        $isUnopened = $rowId > $lastOpenedId;
        if ($isUnopened) {
            $unopenedCount++;
        }

        $message = trim((string) ($row['message'] ?? ''));
        if (preg_match('/^you sent a message to\s*$/i', $message)) {
            $senderName = trim((string) ($row['sender_name'] ?? 'a user'));
            $row['message'] = 'New message from ' . ($senderName !== '' ? $senderName : 'a user') . '.';
        }

        $row['sender_name'] = trim((string) ($row['sender_name'] ?? ''));
        $row['is_unopened'] = $isUnopened;
        $items[] = $row;
    }

    return [
        'items' => $items,
        'unread_count' => $unreadCount,
        'total_count' => count($items),
        'unopened_count' => $unopenedCount,
        'latest_id' => $latestId,
        'last_opened_id' => $lastOpenedId,
    ];
}

function udcs_notifications_mark_opened(mysqli $conn, int $userId, string $email, int $latestSeenId = 0): int
{
    udcs_notifications_ensure_schema($conn);

    $userId = (int) $userId;
    $email = trim($email);
    if ($userId <= 0 || $email === '') {
        return 0;
    }

    $idString = (string) $userId;
    $maxId = 0;
    if ($latestSeenId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT MAX(id) AS max_id
             FROM notifications
             WHERE id <= ?
               AND (receiver_user_id = ? OR receiver = ? OR receiver = ?)"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iiss', $latestSeenId, $userId, $idString, $email);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                $maxId = (int) ($row['max_id'] ?? 0);
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $payload = udcs_notifications_fetch_for_user($conn, $userId, $email, 1);
        $maxId = (int) ($payload['latest_id'] ?? 0);
    }

    if ($maxId <= 0) {
        return 0;
    }

    $updateStmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET last_notification_opened_id = GREATEST(COALESCE(last_notification_opened_id, 0), ?)
         WHERE id = ?
         LIMIT 1"
    );
    if ($updateStmt) {
        mysqli_stmt_bind_param($updateStmt, 'ii', $maxId, $userId);
        mysqli_stmt_execute($updateStmt);
        mysqli_stmt_close($updateStmt);
    }

    return $maxId;
}

function udcs_notifications_mark_read(mysqli $conn, int $userId, string $email, int $notificationId): int
{
    udcs_notifications_ensure_schema($conn);

    $userId = (int) $userId;
    $notificationId = (int) $notificationId;
    $email = trim($email);
    if ($userId <= 0 || $notificationId <= 0 || $email === '') {
        return 0;
    }

    $idString = (string) $userId;
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE notifications
         SET status = 'read'
         WHERE id = ?
           AND (receiver_user_id = ? OR receiver = ? OR receiver = ?)"
    );
    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 'iiss', $notificationId, $userId, $idString, $email);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    udcs_notifications_mark_opened($conn, $userId, $email, $notificationId);
    return max(0, (int) $affected);
}

function udcs_notifications_mark_all_read(mysqli $conn, int $userId, string $email): int
{
    udcs_notifications_ensure_schema($conn);

    $userId = (int) $userId;
    $email = trim($email);
    if ($userId <= 0 || $email === '') {
        return 0;
    }

    $idString = (string) $userId;
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE notifications
         SET status = 'read'
         WHERE status = 'unread'
           AND (receiver_user_id = ? OR receiver = ? OR receiver = ?)"
    );
    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 'iss', $userId, $idString, $email);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    udcs_notifications_mark_opened($conn, $userId, $email);
    return max(0, (int) $affected);
}

// SECTION: Bind a dynamic number of parameters to a prepared statement.
function udcs_db_stmt_bind(mysqli_stmt $stmt, string $types, array &$values): bool
{
    if ($types === '') {
        return true;
    }
    if (strlen($types) !== count($values)) {
        return false;
    }

    $args = [$types];
    foreach ($values as $index => &$value) {
        $args[] = &$value;
    }

    return (bool) call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $args));
}
