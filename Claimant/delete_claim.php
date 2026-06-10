<?php
// Tags: [CLAIMANT] [CLAIM] [STATUS]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';

header('Content-Type: application/json; charset=UTF-8');

/* =========================
   AUTH CHECK
========================= */
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'claimant') {
    header("Location: ../claimant-access.php");
    exit();
}

$user_email = $_SESSION['email'];

/* =========================
   GET CLAIMANT DETAILS
========================= */
$user_data = udcs_db_fetch_user_by_email_role($conn, $user_email, 'claimant', 'id, full_name');
if (!$user_data) {
    header("Location: ../claimant-access.php");
    exit();
}

$claimant_id   = $user_data['id'];
$claimant_name = $user_data['full_name'];
udcs_claims_v2_ensure_schema($conn);

/* =========================
   READ JSON INPUT
========================= */
$data     = json_decode(file_get_contents('php://input'), true);
$claim_id = (int)($data['claim_id'] ?? 0);
$csrf_token = (string) ($data['csrf_token'] ?? '');

if (!udcs_csrf_validate($csrf_token, 'claim_delete')) {
    echo json_encode(['success' => false, 'message' => 'Security validation failed. Please refresh and try again.']);
    exit();
}

if ($claim_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'The selected claim reference is invalid.']);
    exit();
}

/* =========================
   VERIFY CLAIM OWNERSHIP
========================= */
$verifyStmt = mysqli_prepare(
    $conn,
    "SELECT id
     FROM claims
     WHERE id = ?
       AND COALESCE(claimant_user_id, claimant_id) = ?
       AND COALESCE(NULLIF(model_version, ''), 'legacy') = 'v2'
       AND COALESCE(legacy_read_only, 0) = 0
       AND COALESCE(NULLIF(status, ''), claim_status) = 'Draft'
     LIMIT 1"
);
if (!$verifyStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'We could not verify your claim right now. Please try again.'
    ]);
    exit();
}
mysqli_stmt_bind_param($verifyStmt, 'ii', $claim_id, $claimant_id);
mysqli_stmt_execute($verifyStmt);
$verify = mysqli_stmt_get_result($verifyStmt);
mysqli_stmt_close($verifyStmt);

if (!$verify || mysqli_num_rows($verify) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Only redesigned draft claims can be deleted.'
    ]);
    exit();
}

if (udcs_claim_delete_single($conn, $claim_id)) {
    $notif_msg = "Claim deleted by claimant ($claimant_name).";

    $insertNotif = udcs_db_insert_notification($conn, $user_email, $user_email, $notif_msg);
    if (!$insertNotif) {
        error_log("Notification insert failed (DELETE): " . mysqli_error($conn));
    }

    bk_activity_log($conn, [
        'actor_id' => (int) $claimant_id,
        'actor_role' => 'claimant',
        'claim_id' => (int) $claim_id,
        'action_key' => 'claim_deleted',
        'action_label' => 'Claim Deleted',
        'details' => 'Pending claim deleted by claimant.',
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Your claim was deleted successfully.'
    ]);
    exit();
}

echo json_encode([
    'success' => false,
    'message' => 'We could not delete your claim right now. Please try again.'
]);


