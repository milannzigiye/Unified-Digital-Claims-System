<?php
declare(strict_types=1);

function profile_photo_path(?string $photoFilename): string
{
    if (!empty($photoFilename) && file_exists(__DIR__ . '/uploads/' . $photoFilename)) {
        return '../uploads/' . $photoFilename;
    }
    return '../Images/logo.png';
}

function profile_update_basic(mysqli $conn, array $userData, string $role, string $email, array $post, array $files): array
{
    $password = isset($post['password']) ? trim((string)$post['password']) : '';
    $rePassword = isset($post['re_password']) ? trim((string)$post['re_password']) : '';
    $photo = $files['photo'] ?? null;
    $currentPhoto = (string)($userData['photo'] ?? '');

    if ($password !== '' && $password !== $rePassword) {
        return ['ok' => false, 'message' => 'Passwords do not match.'];
    }

    $updateFields = [];
    $updateParams = [];
    $updateTypes = '';

    if ($password !== '') {
        $updateFields[] = '`password` = ?';
        $updateTypes .= 's';
        $updateParams[] = password_hash($password, PASSWORD_DEFAULT);
    }

    if (is_array($photo) && ($photo['name'] ?? '') !== '') {
        $targetDir = __DIR__ . '/uploads/';
        $imageFileType = strtolower(pathinfo((string)$photo['name'], PATHINFO_EXTENSION));

        if (!is_uploaded_file((string)($photo['tmp_name'] ?? '')) || getimagesize((string)$photo['tmp_name']) === false) {
            return ['ok' => false, 'message' => 'File is not an image.'];
        }
        if ((int)($photo['size'] ?? 0) > 2000000) {
            return ['ok' => false, 'message' => 'Sorry, your file is too large.'];
        }
        if (!in_array($imageFileType, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return ['ok' => false, 'message' => 'Sorry, only JPG, JPEG, PNG & GIF files are allowed.'];
        }

        $uniqueFilename = uniqid('', true) . '.' . $imageFileType;
        $targetFile = $targetDir . $uniqueFilename;
        if (!move_uploaded_file((string)$photo['tmp_name'], $targetFile)) {
            return ['ok' => false, 'message' => 'Sorry, there was an error uploading your file.'];
        }

        if ($currentPhoto !== '' && file_exists($targetDir . $currentPhoto)) {
            @unlink($targetDir . $currentPhoto);
        }

        $updateFields[] = '`photo` = ?';
        $updateTypes .= 's';
        $updateParams[] = $uniqueFilename;
    }

    if (count($updateFields) === 0) {
        return ['ok' => false, 'message' => 'No changes were submitted.'];
    }

    $sql = 'UPDATE `users` SET ' . implode(', ', $updateFields) . ' WHERE email = ? AND role = ?';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Error updating your profile.'];
    }

    $updateTypes .= 'ss';
    $updateParams[] = $email;
    $updateParams[] = $role;
    mysqli_stmt_bind_param($stmt, $updateTypes, ...$updateParams);
    $result = mysqli_stmt_execute($stmt);
    if (!$result) {
        return ['ok' => false, 'message' => 'Error updating your profile.'];
    }

    $notifMsg = 'You have updated your profile information.';
    udcs_db_insert_notification($conn, $email, $email, $notifMsg);

    return ['ok' => true, 'message' => 'Profile updated successfully.'];
}

function profile_update_secure_password(
    mysqli $conn,
    array $userData,
    string $oldPassword,
    string $newPassword,
    string $confirmPassword,
    bool $notifyUserOnChange = false
): array {
    if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
        return ['ok' => false, 'message' => 'All password fields are required.'];
    }
    if (!password_verify($oldPassword, (string)($userData['password'] ?? ''))) {
        return ['ok' => false, 'message' => 'The old password you entered is incorrect.'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'message' => 'The new passwords do not match.'];
    }

    $userId = (int)$userData['id'];
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = mysqli_prepare($conn, 'UPDATE users SET password = ? WHERE id = ?');
    if (!$updateStmt) {
        return ['ok' => false, 'message' => 'Error updating password.'];
    }
    mysqli_stmt_bind_param($updateStmt, 'si', $hashedPassword, $userId);
    if (!mysqli_stmt_execute($updateStmt)) {
        return ['ok' => false, 'message' => 'Error updating password.'];
    }

    if ($notifyUserOnChange) {
        $notificationMessage = 'Your account password was changed successfully.';
        udcs_db_insert_notification($conn, (string) $userId, (string) $userId, $notificationMessage);
    }

    return ['ok' => true, 'message' => 'Password updated successfully.'];
}

function profile_delete_account(mysqli $conn, int $userId, string $userEmail): array
{
    mysqli_begin_transaction($conn);
    try {
        $deleteClaimsStmt = mysqli_prepare($conn, 'DELETE FROM claims WHERE claimant_id = ?');
        if ($deleteClaimsStmt) {
            mysqli_stmt_bind_param($deleteClaimsStmt, 'i', $userId);
            mysqli_stmt_execute($deleteClaimsStmt);
        }

        $deleteNotifStmt = mysqli_prepare($conn, 'DELETE FROM notifications WHERE receiver = ? OR sender = ?');
        if ($deleteNotifStmt) {
            mysqli_stmt_bind_param($deleteNotifStmt, 'ss', $userEmail, $userEmail);
            mysqli_stmt_execute($deleteNotifStmt);
        }

        $deleteMsgStmt = mysqli_prepare($conn, 'DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?');
        if ($deleteMsgStmt) {
            mysqli_stmt_bind_param($deleteMsgStmt, 'ii', $userId, $userId);
            mysqli_stmt_execute($deleteMsgStmt);
        }

        $deleteUserStmt = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ?');
        if ($deleteUserStmt) {
            mysqli_stmt_bind_param($deleteUserStmt, 'i', $userId);
            mysqli_stmt_execute($deleteUserStmt);
        }

        mysqli_commit($conn);
        return ['ok' => true, 'message' => 'Your account has been permanently deleted.'];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'message' => 'Error deleting account.'];
    }
}
