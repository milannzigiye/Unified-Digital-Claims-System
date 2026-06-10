<?php
// Tags: [CLAIMANT] [CLAIM] [STATUS]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/claims_v2.php';

header('Content-Type: application/json');

// Check if user is logged in using email
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'claimant') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_email = trim((string) ($_SESSION['email'] ?? ''));
$claim_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($claim_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
    exit();
}

// Get claimant ID from email
$claimant_id = udcs_db_fetch_user_id_by_email_role($conn, $user_email, 'claimant');
if ($claimant_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}
udcs_claims_v2_ensure_schema($conn);
$claimAccountSql = udcs_claim_account_reference_sql('c');

// Verify claim belongs to claimant and get all details including distribution fields
$stmt = mysqli_prepare(
    $conn,
    "
    SELECT 
        c.id,
        COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name) AS deceased_name,
        COALESCE(NULLIF(c.deceased_id_number, ''), c.deceased_national_id) AS deceased_national_id,
        {$claimAccountSql} AS account_number,
        {$claimAccountSql} AS accout_number,
        COALESCE(c.date_of_death, c.deceased_date) AS date_of_death,
        c.deceased_date,
        c.claim_type,
        c.claim_amount,
        c.claim_description,
        COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
        c.claim_status,
        c.distribution_method,
        c.distribution_details,
        c.submitted_at,
        c.updated_at,
        COALESCE(ca.asset_classes, '') AS asset_classes
    FROM claims c
    LEFT JOIN (
        SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
        FROM claim_assets
        GROUP BY claim_id
    ) ca ON ca.claim_id = c.id
    WHERE c.id = ?
    AND COALESCE(c.claimant_user_id, c.claimant_id) = ?
"
);
$result = false;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'ii', $claim_id, $claimant_id);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
    }
}

if ($result && mysqli_num_rows($result) > 0) {
    $claim = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'claim' => $claim]);
} else {
    echo json_encode(['success' => false, 'message' => 'Claim not found or access denied']);
}
?>

