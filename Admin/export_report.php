<?php
// Tags: [ADMIN] [COMPREHENSIVE] [REPORT] [PDF]
require_once '../security.php';
secure_session_start();

require_once '../connect.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';

if (!isset($_SESSION['email']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    exit('Unauthorized');
}

$tcpdfPath = dirname(__DIR__) . '/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    exit('TCPDF library not found.');
}
require_once $tcpdfPath;
require_once dirname(__DIR__) . '/components/pdf_report_theme.php';

bk_claims_ensure_workflow_schema($conn);
udcs_claims_v2_ensure_schema($conn);

$reportType = trim((string) ($_GET['report'] ?? 'summary'));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$claimType = trim((string) ($_GET['claim_type'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$claimAccountSql = udcs_claim_account_reference_sql('c');

$whereParts = ['1=1'];
$types = '';
$params = [];
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $whereParts[] = 'DATE(c.submitted_at) >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $whereParts[] = 'DATE(c.submitted_at) <= ?';
    $types .= 's';
    $params[] = $dateTo;
}
if ($status !== '' && strtolower($status) !== 'all') {
    $whereParts[] = "LOWER(REPLACE(COALESCE(NULLIF(c.status, ''), c.claim_status), '_', ' ')) = ?";
    $types .= 's';
    $params[] = udcs_claim_status_key($status);
}
if ($claimType !== '' && strtolower($claimType) !== 'all') {
    $whereParts[] = '(c.claim_type = ? OR EXISTS (SELECT 1 FROM claim_assets ca_filter WHERE ca_filter.claim_id = c.id AND ca_filter.asset_class = ?))';
    $types .= 'ss';
    $params[] = $claimType;
    $params[] = $claimType;
}
if ($search !== '') {
    $whereParts[] = '(
        CAST(c.id AS CHAR) LIKE ?
        OR u.full_name LIKE ?
        OR u.email LIKE ?
        OR COALESCE(NULLIF(c.deceased_full_name, \'\'), c.deceased_name) LIKE ?
        OR c.relationship LIKE ?
        OR c.marital_status LIKE ?
        OR c.spouse_status LIKE ?
        OR c.children_status LIKE ?
        OR c.manual_review_reason LIKE ?
        OR c.preferred_payout_method LIKE ?
        OR ' . $claimAccountSql . ' LIKE ?
        OR c.distribution_method LIKE ?
        OR c.distribution_details LIKE ?
        OR c.comment LIKE ?
        OR COALESCE(ca.asset_classes, \'\') LIKE ?
        OR COALESCE(ca.asset_terms, \'\') LIKE ?
        OR COALESCE(cp.people_terms, \'\') LIKE ?
        OR COALESCE(dc.document_terms, \'\') LIKE ?
    )';
    $term = '%' . $search . '%';
    $types .= str_repeat('s', 18);
    for ($i = 0; $i < 18; $i++) {
        $params[] = $term;
    }
}
$whereSql = implode(' AND ', $whereParts);

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
    c.claim_type,
    c.claim_amount,
    c.finance_assessed_amount,
    COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
    {$claimAccountSql} AS account_number,
    {$claimAccountSql} AS accout_number,
    c.distribution_method,
    c.distribution_details,
    c.comment,
    DATE_FORMAT(c.submitted_at, '%Y-%m-%d %H:%i') AS submitted_label,
    DATE_FORMAT(c.updated_at, '%Y-%m-%d %H:%i') AS updated_label,
    u.full_name AS claimant_name,
    ul.full_name AS legal_assignee_name,
    uf.full_name AS finance_assignee_name,
    COALESCE(ca.asset_classes, '') AS asset_classes
FROM claims c
INNER JOIN users u ON u.id = COALESCE(NULLIF(c.claimant_user_id, 0), c.claimant_id)
LEFT JOIN users ul ON ul.id = c.assigned_legal_id
LEFT JOIN users uf ON uf.id = c.assigned_finance_id
LEFT JOIN ($assetJoinSql) ca ON ca.claim_id = c.id
LEFT JOIN ($peopleJoinSql) cp ON cp.claim_id = c.id
LEFT JOIN ($documentJoinSql) dc ON dc.claim_id = c.id
WHERE $whereSql
ORDER BY c.submitted_at DESC
";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    udcs_exit_public_error('Admin comprehensive export failed during statement preparation.', 'The comprehensive report could not be generated right now.', $conn);
}

if (!udcs_db_stmt_bind($stmt, $types, $params) || !mysqli_stmt_execute($stmt)) {
    udcs_exit_public_error('Admin comprehensive export failed during statement execution.', 'The comprehensive report could not be generated right now.', $conn);
}

$result = mysqli_stmt_get_result($stmt);
if (!$result) {
    udcs_exit_public_error('Admin comprehensive export failed while reading the result set.', 'The comprehensive report could not be generated right now.', $conn);
}

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $claimId = (int) ($row['id'] ?? 0);
    $contract = udcs_claim_fetch_review_contract($conn, $claimId);
    if (!is_array($contract)) {
        continue;
    }
    $payout = (array) ($contract['payout'] ?? []);
    $peopleSummary = (array) ($contract['people']['summary'] ?? []);
    $assetSummary = (array) ($contract['assets']['summary'] ?? []);

    $rows[] = [
        'claim_id' => 'CL-' . str_pad((string) $claimId, 6, '0', STR_PAD_LEFT),
        'claimant' => (string) (($peopleSummary['claimant_name'] ?? '') !== '' ? $peopleSummary['claimant_name'] : ($row['claimant_name'] ?? 'Unknown')),
        'deceased' => (string) (($peopleSummary['deceased_name'] ?? '') !== '' ? $peopleSummary['deceased_name'] : ($row['deceased_name'] ?? 'N/A')),
        'status' => (string) ($contract['status']['label'] ?? udcs_claim_status_label((string) ($row['effective_status'] ?? ''))),
        'assets' => (string) ($assetSummary['label'] ?? udcs_claim_asset_summary_label((string) ($row['asset_classes'] ?? ''), (string) ($row['claim_type'] ?? ''))),
        'value' => 'Claimed: ' . udcs_claim_contract_value_label($contract, 'estimated') . ' | Verified: ' . udcs_claim_contract_value_label($contract, 'verified'),
        'settlement' => (string) ($payout['preferred_label'] ?? bk_distribution_method_label((string) ($row['distribution_method'] ?? ''))),
        'updated' => (string) ($row['updated_label'] ?? '-'),
    ];
}

$pdf = udcs_pdf_create_document([
    'title' => 'Claims Summary Export',
    'portal' => 'Admin Portal',
    'model' => 'Comprehensive Report Model',
    'panel' => 'Claims Review Panel',
    'timestamp' => date('Y-m-d H:i:s'),
]);

udcs_pdf_render_filter_summary($pdf, [
    ['label' => 'Date Range', 'value' => ($dateFrom !== '' || $dateTo !== '') ? (($dateFrom !== '' ? $dateFrom : 'Any') . ' to ' . ($dateTo !== '' ? $dateTo : 'Any')) : ''],
    ['label' => 'Status', 'value' => $status !== '' ? udcs_claim_status_label($status) : ''],
    ['label' => 'Claim Type', 'value' => $claimType !== '' && strtolower($claimType) !== 'all' ? udcs_claim_asset_label($claimType) : ''],
    ['label' => 'Search', 'value' => $search],
]);

$columns = [
    ['key' => 'claim_id', 'label' => 'Claim Ref', 'weight' => 0.9, 'align' => 'L'],
    ['key' => 'claimant', 'label' => 'Claimant', 'weight' => 1.15, 'align' => 'L'],
    ['key' => 'deceased', 'label' => 'Deceased', 'weight' => 1.15, 'align' => 'L'],
    ['key' => 'status', 'label' => 'Status', 'weight' => 0.95, 'align' => 'L'],
    ['key' => 'assets', 'label' => 'BK Assets', 'weight' => 1.35, 'align' => 'L'],
    ['key' => 'value', 'label' => 'Value Snapshot', 'weight' => 1.45, 'align' => 'L'],
    ['key' => 'settlement', 'label' => 'Settlement', 'weight' => 1.2, 'align' => 'L'],
    ['key' => 'updated', 'label' => 'Last Update', 'weight' => 1.0, 'align' => 'L'],
];

udcs_pdf_render_table($pdf, $columns, $rows, [
    'line_height' => 4.4,
    'header_height' => 7.2,
    'max_lines_per_cell' => 0,
]);

while (ob_get_level() > 0) {
    ob_end_clean();
}

$pdf->Output('claims_review_admin_comprehensive_' . date('Ymd_His') . '.pdf', 'I');
exit();

