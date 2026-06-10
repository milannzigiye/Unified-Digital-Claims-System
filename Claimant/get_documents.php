<?php
// Tags: [CLAIMANT]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/claims_v2.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'claimant') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$claim_id = isset($_GET['claim_id']) ? (int)$_GET['claim_id'] : 0;
$claimant_id = $_SESSION['user_id'];
udcs_claims_v2_ensure_schema($conn);

// Verify claim belongs to claimant
$verifyStmt = mysqli_prepare($conn, 'SELECT id FROM claims WHERE id = ? AND COALESCE(claimant_user_id, claimant_id) = ? LIMIT 1');
$verify = false;
if ($verifyStmt) {
    mysqli_stmt_bind_param($verifyStmt, 'ii', $claim_id, $claimant_id);
    if (mysqli_stmt_execute($verifyStmt)) {
        $verify = mysqli_stmt_get_result($verifyStmt);
    }
}

if ($verify && mysqli_num_rows($verify) > 0) {
    $listStmt = mysqli_prepare($conn, 'SELECT * FROM documents WHERE claim_id = ? ORDER BY uploaded_at DESC');
    $result = false;
    if ($listStmt) {
        mysqli_stmt_bind_param($listStmt, 'i', $claim_id);
        if (mysqli_stmt_execute($listStmt)) {
            $result = mysqli_stmt_get_result($listStmt);
        }
    }
    
    $documents = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $documents[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'documents' => $documents]);
} else {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
}
?>

