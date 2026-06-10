<?php
include 'connect.php';
require_once __DIR__ . '/security.php';
secure_session_start();
require_once __DIR__ . '/components/workflow.php';
require_once __DIR__ . '/components/claims_v2.php';
require_once __DIR__ . '/components/head.php';
require_once __DIR__ . '/components/alert.php';

udcs_claims_v2_ensure_schema($conn);

$fetchCount = static function (string $sql, string $types = '', array $params = []) use ($conn): int {
    try {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return 0;
        }
        if (!udcs_db_stmt_bind($stmt, $types, $params)) {
            mysqli_stmt_close($stmt);
            return 0;
        }
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return 0;
        }
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_row($result) : null;
        mysqli_stmt_close($stmt);
        return (int) ($row[0] ?? 0);
    } catch (mysqli_sql_exception $e) {
        return 0;
    }
};

$hasAnyRow = static function (string $sql, string $types = '', array $params = []) use ($conn): bool {
    try {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return false;
        }
        if (!udcs_db_stmt_bind($stmt, $types, $params)) {
            mysqli_stmt_close($stmt);
            return false;
        }
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
        $result = mysqli_stmt_get_result($stmt);
        $hasRow = $result && mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);
        return $hasRow;
    } catch (mysqli_sql_exception $e) {
        return false;
    }
};

$old = static function (string $key): string {
    return htmlspecialchars((string) ($_POST[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};

$adminExists = $hasAnyRow(
    "SELECT id FROM users WHERE role = ? AND acceptance = ? LIMIT 1",
    'ss',
    ['admin', 'Yes']
);

$liveClaimants = $fetchCount("SELECT COUNT(*) FROM users WHERE role = ?", 's', ['claimant']);
$liveClaims = $fetchCount("SELECT COUNT(*) FROM claims");

$claimsStatusExpression = null;
$statusColumnExists = $hasAnyRow(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'claims'
       AND COLUMN_NAME = ?
     LIMIT 1",
    's',
    ['status']
);
$claimStatusExists = $hasAnyRow(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'claims'
       AND COLUMN_NAME = ?
     LIMIT 1",
    's',
    ['claim_status']
);
if ($statusColumnExists && $claimStatusExists) {
    $claimsStatusExpression = "COALESCE(NULLIF(status, ''), claim_status)";
} elseif ($statusColumnExists) {
    $claimsStatusExpression = 'status';
} elseif ($claimStatusExists) {
    $claimsStatusExpression = 'claim_status';
}

$liveClosedClaims = 0;
if ($claimsStatusExpression !== null) {
    $liveClosedClaims = $fetchCount(
        "SELECT COUNT(*) FROM claims
         WHERE LOWER($claimsStatusExpression) LIKE '%approved by finance%'
            OR LOWER($claimsStatusExpression) IN ('closed','approved','approved for disbursement','disbursed')"
    );
}

$liveActivityEvents = $fetchCount("SELECT COUNT(*) FROM activity_logs");

$aud = $_GET['aud'] ?? 'claimant';
if (!in_array($aud, ['claimant', 'staff'], true)) {
    $aud = 'claimant';
}
$tab = $_GET['tab'] ?? 'login';
if (!in_array($tab, ['login', 'signup'], true)) {
    $tab = 'login';
}

$activeAud = $aud;
$activeTab = $tab;
$notification = ['type' => '', 'message' => '', 'show' => false];
$accessClaimantLoginCsrf = udcs_csrf_get('access_claimant_login_form');
$accessClaimantSignupCsrf = udcs_csrf_get('access_claimant_signup_form');
$accessStaffLoginCsrf = udcs_csrf_get('access_staff_login_form');
$accessStaffSignupCsrf = udcs_csrf_get('access_staff_signup_form');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['claimant_signup'])) {
        $activeAud = 'claimant';
        $activeTab = 'signup';

        if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'access_claimant_signup_form')) {
            $notification = ['type' => 'danger', 'message' => 'Your session expired. Please try again.', 'show' => true];
        }

        $first = trim((string) ($_POST['claimant_fname'] ?? ''));
        $last = trim((string) ($_POST['claimant_lname'] ?? ''));
        $name = trim($first . ' ' . $last);
        $email = trim((string) ($_POST['claimant_email'] ?? ''));
        $phone = trim((string) ($_POST['claimant_phone'] ?? ''));
        $password = $_POST['claimant_password'] ?? '';
        $confirm = $_POST['claimant_confirm_password'] ?? '';

        if (
            !$notification['show']
            && ($name === '' || $email === '' || $phone === '')
        ) {
            $notification = ['type' => 'danger', 'message' => 'Please complete all required claimant fields.', 'show' => true];
        } elseif (!$notification['show'] && $password !== $confirm) {
            $notification = ['type' => 'danger', 'message' => 'Claimant passwords do not match.', 'show' => true];
        } elseif (!$notification['show'] && !udcs_password_meets_policy($password)) {
            $notification = ['type' => 'danger', 'message' => 'Password must be at least 8 characters long and include uppercase, lowercase, and a number.', 'show' => true];
        } else {
            $existingId = udcs_db_fetch_user_id_by_email_role($conn, $email, null);
            if ($existingId > 0) {
                $notification = ['type' => 'danger', 'message' => 'This email is already in use.', 'show' => true];
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $insertStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO users (full_name, email, phone, role, password, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
                );
                $role = 'claimant';
                $ok = false;
                if ($insertStmt) {
                    mysqli_stmt_bind_param($insertStmt, 'sssss', $name, $email, $phone, $role, $hashed);
                    $ok = mysqli_stmt_execute($insertStmt);
                }

                if ($ok) {
                    $newId = (int) mysqli_insert_id($conn);
                    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                    $otpStmt = mysqli_prepare(
                        $conn,
                        'UPDATE users
                         SET email_otp = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), email_verified = 0
                         WHERE id = ?
                         LIMIT 1'
                    );
                    if ($otpStmt) {
                        mysqli_stmt_bind_param($otpStmt, 'si', $otp, $newId);
                        mysqli_stmt_execute($otpStmt);
                    }

                    require_once __DIR__ . '/sendOtpEmail.php';
                    $otpError = '';
                    $otpSent = sendOtpEmail($email, $name, $otp, $otpError);
                    if ($otpSent) {
                        $_SESSION['otp_email'] = $email;
                        $_SESSION['otp_return_url'] = 'claimant-access.php';
                    }

                    bk_activity_log($conn, [
                        'actor_id' => $newId,
                        'actor_role' => 'claimant',
                        'action_key' => 'claimant_account_created',
                        'action_label' => 'Claimant Account Created',
                        'details' => 'Claimant account created from unified access page.',
                    ]);

                    if ($otpSent) {
                        $notification = ['type' => 'success', 'message' => 'Claimant account created. OTP sent to email.', 'show' => true];
                        header('refresh:1;url=verify-otp.php');
                        exit();
                    }

                    bk_activity_log($conn, [
                        'actor_id' => $newId,
                        'actor_role' => 'claimant',
                        'action_key' => 'otp_send_failed',
                        'action_label' => 'OTP Send Failed',
                        'details' => 'Claimant account created but OTP email could not be delivered.',
                        'meta' => ['error' => $otpError],
                    ]);
                    $notification = ['type' => 'warning', 'message' => 'Claimant account created, but OTP email could not be sent. Configure email sender and sign in again to resend OTP.', 'show' => true];
                } else {
                    $notification = ['type' => 'danger', 'message' => 'Claimant sign-up failed. Please try again.', 'show' => true];
                }
            }
        }
    }

    if (isset($_POST['claimant_login'])) {
        $activeAud = 'claimant';
        $activeTab = 'login';

        if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'access_claimant_login_form')) {
            $notification = ['type' => 'danger', 'message' => 'Your session expired. Please try again.', 'show' => true];
        }

        $email = trim((string) ($_POST['claimant_email'] ?? ''));
        $password = $_POST['claimant_password'] ?? '';
        $throttleScope = 'access_claimant_login';
        $retryAfterSeconds = 0;

        if (!$notification['show'] && udcs_auth_throttle_is_limited($throttleScope, $email, $retryAfterSeconds)) {
            $notification = ['type' => 'danger', 'message' => udcs_auth_throttle_message($retryAfterSeconds), 'show' => true];
        }

        $user = !$notification['show'] ? udcs_db_fetch_user_by_email_role($conn, $email, null) : null;

        if (!$notification['show'] && $user) {
            $role = (string) ($user['role'] ?? '');
            $id = (int) ($user['id'] ?? 0);

            if ($role !== 'claimant') {
                bk_activity_log($conn, [
                    'actor_id' => $id,
                    'actor_role' => $role !== '' ? $role : 'system',
                    'action_key' => 'login_denied_wrong_portal',
                    'action_label' => 'Login Denied: Wrong Portal',
                    'details' => 'Staff account attempted to sign in through claimant access.',
                ]);

                $notification = ['type' => 'warning', 'message' => 'This account belongs to bank staff. Use Staff Access.', 'show' => true];
                $activeAud = 'staff';
                $activeTab = 'login';
            } elseif (!password_verify($password, (string) ($user['password'] ?? ''))) {
                $failureState = udcs_auth_throttle_record_failure($throttleScope, $email);
                $notification = [
                    'type' => 'danger',
                    'message' => $failureState['locked']
                        ? udcs_auth_throttle_message((int) $failureState['retry_after'])
                        : 'Invalid password.',
                    'show' => true,
                ];
            } elseif ((int) ($user['email_verified'] ?? 0) === 0) {
                udcs_auth_throttle_clear($throttleScope, $email);
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $otpStmt = mysqli_prepare(
                    $conn,
                    'UPDATE users
                     SET email_otp = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                     WHERE id = ?
                     LIMIT 1'
                );
                if ($otpStmt) {
                    mysqli_stmt_bind_param($otpStmt, 'si', $otp, $id);
                    mysqli_stmt_execute($otpStmt);
                }

                require_once __DIR__ . '/sendOtpEmail.php';
                $otpError = '';
                $otpSent = sendOtpEmail((string) $user['email'], (string) $user['full_name'], $otp, $otpError);
                if ($otpSent) {
                    $_SESSION['otp_email'] = $user['email'];
                    $_SESSION['otp_return_url'] = 'claimant-access.php';
                    $notification = ['type' => 'info', 'message' => 'Verification required. A new OTP was sent to your email.', 'show' => true];
                    header('refresh:1;url=verify-otp.php');
                    exit();
                }

                bk_activity_log($conn, [
                    'actor_id' => $id,
                    'actor_role' => 'claimant',
                    'action_key' => 'otp_send_failed',
                    'action_label' => 'OTP Send Failed',
                    'details' => 'Claimant OTP challenge was generated but email delivery failed.',
                    'meta' => ['error' => $otpError],
                ]);
                $notification = ['type' => 'danger', 'message' => 'Could not send OTP email right now. Configure email sender and try again.', 'show' => true];
            } else {
                udcs_auth_throttle_clear($throttleScope, $email);
                udcs_auth_log_in_user($user);

                $totalClaims = 0;
                $countStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM claims WHERE COALESCE(claimant_user_id, claimant_id) = ?');
                if ($countStmt) {
                    $claimantId = (int) ($user['id'] ?? 0);
                    mysqli_stmt_bind_param($countStmt, 'i', $claimantId);
                    if (mysqli_stmt_execute($countStmt)) {
                        $countResult = mysqli_stmt_get_result($countStmt);
                        if ($countResult && mysqli_num_rows($countResult) === 1) {
                            $countRow = mysqli_fetch_assoc($countResult);
                            $totalClaims = (int) ($countRow['total'] ?? 0);
                        }
                    }
                }

                $goTo = $totalClaims > 0 ? 'Claimant/dashboard.php' : 'Claimant/form_v2.php';
                $notification = ['type' => 'success', 'message' => 'Sign-in successful. Redirecting...', 'show' => true];
                header('refresh:1;url=' . $goTo);
                exit();
            }
        } elseif (!$notification['show']) {
            $failureState = udcs_auth_throttle_record_failure($throttleScope, $email);
            $notification = [
                'type' => 'danger',
                'message' => $failureState['locked']
                    ? udcs_auth_throttle_message((int) $failureState['retry_after'])
                    : 'Account not found.',
                'show' => true,
            ];
        }
    }

    if (isset($_POST['staff_signup'])) {
        $activeAud = 'staff';
        $activeTab = 'signup';

        if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'access_staff_signup_form')) {
            $notification = ['type' => 'danger', 'message' => 'Your session expired. Please try again.', 'show' => true];
        }

        $first = trim((string) ($_POST['staff_fname'] ?? ''));
        $last = trim((string) ($_POST['staff_lname'] ?? ''));
        $name = trim($first . ' ' . $last);
        $email = trim((string) ($_POST['staff_email'] ?? ''));
        $phone = trim((string) ($_POST['staff_phone'] ?? ''));
        $role = trim((string) ($_POST['staff_role'] ?? ''));
        $password = $_POST['staff_password'] ?? '';
        $confirm = $_POST['staff_confirm_password'] ?? '';

        if (!$notification['show'] && !in_array($role, ['legal', 'finance', 'admin'], true)) {
            $notification = ['type' => 'danger', 'message' => 'Please select a valid staff role.', 'show' => true];
        } elseif (!$notification['show'] && $role === 'admin' && $adminExists) {
            $notification = ['type' => 'warning', 'message' => 'Admin account is already assigned.', 'show' => true];
        } elseif (!$notification['show'] && $password !== $confirm) {
            $notification = ['type' => 'danger', 'message' => 'Staff passwords do not match.', 'show' => true];
        } elseif (!$notification['show'] && !udcs_password_meets_policy($password)) {
            $notification = ['type' => 'danger', 'message' => 'Password must be at least 8 characters long and include uppercase, lowercase, and a number.', 'show' => true];
        } else {
            $existingId = udcs_db_fetch_user_id_by_email_role($conn, $email, null);
            if ($existingId > 0) {
                $notification = ['type' => 'danger', 'message' => 'This email is already in use.', 'show' => true];
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $insertStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO users (full_name, email, phone, role, password, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
                );
                $ok = false;
                if ($insertStmt) {
                    mysqli_stmt_bind_param($insertStmt, 'sssss', $name, $email, $phone, $role, $hashed);
                    $ok = mysqli_stmt_execute($insertStmt);
                }

                if ($ok) {
                    $newId = (int) mysqli_insert_id($conn);
                    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                    $otpStmt = mysqli_prepare(
                        $conn,
                        'UPDATE users
                         SET email_otp = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), email_verified = 0
                         WHERE id = ?
                         LIMIT 1'
                    );
                    if ($otpStmt) {
                        mysqli_stmt_bind_param($otpStmt, 'si', $otp, $newId);
                        mysqli_stmt_execute($otpStmt);
                    }

                    require_once __DIR__ . '/sendOtpEmail.php';
                    $otpError = '';
                    $otpSent = sendOtpEmail($email, $name, $otp, $otpError);
                    if ($otpSent) {
                        $_SESSION['otp_email'] = $email;
                        $_SESSION['otp_return_url'] = 'login.php';

                        $notification = ['type' => 'success', 'message' => 'Staff account request created. OTP sent to email.', 'show' => true];
                        header('refresh:1;url=verify-otp.php');
                        exit();
                    }

                    bk_activity_log($conn, [
                        'actor_id' => $newId,
                        'actor_role' => $role,
                        'action_key' => 'otp_send_failed',
                        'action_label' => 'OTP Send Failed',
                        'details' => 'Staff account created but OTP email could not be delivered.',
                        'meta' => ['error' => $otpError],
                    ]);
                    $notification = ['type' => 'warning', 'message' => 'Staff account created, but OTP email could not be sent. Configure email sender and sign in again to resend OTP.', 'show' => true];
                } else {
                    $notification = ['type' => 'danger', 'message' => 'Staff sign-up failed. Please try again.', 'show' => true];
                }
            }
        }
    }

    if (isset($_POST['staff_login'])) {
        $activeAud = 'staff';
        $activeTab = 'login';

        if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'access_staff_login_form')) {
            $notification = ['type' => 'danger', 'message' => 'Your session expired. Please try again.', 'show' => true];
        }

        $email = trim((string) ($_POST['staff_email'] ?? ''));
        $password = $_POST['staff_password'] ?? '';
        $throttleScope = 'access_staff_login';
        $retryAfterSeconds = 0;

        if (!$notification['show'] && udcs_auth_throttle_is_limited($throttleScope, $email, $retryAfterSeconds)) {
            $notification = ['type' => 'danger', 'message' => udcs_auth_throttle_message($retryAfterSeconds), 'show' => true];
        }

        $user = !$notification['show'] ? udcs_db_fetch_user_by_email_role($conn, $email, null) : null;

        if (!$notification['show'] && $user) {
            $role = (string) ($user['role'] ?? '');
            $id = (int) ($user['id'] ?? 0);

            if ($role === 'claimant') {
                $notification = ['type' => 'warning', 'message' => 'Claimants should use Claimant Access.', 'show' => true];
                $activeAud = 'claimant';
                $activeTab = 'login';
            } elseif (!password_verify($password, (string) ($user['password'] ?? ''))) {
                $failureState = udcs_auth_throttle_record_failure($throttleScope, $email);
                $notification = [
                    'type' => 'danger',
                    'message' => $failureState['locked']
                        ? udcs_auth_throttle_message((int) $failureState['retry_after'])
                        : 'Invalid password.',
                    'show' => true,
                ];
            } elseif (in_array($role, ['admin', 'legal', 'finance'], true) && (string) ($user['acceptance'] ?? '') !== 'Yes') {
                udcs_auth_throttle_clear($throttleScope, $email);
                $notification = ['type' => 'warning', 'message' => 'Your account has no access yet. Please wait for admin acceptance.', 'show' => true];
            } elseif ((int) ($user['email_verified'] ?? 0) === 0) {
                udcs_auth_throttle_clear($throttleScope, $email);
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $otpStmt = mysqli_prepare(
                    $conn,
                    'UPDATE users
                     SET email_otp = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                     WHERE id = ?
                     LIMIT 1'
                );
                if ($otpStmt) {
                    mysqli_stmt_bind_param($otpStmt, 'si', $otp, $id);
                    mysqli_stmt_execute($otpStmt);
                }

                require_once __DIR__ . '/sendOtpEmail.php';
                $otpError = '';
                $otpSent = sendOtpEmail((string) $user['email'], (string) $user['full_name'], $otp, $otpError);
                if ($otpSent) {
                    $_SESSION['otp_email'] = $user['email'];
                    $_SESSION['otp_return_url'] = 'login.php';

                    $notification = ['type' => 'info', 'message' => 'OTP verification required. Check your email.', 'show' => true];
                    header('refresh:1;url=verify-otp.php');
                    exit();
                }

                bk_activity_log($conn, [
                    'actor_id' => $id,
                    'actor_role' => $role !== '' ? $role : 'system',
                    'action_key' => 'otp_send_failed',
                    'action_label' => 'OTP Send Failed',
                    'details' => 'Staff OTP challenge was generated but email delivery failed.',
                    'meta' => ['error' => $otpError],
                ]);
                $notification = ['type' => 'danger', 'message' => 'Could not send OTP email right now. Configure email sender and try again.', 'show' => true];
            } else {
                udcs_auth_throttle_clear($throttleScope, $email);
                udcs_auth_log_in_user($user);

                $notification = ['type' => 'success', 'message' => 'Login successful. Redirecting...', 'show' => true];
                if ($role === 'admin') {
                    header('refresh:1;url=Admin/dashboard.php');
                } elseif ($role === 'legal') {
                    header('refresh:1;url=Legal/dashboard.php');
                } else {
                    header('refresh:1;url=Finance/dashboard.php');
                }
                exit();
            }
        } elseif (!$notification['show']) {
            $failureState = udcs_auth_throttle_record_failure($throttleScope, $email);
            $notification = [
                'type' => 'danger',
                'message' => $failureState['locked']
                    ? udcs_auth_throttle_message((int) $failureState['retry_after'])
                    : 'Account not found.',
                'show' => true,
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" x-data="{
    audience: '<?php echo htmlspecialchars($activeAud, ENT_QUOTES, 'UTF-8'); ?>',
    tab: '<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>',
    showClaimantLoginPassword: false,
    showClaimantSignupPassword: false,
    showClaimantSignupConfirm: false,
    showStaffLoginPassword: false,
    showStaffSignupPassword: false,
    showStaffSignupConfirm: false
}">
<head>
    <?php render_head('Bank of Kigali | UDCS Access'); ?>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-[linear-gradient(160deg,#e8f0fb_0%,#f5f8fd_45%,#edf4ff_100%)] text-bk-text">
    <header class="sticky top-0 z-40 border-b border-bk-border bg-bk-surface/95">
        <div class="mx-auto flex h-20 w-full max-w-[1600px] items-center justify-between px-4 sm:px-6 lg:px-8">
            <a href="index.php" class="flex items-center gap-3">
                <img src="Images/logo.png" alt="Bank of Kigali" class="h-10 w-10 rounded-md bg-white p-1 shadow-soft">
                <div>
                    <p class="font-display text-xl font-bold tracking-tight text-bk-text">UDCS</p>
                    <p class="hidden text-[11px] font-semibold uppercase tracking-[0.12em] text-bk-muted sm:block">Unified Digital Claims System</p>
                </div>
            </a>
            <div class="hidden items-center gap-3 md:flex">
                <a href="access.php?aud=staff&tab=login" class="ui-btn ui-btn-sm ui-btn-secondary">Staff Access</a>
                <a href="access.php?aud=claimant&tab=signup" class="ui-btn ui-btn-sm ui-btn-primary">Claimant Access</a>
            </div>
        </div>
    </header>

    <main class="mx-auto grid w-full max-w-[1600px] gap-6 p-4 sm:p-6 lg:grid-cols-[1.05fr_1fr] lg:p-8">
        <section class="rounded-2xl border border-bk-border bg-gradient-to-br from-[#033a7f] to-[#034ea2] p-6 text-white shadow-app sm:p-8">
            <div class="flex items-center gap-3">
                <img src="Images/logo.png" alt="BK" class="h-11 w-11 rounded-lg bg-white p-1.5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-white/85">Bank of Kigali</p>
                    <h1 class="font-display text-2xl font-bold leading-tight">UNIFIED DIGITAL CLAIMS SYSTEM</h1>
                </div>
            </div>

            <p class="mt-5 max-w-2xl text-sm leading-relaxed text-white/90 sm:text-base">
                Single secure entry for claimants and bank staff. OTP identity checks, guided document intake,
                and role-based processing remain fully active in this unified portal.
            </p>

            <div class="mt-6 rounded-xl border border-white/25 bg-white/10 p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-white/80">Live System Metrics</p>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div class="rounded-lg border border-white/25 bg-white/10 p-3">
                        <p class="text-[11px] uppercase tracking-[0.09em] text-white/80">Registered Claimants</p>
                        <p class="mt-1 text-2xl font-bold"><?php echo number_format($liveClaimants); ?></p>
                    </div>
                    <div class="rounded-lg border border-white/25 bg-white/10 p-3">
                        <p class="text-[11px] uppercase tracking-[0.09em] text-white/80">Claims Logged</p>
                        <p class="mt-1 text-2xl font-bold"><?php echo number_format($liveClaims); ?></p>
                    </div>
                    <div class="rounded-lg border border-white/25 bg-white/10 p-3">
                        <p class="text-[11px] uppercase tracking-[0.09em] text-white/80">Claims Closed</p>
                        <p class="mt-1 text-2xl font-bold"><?php echo number_format($liveClosedClaims); ?></p>
                    </div>
                    <div class="rounded-lg border border-white/25 bg-white/10 p-3">
                        <p class="text-[11px] uppercase tracking-[0.09em] text-white/80">Audit Events</p>
                        <p class="mt-1 text-2xl font-bold"><?php echo number_format($liveActivityEvents); ?></p>
                    </div>
                </div>
            </div>

            <div class="mt-5 rounded-xl border border-white/25 bg-white/10 p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-white/80">Projected Operational Impact</p>
                <p class="mt-1 text-[11px] uppercase tracking-[0.1em] text-white/75">Panel demonstration projections (not audited production KPIs)</p>
                <div class="mt-3 space-y-2 text-sm text-white/92">
                    <p><span class="font-semibold">Rework Reduction:</span> 30-50% lower from early OCR rejection of invalid submissions.</p>
                    <p><span class="font-semibold">Claimant Update Speed:</span> &lt; 1 business day after each decision stage.</p>
                    <p><span class="font-semibold">Stage Visibility:</span> 100% traceable intake/legal/finance trail in activity logs.</p>
                    <p><span class="font-semibold">Follow-up Load:</span> 25-40% lower due to clear rejection reasons.</p>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-bk-border bg-bk-surface p-6 shadow-app sm:p-8">
            <h2 class="font-display text-2xl font-bold text-bk-text">Access Portal</h2>
            <p class="mt-1 text-sm text-bk-muted">Choose audience and action. All existing authentication logic remains active.</p>

            <?php if ($notification['show']): ?>
                <?php render_alert($notification['message'], ['type' => $notification['type'], 'dismissible' => true, 'class' => 'mt-4']); ?>
            <?php endif; ?>

            <div class="mt-5 grid grid-cols-2 rounded-app border border-bk-border bg-bk-bg p-1">
                <button type="button" class="rounded-app px-3 py-2 text-sm font-semibold" :class="audience==='claimant' ? 'bg-bk-surface text-bk-text shadow-sm' : 'text-bk-muted'" @click="audience='claimant'; tab='login'">Claimant</button>
                <button type="button" class="rounded-app px-3 py-2 text-sm font-semibold" :class="audience==='staff' ? 'bg-bk-surface text-bk-text shadow-sm' : 'text-bk-muted'" @click="audience='staff'; tab='login'">Staff</button>
            </div>

            <div class="mt-3 grid grid-cols-2 rounded-app border border-bk-border bg-bk-bg p-1">
                <button type="button" class="rounded-app px-3 py-2 text-sm font-medium" :class="tab==='login' ? 'bg-bk-surface text-bk-text shadow-sm' : 'text-bk-muted'" @click="tab='login'">Sign In</button>
                <button type="button" class="rounded-app px-3 py-2 text-sm font-medium" :class="tab==='signup' ? 'bg-bk-surface text-bk-text shadow-sm' : 'text-bk-muted'" @click="tab='signup'">Create Account</button>
            </div>

            <form method="POST" x-show="audience==='claimant' && tab==='login'" x-cloak class="mt-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($accessClaimantLoginCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="ui-field">
                    <label for="claimant-login-email" class="ui-label">Claimant Email</label>
                    <input id="claimant-login-email" type="email" name="claimant_email" class="ui-input" required value="<?php echo $old('claimant_email'); ?>">
                </div>
                <div class="ui-field">
                    <label for="claimant-login-password" class="ui-label">Password</label>
                    <div class="relative">
                        <input id="claimant-login-password" :type="showClaimantLoginPassword ? 'text' : 'password'" name="claimant_password" class="ui-input pr-12" required>
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showClaimantLoginPassword=!showClaimantLoginPassword"><span x-text="showClaimantLoginPassword ? 'Hide' : 'Show'"></span></button>
                    </div>
                </div>
                <button type="submit" name="claimant_login" value="1" class="ui-btn ui-btn-lg ui-btn-primary w-full">Sign in as Claimant</button>
            </form>

            <form method="POST" x-show="audience==='claimant' && tab==='signup'" x-cloak class="mt-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($accessClaimantSignupCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="ui-field">
                        <label for="claimant-signup-fname" class="ui-label">First Name</label>
                        <input id="claimant-signup-fname" type="text" name="claimant_fname" class="ui-input" required value="<?php echo $old('claimant_fname'); ?>">
                    </div>
                    <div class="ui-field">
                        <label for="claimant-signup-lname" class="ui-label">Last Name</label>
                        <input id="claimant-signup-lname" type="text" name="claimant_lname" class="ui-input" required value="<?php echo $old('claimant_lname'); ?>">
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="ui-field">
                        <label for="claimant-signup-email" class="ui-label">Email</label>
                        <input id="claimant-signup-email" type="email" name="claimant_email" class="ui-input" required value="<?php echo $old('claimant_email'); ?>">
                    </div>
                    <div class="ui-field">
                        <label for="claimant-signup-phone" class="ui-label">Phone</label>
                        <input id="claimant-signup-phone" type="tel" name="claimant_phone" class="ui-input" required value="<?php echo $old('claimant_phone'); ?>">
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="ui-field">
                        <label for="claimant-signup-password" class="ui-label">Password</label>
                        <div class="relative">
                            <input id="claimant-signup-password" :type="showClaimantSignupPassword ? 'text' : 'password'" name="claimant_password" class="ui-input pr-12" required minlength="8">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showClaimantSignupPassword=!showClaimantSignupPassword"><span x-text="showClaimantSignupPassword ? 'Hide' : 'Show'"></span></button>
                        </div>
                    </div>
                    <div class="ui-field">
                        <label for="claimant-signup-confirm-password" class="ui-label">Confirm Password</label>
                        <div class="relative">
                            <input id="claimant-signup-confirm-password" :type="showClaimantSignupConfirm ? 'text' : 'password'" name="claimant_confirm_password" class="ui-input pr-12" required minlength="8">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showClaimantSignupConfirm=!showClaimantSignupConfirm"><span x-text="showClaimantSignupConfirm ? 'Hide' : 'Show'"></span></button>
                        </div>
                    </div>
                </div>
                <button type="submit" name="claimant_signup" value="1" class="ui-btn ui-btn-lg ui-btn-primary w-full">Create Claimant Account</button>
            </form>

            <form method="POST" x-show="audience==='staff' && tab==='login'" x-cloak class="mt-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($accessStaffLoginCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="ui-field">
                    <label for="staff-login-email" class="ui-label">Staff Email</label>
                    <input id="staff-login-email" type="email" name="staff_email" class="ui-input" required value="<?php echo $old('staff_email'); ?>">
                </div>
                <div class="ui-field">
                    <label for="staff-login-password" class="ui-label">Password</label>
                    <div class="relative">
                        <input id="staff-login-password" :type="showStaffLoginPassword ? 'text' : 'password'" name="staff_password" class="ui-input pr-12" required>
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showStaffLoginPassword=!showStaffLoginPassword"><span x-text="showStaffLoginPassword ? 'Hide' : 'Show'"></span></button>
                    </div>
                </div>
                <button type="submit" name="staff_login" value="1" class="ui-btn ui-btn-lg ui-btn-primary w-full">Sign in as Staff</button>
            </form>

            <form method="POST" x-show="audience==='staff' && tab==='signup'" x-cloak class="mt-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($accessStaffSignupCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="ui-field">
                    <label for="staff-role" class="ui-label">Staff Role</label>
                    <select id="staff-role" name="staff_role" class="ui-select" required>
                        <option value="" disabled <?php echo $old('staff_role') === '' ? 'selected' : ''; ?>>Select role</option>
                        <option value="legal" <?php echo $old('staff_role') === 'legal' ? 'selected' : ''; ?>>Legal Department</option>
                        <option value="finance" <?php echo $old('staff_role') === 'finance' ? 'selected' : ''; ?>>Finance Department</option>
                        <option value="admin" <?php echo $adminExists ? 'disabled' : ''; ?> <?php echo $old('staff_role') === 'admin' ? 'selected' : ''; ?>>Admin <?php echo $adminExists ? '(Assigned)' : ''; ?></option>
                    </select>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="ui-field">
                        <label for="staff-fname" class="ui-label">First Name</label>
                        <input id="staff-fname" type="text" name="staff_fname" class="ui-input" required value="<?php echo $old('staff_fname'); ?>">
                    </div>
                    <div class="ui-field">
                        <label for="staff-lname" class="ui-label">Last Name</label>
                        <input id="staff-lname" type="text" name="staff_lname" class="ui-input" required value="<?php echo $old('staff_lname'); ?>">
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="ui-field">
                        <label for="staff-signup-email" class="ui-label">Email</label>
                        <input id="staff-signup-email" type="email" name="staff_email" class="ui-input" required value="<?php echo $old('staff_email'); ?>">
                    </div>
                    <div class="ui-field">
                        <label for="staff-phone" class="ui-label">Phone</label>
                        <input id="staff-phone" type="tel" name="staff_phone" class="ui-input" required value="<?php echo $old('staff_phone'); ?>">
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="ui-field">
                        <label for="staff-signup-password" class="ui-label">Password</label>
                        <div class="relative">
                            <input id="staff-signup-password" :type="showStaffSignupPassword ? 'text' : 'password'" name="staff_password" class="ui-input pr-12" required minlength="8">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showStaffSignupPassword=!showStaffSignupPassword"><span x-text="showStaffSignupPassword ? 'Hide' : 'Show'"></span></button>
                        </div>
                    </div>
                    <div class="ui-field">
                        <label for="staff-signup-confirm-password" class="ui-label">Confirm Password</label>
                        <div class="relative">
                            <input id="staff-signup-confirm-password" :type="showStaffSignupConfirm ? 'text' : 'password'" name="staff_confirm_password" class="ui-input pr-12" required minlength="8">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showStaffSignupConfirm=!showStaffSignupConfirm"><span x-text="showStaffSignupConfirm ? 'Hide' : 'Show'"></span></button>
                        </div>
                    </div>
                </div>
                <button type="submit" name="staff_signup" value="1" class="ui-btn ui-btn-lg ui-btn-primary w-full">Create Staff Account</button>
            </form>
        </section>
    </main>
</body>
</html>
