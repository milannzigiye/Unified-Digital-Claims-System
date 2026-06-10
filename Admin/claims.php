<?php
// Tags: [ADMIN] [CLAIM] [STATUS]
include '../connect.php';
require_once '../security.php';
secure_session_start();

require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claims_list_ui.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $sessionEmail, 'admin');
if (!$user_data) {
    header('Location: ../login.php');
    exit();
}

$user_email = $sessionEmail;
$admin_name = (string) ($user_data['full_name'] ?? 'Administrator');
$userPhoto = (string) ($user_data['photo'] ?? '');
$photo = $userPhoto !== '' ? '../uploads/' . ltrim($userPhoto, '/\\') : '../Images/logo.png';

bk_claims_ensure_workflow_schema($conn);
udcs_claims_v2_ensure_schema($conn);
bk_backfill_unassigned_claims($conn);

function admin_claim_status_key(?string $status): string
{
    $key = strtolower(trim((string) $status));
    $key = str_replace('_', ' ', $key);
    return preg_replace('/\s+/', ' ', $key) ?? '';
}

function admin_claim_status_label(?string $status): string
{
    return udcs_claim_status_label($status);
}

function admin_claim_status_class(?string $status): string
{
    return match (udcs_claim_status_class($status)) {
        'status-pending' => 'badge-pending',
        'status-review', 'status-warning' => 'badge-review',
        'status-approved' => 'badge-approved',
        'status-rejected' => 'badge-rejected',
        default => 'badge-default',
    };
}

function admin_claim_signal_class(?string $severity): string
{
    return match (strtolower(trim((string) $severity))) {
        'danger', 'error' => 'is-danger',
        'warning' => 'is-warning',
        default => 'is-ok',
    };
}

function admin_claim_page_url(int $page, array $params): string
{
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

function admin_distribution_details_summary(?string $rawDetails): string
{
    $rows = bk_distribution_detail_rows($rawDetails);
    if (empty($rows)) {
        return 'No additional settlement details.';
    }

    $parts = [];
    foreach ($rows as $row) {
        $label = trim((string) ($row['label'] ?? ''));
        $value = trim((string) ($row['value'] ?? ''));
        if ($label === '' || $value === '') {
            continue;
        }
        $parts[] = $label . ': ' . $value;
    }

    return !empty($parts) ? implode(' | ', $parts) : 'No additional settlement details.';
}

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$status = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));
$claimAccountSql = udcs_claim_account_reference_sql('c');

$whereParts = ['1=1'];
$filterTypes = '';
$filterParams = [];
if ($status !== '' && strtolower($status) !== 'all') {
    $whereParts[] = "LOWER(REPLACE(COALESCE(NULLIF(c.status, ''), c.claim_status),'_',' ')) = ?";
    $filterTypes .= 's';
    $filterParams[] = admin_claim_status_key($status);
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
        OR COALESCE(ca.asset_classes, \'\') LIKE ?
        OR COALESCE(ca.asset_terms, \'\') LIKE ?
        OR COALESCE(cp.people_terms, \'\') LIKE ?
        OR COALESCE(dc.document_terms, \'\') LIKE ?
    )';
    $term = '%' . $search . '%';
    $filterTypes .= str_repeat('s', 17);
    for ($i = 0; $i < 17; $i++) {
        $filterParams[] = $term;
    }
}
if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $whereParts[] = 'DATE(c.submitted_at) >= ?';
    $filterTypes .= 's';
    $filterParams[] = $date_from;
}
if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $whereParts[] = 'DATE(c.submitted_at) <= ?';
    $filterTypes .= 's';
    $filterParams[] = $date_to;
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
        COUNT(*) AS document_count,
        MAX(CASE WHEN LOWER(document_type) = 'marriage_certificate' THEN 1 ELSE 0 END) AS has_marriage_certificate,
        GROUP_CONCAT(DISTINCT CONCAT_WS(' ', document_type, ocr_status, legal_review_status, rejection_reason) SEPARATOR ' || ') AS document_terms
    FROM documents
    GROUP BY claim_id
";

$countStmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims c
     INNER JOIN users u ON u.id = COALESCE(NULLIF(c.claimant_user_id, 0), c.claimant_id)
     LEFT JOIN ($assetJoinSql) ca ON ca.claim_id = c.id
     LEFT JOIN ($peopleJoinSql) cp ON cp.claim_id = c.id
     LEFT JOIN ($documentJoinSql) dc ON dc.claim_id = c.id
     WHERE $whereSql"
);
$totalRows = 0;
if ($countStmt && udcs_db_stmt_bind($countStmt, $filterTypes, $filterParams) && mysqli_stmt_execute($countStmt)) {
    $countResult = mysqli_stmt_get_result($countStmt);
    $totalRows = (int) ((mysqli_fetch_assoc($countResult)['total'] ?? 0));
}
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$claimsSql = "
SELECT
    c.*,
    COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name) AS deceased_display_name,
    COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
    DATE_FORMAT(c.submitted_at, '%d %b %Y %H:%i') AS submitted_label,
    u.full_name, u.email,
    ul.full_name AS legal_assignee_name,
    uf.full_name AS finance_assignee_name,
    COALESCE(dc.document_count, 0) AS document_count,
    COALESCE(dc.has_marriage_certificate, 0) AS has_marriage_certificate,
    COALESCE(ca.asset_classes, '') AS asset_classes
FROM claims c
INNER JOIN users u ON u.id = COALESCE(NULLIF(c.claimant_user_id, 0), c.claimant_id)
LEFT JOIN users ul ON ul.id = c.assigned_legal_id
LEFT JOIN users uf ON uf.id = c.assigned_finance_id
LEFT JOIN ($documentJoinSql) dc ON dc.claim_id = c.id
LEFT JOIN ($assetJoinSql) ca ON ca.claim_id = c.id
LEFT JOIN ($peopleJoinSql) cp ON cp.claim_id = c.id
WHERE $whereSql
ORDER BY
    CASE COALESCE(NULLIF(c.status, ''), c.claim_status)
        WHEN 'Pending Legal Review' THEN 1
        WHEN 'Manual Legal Review Required' THEN 2
        WHEN 'More Information Required' THEN 3
        WHEN 'Pending Finance Review' THEN 4
        WHEN 'Returned by Finance' THEN 5
        WHEN 'Approved for Disbursement' THEN 6
        WHEN 'Disbursed' THEN 7
        WHEN 'Closed' THEN 8
        WHEN 'Rejected by Legal' THEN 9
        WHEN 'OCR Validation Failed' THEN 10
        WHEN 'Draft' THEN 11
        WHEN 'Submitted' THEN 12
        WHEN 'pending' THEN 1
        WHEN 'under_review' THEN 2
        WHEN 'under review' THEN 2
        WHEN 'transferred to finance' THEN 4
        WHEN 'approved by finance' THEN 8
        WHEN 'rejected by legal' THEN 9
        WHEN 'rejected by finance' THEN 10
        ELSE 13
    END,
    c.submitted_at DESC
LIMIT ? OFFSET ?
";
$claimsTypes = $filterTypes . 'ii';
$claimsParams = $filterParams;
$claimsParams[] = $limit;
$claimsParams[] = $offset;
$claimsStmt = mysqli_prepare($conn, $claimsSql);
$claimsResult = false;
if ($claimsStmt && udcs_db_stmt_bind($claimsStmt, $claimsTypes, $claimsParams) && mysqli_stmt_execute($claimsStmt)) {
    $claimsResult = mysqli_stmt_get_result($claimsStmt);
}

$stats = [];
$statsStmt = mysqli_prepare($conn, "SELECT COALESCE(NULLIF(status, ''), claim_status) AS effective_status, COUNT(*) AS total FROM claims GROUP BY COALESCE(NULLIF(status, ''), claim_status)");
$statsResult = false;
if ($statsStmt && mysqli_stmt_execute($statsStmt)) {
    $statsResult = mysqli_stmt_get_result($statsStmt);
}
if ($statsResult) {
    while ($row = mysqli_fetch_assoc($statsResult)) {
        $key = admin_claim_status_key((string) ($row['effective_status'] ?? ''));
        $stats[$key] = ($stats[$key] ?? 0) + (int) ($row['total'] ?? 0);
    }
}

$baseParams = $_GET;
unset($baseParams['page']);

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Claims Oversight | Admin Dashboard', '..', $headExtra); ?>
    <style>
        .claims-wrapper {
            padding: 1rem 1.25rem 2rem;
        }

        .claims-content {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.1rem;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
            padding: 1rem 1rem 1.2rem;
        }

        .claims-page-header {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.85rem;
            margin-bottom: 1rem;
        }

        .claims-page-header h2 {
            font-family: var(--app-display-font), var(--app-font), sans-serif;
            color: rgb(var(--bk-text-rgb));
            letter-spacing: 0.01em;
            margin: 0.5rem 0 0;
            font-size: clamp(1.45rem, 2.1vw, 1.9rem);
        }

        .claims-page-header p {
            color: rgb(var(--bk-muted-rgb));
            margin: 0.35rem 0 0;
            max-width: 48rem;
            font-size: 0.92rem;
        }

        .claims-tools {
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }

        .filter-card {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.95rem;
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.05), rgba(var(--bk-surface-rgb), 1));
            box-shadow: var(--shadow-soft);
            padding: 0.9rem;
            margin-bottom: 1rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1.7fr 1fr 1fr 1fr auto;
            gap: 0.7rem;
            align-items: end;
        }

        .field {
            display: grid;
            gap: 0.3rem;
            min-width: 0;
        }

        .field label {
            color: rgb(var(--bk-text-rgb));
            font-weight: 700;
            letter-spacing: 0.01em;
            font-size: 0.78rem;
            text-transform: uppercase;
        }

        .claims-wrapper .ui-input,
        .claims-wrapper .ui-select {
            min-height: 2.8rem;
            font-weight: 500;
            width: 100%;
        }

        .claims-wrapper .ui-input::placeholder {
            color: rgba(var(--bk-muted-rgb), 0.96);
        }

        .filter-actions {
            display: flex;
            align-items: center;
            gap: 0.42rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .claims-total {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            padding: 0.45rem 0.72rem;
            min-height: 2.6rem;
            background: rgba(var(--bk-surface-rgb), 0.95);
            font-size: 0.76rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
        }

        .status-shortcuts {
            margin: 0 0 0.95rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.42rem 0.68rem;
            font-size: 0.76rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
            transition: border-color 0.15s ease, background-color 0.15s ease, transform 0.15s ease;
        }

        .status-chip:hover {
            border-color: rgba(var(--bk-primary-rgb), 0.5);
            background: rgba(var(--bk-primary-rgb), 0.08);
            transform: translateY(-1px);
            color: rgb(var(--bk-text-rgb));
        }

        .status-chip.active {
            color: #fff;
            border-color: rgba(var(--bk-primary-rgb), 0.95);
            background: rgb(var(--bk-primary-rgb));
        }

        .chip-count {
            min-width: 1.45rem;
            min-height: 1.45rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            border: 1px solid rgba(var(--bk-border-rgb), 0.95);
            background: rgba(var(--bk-bg-rgb), 0.78);
            color: rgb(var(--bk-text-rgb));
            padding: 0 0.28rem;
        }

        .status-chip.active .chip-count {
            border-color: rgba(255, 255, 255, 0.5);
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
        }

        .claims-wrapper .claims-table thead th .table-entity-label,
        .claims-wrapper .claims-table thead th .table-entity-label i,
        .claims-wrapper .claims-table thead th,
        .claims-wrapper .claims-table thead th * {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.28rem 0.68rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid transparent;
        }

        .badge-pending {
            color: rgb(var(--bk-warning-rgb));
            border-color: rgba(var(--bk-warning-rgb), 0.38);
            background: rgba(var(--bk-warning-rgb), 0.14);
        }

        .badge-review {
            color: rgb(var(--bk-primary-rgb));
            border-color: rgba(var(--bk-primary-rgb), 0.34);
            background: rgba(var(--bk-primary-rgb), 0.12);
        }

        .badge-approved {
            color: rgb(var(--bk-success-rgb));
            border-color: rgba(var(--bk-success-rgb), 0.34);
            background: rgba(var(--bk-success-rgb), 0.13);
        }

        .badge-rejected {
            color: rgb(var(--bk-danger-rgb));
            border-color: rgba(var(--bk-danger-rgb), 0.34);
            background: rgba(var(--bk-danger-rgb), 0.12);
        }

        .badge-default {
            color: rgb(var(--bk-text-rgb));
            border-color: rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-muted-rgb), 0.12);
        }

        body.bk-role-page.bk-role-admin .status-badge.badge-pending {
            color: rgb(var(--bk-warning-rgb)) !important;
            border-color: rgba(var(--bk-warning-rgb), 0.46) !important;
            background: rgba(var(--bk-warning-rgb), 0.18) !important;
        }

        body.bk-role-page.bk-role-admin .status-badge.badge-review {
            color: rgb(var(--bk-primary-rgb)) !important;
            border-color: rgba(var(--bk-primary-rgb), 0.48) !important;
            background: rgba(var(--bk-primary-rgb), 0.16) !important;
        }

        body.bk-role-page.bk-role-admin .status-badge.badge-approved {
            color: rgb(var(--bk-success-rgb)) !important;
            border-color: rgba(var(--bk-success-rgb), 0.46) !important;
            background: rgba(var(--bk-success-rgb), 0.18) !important;
        }

        body.bk-role-page.bk-role-admin .status-badge.badge-rejected {
            color: rgb(var(--bk-danger-rgb)) !important;
            border-color: rgba(var(--bk-danger-rgb), 0.46) !important;
            background: rgba(var(--bk-danger-rgb), 0.16) !important;
        }

        body.bk-role-page.bk-role-admin .status-badge.badge-default {
            color: rgb(var(--bk-text-rgb)) !important;
            border-color: rgba(var(--bk-border-rgb), 1) !important;
            background: rgba(var(--bk-muted-rgb), 0.16) !important;
        }

        .table-shell {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.95rem;
            overflow: hidden;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
        }

        .table-scroll {
            overflow-x: auto;
        }

        .claims-table {
            width: 100%;
            min-width: 1040px;
            border-collapse: collapse;
        }

        .claims-table th {
            background: rgba(var(--bk-primary-rgb), 0.08);
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: left;
            padding: 0.64rem 0.7rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            color: rgb(var(--bk-text-rgb));
        }

        .claims-table th.is-case,
        .claims-table td.is-case { width: 18rem; }

        .claims-table th.is-assets,
        .claims-table td.is-assets { width: 18rem; }

        .claims-table th.is-signals,
        .claims-table td.is-signals { width: 15rem; max-width: 15rem; }

        .claims-table th.is-status,
        .claims-table td.is-status { width: 12rem; }

        .claims-table th.is-submitted,
        .claims-table td.is-submitted { width: 9rem; }

        .claims-table th.is-actions,
        .claims-table td.is-actions { width: 9.8rem; }

        .claims-table td {
            padding: 0.82rem 0.76rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.85);
            vertical-align: top;
            font-size: 0.84rem;
            color: rgb(var(--bk-text-rgb));
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .claims-table tbody tr:hover td {
            background: rgba(var(--bk-primary-rgb), 0.05);
        }

        .claims-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .claim-id {
            font-family: "Courier New", monospace;
            font-weight: 700;
            color: rgb(var(--bk-primary-rgb));
        }

        .admin-case-stack {
            display: grid;
            gap: 0.34rem;
            min-width: 0;
        }

        .admin-case-top {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            flex-wrap: wrap;
        }

        .admin-case-title {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 800;
            line-height: 1.22;
            color: rgb(var(--bk-text-rgb));
        }

        .admin-case-meta {
            display: grid;
            gap: 0.16rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.76rem;
            line-height: 1.34;
        }

        .admin-case-meta strong {
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .subtle {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.76rem;
            margin-top: 0.18rem;
        }

        .amount {
            font-weight: 700;
            white-space: nowrap;
        }

        .admin-cell-stack {
            display: grid;
            gap: 0.28rem;
            min-width: 0;
        }

        .admin-cell-stack.is-tight {
            gap: 0.2rem;
        }

        .admin-cell-title {
            font-weight: 800;
            color: rgb(var(--bk-text-rgb));
            line-height: 1.25;
        }

        .admin-cell-line {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.76rem;
            line-height: 1.25;
        }

        .admin-kpi-line {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.2rem;
        }

        .admin-inline-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem 0.48rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.75rem;
            line-height: 1.32;
        }

        .admin-inline-meta strong {
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .admin-kv-list {
            display: grid;
            gap: 0.26rem;
            margin-top: 0.18rem;
        }

        .admin-kv-line {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.55rem;
            font-size: 0.76rem;
            line-height: 1.32;
            color: rgb(var(--bk-muted-rgb));
        }

        .admin-kv-line strong {
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .admin-kv-line span:last-child {
            text-align: right;
        }

        .admin-mini-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.28rem;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 0.95);
            background: rgba(var(--bk-bg-rgb), 0.72);
            color: rgb(var(--bk-text-rgb));
            font-size: 0.72rem;
            font-weight: 750;
            padding: 0.22rem 0.5rem;
        }

        .admin-signal-stack {
            display: grid;
            gap: 0.32rem;
        }

        .admin-signal-stack.is-compact {
            gap: 0.26rem;
        }

        .admin-signal {
            display: flex;
            align-items: flex-start;
            gap: 0.34rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.68rem;
            background: rgba(var(--bk-bg-rgb), 0.45);
            color: rgb(var(--bk-text-rgb));
            font-size: 0.75rem;
            font-weight: 750;
            line-height: 1.3;
            padding: 0.42rem 0.5rem;
        }

        .admin-signal.is-compact {
            padding: 0.34rem 0.44rem;
            font-size: 0.72rem;
            line-height: 1.22;
        }

        .admin-signal i {
            margin-top: 0.04rem;
            flex: 0 0 auto;
        }

        .admin-signal.is-danger {
            border-color: rgba(var(--bk-danger-rgb), 0.44);
            background: rgba(var(--bk-danger-rgb), 0.1);
            color: rgb(var(--bk-danger-rgb));
        }

        .admin-signal.is-warning {
            border-color: rgba(var(--bk-warning-rgb), 0.46);
            background: rgba(var(--bk-warning-rgb), 0.12);
            color: #7a4b00;
        }

        .admin-signal.is-ok {
            border-color: rgba(var(--bk-success-rgb), 0.38);
            background: rgba(var(--bk-success-rgb), 0.09);
            color: rgb(var(--bk-success-rgb));
        }

        .admin-hidden-hint {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.71rem;
            font-weight: 700;
            line-height: 1.24;
        }

        .admin-status-stack {
            display: grid;
            gap: 0.28rem;
            align-content: start;
        }

        .admin-status-note {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.75rem;
            line-height: 1.28;
            font-weight: 700;
        }

        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.42rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.1rem;
            min-height: 2.1rem;
            border-radius: 0.62rem;
            font-size: 0.82rem;
            padding: 0.2rem 0.55rem;
            text-decoration: none;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-surface-rgb), 1);
            color: rgb(var(--bk-text-rgb));
            transition: transform 0.14s ease, border-color 0.14s ease, background-color 0.14s ease;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            color: rgb(var(--bk-text-rgb));
        }

        .action-btn.is-view:hover {
            border-color: rgba(var(--bk-primary-rgb), 0.55);
            background: rgba(var(--bk-primary-rgb), 0.12);
        }

        .action-btn.is-view {
            border-color: rgba(var(--bk-primary-rgb), 0.88);
            background: rgb(var(--bk-primary-rgb));
            color: #fff;
        }

        .empty-state {
            text-align: center;
            padding: 2.1rem 1rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.9rem;
        }

        .pager {
            margin-top: 0.95rem;
            display: flex;
            justify-content: center;
            gap: 0.35rem;
            flex-wrap: wrap;
        }

        .pager-link {
            display: inline-flex;
            min-width: 2rem;
            height: 2rem;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.65rem;
            color: rgb(var(--bk-text-rgb));
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0 0.4rem;
            background: rgb(var(--bk-surface-rgb));
        }

        .pager-link:hover {
            background: rgba(var(--bk-primary-rgb), 0.08);
            border-color: rgba(var(--bk-primary-rgb), 0.45);
            color: rgb(var(--bk-text-rgb));
        }

        .pager-link.is-current {
            background-color: rgb(var(--bk-primary-rgb));
            border-color: rgb(var(--bk-primary-rgb));
            color: #fff;
        }

        .pager-note {
            text-align: center;
            margin-top: 0.4rem;
            font-size: 0.78rem;
            color: rgb(var(--bk-muted-rgb));
        }

        .modal { position:fixed; inset:0; z-index:1300; display:none; align-items:center; justify-content:center; padding:1.2rem; background:rgba(12,22,39,.76); }
        .modal.open { display:flex; }
        .modal-panel { width:min(96rem, calc(100vw - 1.5rem)); max-height:95vh; overflow:auto; border:1px solid rgba(var(--bk-border-rgb),1); border-radius:1.3rem; background:rgb(var(--bk-surface-rgb)); box-shadow:0 26px 66px rgba(12,22,39,.28); }
        .modal-head { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.1rem; border-bottom:1px solid rgba(var(--bk-border-rgb),1); background:linear-gradient(180deg, rgba(var(--bk-primary-rgb),.08), rgba(var(--bk-white-rgb),.98)); }
        .modal-body { padding:1.08rem; display:grid; gap:.9rem; background:rgba(var(--bk-bg-rgb),.72); }
        .grid { display:grid; grid-template-columns:1fr; gap:.6rem; }
        .d { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.75rem; padding:.56rem .62rem; background:rgba(var(--bk-bg-rgb),.45); }
        .d .k { margin:0; font-size:.69rem; color:rgb(var(--bk-muted-rgb)); text-transform:uppercase; letter-spacing:.04em; }
        .d .v { margin:.18rem 0 0; font-size:.84rem; font-weight:600; word-break:break-word; }
        pre { margin:0; white-space:pre-wrap; font-family:var(--app-font),Inter,system-ui,sans-serif; font-size:.82rem; }
        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .filter-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 767.98px) {
            .claims-wrapper { padding: 0.85rem 0.75rem 1.3rem; }
            .claims-tools {
                width: 100%;
            }
            .claims-tools .ui-btn {
                width: 100%;
                justify-content: center;
            }
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .filter-actions {
                justify-content: stretch;
            }
            .filter-actions .ui-btn,
            .claims-total {
                width: 100%;
                justify-content: center;
            }
            .modal { padding: .6rem; }
            .modal-panel { width:min(100%, calc(100vw - 1.2rem)); }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="bk-role-page bk-role-admin">
<?php include 'navbar.php'; ?>

<main class="main-content claims-wrapper">
    <div class="claims-content">
        <div class="claims-page-header">
            <div>
                <h2>Claims Review</h2>
                <p>Monitor the full lifecycle and route communications from one queue.</p>
            </div>
            <div class="claims-tools">
                <?php $claimsExportParams = $_GET; unset($claimsExportParams['page']); ?>
                <a class="ui-btn ui-btn-md ui-btn-secondary" href="export_claims_pdf.php?<?php echo bk_e(http_build_query($claimsExportParams)); ?>" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-pdf"></i><span>Export PDF</span>
                </a>
            </div>
        </div>

        <div class="filter-card">
            <form method="GET" class="filter-grid">
                <div class="field">
                    <label for="search">Search</label>
                    <input id="search" name="search" class="ui-input" value="<?php echo bk_e($search); ?>" placeholder="ID, claimant, deceased, destination detail">
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="ui-select">
                        <option value="">All statuses</option>
                        <?php foreach ([
                            'Pending Legal Review',
                            'Manual Legal Review Required',
                            'More Information Required',
                            'Pending Finance Review',
                            'Returned by Finance',
                            'Approved for Disbursement',
                            'Closed',
                            'Rejected by Legal',
                        ] as $statusOption): ?>
                            <option value="<?php echo bk_e($statusOption); ?>" <?php echo admin_claim_status_key($status) === admin_claim_status_key($statusOption) ? 'selected' : ''; ?>>
                                <?php echo bk_e(admin_claim_status_label($statusOption)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="date_from">From Date</label>
                    <input id="date_from" name="date_from" type="date" class="ui-input" value="<?php echo bk_e($date_from); ?>">
                </div>
                <div class="field">
                    <label for="date_to">To Date</label>
                    <input id="date_to" name="date_to" type="date" class="ui-input" value="<?php echo bk_e($date_to); ?>">
                </div>
                <div class="filter-actions">
                    <button class="ui-btn ui-btn-md ui-btn-primary" type="submit">
                        <i class="bi bi-funnel"></i><span>Apply</span>
                    </button>
                    <a class="ui-btn ui-btn-md ui-btn-secondary" href="claims.php">Reset</a>
                    <span class="claims-total"><?php echo number_format($totalRows); ?> claims</span>
                </div>
            </form>
        </div>

        <div class="status-shortcuts">
            <?php
            $chips = [
                '' => ['All', (int) array_sum($stats)],
                'Pending Legal Review' => ['Pending Legal', (int) ($stats[admin_claim_status_key('Pending Legal Review')] ?? $stats['pending'] ?? 0)],
                'Manual Legal Review Required' => ['Manual Legal', (int) ($stats[admin_claim_status_key('Manual Legal Review Required')] ?? 0)],
                'More Information Required' => ['More Info', (int) ($stats[admin_claim_status_key('More Information Required')] ?? 0)],
                'Pending Finance Review' => ['Pending Finance', (int) ($stats[admin_claim_status_key('Pending Finance Review')] ?? $stats['transferred to finance'] ?? 0)],
                'Returned by Finance' => ['Returned by Finance', (int) ($stats[admin_claim_status_key('Returned by Finance')] ?? $stats['rejected by finance'] ?? 0)],
                'Closed' => ['Closed', (int) ($stats[admin_claim_status_key('Closed')] ?? $stats['approved by finance'] ?? 0)],
                'Rejected by Legal' => ['Rejected by Legal', (int) ($stats[admin_claim_status_key('Rejected by Legal')] ?? $stats['rejected by legal'] ?? 0)],
            ];
            foreach ($chips as $chipStatus => $chipConfig):
                [$chipLabel, $chipCount] = $chipConfig;
                $params = $baseParams;
                if ($chipStatus === '') {
                    unset($params['status']);
                } else {
                    $params['status'] = $chipStatus;
                }
                $active = admin_claim_status_key($status) === admin_claim_status_key($chipStatus);
                ?>
                <a href="?<?php echo bk_e(http_build_query($params)); ?>" class="status-chip<?php echo $active ? ' active' : ''; ?>">
                    <?php echo bk_e($chipLabel); ?>
                    <span class="chip-count"><?php echo number_format($chipCount); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="table-shell">
            <div class="table-scroll bk-table-shell">
                <table class="claims-table" data-udcs-expand-group data-udcs-expand-single="true">
                    <thead>
                    <tr>
                        <th class="is-case"><span class="table-entity-label"><i class="bi bi-folder2-open"></i>Case</span></th>
                        <th class="is-assets"><span class="table-entity-label"><i class="bi bi-bank"></i>BK Assets</span></th>
                        <th class="is-signals"><span class="table-entity-label"><i class="bi bi-shield-exclamation"></i>Review Signals</span></th>
                        <th class="is-status"><span class="table-entity-label"><i class="bi bi-check2-circle"></i>Status</span></th>
                        <th class="is-submitted"><span class="table-entity-label"><i class="bi bi-calendar2-week"></i>Date</span></th>
                        <th class="is-actions"><span class="table-entity-label"><i class="bi bi-sliders"></i>Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($claimsResult && mysqli_num_rows($claimsResult) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($claimsResult)): ?>
                            <?php
                            $reviewContract = udcs_claim_fetch_review_contract($conn, (int) ($row['id'] ?? 0), $row);
                            if (!is_array($reviewContract)) {
                                $reviewContract = [];
                            }
                            $peopleSummary = (array) ($reviewContract['people']['summary'] ?? []);
                            $assetSummaryData = (array) ($reviewContract['assets']['summary'] ?? []);
                            $documentSummary = (array) ($reviewContract['documents']['summary'] ?? []);
                            $reviewFlags = (array) ($reviewContract['review']['flags'] ?? []);
                            $payoutSummary = (array) ($reviewContract['payout'] ?? []);
                            $claimCode = 'CL-' . str_pad((string) ($row['id'] ?? ''), 6, '0', STR_PAD_LEFT);
                            $statusValue = (string) ($reviewContract['status']['key'] ?? ($row['effective_status'] ?? $row['claim_status'] ?? ''));
                            $statusLabel = (string) ($reviewContract['status']['label'] ?? admin_claim_status_label($statusValue));
                            $statusClass = admin_claim_status_class($statusValue);
                            $claimantName = (string) (($peopleSummary['claimant_name'] ?? '') !== '' ? $peopleSummary['claimant_name'] : ($row['full_name'] ?? 'Unknown'));
                            $deceasedName = (string) (($peopleSummary['deceased_name'] ?? '') !== '' ? $peopleSummary['deceased_name'] : ($row['deceased_display_name'] ?? 'Not recorded'));
                            $relationshipLabel = udcs_claim_relationship_label((string) ($row['relationship'] ?? ''));
                            $assetSummary = (string) ($assetSummaryData['label'] ?? udcs_claim_asset_summary_label((string) ($row['asset_classes'] ?? ''), (string) ($row['claim_type'] ?? '')));
                            $estimatedTotalLabel = (string) (($assetSummaryData['estimated_total_label'] ?? '') !== ''
                                ? $assetSummaryData['estimated_total_label']
                                : ($reviewContract['summary']['claimant_value_label'] ?? 'Not declared'));
                            $verifiedTotalLabel = (string) (($assetSummaryData['verified_total_label'] ?? '') !== ''
                                ? $assetSummaryData['verified_total_label']
                                : ($reviewContract['summary']['finance_value_label'] ?? 'Not assessed yet'));
                            $payoutLabel = (string) ($payoutSummary['preferred_label'] ?? 'Not recorded');
                            $destinationSummary = bk_claim_destination_summary(
                                bk_claim_account_reference($row),
                                (string) ($row['distribution_method'] ?? ''),
                                (string) ($row['distribution_details'] ?? '')
                            );
                            $reviewFlagSummary = !empty($reviewFlags)
                                ? implode(' | ', array_map(
                                    static fn(array $flag): string => trim((string) ($flag['label'] ?? 'Review signal')),
                                    $reviewFlags
                                ))
                                : 'No automatic blockers detected.';
                            $expandPanelId = 'admin-claim-expand-' . (int) ($row['id'] ?? 0);
                            $criticalFlags = 0;
                            $warningFlags = 0;
                            foreach ($reviewFlags as $flag) {
                                $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
                                if ($severity === 'danger') {
                                    $criticalFlags++;
                                } elseif ($severity === 'warning') {
                                    $warningFlags++;
                                }
                            }
                            $visibleFlags = array_slice($reviewFlags, 0, 2);
                            $topFlag = !empty($visibleFlags) ? $visibleFlags[0] : null;
                            $statusSupportLabel = trim((string) ($row['manual_review_reason'] ?? '')) !== ''
                                ? ('Flag: ' . (string) ($row['manual_review_reason'] ?? ''))
                                : ((int) ($documentSummary['count'] ?? 0) > 0
                                    ? number_format((int) ($documentSummary['count'] ?? 0)) . ' file(s) attached'
                                    : 'No supporting files yet');
                            ?>
                            <tr>
                                <td class="is-case">
                                    <div class="admin-case-stack">
                                        <div class="admin-case-top">
                                            <span class="claim-id"><?php echo bk_e($claimCode); ?></span>
                                            <?php if ((bool) ($reviewContract['status']['is_legacy'] ?? false)): ?>
                                                <span class="admin-mini-pill">Legacy</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="admin-case-title"><?php echo bk_e($claimantName); ?></p>
                                        <div class="admin-case-meta">
                                            <span><strong>Deceased:</strong> <?php echo bk_e($deceasedName); ?></span>
                                            <span><strong>Email:</strong> <?php echo bk_e((string) ($row['email'] ?? 'Not recorded')); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="is-assets">
                                    <div class="admin-cell-stack is-tight">
                                        <div class="admin-cell-title"><?php echo bk_e($assetSummary); ?></div>
                                        <div class="admin-kv-list">
                                            <div class="admin-kv-line"><strong>Estimate</strong><span><?php echo bk_e($estimatedTotalLabel); ?></span></div>
                                            <div class="admin-kv-line"><strong>Verified</strong><span><?php echo bk_e($verifiedTotalLabel); ?></span></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="is-signals">
                                    <div class="admin-signal-stack is-compact">
                                        <div class="admin-mini-pill"><?php echo number_format($criticalFlags); ?> critical / <?php echo number_format($warningFlags); ?> warning</div>
                                        <?php if ($topFlag !== null): ?>
                                                <?php
                                                $flagClass = admin_claim_signal_class((string) ($topFlag['severity'] ?? ''));
                                                $flagIcon = $flagClass === 'is-danger' ? 'bi-x-octagon-fill' : ($flagClass === 'is-warning' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
                                                ?>
                                                <div class="admin-signal is-compact <?php echo bk_e($flagClass); ?>">
                                                    <i class="bi <?php echo bk_e($flagIcon); ?>"></i>
                                                    <span><?php echo bk_e((string) ($topFlag['label'] ?? 'Review signal')); ?></span>
                                                </div>
                                            <?php if (count($reviewFlags) > 1): ?>
                                                <div class="admin-hidden-hint">Open claim to view <?php echo number_format(count($reviewFlags) - 1); ?> more signal(s).</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="admin-signal is-compact is-ok"><i class="bi bi-check-circle-fill"></i><span>No automatic blockers detected.</span></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="is-status">
                                    <div class="admin-status-stack">
                                        <span class="status-badge <?php echo bk_e($statusClass); ?>"><?php echo bk_e($statusLabel); ?></span>
                                        <div class="admin-status-note"><?php echo bk_e($statusSupportLabel); ?></div>
                                    </div>
                                </td>
                                <td class="is-submitted"><?php echo bk_e((string) ($row['submitted_label'] ?? '')); ?></td>
                                <td class="is-actions">
                                    <div class="actions">
                                        <?php udcs_claims_list_render_expand_button($expandPanelId, ['label' => 'More']); ?>
                                        <button
                                            type="button"
                                            class="action-btn is-view"
                                            data-open="claim"
                                            data-claim-id="<?php echo (int) ($row['id'] ?? 0); ?>"
                                            title="View details"
                                        ><i class="bi bi-eye"></i><span>Open</span></button>
                                    </div>
                                </td>
                            </tr>
                            <?php
                            udcs_claims_list_render_expand_row($expandPanelId, 6, [
                                [
                                    'title' => 'Case Context',
                                    'lines' => [
                                        ['label' => 'Claim code', 'value' => $claimCode],
                                        ['label' => 'Claimant', 'value' => $claimantName],
                                        ['label' => 'Deceased', 'value' => $deceasedName],
                                        ['label' => 'Date', 'value' => (string) ($row['submitted_label'] ?? 'Not recorded')],
                                    ],
                                ],
                                [
                                    'title' => 'Family Disclosure',
                                    'lines' => [
                                        ['label' => 'Relationship path', 'value' => $relationshipLabel],
                                        ['label' => 'Children declared', 'value' => number_format((int) ($peopleSummary['child_count'] ?? 0))],
                                        ['label' => 'Co-heirs declared', 'value' => number_format((int) ($peopleSummary['co_heir_count'] ?? 0))],
                                        ['label' => 'Marital status', 'value' => ucwords(strtolower(str_replace('_', ' ', (string) ($row['marital_status'] ?? 'Not specified'))))],
                                        ['label' => 'Children status', 'value' => ucwords(strtolower(str_replace('_', ' ', (string) ($row['children_status'] ?? 'Not specified'))))],
                                    ],
                                ],
                                [
                                    'title' => 'Review and Documents',
                                    'lines' => [
                                        ['label' => 'Signals', 'value' => $reviewFlagSummary],
                                        ['label' => 'OCR passed', 'value' => number_format((int) ($documentSummary['ocr_passed_count'] ?? 0)) . '/' . number_format((int) ($documentSummary['count'] ?? 0))],
                                        ['label' => 'Legal rejected docs', 'value' => number_format((int) ($documentSummary['legal_rejected_count'] ?? 0))],
                                        ['label' => 'Manual review reason', 'value' => (string) (($row['manual_review_reason'] ?? '') !== '' ? $row['manual_review_reason'] : 'Not flagged')],
                                    ],
                                ],
                                [
                                    'title' => 'Settlement and Routing',
                                    'lines' => [
                                        ['label' => 'Preferred settlement', 'value' => $payoutLabel],
                                        ['label' => 'Destination summary', 'value' => $destinationSummary],
                                        ['label' => 'Legal assignee', 'value' => (string) (($row['legal_assignee_name'] ?? '') !== '' ? $row['legal_assignee_name'] : 'Unassigned')],
                                        ['label' => 'Finance assignee', 'value' => (string) (($row['finance_assignee_name'] ?? '') !== '' ? $row['finance_assignee_name'] : 'Unassigned')],
                                    ],
                                ],
                            ]);
                            ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="bi bi-inbox"></i>
                                No claims found for this filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="Page navigation">
                <?php if ($page > 1): ?>
                    <a class="pager-link" href="<?php echo bk_e(admin_claim_page_url($page - 1, $baseParams)); ?>"><i class="bi bi-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a class="pager-link<?php echo $i === $page ? ' is-current' : ''; ?>" href="<?php echo bk_e(admin_claim_page_url($i, $baseParams)); ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="pager-link" href="<?php echo bk_e(admin_claim_page_url($page + 1, $baseParams)); ?>"><i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </nav>
            <p class="pager-note">
                Page <?php echo $page; ?> of <?php echo $totalPages; ?>.
                Showing <?php echo $totalRows > 0 ? min($limit, $totalRows - $offset) : 0; ?> of <?php echo number_format($totalRows); ?> claims.
            </p>
        <?php endif; ?>
        </div>
</main>

<section id="claimModal" class="modal" aria-hidden="true">
    <article class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="claimModalTitle">
        <header class="modal-head">
            <h2 id="claimModalTitle">Claim Review</h2>
            <button type="button" id="closeModal" class="ui-btn ui-btn-sm ui-btn-ghost"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="modal-body" id="claimModalContent">
            <div class="empty-state"><i class="bi bi-hourglass-split"></i>Loading claim details...</div>
        </div>
    </article>
</section>

<script>
(() => {
    const modal = document.getElementById('claimModal');
    const closeBtn = document.getElementById('closeModal');
    const modalContent = document.getElementById('claimModalContent');
    if (!modal || !closeBtn || !modalContent) {
        return;
    }

    const openModal = async (claimId) => {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        modalContent.innerHTML = '<div class="empty-state"><i class="bi bi-hourglass-split"></i>Loading claim details...</div>';

        try {
            const response = await fetch(`load_claim_details.php?id=${encodeURIComponent(claimId)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error('Unable to load this claim.');
            }
            modalContent.innerHTML = await response.text();
        } catch (error) {
            modalContent.innerHTML = '<div class="empty-state"><i class="bi bi-exclamation-circle"></i>Claim details could not be loaded right now.</div>';
        }
    };

    document.querySelectorAll('[data-open="claim"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const claimId = btn.dataset.claimId || '';
            if (claimId !== '') {
                openModal(claimId);
            }
        });
    });

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('open')) closeModal(); });
})();
</script>
<?php udcs_claims_list_render_assets(); ?>
</body>
</html>


