<?php
// SECTION: Load database connection and start user session.
include 'connect.php';
require_once __DIR__ . '/security.php';
secure_session_start();
require_once __DIR__ . '/components/workflow.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// SECTION: Keep old links working by sending users to the new entry points.
$legacyEntry = $_GET['entry'] ?? '';
if ($legacyEntry === 'claimant-new') {
    header('Location: claimant-access.php?mode=signup');
    exit();
}
if ($legacyEntry === 'claimant-login') {
    header('Location: claimant-access.php?mode=login');
    exit();
}
if ($legacyEntry === 'staff') {
    header('Location: login.php');
    exit();
}

$adminExists = false;
$adminStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE role = ? AND acceptance = ? LIMIT 1");
if ($adminStmt) {
    $adminRole = 'admin';
    $accepted = 'Yes';
    mysqli_stmt_bind_param($adminStmt, 'ss', $adminRole, $accepted);
    if (mysqli_stmt_execute($adminStmt)) {
        $adminResult = mysqli_stmt_get_result($adminStmt);
        $adminExists = $adminResult && mysqli_num_rows($adminResult) > 0;
    }
    mysqli_stmt_close($adminStmt);
}

$notification = [
    'type' => '',
    'message' => '',
    'show' => false,
];
$staffLoginCsrf = udcs_csrf_get('staff_login_form');
$staffSignupCsrf = udcs_csrf_get('staff_signup_form');

// SECTION: Handle both staff sign-up and staff sign-in requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['signup'])) {
        if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'staff_signup_form')) {
            $notification = [
                'type' => 'danger',
                'message' => 'Your session expired. Please try again.',
                'show' => true,
            ];
        }

        // SECTION: Read and validate staff registration fields.
        $first_name = mysqli_real_escape_string($conn, trim($_POST['fname'] ?? ''));
        $last_name = mysqli_real_escape_string($conn, trim($_POST['lname'] ?? ''));
        $full_name = trim($first_name . ' ' . $last_name);
        $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
        $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
        $role = mysqli_real_escape_string($conn, trim($_POST['role'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$notification['show'] && !in_array($role, ['legal', 'finance', 'admin'], true)) {
            $notification = [
                'type' => 'danger',
                'message' => 'Please select a valid staff account type.',
                'show' => true,
            ];
        }

        if (!$notification['show'] && $role === 'admin' && $adminExists) {
            $notification = [
                'type' => 'warning',
                'message' => 'Admin account is already assigned.',
                'show' => true,
            ];
        }

        if (!$notification['show'] && $password !== $confirm) {
            $notification = [
                'type' => 'danger',
                'message' => 'Passwords do not match.',
                'show' => true,
            ];
        }

        if (!$notification['show'] && !udcs_password_meets_policy($password)) {
            $notification = [
                'type' => 'danger',
                'message' => 'Password must be at least 8 characters long and include uppercase, lowercase, and a number.',
                'show' => true,
            ];
        }

        if (!$notification['show']) {
            $checkStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
            $emailExists = false;
            if ($checkStmt) {
                mysqli_stmt_bind_param($checkStmt, 's', $email);
                mysqli_stmt_execute($checkStmt);
                $check = mysqli_stmt_get_result($checkStmt);
                $emailExists = $check && mysqli_num_rows($check) > 0;
                mysqli_stmt_close($checkStmt);
            }
            if ($emailExists) {
                $notification = [
                    'type' => 'danger',
                    'message' => 'This email is already in use.',
                    'show' => true,
                ];
            }
        }

        if (!$notification['show']) {
            // SECTION: Save the new user, create OTP, and send verification email.
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $insertStmt = mysqli_prepare(
                $conn,
                "INSERT INTO users (full_name, email, phone, role, password, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );

            if ($insertStmt) {
                mysqli_stmt_bind_param($insertStmt, 'sssss', $full_name, $email, $phone, $role, $hashed);
            }

            if ($insertStmt && mysqli_stmt_execute($insertStmt)) {
                mysqli_stmt_close($insertStmt);
                $newUserId = (int) mysqli_insert_id($conn);
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                $otpUpdateStmt = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET email_otp = ?,
                         otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                         email_verified = 0
                     WHERE email = ?"
                );
                if ($otpUpdateStmt) {
                    mysqli_stmt_bind_param($otpUpdateStmt, 'ss', $otp, $email);
                    mysqli_stmt_execute($otpUpdateStmt);
                    mysqli_stmt_close($otpUpdateStmt);
                }

                include 'sendOtpEmail.php';
                $otpError = '';
                $otpSent = sendOtpEmail($email, $full_name, $otp, $otpError);
                if ($otpSent) {
                    $_SESSION['otp_email'] = $email;
                    $_SESSION['otp_return_url'] = 'login.php';
                    $notification = [
                        'type' => 'success',
                        'message' => 'Staff account request created. OTP sent to your email.',
                        'show' => true,
                    ];
                } else {
                    $notification = [
                        'type' => 'warning',
                        'message' => 'Staff account created, but OTP email could not be sent. Configure email sender and sign in again to resend OTP.',
                        'show' => true,
                    ];
                }

                $notif_msg = 'Your staff account request was submitted successfully.';
                udcs_db_insert_notification($conn, $email, $email, $notif_msg);

                bk_activity_log($conn, [
                    'actor_id' => $newUserId,
                    'actor_role' => $role,
                    'action_key' => 'staff_account_created',
                    'action_label' => 'Staff Account Created',
                    'details' => 'New staff account was created and is pending OTP verification.',
                    'meta' => [
                        'user_id' => $newUserId,
                        'role' => $role,
                        'email' => $email,
                    ],
                ]);

                if ($otpSent) {
                    header('refresh:2;url=verify-otp.php');
                } else {
                    bk_activity_log($conn, [
                        'actor_id' => $newUserId,
                        'actor_role' => $role,
                        'action_key' => 'otp_send_failed',
                        'action_label' => 'OTP Send Failed',
                        'details' => 'Staff account was created but OTP email delivery failed.',
                        'meta' => ['error' => $otpError],
                    ]);
                }
            } else {
                if ($insertStmt) {
                    mysqli_stmt_close($insertStmt);
                }
                $notification = [
                    'type' => 'danger',
                    'message' => 'Sign-up failed. Please try again.',
                    'show' => true,
                ];
            }
        }
    }

    if (isset($_POST['login'])) {
        if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'staff_login_form')) {
            $notification = [
                'type' => 'danger',
                'message' => 'Your session expired. Please try again.',
                'show' => true,
            ];
        }

        // SECTION: Validate staff login and route each role to its dashboard.
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $throttleScope = 'staff_login';
        $retryAfterSeconds = 0;

        if (!$notification['show'] && udcs_auth_throttle_is_limited($throttleScope, $email, $retryAfterSeconds)) {
            $notification = [
                'type' => 'danger',
                'message' => udcs_auth_throttle_message($retryAfterSeconds),
                'show' => true,
            ];
        }

        $query = null;
        if (!$notification['show']) {
            $loginStmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? LIMIT 1");
            if ($loginStmt) {
                mysqli_stmt_bind_param($loginStmt, 's', $email);
                mysqli_stmt_execute($loginStmt);
                $query = mysqli_stmt_get_result($loginStmt);
                mysqli_stmt_close($loginStmt);
            }
        }

        if (!$notification['show'] && $query && mysqli_num_rows($query) === 1) {
            $user = mysqli_fetch_assoc($query);

            if ($user['role'] === 'claimant') {
                bk_activity_log($conn, [
                    'actor_id' => (int) ($user['id'] ?? 0),
                    'actor_role' => 'claimant',
                    'action_key' => 'login_denied_wrong_portal',
                    'action_label' => 'Login Denied: Wrong Portal',
                    'details' => 'Claimant attempted to sign in through the staff access page.',
                ]);
                $notification = [
                    'type' => 'warning',
                    'message' => 'Claimants should use the claimant access portal.',
                    'show' => true,
                ];
            } elseif (!password_verify($password, $user['password'])) {
                $failureState = udcs_auth_throttle_record_failure($throttleScope, $email);
                bk_activity_log($conn, [
                    'actor_id' => (int) ($user['id'] ?? 0),
                    'actor_role' => (string) ($user['role'] ?? 'system'),
                    'action_key' => 'login_failed_invalid_password',
                    'action_label' => 'Login Failed',
                    'details' => 'Sign-in failed due to invalid password.',
                ]);
                $notification = [
                    'type' => 'danger',
                    'message' => $failureState['locked']
                        ? udcs_auth_throttle_message((int) $failureState['retry_after'])
                        : 'Invalid password.',
                    'show' => true,
                ];
            } elseif (in_array($user['role'], ['admin', 'legal', 'finance'], true) && $user['acceptance'] !== 'Yes') {
                udcs_auth_throttle_clear($throttleScope, $email);
                bk_activity_log($conn, [
                    'actor_id' => (int) ($user['id'] ?? 0),
                    'actor_role' => (string) ($user['role'] ?? 'system'),
                    'action_key' => 'login_blocked_unapproved_staff',
                    'action_label' => 'Login Blocked: Access Not Approved',
                    'details' => 'Staff login blocked because admin approval is still pending.',
                ]);
                $notification = [
                    'type' => 'warning',
                    'message' => 'Your account has no access yet. Please wait for admin acceptance.',
                    'show' => true,
                ];
            } elseif ((int) $user['email_verified'] === 0) {
                udcs_auth_throttle_clear($throttleScope, $email);
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                $otpLoginStmt = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET email_otp = ?,
                         otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                     WHERE id = ?"
                );
                if ($otpLoginStmt) {
                    $userId = (int) ($user['id'] ?? 0);
                    mysqli_stmt_bind_param($otpLoginStmt, 'si', $otp, $userId);
                    mysqli_stmt_execute($otpLoginStmt);
                    mysqli_stmt_close($otpLoginStmt);
                }

                include 'sendOtpEmail.php';
                $otpError = '';
                $otpSent = sendOtpEmail($user['email'], $user['full_name'], $otp, $otpError);

                if ($otpSent) {
                    $_SESSION['otp_email'] = $user['email'];
                    $_SESSION['otp_return_url'] = 'login.php';

                    bk_activity_log($conn, [
                        'actor_id' => (int) ($user['id'] ?? 0),
                        'actor_role' => (string) ($user['role'] ?? 'system'),
                        'action_key' => 'otp_challenge_sent',
                        'action_label' => 'OTP Challenge Sent',
                        'details' => 'A login OTP challenge was issued for this account.',
                    ]);

                    $notification = [
                        'type' => 'info',
                        'message' => 'OTP verification required. Check your email.',
                        'show' => true,
                    ];

                    header('refresh:2;url=verify-otp.php');
                    exit();
                }

                bk_activity_log($conn, [
                    'actor_id' => (int) ($user['id'] ?? 0),
                    'actor_role' => (string) ($user['role'] ?? 'system'),
                    'action_key' => 'otp_send_failed',
                    'action_label' => 'OTP Send Failed',
                    'details' => 'A login OTP challenge was generated but email delivery failed.',
                    'meta' => ['error' => $otpError],
                ]);
                $notification = [
                    'type' => 'danger',
                    'message' => 'Could not send OTP email right now. Configure email sender and try again.',
                    'show' => true,
                ];
            } else {
                udcs_auth_throttle_clear($throttleScope, $email);
                udcs_auth_log_in_user($user);

                bk_activity_log($conn, [
                    'actor_id' => (int) ($user['id'] ?? 0),
                    'actor_role' => (string) ($user['role'] ?? 'system'),
                    'action_key' => 'login_success',
                    'action_label' => 'Login Successful',
                    'details' => 'User signed in successfully through staff access.',
                ]);

                $notification = [
                    'type' => 'success',
                    'message' => 'Login successful. Redirecting...',
                    'show' => true,
                ];

                if ($user['role'] === 'admin') {
                    header('refresh:1;url=Admin/dashboard.php');
                } elseif ($user['role'] === 'legal') {
                    header('refresh:1;url=Legal/dashboard.php');
                } else {
                    header('refresh:1;url=Finance/dashboard.php');
                }
            }
        } elseif (!$notification['show']) {
            $failureState = udcs_auth_throttle_record_failure($throttleScope, $email);
            bk_activity_log($conn, [
                'actor_id' => 0,
                'actor_role' => 'system',
                'action_key' => 'login_failed_account_not_found',
                'action_label' => 'Login Failed',
                'details' => 'Sign-in failed because no account matched the provided email.',
                'meta' => [
                    'email' => $email,
                    'portal' => 'staff',
                ],
            ]);
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

// SECTION: Decide which tab should be opened first when the page reloads.
$defaultTab = isset($_POST['signup']) ? 'signup' : 'login';

require_once __DIR__ . '/components/head.php';
require_once __DIR__ . '/components/alert.php';
?>
<!DOCTYPE html>
<html lang="en" x-data="{
    tab: '<?php echo $defaultTab; ?>',
    showLoginPassword: false,
    showSignupPassword: false,
    showSignupConfirm: false,
    signupPasswordValue: '',
    signupConfirmValue: '',
    formError: ''
}">
<head>
    <?php render_head('UNIFIED DIGITAL CLAIMS SYSTEM | Staff Access'); ?>
</head>
<body>
    <main class="grid min-h-screen lg:grid-cols-2">
        <!-- SECTION: Left brand panel (context for staff users). -->
        <section class="relative hidden border-r border-bk-border bg-bk-primary text-white lg:flex lg:items-center">
            <div class="w-full max-w-md p-10">
                <a href="index.php" class="inline-flex items-center gap-2 text-sm text-white/85 hover:text-white">&larr; Back to home</a>
                <p class="mt-8 inline-flex rounded-full border border-white/30 bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.12em]">Bank Staff Portal</p>
                <h1 class="mt-4 font-display text-4xl font-bold leading-tight">Staff Access</h1>
                <p class="mt-4 text-base text-white/85">
                    Sign in to process claims in legal, finance, or admin workflows.
                </p>
                <ul class="mt-6 space-y-2 text-sm text-white/80">
                    <li>Approved staff accounts only.</li>
                    <li>OTP verification required.</li>
                </ul>
            </div>
        </section>

        <!-- SECTION: Right authentication panel (tabs + forms + alerts). -->
        <section class="flex items-center justify-center px-4 py-8 sm:px-6 lg:px-10">
            <div class="w-full max-w-xl rounded-2xl border border-bk-border bg-bk-surface/85 p-6 shadow-app sm:p-8">
                <div class="mb-6 space-y-2">
                    <div class="flex items-center justify-between">
                        <a href="index.php" class="text-sm font-medium text-bk-muted hover:text-bk-text lg:hidden">&larr; Home</a>
                        <h2 class="font-display text-2xl font-semibold text-bk-text">Staff Access</h2>
                        <span class="hidden h-8 w-8 lg:block" aria-hidden="true"></span>
                    </div>
                    <p class="text-sm text-bk-muted">For legal, finance, and admin users with approved Bank of Kigali access.</p>
                </div>

                <?php if ($notification['show']): ?>
                    <?php render_alert($notification['message'], ['type' => $notification['type'], 'dismissible' => true, 'class' => 'mb-6']); ?>
                <?php endif; ?>

                <div class="mb-6 grid grid-cols-2 rounded-app border border-bk-border bg-bk-bg p-1" role="tablist" aria-label="Staff authentication tabs">
                    <button type="button" class="rounded-app px-3 py-2 text-sm font-medium" :class="tab==='login' ? 'bg-bk-surface text-bk-text shadow-sm' : 'text-bk-muted'" @click="tab='login'" role="tab" :aria-selected="(tab==='login').toString()">Sign In</button>
                    <button type="button" class="rounded-app px-3 py-2 text-sm font-medium" :class="tab==='signup' ? 'bg-bk-surface text-bk-text shadow-sm' : 'text-bk-muted'" @click="tab='signup'" role="tab" :aria-selected="(tab==='signup').toString()">Create Staff Account</button>
                </div>

                <form method="POST" action="" x-show="tab==='login'" x-cloak class="space-y-4" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($staffLoginCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="ui-field">
                        <label for="login-email" class="ui-label">Email address</label>
                        <input id="login-email" name="email" type="email" class="ui-input" required autocomplete="email" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="ui-field">
                        <label for="login-password" class="ui-label">Password</label>
                        <div class="relative">
                            <input id="login-password" name="password" :type="showLoginPassword ? 'text' : 'password'" class="ui-input pr-12" required autocomplete="current-password">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showLoginPassword = !showLoginPassword" :aria-label="showLoginPassword ? 'Hide password' : 'Show password'">
                                <span x-text="showLoginPassword ? 'Hide' : 'Show'"></span>
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label class="ui-checkbox-wrapper" for="remember-me">
                            <input id="remember-me" type="checkbox" name="remember" class="ui-checkbox">
                            <span class="ui-checkbox-label">Remember me</span>
                        </label>
                        <a href="#" class="text-sm font-medium text-bk-primary">Forgot password?</a>
                    </div>

                    <button type="submit" name="login" value="1" class="ui-btn ui-btn-lg ui-btn-primary w-full">Sign in as staff</button>
                    <p class="text-center text-sm text-bk-muted">Are you a claimant? <a href="claimant-access.php" class="font-semibold text-bk-primary hover:text-bk-text">Use claimant access</a>.</p>
                </form>

                <form method="POST" action="" x-show="tab==='signup'" x-cloak class="space-y-4" autocomplete="on" @submit="if (signupPasswordValue !== signupConfirmValue) { formError='Passwords do not match.'; $event.preventDefault(); } else { formError=''; }">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($staffSignupCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="ui-field rounded-app border border-bk-primary/35 bg-bk-primary/5 p-3 shadow-[0_0_0_1px_rgba(3,78,162,0.12)]">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <label for="signup-role" class="ui-label !mb-0 !font-semibold !text-bk-primary">Staff account type</label>
                            <span class="rounded-full bg-bk-primary/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-bk-primary">Required</span>
                        </div>
                        <select id="signup-role" name="role" class="ui-select !border-bk-primary/40 !bg-bk-surface" required>
                            <option value="" disabled <?php echo empty($_POST['role']) ? 'selected' : ''; ?>>Select account type</option>
                            <option value="legal" <?php echo (($_POST['role'] ?? '') === 'legal') ? 'selected' : ''; ?>>Legal Department</option>
                            <option value="finance" <?php echo (($_POST['role'] ?? '') === 'finance') ? 'selected' : ''; ?>>Finance Department</option>
                            <option value="admin" <?php echo $adminExists ? 'disabled' : ''; ?> <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin <?php echo $adminExists ? '(Assigned)' : ''; ?></option>
                        </select>
                        <p class="mt-2 text-xs font-medium text-bk-muted">Choose the role first. Access permissions are assigned from this selection.</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="ui-field">
                            <label for="signup-fname" class="ui-label">First name</label>
                            <input id="signup-fname" name="fname" type="text" class="ui-input" required autocomplete="given-name" value="<?php echo htmlspecialchars($_POST['fname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="ui-field">
                            <label for="signup-lname" class="ui-label">Last name</label>
                            <input id="signup-lname" name="lname" type="text" class="ui-input" required autocomplete="family-name" value="<?php echo htmlspecialchars($_POST['lname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="ui-field">
                            <label for="signup-email" class="ui-label">Email</label>
                            <input id="signup-email" name="email" type="email" class="ui-input" required autocomplete="email" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="ui-field">
                            <label for="signup-phone" class="ui-label">Phone</label>
                            <input id="signup-phone" name="phone" type="tel" class="ui-input" required autocomplete="tel" value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="ui-field">
                            <label for="signup-password" class="ui-label">Password</label>
                            <div class="relative">
                                <input id="signup-password" name="password" :type="showSignupPassword ? 'text' : 'password'" x-model="signupPasswordValue" class="ui-input pr-12" required autocomplete="new-password" minlength="8">
                                <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showSignupPassword = !showSignupPassword" :aria-label="showSignupPassword ? 'Hide password' : 'Show password'">
                                    <span x-text="showSignupPassword ? 'Hide' : 'Show'"></span>
                                </button>
                            </div>
                            <p class="ui-help">Use at least 8 characters with uppercase, lowercase, and numbers.</p>
                        </div>

                        <div class="ui-field">
                            <label for="signup-confirm-password" class="ui-label">Confirm password</label>
                            <div class="relative">
                                <input id="signup-confirm-password" name="confirm_password" :type="showSignupConfirm ? 'text' : 'password'" x-model="signupConfirmValue" class="ui-input pr-12" required autocomplete="new-password" minlength="8">
                                <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs text-bk-muted hover:text-bk-text" @click="showSignupConfirm = !showSignupConfirm" :aria-label="showSignupConfirm ? 'Hide password' : 'Show password'">
                                    <span x-text="showSignupConfirm ? 'Hide' : 'Show'"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <p x-show="formError" x-text="formError" class="ui-error" role="alert"></p>

                    <label class="ui-checkbox-wrapper" for="terms-agreement">
                        <input id="terms-agreement" type="checkbox" required class="ui-checkbox">
                        <span class="ui-checkbox-label">I agree to the Terms and Conditions.</span>
                    </label>

                    <button type="submit" name="signup" value="1" class="ui-btn ui-btn-lg ui-btn-primary w-full">Create staff account</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
