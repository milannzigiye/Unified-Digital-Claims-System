<?php
// Tags: [CLAIMANT] [LEGACY] [REDIRECT]
require_once '../security.php';
secure_session_start();

$claimId = (int) ($_REQUEST['claim_id'] ?? 0);
$target = 'form_v2.php';
if ($claimId > 0) {
    $target .= '?claim_id=' . $claimId;
}

$acceptHeader = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
$requestType = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$isJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
    || str_contains($acceptHeader, 'application/json')
    || $requestType === 'xmlhttprequest';

if ($isJsonRequest) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Legacy claim updates have been retired. Continue through the redesigned claim form.',
        'redirect' => $target,
    ]);
    exit();
}

header('Location: ' . $target);
exit();
