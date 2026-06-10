<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function auth_find_user_by_email_role(mysqli $conn, string $email, string $role): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? AND role = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'ss', $email, $role);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if (!$result || mysqli_num_rows($result) === 0) {
        return null;
    }

    return mysqli_fetch_assoc($result) ?: null;
}

function require_web_role(mysqli $conn, string $role, string $loginPath = '../login.php'): array
{
    secure_session_start();

    $email = $_SESSION['email'] ?? '';
    $sessionRole = $_SESSION['role'] ?? '';
    if (!is_string($email) || $email === '' || $sessionRole !== $role) {
        header("Location: $loginPath");
        exit();
    }

    $user = auth_find_user_by_email_role($conn, $email, $role);
    if (!$user) {
        header("Location: $loginPath");
        exit();
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'] ?? '';
    $_SESSION['role'] = $role;
    $_SESSION['email'] = $email;

    return $user;
}

function require_api_role(mysqli $conn, string $role): array
{
    secure_session_start();

    $email = $_SESSION['email'] ?? '';
    $sessionRole = $_SESSION['role'] ?? '';
    if (!is_string($email) || $email === '' || $sessionRole !== $role) {
        exit('Unauthorized');
    }

    $user = auth_find_user_by_email_role($conn, $email, $role);
    if (!$user) {
        exit('Unauthorized');
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'] ?? '';
    $_SESSION['role'] = $role;
    $_SESSION['email'] = $email;

    return $user;
}
