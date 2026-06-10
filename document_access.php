<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
secure_session_start();
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/components/helpers.php';
require_once __DIR__ . '/components/claim_documents.php';

$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$email = trim((string) ($_SESSION['email'] ?? ''));
if ($email === '' || !in_array($role, ['claimant', 'legal', 'finance', 'admin'], true)) {
    http_response_code(403);
    exit('Unauthorized');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    $userId = udcs_db_fetch_user_id_by_email_role($conn, $email, $role);
}
if ($userId <= 0) {
    http_response_code(403);
    exit('Unauthorized');
}

$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(400);
    exit('Invalid document reference.');
}

$document = udcs_claim_document_fetch_with_claim($conn, $documentId);
if (!$document || !udcs_claim_document_user_can_access($document, $role, $userId)) {
    http_response_code(404);
    exit('Document not found.');
}

$resolvedPath = udcs_claim_document_resolve_path((string) ($document['file_path'] ?? ''));
if ($resolvedPath === null || !is_file($resolvedPath)) {
    http_response_code(404);
    exit('Document not found.');
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

$downloadRequested = (string) ($_GET['download'] ?? '0') === '1';
$inlineAllowed = str_starts_with($mime, 'image/') || $mime === 'application/pdf';
$disposition = ($downloadRequested || !$inlineAllowed) ? 'attachment' : 'inline';

$documentType = trim((string) ($document['document_type'] ?? 'document'));
$extension = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
$safeBaseName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $documentType);
$safeBaseName = is_string($safeBaseName) ? trim($safeBaseName, '._-') : 'document';
if ($safeBaseName === '') {
    $safeBaseName = 'document';
}
$downloadName = $safeBaseName . ($extension !== '' ? '.' . $extension : '');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($resolvedPath));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $downloadName) . '"');

$handle = @fopen($resolvedPath, 'rb');
if ($handle === false) {
    http_response_code(404);
    exit('Document not found.');
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
