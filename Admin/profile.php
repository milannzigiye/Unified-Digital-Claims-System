<?php
// Tags: [ADMIN] [PROFILE] [AUTH]
include '../connect.php';
require_once '../security.php';
secure_session_start();

require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/alert.php';
require_once dirname(__DIR__) . '/components/workflow.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $sessionEmail, 'admin');
if (!$user_data) {
    header('Location: ../login.php');
    exit();
}

$user_email = $sessionEmail;
$user_id = (int) ($user_data['id'] ?? 0);
$profileAlert = null;

if (isset($_POST['save_profile'])) {
    $old_password = trim((string) ($_POST['old_password'] ?? ''));
    $new_password = trim((string) ($_POST['new_password'] ?? ''));
    $confirm_password = trim((string) ($_POST['confirm_password'] ?? ''));

    if ($new_password !== '' || $confirm_password !== '' || $old_password !== '') {
        if ($old_password === '' || $new_password === '' || $confirm_password === '') {
            $profileAlert = ['type' => 'danger', 'message' => 'Current password, new password, and confirmation are required.'];
        } elseif (!password_verify($old_password, (string) ($user_data['password'] ?? ''))) {
            $profileAlert = ['type' => 'danger', 'message' => 'Current password is incorrect.'];
        } elseif ($new_password !== $confirm_password) {
            $profileAlert = ['type' => 'danger', 'message' => 'New password and confirmation do not match.'];
        } elseif (strlen($new_password) < 8) {
            $profileAlert = ['type' => 'danger', 'message' => 'New password must be at least 8 characters long.'];
        }
    }

    if ($profileAlert === null) {
        if ($new_password === '') {
            $profileAlert = ['type' => 'warning', 'message' => 'No changes detected.'];
        } else {
            $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
            $updateStmt = mysqli_prepare($conn, 'UPDATE users SET password = ? WHERE id = ? LIMIT 1');
            if ($updateStmt) {
                mysqli_stmt_bind_param($updateStmt, 'si', $hashedPassword, $user_id);
            }

            if ($updateStmt && mysqli_stmt_execute($updateStmt)) {
                udcs_db_insert_notification($conn, (string) $user_id, (string) $user_id, 'Your password has been updated.');
                bk_activity_log($conn, [
                    'actor_id' => $user_id,
                    'actor_role' => 'admin',
                    'action_key' => 'password_changed',
                    'action_label' => 'Password Changed',
                    'details' => 'Admin user updated their account password.',
                ]);
                $profileAlert = ['type' => 'success', 'message' => 'Password updated successfully.'];
                $refreshStmt = mysqli_prepare($conn, 'SELECT * FROM users WHERE id = ? LIMIT 1');
                if ($refreshStmt) {
                    mysqli_stmt_bind_param($refreshStmt, 'i', $user_id);
                    if (mysqli_stmt_execute($refreshStmt)) {
                        $refreshResult = mysqli_stmt_get_result($refreshStmt);
                        if ($refreshResult && mysqli_num_rows($refreshResult) === 1) {
                            $user_data = mysqli_fetch_assoc($refreshResult);
                        }
                    }
                }
            } else {
                $profileAlert = ['type' => 'danger', 'message' => 'Failed to update password.'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    render_head(
        'Admin Profile | UNIFIED DIGITAL CLAIMS SYSTEM',
        '..',
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">'
    );
    ?>
    <style>
        .profile-shell { padding: 1rem 1.25rem 2rem; }
        .profile-hero {
            position: relative; overflow: hidden; border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1.2rem;
            background: linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.17), rgba(var(--bk-primary-rgb), 0.04) 48%, rgba(var(--bk-surface-rgb), 0.98)), rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft); padding: 1.3rem 1.35rem;
        }
        .profile-hero::before {
            content: ''; position: absolute; width: 14rem; height: 14rem; right: -3.7rem; top: -5.3rem; border-radius: 999px;
            background: radial-gradient(circle, rgba(var(--bk-primary-rgb), 0.2), rgba(var(--bk-primary-rgb), 0)); pointer-events: none; animation: float 7s ease-in-out infinite;
        }
        .profile-hero h1 { margin: 0.45rem 0 0; font-family: var(--app-display-font), var(--app-font), sans-serif; font-size: clamp(1.6rem, 2.5vw, 2.15rem); line-height: 1.2; color: rgb(var(--bk-text-rgb)); font-weight: 800; letter-spacing: 0.01em; }
        .profile-hero p { margin: 0.55rem 0 0; color: rgb(var(--bk-muted-rgb)); font-size: 0.95rem; max-width: 44rem; }
        .profile-grid { margin-top: 1rem; display: grid; gap: 0.9rem; }
        .profile-card { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1rem; background: rgb(var(--bk-surface-rgb)); box-shadow: var(--shadow-soft); overflow: hidden; }
        .profile-card-head { padding: 0.8rem 0.95rem; border-bottom: 1px solid rgba(var(--bk-border-rgb), 1); background: rgba(var(--bk-primary-rgb), 0.06); }
        .profile-card-title { margin: 0; color: rgb(var(--bk-text-rgb)); font-size: 1rem; font-weight: 700; }
        .profile-card-subtitle { margin: 0.15rem 0 0; color: rgb(var(--bk-muted-rgb)); font-size: 0.8rem; }
        .profile-card-body { padding: 0.9rem 0.95rem; }
        .settings-grid { display: grid; gap: 0.9rem; }
        .settings-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
        .password-wrap { position: relative; }
        .pw-toggle {
            position: absolute; right: 0.45rem; top: 50%; transform: translateY(-50%); border: 0; border-radius: 0.5rem; background: transparent;
            color: rgb(var(--bk-muted-rgb)); width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center;
        }
        .pw-toggle:hover { background: rgba(var(--bk-primary-rgb), 0.09); color: rgb(var(--bk-text-rgb)); }
        @media (max-width: 780px) {
            .settings-row { grid-template-columns: 1fr; }
            .profile-shell { padding-left: 0.8rem; padding-right: 0.8rem; }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content profile-shell">
    <section class="profile-hero">
        <h1>Profile and Security</h1>
        <p>Use this page to securely update your account password.</p>
    </section>

    <?php if ($profileAlert): ?>
        <div class="mt-4"><?php render_alert($profileAlert['message'], ['type' => $profileAlert['type']]); ?></div>
    <?php endif; ?>

    <div class="profile-grid">
        <section class="settings-grid">
            <form method="POST" id="profileForm" class="settings-grid">
                <article class="profile-card">
                    <header class="profile-card-head">
                        <h2 class="profile-card-title">Password Update</h2>
                        <p class="profile-card-subtitle">Use your current password to confirm this change.</p>
                    </header>
                    <div class="profile-card-body settings-grid">
                        <div class="ui-field">
                            <label class="ui-label" for="old_password">Current Password</label>
                            <div class="password-wrap">
                                <input id="old_password" type="password" name="old_password" class="ui-input pr-11" placeholder="Enter current password">
                                <button type="button" class="pw-toggle" data-toggle-password="old_password" aria-label="Toggle password visibility"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="settings-row">
                            <div class="ui-field">
                                <label class="ui-label" for="new_password">New Password</label>
                                <div class="password-wrap">
                                    <input id="new_password" type="password" name="new_password" class="ui-input pr-11" placeholder="Enter new password">
                                    <button type="button" class="pw-toggle" data-toggle-password="new_password" aria-label="Toggle password visibility"><i class="fa-regular fa-eye"></i></button>
                                </div>
                            </div>
                            <div class="ui-field">
                                <label class="ui-label" for="confirm_password">Confirm Password</label>
                                <div class="password-wrap">
                                    <input id="confirm_password" type="password" name="confirm_password" class="ui-input pr-11" placeholder="Repeat new password">
                                    <button type="button" class="pw-toggle" data-toggle-password="confirm_password" aria-label="Toggle password visibility"><i class="fa-regular fa-eye"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="profile-actions">
                            <button class="ui-btn ui-btn-md ui-btn-primary" type="submit" name="save_profile">Update Password</button>
                        </div>
                    </div>
                </article>
            </form>
        </section>

        <aside class="security-notes" aria-label="Password security guidance">
            <div class="security-notes-head">
                <span class="security-icon"><i class="fa-solid fa-shield-halved"></i></span>
                <div>
                    <h2>Security Rules</h2>
                    <p>Keep admin access controlled without adding unnecessary profile clutter.</p>
                </div>
            </div>
            <ul class="security-rule-list">
                <li><i class="fa-solid fa-check"></i><span>Current password is required before any change is accepted.</span></li>
                <li><i class="fa-solid fa-check"></i><span>New password must be at least 8 characters long.</span></li>
                <li><i class="fa-solid fa-check"></i><span>Every successful password update is recorded in the activity trail.</span></li>
            </ul>
        </aside>
    </div>
</main>

<script>
(() => {
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', function () {
            const targetId = button.getAttribute('data-toggle-password');
            const input = document.getElementById(targetId);
            const icon = button.querySelector('i');
            if (!input || !icon) return;
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            icon.className = visible ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
        });
    });
})();
</script>
</body>
</html>


