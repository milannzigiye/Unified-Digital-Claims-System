<?php
// Tags: [CLAIMANT] [REPORT] [PDF]
require_once '../security.php';
secure_session_start();

require_once '../connect.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';

if (($_SESSION['role'] ?? '') !== 'claimant') {
    header('Location: ../claimant-access.php');
    exit();
}

$tcpdfPath = dirname(__DIR__) . '/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    exit('TCPDF library not found.');
}
require_once $tcpdfPath;
require_once dirname(__DIR__) . '/components/pdf_report_theme.php';
udcs_claims_v2_ensure_schema($conn);

$claimantId = (int) ($_SESSION['user_id'] ?? 0);
if ($claimantId <= 0) {
    $sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
    if ($sessionEmail === '') {
        header('Location: ../claimant-access.php');
        exit();
    }

    $claimantId = udcs_db_fetch_user_id_by_email_role($conn, $sessionEmail, 'claimant');
    if ($claimantId <= 0) {
        header('Location: ../claimant-access.php');
        exit();
    }
    $_SESSION['user_id'] = $claimantId;
}

$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$userStmt = mysqli_prepare($conn, 'SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
$userRow = null;
if ($userStmt) {
    mysqli_stmt_bind_param($userStmt, 'i', $claimantId);
    if (mysqli_stmt_execute($userStmt)) {
        $userResult = mysqli_stmt_get_result($userStmt);
        $userRow = $userResult ? mysqli_fetch_assoc($userResult) : null;
    }
}
$claimantName = (string) ($userRow['full_name'] ?? 'Claimant');
$claimantEmail = (string) ($userRow['email'] ?? '');

function claimant_export_is_blank_draft(array $claim): bool
{
    $status = trim((string) ($claim['effective_status'] ?? $claim['claim_status'] ?? ''));
    if ($status !== 'Draft') {
        return false;
    }

    $fields = [
        (string) ($claim['deceased_full_name'] ?? $claim['deceased_name'] ?? ''),
        (string) ($claim['relationship'] ?? ''),
        (string) ($claim['marital_status'] ?? ''),
        (string) ($claim['spouse_status'] ?? ''),
        (string) ($claim['children_status'] ?? ''),
        (string) ($claim['preferred_payout_method'] ?? ''),
        (string) ($claim['distribution_method'] ?? ''),
        (string) ($claim['distribution_details'] ?? ''),
        (string) ($claim['asset_classes'] ?? ''),
    ];

    foreach ($fields as $value) {
        if (trim($value) !== '') {
            return false;
        }
    }

    $submittedAt = trim((string) ($claim['submitted_at'] ?? ''));
    $updatedAt = trim((string) ($claim['updated_at'] ?? ''));
    return $submittedAt === '' || $submittedAt === $updatedAt;
}

$whereParts = ['COALESCE(NULLIF(c.claimant_user_id, 0), c.claimant_id) = ?'];
$types = 'i';
$params = [$claimantId];
if ($search !== '') {
    $whereParts[] = '(
        COALESCE(NULLIF(c.deceased_full_name, \'\'), c.deceased_name) LIKE ?
        OR c.claim_type LIKE ?
        OR c.relationship LIKE ?
        OR c.marital_status LIKE ?
        OR c.spouse_status LIKE ?
        OR c.children_status LIKE ?
        OR c.preferred_payout_method LIKE ?
        OR c.distribution_method LIKE ?
        OR c.distribution_details LIKE ?
        OR COALESCE(ca.asset_classes, \'\') LIKE ?
        OR COALESCE(ca.asset_terms, \'\') LIKE ?
        OR COALESCE(cp.people_terms, \'\') LIKE ?
        OR COALESCE(dc.document_terms, \'\') LIKE ?
        OR CAST(c.claim_amount AS CHAR) LIKE ?
        OR CAST(c.id AS CHAR) LIKE ?
    )';
    $term = '%' . $search . '%';
    $types .= str_repeat('s', 15);
    for ($i = 0; $i < 15; $i++) {
        $params[] = $term;
    }
}
if ($status !== '') {
    $whereParts[] = "LOWER(REPLACE(COALESCE(NULLIF(c.status, ''), c.claim_status), '_', ' ')) = ?";
    $types .= 's';
    $params[] = udcs_claim_status_key($status);
}
$whereSql = implode(' AND ', $whereParts);
$claimAccountSql = udcs_claim_account_reference_sql('c');

$assetJoinSql = "
    SELECT
        claim_id,
        GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes,
        GROUP_CONCAT(DISTINCT CONCAT_WS(' ', asset_class, account_reference, finance_status, payout_preference_override) SEPARATOR ' || ') AS asset_terms
    FROM claim_assets
    GROUP BY claim_id
";
$peopleJoinSql = "
    SELECT
        cp.claim_id,
        GROUP_CONCAT(DISTINCT CONCAT_WS(' ', p.full_name, p.id_number, p.contact_phone, p.contact_email, cp.role, cp.relationship_type) SEPARATOR ' || ') AS people_terms
    FROM claim_people cp
    INNER JOIN people p ON p.person_id = cp.person_id
    GROUP BY cp.claim_id
";
$documentJoinSql = "
    SELECT
        claim_id,
        GROUP_CONCAT(DISTINCT CONCAT_WS(' ', document_type, ocr_status, legal_review_status, rejection_reason) SEPARATOR ' || ') AS document_terms
    FROM documents
    GROUP BY claim_id
";

$sql = "
SELECT
    c.id,
    COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name) AS deceased_name,
    c.relationship,
    c.claim_type,
    c.claim_amount,
    COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
    {$claimAccountSql} AS account_number,
    {$claimAccountSql} AS accout_number,
    c.distribution_method,
    c.distribution_details,
    DATE_FORMAT(c.submitted_at, '%Y-%m-%d %H:%i') AS submitted_label,
    COALESCE(ca.asset_classes, '') AS asset_classes
FROM claims c
LEFT JOIN ($assetJoinSql) ca ON ca.claim_id = c.id
LEFT JOIN ($peopleJoinSql) cp ON cp.claim_id = c.id
LEFT JOIN ($documentJoinSql) dc ON dc.claim_id = c.id
WHERE $whereSql
ORDER BY c.submitted_at DESC
";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    udcs_exit_public_error('Claimant export failed during statement preparation.', 'The claims report could not be generated right now.', $conn);
}

if (!udcs_db_stmt_bind($stmt, $types, $params) || !mysqli_stmt_execute($stmt)) {
    udcs_exit_public_error('Claimant export failed during statement execution.', 'The claims report could not be generated right now.', $conn);
}

$result = mysqli_stmt_get_result($stmt);
if (!$result) {
    udcs_exit_public_error('Claimant export failed while reading the result set.', 'The claims report could not be generated right now.', $conn);
}

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    if (claimant_export_is_blank_draft($row)) {
        continue;
    }

    $claimId = (int) ($row['id'] ?? 0);
    $contract = udcs_claim_fetch_review_contract($conn, $claimId);
    if (!is_array($contract)) {
        continue;
    }
    $claim = (array) ($contract['claim'] ?? $row);
    $peopleSummary = (array) ($contract['people']['summary'] ?? []);
    $assetSummary = (array) ($contract['assets']['summary'] ?? []);
    $payout = (array) ($contract['payout'] ?? []);

    $rows[] = [
        'claim_id' => 'CL-' . str_pad((string) $claimId, 6, '0', STR_PAD_LEFT),
        'deceased' => (string) (($peopleSummary['deceased_name'] ?? '') !== '' ? $peopleSummary['deceased_name'] : ($row['deceased_name'] ?? 'N/A')),
        'bk_assets' => (string) ($assetSummary['label'] ?? udcs_claim_asset_summary_label((string) ($row['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''))),
        'value' => udcs_claim_contract_value_label($contract, 'estimated'),
        'status' => (string) ($contract['status']['label'] ?? udcs_claim_status_label((string) ($row['effective_status'] ?? ''))),
        'settlement' => (string) ($payout['preferred_label'] ?? bk_distribution_method_label((string) ($claim['distribution_method'] ?? ''))),
        'submitted' => (string) ($row['submitted_label'] ?? '-'),
    ];
}

$pdf = udcs_pdf_create_document([
    'title' => 'Submitted Claims Export',
    'portal' => 'Claimant Portal',
    'model' => 'Self-Service Model',
    'panel' => 'Submitted Claims Panel',
    'timestamp' => date('Y-m-d H:i:s'),
]);

udcs_pdf_render_filter_summary($pdf, [
    ['label' => 'Status', 'value' => $status !== '' ? udcs_claim_status_label($status) : ''],
    ['label' => 'Search', 'value' => $search],
]);

$columns = [
    ['key' => 'claim_id', 'label' => 'Claim Ref', 'weight' => 0.95, 'align' => 'L'],
    ['key' => 'deceased', 'label' => 'Deceased', 'weight' => 1.45, 'align' => 'L'],
    ['key' => 'status', 'label' => 'Status', 'weight' => 1.0, 'align' => 'L'],
    ['key' => 'bk_assets', 'label' => 'BK Assets', 'weight' => 1.7, 'align' => 'L'],
    ['key' => 'value', 'label' => 'Value', 'weight' => 1.05, 'align' => 'R'],
    ['key' => 'settlement', 'label' => 'Settlement', 'weight' => 1.35, 'align' => 'L'],
    ['key' => 'submitted', 'label' => 'Date', 'weight' => 1.0, 'align' => 'L'],
];

udcs_pdf_render_table($pdf, $columns, $rows, [
    'line_height' => 4.5,
    'header_height' => 7.1,
    'max_lines_per_cell' => 0,
]);

while (ob_get_level() > 0) {
    ob_end_clean();
}

$pdf->Output('my_claims_' . date('Ymd_His') . '.pdf', 'I');
exit();

