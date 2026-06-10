<?php
// SECTION: Load connection and current OTP session context.
include 'connect.php';
require_once __DIR__ . '/security.php';
secure_session_start();
require_once __DIR__ . '/components/workflow.php';

// SECTION: Block direct access if no OTP flow is active.
if (!isset($_SESSION['otp_email'])) {
    header('Location: index.php');
    exit();
}

$email = $_SESSION['otp_email'];
$allowedReturnUrls = ['login.php', 'claimant-access.php'];
$returnUrl = $_SESSION['otp_return_url'] ?? 'login.php';
if (!in_array($returnUrl, $allowedReturnUrls, true)) {
    $returnUrl = 'login.php';
}
$returnLabel = $returnUrl === 'claimant-access.php' ? 'Back to claimant access' : 'Back to staff access';
$error = '';
$success = '';
$otpGuardBucketKey = hash('sha256', strtolower(trim((string) $email)));
if (!isset($_SESSION['otp_verify_guard']) || !is_array($_SESSION['otp_verify_guard'])) {
    $_SESSION['otp_verify_guard'] = [];
}
if (!isset($_SESSION['otp_verify_guard'][$otpGuardBucketKey]) || !is_array($_SESSION['otp_verify_guard'][$otpGuardBucketKey])) {
    $_SESSION['otp_verify_guard'][$otpGuardBucketKey] = [
        'failed_attempts' => 0,
        'blocked_until' => 0,
    ];
}

// SECTION: Verify OTP submission and activate the user account email.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otpGuard = (array) ($_SESSION['otp_verify_guard'][$otpGuardBucketKey] ?? []);
    $blockedUntil = (int) ($otpGuard['blocked_until'] ?? 0);
    $now = time();

    if ($blockedUntil > $now) {
        $secondsRemaining = max(1, $blockedUntil - $now);
        $minutes = (int) ceil($secondsRemaining / 60);
        $error = 'Too many failed OTP attempts. Please wait about ' . $minutes . ' minute(s) and try again.';
    } else {
        $verificationFailed = false;
        $otp = preg_replace('/\D/', '', $_POST['otp'] ?? '');

        if (strlen($otp) !== 6) {
            $verificationFailed = true;
            bk_activity_log($conn, [
                'actor_id' => 0,
                'actor_role' => 'system',
                'action_key' => 'otp_verification_failed_invalid_format',
                'action_label' => 'OTP Verification Failed',
                'details' => 'OTP verification failed because the submitted code format was invalid.',
                'meta' => [
                    'email' => $email,
                ],
            ]);
            $error = 'Please enter a valid 6-digit verification code.';
        } else {
            $otpSafe = $otp;
            $check = null;
            $checkStmt = mysqli_prepare(
                $conn,
                "SELECT
                    id,
                    role,
                    email_otp,
                    CASE
                        WHEN otp_expires_at IS NOT NULL AND otp_expires_at >= NOW() THEN 1
                        ELSE 0
                    END AS otp_is_active
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );
            if ($checkStmt) {
                mysqli_stmt_bind_param($checkStmt, 's', $email);
                mysqli_stmt_execute($checkStmt);
                $check = mysqli_stmt_get_result($checkStmt);
                mysqli_stmt_close($checkStmt);
            }

            if (!$check || mysqli_num_rows($check) !== 1) {
                $verificationFailed = true;
                bk_activity_log($conn, [
                    'actor_id' => 0,
                    'actor_role' => 'system',
                    'action_key' => 'otp_verification_failed_account_session_missing',
                    'action_label' => 'OTP Verification Failed',
                    'details' => 'OTP verification failed because the account session could not be found.',
                    'meta' => [
                        'email' => $email,
                    ],
                ]);
                $error = 'Account session not found. Please sign in again.';
            } else {
                $userOtpRow = mysqli_fetch_assoc($check);
                $storedOtp = (string) ($userOtpRow['email_otp'] ?? '');
                $otpIsActive = (int) ($userOtpRow['otp_is_active'] ?? 0) === 1;
                $userRole = strtolower(trim((string) ($userOtpRow['role'] ?? 'system')));
                $userId = (int) ($userOtpRow['id'] ?? 0);

                if ($storedOtp === '') {
                    $verificationFailed = true;
                    bk_activity_log($conn, [
                        'actor_id' => $userId,
                        'actor_role' => $userRole !== '' ? $userRole : 'system',
                        'action_key' => 'otp_verification_failed_no_active_code',
                        'action_label' => 'OTP Verification Failed',
                        'details' => 'OTP verification failed because no active OTP code was available.',
                    ]);
                    $error = 'No active verification code was found. Please request a new OTP.';
                } elseif (!hash_equals($storedOtp, $otpSafe)) {
                    $verificationFailed = true;
                    bk_activity_log($conn, [
                        'actor_id' => $userId,
                        'actor_role' => $userRole !== '' ? $userRole : 'system',
                        'action_key' => 'otp_verification_failed_incorrect_code',
                        'action_label' => 'OTP Verification Failed',
                        'details' => 'OTP verification failed because the submitted code was incorrect.',
                    ]);
                    $error = 'The verification code you entered is incorrect.';
                } elseif (!$otpIsActive) {
                    $verificationFailed = true;
                    bk_activity_log($conn, [
                        'actor_id' => $userId,
                        'actor_role' => $userRole !== '' ? $userRole : 'system',
                        'action_key' => 'otp_verification_failed_expired_code',
                        'action_label' => 'OTP Verification Failed',
                        'details' => 'OTP verification failed because the code expired.',
                    ]);
                    $error = 'This verification code has expired. Please resend and try again.';
                } else {
                    $update = false;
                    $updateStmt = mysqli_prepare(
                        $conn,
                        "UPDATE users
                         SET email_verified = 1,
                             email_otp = NULL,
                             otp_expires_at = NULL
                         WHERE id = ?
                         LIMIT 1"
                    );
                    if ($updateStmt) {
                        mysqli_stmt_bind_param($updateStmt, 'i', $userId);
                        $update = mysqli_stmt_execute($updateStmt);
                        mysqli_stmt_close($updateStmt);
                    }

                    if ($update) {
                        bk_activity_log($conn, [
                            'actor_id' => $userId,
                            'actor_role' => $userRole !== '' ? $userRole : 'system',
                            'action_key' => 'otp_verification_success',
                            'action_label' => 'OTP Verified',
                            'details' => 'Email OTP verification completed successfully.',
                        ]);
                        unset($_SESSION['otp_verify_guard'][$otpGuardBucketKey]);
                        unset($_SESSION['otp_email']);
                        unset($_SESSION['otp_return_url']);
                        $success = 'OTP verified successfully. Redirecting...';
                        header('refresh:1;url=' . $returnUrl);
                    } else {
                        $verificationFailed = true;
                        bk_activity_log($conn, [
                            'actor_id' => $userId,
                            'actor_role' => $userRole !== '' ? $userRole : 'system',
                            'action_key' => 'otp_verification_failed_update_error',
                            'action_label' => 'OTP Verification Failed',
                            'details' => 'OTP verification check passed, but the account could not be updated.',
                        ]);
                        $error = 'We could not verify this code right now. Please try again.';
                    }
                }
            }
        }

        if ($verificationFailed) {
            $attempts = (int) (($otpGuard['failed_attempts'] ?? 0)) + 1;
            $otpGuard['failed_attempts'] = $attempts;
            if ($attempts >= 5) {
                $otpGuard['failed_attempts'] = 0;
                $otpGuard['blocked_until'] = time() + 600;
                $error = 'Too many failed OTP attempts. Please wait 10 minutes and try again.';
                bk_activity_log($conn, [
                    'actor_id' => 0,
                    'actor_role' => 'system',
                    'action_key' => 'otp_verification_temporarily_blocked',
                    'action_label' => 'OTP Verification Temporarily Blocked',
                    'details' => 'OTP verification was temporarily blocked after repeated failed attempts.',
                    'meta' => [
                        'email' => $email,
                        'block_minutes' => 10,
                    ],
                ]);
            }
            $_SESSION['otp_verify_guard'][$otpGuardBucketKey] = $otpGuard;
        }
    }
}

require_once __DIR__ . '/components/head.php';
require_once __DIR__ . '/components/alert.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('OTP Verification | UNIFIED DIGITAL CLAIMS SYSTEM'); ?>
</head>
<body>
    <main class="grid min-h-screen lg:grid-cols-2">
        <!-- SECTION: Left explanation panel for OTP process. -->
        <section class="relative hidden overflow-hidden border-r border-bk-border bg-bk-primary text-white lg:flex lg:flex-col lg:justify-between">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.24),transparent_48%)]"></div>
            <div class="relative p-10">
                <a href="index.php" class="inline-flex items-center gap-2 text-sm text-white/85 hover:text-white">&larr; Back to home</a>
                <h1 class="mt-8 font-display text-5xl font-bold leading-tight">Email Verification</h1>
                <p class="mt-4 max-w-md text-base text-white/85">Secure your UNIFIED DIGITAL CLAIMS SYSTEM account by confirming the one-time code sent to your email.</p>
            </div>
            <div class="relative grid grid-cols-2 gap-4 p-10">
                <article class="rounded-2xl border border-white/25 bg-white/10 p-4">
                    <p class="text-xs uppercase tracking-wide text-white/70">Step 1</p>
                    <p class="mt-2 text-xl font-bold">Check your inbox</p>
                </article>
                <article class="rounded-2xl border border-white/25 bg-white/10 p-4">
                    <p class="text-xs uppercase tracking-wide text-white/70">Step 2</p>
                    <p class="mt-2 text-xl font-bold">Enter 6-digit OTP</p>
                </article>
                <article class="rounded-2xl border border-white/25 bg-white/10 p-4">
                    <p class="text-xs uppercase tracking-wide text-white/70">Step 3</p>
                    <p class="mt-2 text-xl font-bold">Access your workspace</p>
                </article>
                <article class="rounded-2xl border border-white/25 bg-white/10 p-4">
                    <p class="text-xs uppercase tracking-wide text-white/70">Security</p>
                    <p class="mt-2 text-xl font-bold">Time-limited verification</p>
                </article>
            </div>
        </section>

        <!-- SECTION: Right panel where user enters and resends OTP code. -->
        <section class="flex items-center justify-center px-4 py-8 sm:px-6 lg:px-10">
            <div class="w-full max-w-xl rounded-2xl border border-bk-border bg-bk-surface/85 p-6 shadow-app sm:p-8">
                <div class="mb-6 flex items-center justify-between">
                    <a href="index.php" class="text-sm font-medium text-bk-muted hover:text-bk-text lg:hidden">&larr; Home</a>
                    <h2 class="font-display text-2xl font-semibold text-bk-text">OTP Verification</h2>
                    <span class="hidden h-8 w-8 lg:block" aria-hidden="true"></span>
                </div>

                <?php if ($error !== ''): ?>
                    <?php render_alert($error, ['type' => 'danger', 'dismissible' => true, 'class' => 'mb-4']); ?>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <?php render_alert($success, ['type' => 'success', 'dismissible' => false, 'class' => 'mb-4']); ?>
                <?php endif; ?>

                <p class="mb-5 text-sm text-bk-muted">
                    Enter the verification code sent to
                    <span class="font-semibold text-bk-text"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></span>.
                </p>

                <div id="clientNotice" aria-live="polite" class="mb-4"></div>

                <form method="POST" action="" id="otp-form" class="space-y-5" autocomplete="one-time-code">
                    <div class="ui-field">
                        <label class="ui-label" for="otpDigit1">6-digit OTP code</label>
                        <div class="grid grid-cols-6 gap-2 sm:gap-3" role="group" aria-label="Enter six digit code">
                            <input id="otpDigit1" type="text" maxlength="1" inputmode="numeric" class="ui-input otp-digit text-center text-lg font-semibold" autofocus>
                            <input id="otpDigit2" type="text" maxlength="1" inputmode="numeric" class="ui-input otp-digit text-center text-lg font-semibold">
                            <input id="otpDigit3" type="text" maxlength="1" inputmode="numeric" class="ui-input otp-digit text-center text-lg font-semibold">
                            <input id="otpDigit4" type="text" maxlength="1" inputmode="numeric" class="ui-input otp-digit text-center text-lg font-semibold">
                            <input id="otpDigit5" type="text" maxlength="1" inputmode="numeric" class="ui-input otp-digit text-center text-lg font-semibold">
                            <input id="otpDigit6" type="text" maxlength="1" inputmode="numeric" class="ui-input otp-digit text-center text-lg font-semibold">
                        </div>
                        <p class="mt-2 text-xs font-medium text-bk-muted">Verification starts automatically once all 6 digits are entered.</p>
                        <p id="clientError" class="ui-error hidden" role="alert"></p>
                        <input type="hidden" name="otp" id="full-otp">
                    </div>

                    <button type="submit" id="verifyBtn" class="ui-btn ui-btn-lg ui-btn-primary w-full">Verify Code</button>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <button id="resendBtn" type="button" class="ui-btn ui-btn-sm ui-btn-secondary">Resend OTP</button>
                        <span id="timerLabel" class="text-sm font-medium text-bk-muted">Resend available in <span id="timerValue">02:00</span></span>
                    </div>

                    <a href="<?php echo htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex text-sm font-medium text-bk-primary">&larr; <?php echo htmlspecialchars($returnLabel, ENT_QUOTES, 'UTF-8'); ?></a>
                </form>
            </div>
        </section>
    </main>

    <script>
        (function () {
            var digits = Array.from(document.querySelectorAll('.otp-digit'));
            var fullOtpInput = document.getElementById('full-otp');
            var otpForm = document.getElementById('otp-form');
            var resendBtn = document.getElementById('resendBtn');
            var verifyBtn = document.getElementById('verifyBtn');
            var timerLabel = document.getElementById('timerLabel');
            var timerValue = document.getElementById('timerValue');
            var clientError = document.getElementById('clientError');
            var clientNotice = document.getElementById('clientNotice');
            var timer = 120;
            var timerInterval = null;
            var autoSubmitTimer = null;
            var isSubmitting = false;

            function updateHiddenOtp() {
                fullOtpInput.value = digits.map(function (input) { return input.value; }).join('');
            }

            function setSubmittingState() {
                isSubmitting = true;
                digits.forEach(function (input) {
                    input.readOnly = true;
                });
                if (verifyBtn) {
                    verifyBtn.disabled = true;
                    verifyBtn.textContent = 'Verifying...';
                }
                if (resendBtn) {
                    resendBtn.disabled = true;
                }
            }

            function scheduleAutoSubmit() {
                if (autoSubmitTimer) {
                    clearTimeout(autoSubmitTimer);
                    autoSubmitTimer = null;
                }

                if (isSubmitting) {
                    return;
                }

                var otp = fullOtpInput.value;
                if (!/^\d{6}$/.test(otp)) {
                    return;
                }

                autoSubmitTimer = setTimeout(function () {
                    if (isSubmitting) {
                        return;
                    }

                    showNotice('Code received. Verifying now...', 'info');
                    setSubmittingState();
                    if (typeof otpForm.requestSubmit === 'function') {
                        otpForm.requestSubmit();
                        return;
                    }
                    otpForm.submit();
                }, 180);
            }

            function showClientError(message) {
                clientError.textContent = message;
                clientError.classList.remove('hidden');
            }

            function clearClientError() {
                clientError.textContent = '';
                clientError.classList.add('hidden');
            }

            function showNotice(message, type) {
                var tone = 'ui-alert-info';
                if (type === 'success') tone = 'ui-alert-success';
                if (type === 'danger') tone = 'ui-alert-danger';
                if (type === 'warning') tone = 'ui-alert-warning';

                clientNotice.innerHTML = '<div class="ui-alert ' + tone + '"><p>' + message + '</p></div>';
            }

            function startTimer() {
                if (timerInterval) {
                    clearInterval(timerInterval);
                }

                timer = 120;
                resendBtn.disabled = true;
                timerLabel.classList.remove('hidden');
                timerValue.textContent = '02:00';

                timerInterval = setInterval(function () {
                    timer -= 1;
                    var minutes = Math.floor(timer / 60);
                    var seconds = timer % 60;
                    timerValue.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

                    if (timer <= 0) {
                        clearInterval(timerInterval);
                        resendBtn.disabled = false;
                        timerLabel.textContent = 'You can resend OTP now.';
                    }
                }, 1000);
            }

            digits.forEach(function (input, index) {
                input.addEventListener('input', function () {
                    this.value = this.value.replace(/\D/g, '').slice(0, 1);
                    updateHiddenOtp();
                    clearClientError();

                    if (this.value && index < digits.length - 1) {
                        digits[index + 1].focus();
                    }

                    scheduleAutoSubmit();
                });

                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Backspace' && !this.value && index > 0) {
                        digits[index - 1].focus();
                    }

                    if (event.key.length === 1 && !/\d/.test(event.key)) {
                        event.preventDefault();
                    }
                });

                input.addEventListener('paste', function (event) {
                    event.preventDefault();
                    var pasted = (event.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
                    if (!pasted) return;

                    pasted.split('').forEach(function (char, i) {
                        if (digits[i]) {
                            digits[i].value = char;
                        }
                    });

                    updateHiddenOtp();
                    clearClientError();
                    digits[Math.min(pasted.length, digits.length) - 1].focus();
                    scheduleAutoSubmit();
                });
            });

            otpForm.addEventListener('submit', function (event) {
                var otp = fullOtpInput.value;
                if (!/^\d{6}$/.test(otp)) {
                    event.preventDefault();
                    showClientError('Please enter the complete 6-digit OTP code.');
                    isSubmitting = false;
                    if (verifyBtn) {
                        verifyBtn.disabled = false;
                        verifyBtn.textContent = 'Verify Code';
                    }
                    digits.forEach(function (input) {
                        input.readOnly = false;
                    });
                    return;
                }

                if (!isSubmitting) {
                    setSubmittingState();
                }
            });

            resendBtn.addEventListener('click', function () {
                if (resendBtn.disabled) {
                    return;
                }

                resendBtn.disabled = true;
                showNotice('Requesting a new OTP...', 'info');

                fetch('resend_otp.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        email: <?php echo json_encode($email); ?>
                    })
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            showNotice('A new OTP has been sent to your email.', 'success');
                            digits.forEach(function (input) { input.value = ''; });
                            updateHiddenOtp();
                            digits[0].focus();
                            timerLabel.innerHTML = 'Resend available in <span id="timerValue">02:00</span>';
                            timerValue = document.getElementById('timerValue');
                            startTimer();
                            return;
                        }

                        showNotice((data && data.message) ? data.message : 'Failed to resend OTP. Please try again.', 'danger');
                        resendBtn.disabled = false;
                    })
                    .catch(function () {
                        showNotice('An error occurred while resending OTP. Please try again.', 'danger');
                        resendBtn.disabled = false;
                    });
            });

            startTimer();
        })();
    </script>
</body>
</html>



