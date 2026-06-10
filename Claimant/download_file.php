<?php
// Tags: [CLAIMANT]
require_once '../security.php';
secure_session_start();
require_once '../connect.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'claimant')) {
    http_response_code(403);
    exit('Unauthorized');
}

$file_id = (int) ($_GET['id'] ?? 0);
if ($file_id <= 0) {
    http_response_code(400);
    exit('Invalid file reference.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    exit('Unauthorized');
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT cm.file_path, cm.file_name
     FROM chat_messages cm
     INNER JOIN chat_participants cp ON cm.room_id = cp.room_id
     WHERE cm.id = ?
       AND cp.user_id = ?
       AND cm.file_path IS NOT NULL
     LIMIT 1"
);
if (!$stmt) {
    http_response_code(404);
    exit('File not found.');
}

mysqli_stmt_bind_param($stmt, 'ii', $file_id, $userId);
if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    http_response_code(404);
    exit('File not found.');
}

$result = mysqli_stmt_get_result($stmt);
$row = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    exit('File not found.');
}

$rawPath = trim((string) ($row['file_path'] ?? ''));
$downloadName = trim((string) ($row['file_name'] ?? 'attachment'));
if ($rawPath === '') {
    http_response_code(404);
    exit('File not found.');
}

$projectRoot = realpath(dirname(__DIR__));
$uploadsRoot = $projectRoot !== false ? realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads') : false;
$candidatePath = $rawPath;
if ($projectRoot !== false && !preg_match('/^[A-Za-z]:[\\\\\\/]|^\//', $candidatePath)) {
    $candidatePath = $projectRoot . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidatePath), DIRECTORY_SEPARATOR);
}

$resolvedPath = realpath($candidatePath);
if ($resolvedPath === false || !is_file($resolvedPath) || $uploadsRoot === false) {
    http_response_code(404);
    exit('File not found.');
}

$normalizedUploadsRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $uploadsRoot), DIRECTORY_SEPARATOR);
$normalizedResolvedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedPath);
$normalizedUploadsPrefix = $normalizedUploadsRoot . DIRECTORY_SEPARATOR;
if (
    strcasecmp($normalizedResolvedPath, $normalizedUploadsRoot) !== 0
    && stripos($normalizedResolvedPath, $normalizedUploadsPrefix) !== 0
) {
    http_response_code(404);
    exit('File not found.');
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = @finfo_file($finfo, $resolvedPath);
        if (is_string($detected) && trim($detected) !== '') {
            $mime = trim($detected);
        }
        @finfo_close($finfo);
    }
}

$safeName = basename($downloadName !== '' ? $downloadName : basename($resolvedPath));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($resolvedPath));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $safeName) . '"');

$handle = @fopen($resolvedPath, 'rb');
if ($handle === false) {
    http_response_code(404);
    exit('File not found.');
}

while (!feof($handle)) {
    $chunk = fread($handle, 8192);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
}
fclose($handle);
exit();

