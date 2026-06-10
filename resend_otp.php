<?php
// SECTION: Start session and connect to database for OTP resend.
require_once __DIR__ . '/security.php';
secure_session_start();
include 'connect.php';
require_once __DIR__ . '/components/workflow.php';

header('Content-Type: application/json');

// SECTION: Validate request input.
// Check if email is provided (supports JSON and regular form POST).
$emailInput = trim((string) ($_POST['email'] ?? ''));
if ($emailInput === '') {
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && $rawBody !== '') {
        $json = json_decode($rawBody, true);
        if (is_array($json)) {
            $emailInput = trim((string) ($json['email'] ?? ''));
        }
    }
}

if ($emailInput === '') {
    bk_activity_log($conn, [
        'actor_id' => 0,
        'actor_role' => 'system',
        'action_key' => 'otp_resend_failed_missing_email',
        'action_label' => 'OTP Resend Failed',
        'details' => 'OTP resend failed because email was missing from request.',
    ]);
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit();
}

if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
    bk_activity_log($conn, [
        'actor_id' => 0,
        'actor_role' => 'system',
        'action_key' => 'otp_resend_failed_invalid_email',
        'action_label' => 'OTP Resend Failed',
        'details' => 'OTP resend failed because an invalid email format was provided.',
        'meta' => [
            'email' => $emailInput,
        ],
    ]);
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address']);
    exit();
}

$guardKey = hash('sha256', strtolower($emailInput));
if (!isset($_SESSION['otp_resend_guard']) || !is_array($_SESSION['otp_resend_guard'])) {
    $_SESSION['otp_resend_guard'] = [];
}
if (!isset($_SESSION['otp_resend_guard'][$guardKey]) || !is_array($_SESSION['otp_resend_guard'][$guardKey])) {
    $_SESSION['otp_resend_guard'][$guardKey] = [
        'window_start' => 0,
        'count' => 0,
        'next_allowed_at' => 0,
        'blocked_until' => 0,
    ];
}

$guard = (array) $_SESSION['otp_resend_guard'][$guardKey];
$now = time();
$blockedUntil = (int) ($guard['blocked_until'] ?? 0);
if ($blockedUntil > $now) {
    $seconds = max(1, $blockedUntil - $now);
    echo json_encode(['success' => false, 'message' => 'Too many OTP requests. Please wait ' . $seconds . ' seconds.']);
    exit();
}

$nextAllowedAt = (int) ($guard['next_allowed_at'] ?? 0);
if ($nextAllowedAt > $now) {
    $seconds = max(1, $nextAllowedAt - $now);
    echo json_encode(['success' => false, 'message' => 'Please wait ' . $seconds . ' seconds before requesting another OTP.']);
    exit();
}

$windowStart = (int) ($guard['window_start'] ?? 0);
if ($windowStart <= 0 || ($now - $windowStart) > 3600) {
    $guard['window_start'] = $now;
    $guard['count'] = 0;
}

if ((int) ($guard['count'] ?? 0) >= 5) {
    $guard['blocked_until'] = $now + (15 * 60);
    $_SESSION['otp_resend_guard'][$guardKey] = $guard;
    echo json_encode(['success' => false, 'message' => 'OTP resend limit reached. Try again in 15 minutes.']);
    exit();
}

// SECTION: Find the user before creating a new OTP.
$query = mysqli_prepare($conn, 'SELECT id, full_name, role FROM users WHERE email = ? LIMIT 1');
if (!$query) {
    echo json_encode(['success' => false, 'message' => 'Unable to process OTP request right now.']);
    exit();
}
mysqli_stmt_bind_param($query, 's', $emailInput);
mysqli_stmt_execute($query);
$result = mysqli_stmt_get_result($query);
mysqli_stmt_close($query);

if (!$result || mysqli_num_rows($result) === 0) {
    bk_activity_log($conn, [
        'actor_id' => 0,
        'actor_role' => 'system',
        'action_key' => 'otp_resend_failed_user_not_found',
        'action_label' => 'OTP Resend Failed',
        'details' => 'OTP resend failed because no matching account was found.',
        'meta' => [
            'email' => $emailInput,
        ],
    ]);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}

$user = mysqli_fetch_assoc($result);
$userId = (int) ($user['id'] ?? 0);
$userName = (string) ($user['full_name'] ?? '');
$userRole = strtolower(trim((string) ($user['role'] ?? 'system')));

// SECTION: Create a fresh OTP and save it with a new expiry time.
$newOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$updateQuery = mysqli_prepare(
    $conn,
    "UPDATE users
     SET email_otp = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), updated_at = NOW()
     WHERE id = ?"
);
if (!$updateQuery) {
    echo json_encode(['success' => false, 'message' => 'Unable to process OTP request right now.']);
    exit();
}
mysqli_stmt_bind_param($updateQuery, 'si', $newOtp, $userId);
$updateOk = mysqli_stmt_execute($updateQuery);
mysqli_stmt_close($updateQuery);

if (!$updateOk) {
    bk_activity_log($conn, [
        'actor_id' => $userId,
        'actor_role' => $userRole !== '' ? $userRole : 'system',
        'action_key' => 'otp_resend_failed_update_error',
        'action_label' => 'OTP Resend Failed',
        'details' => 'OTP resend failed because the account record could not be updated.',
    ]);
    echo json_encode(['success' => false, 'message' => 'Failed to update OTP']);
    exit();
}

$guard['count'] = (int) ($guard['count'] ?? 0) + 1;
$guard['next_allowed_at'] = $now + 60;
$_SESSION['otp_resend_guard'][$guardKey] = $guard;

// SECTION: Send OTP email and return a clear JSON response.
require 'sendOtpEmail.php';
 $otpError = '';
$sent = sendOtpEmail($emailInput, $userName, $newOtp, $otpError);
if ($sent) {
    bk_activity_log($conn, [
        'actor_id' => $userId,
        'actor_role' => $userRole !== '' ? $userRole : 'system',
        'action_key' => 'otp_resent',
        'action_label' => 'OTP Resent',
        'details' => 'A new OTP was generated and sent to the account email address.',
    ]);
    echo json_encode(['success' => true, 'message' => 'New OTP sent successfully']);
} else {
    bk_activity_log($conn, [
        'actor_id' => $userId,
        'actor_role' => $userRole !== '' ? $userRole : 'system',
        'action_key' => 'otp_resend_failed_email_send',
        'action_label' => 'OTP Resend Failed',
        'details' => 'A new OTP was generated but email delivery failed.',
        'meta' => [
            'error' => $otpError,
        ],
    ]);
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Configure email sender and try again.']);
}
?>

