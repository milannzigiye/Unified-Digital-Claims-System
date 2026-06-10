<?php
include '../connect.php';
require_once '../security.php';
secure_session_start();

require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claims_list_ui.php';
// Tags: [AUDIT] [FILTER] [PAGINATION]
// [AUDIT] Admin activity trail.

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
$admin_id = (int) ($user_data['id'] ?? 0);
$admin_name = (string) ($user_data['full_name'] ?? 'Administrator');
$userPhoto = (string) ($user_data['photo'] ?? '');
$photo = $userPhoto !== '' ? '../uploads/' . ltrim($userPhoto, '/\\') : '../Images/logo.png';

// [AUDIT] Ensure audit table exists.
bk_activity_ensure_schema($conn);
udcs_claims_v2_ensure_schema($conn);
bk_activity_backfill_account_creation_events($conn, 500);
$claimAccountSql = udcs_claim_account_reference_sql('c');

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$filterRole = trim((string) ($_GET['role'] ?? ''));
$filterAction = trim((string) ($_GET['action'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

// [FILTER] Build filter SQL.
$whereParts = ['1=1'];
$filterTypes = '';
$filterParams = [];
if ($filterRole !== '') {
    $whereParts[] = 'a.actor_role = ?';
    $filterTypes .= 's';
    $filterParams[] = $filterRole;
}
if ($filterAction !== '') {
    $whereParts[] = '(a.action_key LIKE ? OR a.action_label LIKE ?)';
    $term = '%' . $filterAction . '%';
    $filterTypes .= 'ss';
    $filterParams[] = $term;
    $filterParams[] = $term;
}
if ($search !== '') {
    $whereParts[] = '(a.details LIKE ? OR a.meta_json LIKE ? OR a.action_label LIKE ? OR u.full_name LIKE ? OR CAST(a.claim_id AS CHAR) LIKE ?)';
    $term = '%' . $search . '%';
    $filterTypes .= 'sssss';
    $filterParams[] = $term;
    $filterParams[] = $term;
    $filterParams[] = $term;
    $filterParams[] = $term;
    $filterParams[] = $term;
}
if ($dateFrom !== '') {
    $whereParts[] = 'DATE(a.created_at) >= ?';
    $filterTypes .= 's';
    $filterParams[] = $dateFrom;
}
if ($dateTo !== '') {
    $whereParts[] = 'DATE(a.created_at) <= ?';
    $filterTypes .= 's';
    $filterParams[] = $dateTo;
}
$whereSql = implode(' AND ', $whereParts);

// [PAGINATION] Count rows for current filters.
$countStmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total FROM activity_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE $whereSql"
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

// [AUDIT] Load newest events first.
$logsSql = "
SELECT
    a.id,
    a.actor_id,
    a.actor_role,
    a.claim_id,
    a.action_key,
    a.action_label,
    a.details,
    a.meta_json,
    DATE_FORMAT(a.created_at, '%d %b %Y %H:%i') AS created_label,
    u.full_name AS actor_name,
    COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
    COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name) AS deceased_name,
    c.relationship,
    c.claim_type,
    COALESCE(ca.asset_classes, '') AS asset_classes,
    c.claim_amount,
    {$claimAccountSql} AS account_number,
    {$claimAccountSql} AS accout_number,
    c.distribution_method,
    c.distribution_details,
    c.comment AS claim_comment,
    COALESCE(dc.has_marriage_certificate, 0) AS has_marriage_certificate,
    DATE_FORMAT(c.submitted_at, '%d %b %Y %H:%i') AS claim_submitted_label,
    cu.full_name AS claimant_name,
    cu.email AS claimant_email
FROM activity_logs a
LEFT JOIN users u ON u.id = a.actor_id
LEFT JOIN claims c ON c.id = a.claim_id
LEFT JOIN users cu ON cu.id = COALESCE(NULLIF(c.claimant_user_id, 0), c.claimant_id)
LEFT JOIN (
    SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
    FROM claim_assets
    GROUP BY claim_id
) ca ON ca.claim_id = c.id
LEFT JOIN (
    SELECT claim_id, MAX(CASE WHEN LOWER(document_type) = 'marriage_certificate' THEN 1 ELSE 0 END) AS has_marriage_certificate
    FROM documents
    GROUP BY claim_id
) dc ON dc.claim_id = c.id
WHERE $whereSql
ORDER BY a.created_at DESC
LIMIT ? OFFSET ?
";
$logsTypes = $filterTypes . 'ii';
$logsParams = $filterParams;
$logsParams[] = $limit;
$logsParams[] = $offset;
$logsStmt = mysqli_prepare($conn, $logsSql);
$logsResult = false;
if ($logsStmt && udcs_db_stmt_bind($logsStmt, $logsTypes, $logsParams) && mysqli_stmt_execute($logsStmt)) {
    $logsResult = mysqli_stmt_get_result($logsStmt);
}

function admin_activity_distribution_details_summary(?string $rawDetails): string
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

function admin_activity_humanize_text(?string $raw): string
{
    $text = trim((string) $raw);
    if ($text === '') {
        return '-';
    }

    $text = preg_replace_callback(
        '/\b[a-z0-9]+(?:_[a-z0-9]+)+\b/i',
        static function (array $matches): string {
            $token = strtolower((string) ($matches[0] ?? ''));
            return ucwords(str_replace('_', ' ', $token));
        },
        $text
    ) ?? $text;

    // Hide implementation wording from UI labels.
    $text = preg_replace('/\bbackfill\b\s*:?\s*/i', '', $text) ?? $text;
    $text = str_ireplace('backfilled', 'assigned', $text);

    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text) !== '' ? trim($text) : '-';
}

function admin_activity_action_display(?string $actionLabel, ?string $actionKey): string
{
    $label = trim((string) $actionLabel);
    if ($label !== '') {
        return admin_activity_humanize_text($label);
    }

    $key = trim((string) $actionKey);
    if ($key !== '') {
        return admin_activity_humanize_text($key);
    }

    return 'Action';
}

function admin_activity_meta_summary(?string $rawMeta): string
{
    $text = trim((string) $rawMeta);
    if ($text === '') {
        return '';
    }

    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        return admin_activity_humanize_text($text);
    }

    $parts = [];
    foreach ($decoded as $key => $value) {
        $normalizedKey = strtolower(trim((string) $key));
        if ($normalizedKey === 'backfilled') {
            continue;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        $label = ucwords(str_replace('_', ' ', trim((string) $key)));
        $cleanValue = admin_activity_humanize_text((string) $value);
        if ($label === '' || $cleanValue === '-') {
            continue;
        }
        $parts[] = $label . ': ' . $cleanValue;
    }

    if (empty($parts)) {
        return admin_activity_humanize_text($text);
    }

    return implode(' | ', $parts);
}

// [AUDIT] Role totals for dashboard cards.
$roleCount = [
    'claimant' => 0,
    'legal' => 0,
    'finance' => 0,
    'admin' => 0,
    'system' => 0,
];
$countByRoleStmt = mysqli_prepare(
    $conn,
    "SELECT COALESCE(actor_role, 'system') AS role_name, COUNT(*) AS total FROM activity_logs GROUP BY COALESCE(actor_role, 'system')"
);
$countByRoleResult = false;
if ($countByRoleStmt && mysqli_stmt_execute($countByRoleStmt)) {
    $countByRoleResult = mysqli_stmt_get_result($countByRoleStmt);
}
if ($countByRoleResult) {
    while ($row = mysqli_fetch_assoc($countByRoleResult)) {
        $key = strtolower(trim((string) ($row['role_name'] ?? 'system')));
        if (!isset($roleCount[$key])) {
            $roleCount[$key] = 0;
        }
        $roleCount[$key] = (int) ($row['total'] ?? 0);
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
    <?php render_head('Activity Trail | Admin', '..', $headExtra); ?>
    <style>
        .wrap { padding: 1rem 1.25rem 2rem; }
        .hero, .panel { border: 1px solid rgba(var(--bk-border-rgb),1); border-radius: 1rem; background: rgb(var(--bk-surface-rgb)); box-shadow: var(--shadow-soft); }
        .hero { display:flex; justify-content:space-between; align-items:flex-start; gap:.85rem; flex-wrap:wrap; padding: 1.2rem; background: linear-gradient(135deg, rgba(var(--bk-primary-rgb),.16), rgba(var(--bk-primary-rgb),.03) 50%, rgba(var(--bk-surface-rgb),.98)); }
        .hero-copy { min-width:0; flex:1 1 420px; }
        .hero-actions { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
        .tag { display:inline-flex; border-radius:999px; border:1px solid rgba(var(--bk-primary-rgb),.3); background:rgba(var(--bk-primary-rgb),.12); color:rgb(var(--bk-primary-rgb)); font-size:.72rem; font-weight:700; padding:.22rem .6rem; text-transform:uppercase; letter-spacing:.05em; }
        .hero h1 { margin:.45rem 0 0; font-size:clamp(1.5rem,2.3vw,2.1rem); font-family:var(--app-display-font),var(--app-font),sans-serif; }
        .hero p { margin:.45rem 0 0; color:rgb(var(--bk-muted-rgb)); font-size:.92rem; }
        .stats { margin-top: .95rem; display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:.75rem; }
        .card { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.9rem; background:rgb(var(--bk-surface-rgb)); padding:.8rem .9rem; box-shadow:var(--shadow-soft); }
        .card .k { margin:0; font-size:.72rem; text-transform:uppercase; color:rgb(var(--bk-muted-rgb)); letter-spacing:.05em; }
        .card .v { margin:.2rem 0 0; font-size:1.35rem; font-weight:700; color:rgb(var(--bk-text-rgb)); }
        .panel { margin-top: .95rem; overflow:hidden; }
        .panel-head { display:flex; justify-content:space-between; align-items:center; gap:.6rem; padding:.8rem .9rem; border-bottom:1px solid rgba(var(--bk-border-rgb),1); background:rgba(var(--bk-primary-rgb),.06); }
        .panel-head-actions { display:flex; align-items:center; gap:.45rem; flex-wrap:wrap; }
        .panel-title { margin:0; font-size:1rem; font-weight:700; }
        .panel-sub { margin:.1rem 0 0; font-size:.8rem; color:rgb(var(--bk-muted-rgb)); }
        .filters { display:grid; grid-template-columns:1.35fr 1fr 1fr 1fr 1fr auto; gap:.7rem; padding:.85rem .9rem; border-bottom:1px solid rgba(var(--bk-border-rgb),1); background:rgba(var(--bk-bg-rgb),.45); align-items:end; }
        .table-wrap { padding:.75rem .9rem .9rem; }
        .table-scroll {
            overflow-x:auto;
            border:1px solid rgba(var(--bk-border-rgb),1);
            border-radius:1rem;
            background:rgb(var(--bk-surface-rgb));
            box-shadow:var(--shadow-soft);
        }
        .activity-table {
            width:100%;
            min-width:1040px;
            border-collapse:separate;
            border-spacing:0;
            table-layout:fixed;
            background:rgb(var(--bk-surface-rgb));
        }
        .activity-table thead th {
            position:sticky;
            top:0;
            z-index:2;
            background:rgb(var(--bk-primary-rgb)) !important;
            font-size:.72rem;
            text-transform:uppercase;
            letter-spacing:.05em;
            text-align:left;
            padding:.68rem .7rem;
            border-bottom:1px solid rgba(var(--bk-primary-rgb),0.16) !important;
            color:#ffffff !important;
            white-space:nowrap;
        }
        .activity-table thead th .table-entity-label,
        .activity-table thead th .table-entity-label i {
            color:#ffffff !important;
            opacity:1 !important;
        }
        .activity-table tbody td {
            padding:.72rem .7rem;
            border-bottom:1px solid rgba(var(--bk-border-rgb),0.92) !important;
            font-size:.84rem;
            vertical-align:top;
            word-break:break-word;
            overflow-wrap:anywhere;
            color:rgb(var(--bk-text-rgb));
            background:rgb(var(--bk-surface-rgb)) !important;
        }
        .activity-table tbody tr:nth-child(odd) td { background:rgba(var(--bk-primary-rgb),.032) !important; }
        .activity-table tbody tr:nth-child(even) td { background:rgb(var(--bk-surface-rgb)) !important; }
        .activity-table tbody tr:hover td { background:rgba(var(--bk-primary-rgb),.08) !important; }
        .activity-table th:nth-child(1), .activity-table td:nth-child(1) { width:20%; }
        .activity-table th:nth-child(2), .activity-table td:nth-child(2) { width:10%; }
        .activity-table th:nth-child(3), .activity-table td:nth-child(3) { width:10%; text-align:center; }
        .activity-table th:nth-child(4), .activity-table td:nth-child(4) { width:15%; }
        .activity-table th:nth-child(5), .activity-table td:nth-child(5) { width:30%; }
        .activity-table th:nth-child(6), .activity-table td:nth-child(6) { width:15%; text-align:right; }
        .cell-main { margin:0; font-weight:600; line-height:1.25rem; }
        .cell-sub { margin:.1rem 0 0; font-size:.74rem; color:rgb(var(--bk-muted-rgb)); line-height:1.05rem; }
        .time-cell { white-space:nowrap; }
        .actor-cell, .action-cell { min-width:0; }
        .claim-code {
            display:inline-flex;
            align-items:center;
            border-radius:.55rem;
            border:1px solid rgba(var(--bk-border-rgb),1);
            background:rgba(var(--bk-white-rgb),1);
            padding:.18rem .42rem;
            font-size:.74rem;
            font-weight:700;
            letter-spacing:.02em;
            width:fit-content;
        }
        .claim-preview-status {
            display:inline-flex;
            width:fit-content;
            border-radius:999px;
            border:1px solid rgba(var(--bk-border-rgb),1);
            padding:.2rem .48rem;
            font-size:.68rem;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.035em;
            background:rgba(var(--bk-white-rgb),1);
            color:rgb(var(--bk-text-rgb));
        }
        .claim-preview-status.status-pending { color:rgb(var(--bk-primary-rgb)); border-color:rgba(var(--bk-primary-rgb),.34); background:rgba(var(--bk-primary-rgb),.1); }
        .claim-preview-status.status-review,
        .claim-preview-status.status-warning { color:#7a4b00; border-color:rgba(var(--bk-warning-rgb),.42); background:rgba(var(--bk-warning-rgb),.13); }
        .claim-preview-status.status-approved { color:rgb(var(--bk-success-rgb)); border-color:rgba(var(--bk-success-rgb),.38); background:rgba(var(--bk-success-rgb),.1); }
        .claim-preview-status.status-rejected { color:rgb(var(--bk-danger-rgb)); border-color:rgba(var(--bk-danger-rgb),.38); background:rgba(var(--bk-danger-rgb),.1); }
        .details { white-space:pre-line; line-height:1.25rem; }
        .pill { display:inline-flex; border-radius:999px; font-size:.72rem; font-weight:700; padding:.24rem .5rem; border:1px solid rgba(var(--bk-border-rgb),1); }
        .role-claimant { color:rgb(var(--bk-primary-rgb)); background:rgba(var(--bk-primary-rgb),.12); border-color:rgba(var(--bk-primary-rgb),.35); }
        .role-legal { color:rgb(var(--bk-warning-rgb)); background:rgba(var(--bk-warning-rgb),.15); border-color:rgba(var(--bk-warning-rgb),.35); }
        .role-finance { color:rgb(var(--bk-success-rgb)); background:rgba(var(--bk-success-rgb),.15); border-color:rgba(var(--bk-success-rgb),.35); }
        .role-admin { color:rgb(var(--bk-danger-rgb)); background:rgba(var(--bk-danger-rgb),.14); border-color:rgba(var(--bk-danger-rgb),.35); }
        .role-system { color:rgb(var(--bk-text-rgb)); background:rgba(var(--bk-muted-rgb),.18); border-color:rgba(var(--bk-muted-rgb),.32); }
        .muted { font-size:.76rem; color:rgb(var(--bk-muted-rgb)); }
        .activity-summary-cell { min-width:0; }
        .activity-summary-stack { display:grid; gap:.34rem; min-width:0; }
        .activity-summary-head {
            display:flex;
            flex-wrap:wrap;
            align-items:center;
            gap:.38rem;
            min-width:0;
        }
        .activity-summary-line {
            font-size:.83rem;
            line-height:1.42;
            color:rgb(var(--bk-text-rgb));
            overflow:hidden;
            text-overflow:ellipsis;
            display:-webkit-box;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
        }
        .activity-summary-note {
            font-size:.74rem;
            color:rgb(var(--bk-muted-rgb));
            line-height:1.25;
        }
        .activity-action-chip {
            display:inline-flex;
            align-items:center;
            gap:.28rem;
            border-radius:999px;
            border:1px solid rgba(var(--bk-primary-rgb),.16);
            background:rgba(var(--bk-primary-rgb),.08);
            color:rgb(var(--bk-primary-rgb));
            padding:.2rem .48rem;
            font-size:.68rem;
            font-weight:700;
            line-height:1.1;
            max-width:100%;
        }
        .activity-action-chip i {
            font-size:.72rem;
            flex:0 0 auto;
        }
        .activity-actions {
            display:flex;
            justify-content:flex-end;
            align-items:flex-start;
        }
        .activity-actions .udcs-expand-toggle {
            min-width:auto;
            padding:.42rem .62rem;
        }
        .activity-expand-actions {
            display:flex;
            flex-wrap:wrap;
            gap:.55rem;
            margin-top:.7rem;
        }
        .activity-expand-actions .claim-view {
            margin-top:0;
        }
        .activity-expand-copy {
            white-space:pre-wrap;
            line-height:1.55;
        }
        .claim-view {
            display:inline-flex;
            align-items:center;
            gap:.3rem;
            width:fit-content;
            border:1px solid rgba(var(--bk-primary-rgb),.18);
            border-radius:.7rem;
            padding:.34rem .58rem;
            background:rgb(var(--bk-surface-rgb));
            color:rgb(var(--bk-primary-rgb));
            font-size:.72rem;
            font-weight:700;
            text-decoration:none;
            cursor:pointer;
            box-shadow:0 4px 12px rgba(8,20,44,.06);
        }
        .claim-view:hover {
            background:rgba(var(--bk-primary-rgb),.08);
            color:rgb(var(--bk-primary-rgb));
        }
        .modal { position:fixed; inset:0; z-index:1300; display:none; align-items:center; justify-content:center; padding:1rem; background:rgba(3,78,162,.86); }
        .modal.open { display:flex; }
        .modal-panel { width:min(44rem,100%); max-height:92vh; overflow:auto; border:1px solid rgba(var(--bk-border-rgb),1); border-radius:1rem; background:rgb(var(--bk-surface-rgb)); box-shadow:var(--shadow); }
        .modal-head { display:flex; justify-content:space-between; align-items:center; padding:.8rem .9rem; border-bottom:1px solid rgba(var(--bk-border-rgb),1); background:rgba(var(--bk-primary-rgb),.08); }
        .modal-body { padding:.9rem; display:grid; gap:.7rem; }
        .claim-grid { display:grid; grid-template-columns:1fr; gap:.6rem; }
        .claim-detail { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.75rem; padding:.56rem .62rem; background:rgba(var(--bk-bg-rgb),.45); }
        .claim-detail .k { margin:0; font-size:.69rem; color:rgb(var(--bk-muted-rgb)); text-transform:uppercase; letter-spacing:.04em; }
        .claim-detail .v { margin:.18rem 0 0; font-size:.84rem; font-weight:600; word-break:break-word; }
        .claim-comment { white-space:pre-wrap; font-family:var(--app-font),Inter,system-ui,sans-serif; font-size:.82rem; margin:0; }
        .pager { margin-top:.85rem; display:flex; justify-content:center; gap:.35rem; flex-wrap:wrap; }
        .pager a { display:inline-flex; min-width:2rem; height:2rem; align-items:center; justify-content:center; border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.65rem; text-decoration:none; color:rgb(var(--bk-text-rgb)); font-size:.8rem; font-weight:600; padding:0 .4rem; background:rgb(var(--bk-surface-rgb)); }
        .pager a.current { background:rgb(var(--bk-primary-rgb)); color:#fff; border-color:rgba(var(--bk-primary-rgb),.9); }
        .note { margin-top:.4rem; text-align:center; color:rgb(var(--bk-muted-rgb)); font-size:.76rem; }
        .empty { text-align:center; padding:1.8rem 1rem; color:rgb(var(--bk-muted-rgb)); }
        @media (max-width:1180px) { .stats { grid-template-columns:repeat(2,minmax(0,1fr)); } .filters { grid-template-columns:repeat(2,minmax(0,1fr)); } .filters .f-actions { grid-column:span 2; } }
        @media (max-width:760px) { .wrap { padding:.9rem .8rem 1.4rem; } .stats,.filters { grid-template-columns:1fr; } .filters .f-actions { grid-column:span 1; } .claim-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content wrap">
    <?php $activityExportParams = $_GET; unset($activityExportParams['page']); ?>
    <section class="hero">
        <div class="hero-copy">
            <h1>System Activity Trail</h1>
            <p>Trace claimant, legal, finance, and admin workflow actions with timestamps and claim references.</p>
        </div>
        <div class="hero-actions">
            <a class="ui-btn ui-btn-md ui-btn-secondary" href="export_activity_pdf.php?<?php echo bk_e(http_build_query($activityExportParams)); ?>" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf"></i><span>Export PDF</span>
            </a>
        </div>
    </section>

    <section class="stats">
        <article class="card"><p class="k">Claimant Events</p><p class="v"><?php echo number_format((int) ($roleCount['claimant'] ?? 0)); ?></p></article>
        <article class="card"><p class="k">Legal Events</p><p class="v"><?php echo number_format((int) ($roleCount['legal'] ?? 0)); ?></p></article>
        <article class="card"><p class="k">Finance Events</p><p class="v"><?php echo number_format((int) ($roleCount['finance'] ?? 0)); ?></p></article>
        <article class="card"><p class="k">Admin Events</p><p class="v"><?php echo number_format((int) ($roleCount['admin'] ?? 0)); ?></p></article>
        <article class="card"><p class="k">System Events</p><p class="v"><?php echo number_format((int) ($roleCount['system'] ?? 0)); ?></p></article>
    </section>

    <section class="panel">
        <header class="panel-head">
            <div>
                <h2 class="panel-title">Recorded Events</h2>
                <p class="panel-sub">Cross-role actions captured across account, authentication, messaging, legal review, and finance processing.</p>
            </div>
        </header>

        <form method="GET" class="filters">
            <div class="ui-field">
                <label class="ui-label" for="search">Search</label>
                <input id="search" name="search" class="ui-input" value="<?php echo bk_e($search); ?>" placeholder="Actor, action, claim, details">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="role">Role</label>
                <select id="role" name="role" class="ui-select">
                    <option value="">All roles</option>
                    <?php foreach (['claimant', 'legal', 'finance', 'admin', 'system'] as $role): ?>
                        <option value="<?php echo bk_e($role); ?>" <?php echo strtolower($filterRole) === $role ? 'selected' : ''; ?>>
                            <?php echo bk_e(ucfirst($role)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-label" for="action">Action</label>
                <input id="action" name="action" class="ui-input" value="<?php echo bk_e($filterAction); ?>" placeholder="e.g. Approved by Finance">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="date_from">From</label>
                <input id="date_from" name="date_from" type="date" class="ui-input" value="<?php echo bk_e($dateFrom); ?>">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="date_to">To</label>
                <input id="date_to" name="date_to" type="date" class="ui-input" value="<?php echo bk_e($dateTo); ?>">
            </div>
            <div class="f-actions flex gap-2">
                <button class="ui-btn ui-btn-md ui-btn-primary" type="submit"><i class="bi bi-funnel"></i><span>Apply</span></button>
                <a class="ui-btn ui-btn-md ui-btn-secondary" href="activity.php">Reset</a>
            </div>
        </form>

        <div class="table-wrap">
            <div class="table-scroll bk-table-shell">
                <table class="activity-table" data-udcs-expand-group data-udcs-expand-single="true">
                    <thead>
                    <tr>
                        <th><span class="table-entity-label"><i class="bi bi-clock-history"></i>Time</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-person-badge"></i>Actor</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-shield-check"></i>Role</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-lightning-charge"></i>Action</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-card-text"></i>Details</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-sliders"></i>Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($logsResult && mysqli_num_rows($logsResult) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($logsResult)): ?>
                            <?php
                            $roleKey = strtolower(trim((string) ($row['actor_role'] ?? 'system')));
                            $roleClass = in_array($roleKey, ['claimant', 'legal', 'finance', 'admin', 'system'], true) ? $roleKey : 'system';
                            $claimId = (int) ($row['claim_id'] ?? 0);
                            $claimCode = $claimId > 0 ? 'CL-' . str_pad((string) $claimId, 6, '0', STR_PAD_LEFT) : 'N/A';
                            $activityContract = $claimId > 0 ? udcs_claim_fetch_review_contract($conn, $claimId) : null;
                            $activityPeopleSummary = is_array($activityContract) ? (array) ($activityContract['people']['summary'] ?? []) : [];
                            $activityAssetSummary = is_array($activityContract) ? (array) ($activityContract['assets']['summary'] ?? []) : [];
                            $activityStatusLabel = is_array($activityContract)
                                ? (string) ($activityContract['status']['label'] ?? 'Status not recorded')
                                : 'No linked claim';
                            $activityStatusClass = is_array($activityContract)
                                ? (string) ($activityContract['status']['class'] ?? 'status-neutral')
                                : 'status-neutral';
                            $activityDeceased = (string) (($activityPeopleSummary['deceased_name'] ?? '') !== '' ? $activityPeopleSummary['deceased_name'] : ($row['deceased_name'] ?? ''));
                            $activityAssets = (string) (($activityAssetSummary['label'] ?? '') !== '' ? $activityAssetSummary['label'] : udcs_claim_asset_summary_label((string) ($row['asset_classes'] ?? ''), (string) ($row['claim_type'] ?? '')));
                            $actorLabel = (string) ($row['actor_name'] ?? 'System');
                            if ($actorLabel === '') {
                                $actorLabel = 'System';
                            }
                            $actionDisplay = admin_activity_action_display(
                                (string) ($row['action_label'] ?? ''),
                                (string) ($row['action_key'] ?? '')
                            );
                            $detailsDisplay = admin_activity_humanize_text((string) ($row['details'] ?? ''));
                            $metaSummary = admin_activity_meta_summary((string) ($row['meta_json'] ?? ''));
                            $summarySource = $detailsDisplay !== '-' ? $detailsDisplay : ($metaSummary !== '' ? $metaSummary : 'No additional event detail recorded.');
                            $summaryPreview = function_exists('mb_strimwidth')
                                ? mb_strimwidth($summarySource, 0, 118, '...')
                                : (strlen($summarySource) > 118 ? substr($summarySource, 0, 115) . '...' : $summarySource);
                            $expandPanelId = 'admin-activity-expand-' . (int) ($row['id'] ?? 0);
                            $claimContextHtml = udcs_claims_list_lines_html([
                                ['label' => 'Claim reference', 'value' => $claimCode],
                                ['label' => 'Status', 'value' => $activityStatusLabel],
                                ['label' => 'Deceased', 'value' => $activityDeceased !== '' ? $activityDeceased : 'Not linked'],
                                ['label' => 'Assets', 'value' => $activityAssets !== '' ? $activityAssets : 'Not linked'],
                            ]);
                            if ($claimId > 0) {
                                $claimContextHtml .= '<div class="activity-expand-actions">'
                                    . '<button type="button" class="claim-view" data-open="claim" data-claim-id="' . $claimId . '">'
                                    . '<i class="bi bi-eye"></i><span>Open claim</span></button>'
                                    . '</div>';
                            }
                            ?>
                            <tr>
                                <td class="time-cell">
                                    <p class="cell-main"><span class="entity-chip entity-chip-muted"><i class="bi bi-calendar2-week"></i><?php echo bk_e((string) ($row['created_label'] ?? '')); ?></span></p>
                                    <p class="cell-sub">Event #<?php echo (int) ($row['id'] ?? 0); ?></p>
                                </td>
                                <td class="actor-cell">
                                    <p class="cell-main"><span class="entity-chip"><i class="bi bi-person"></i><?php echo bk_e($actorLabel); ?></span></p>
                                </td>
                                <td><span class="pill role-<?php echo bk_e($roleClass); ?>"><?php echo bk_e(ucfirst($roleClass)); ?></span></td>
                                <td class="action-cell">
                                    <span class="activity-action-chip"><i class="bi bi-arrow-right-circle"></i><?php echo bk_e($actionDisplay); ?></span>
                                </td>
                                <td class="activity-summary-cell">
                                    <div class="activity-summary-stack">
                                        <div class="activity-summary-head">
                                            <span class="claim-code"><?php echo bk_e($claimCode); ?></span>
                                            <?php if ($claimId > 0): ?>
                                                <span class="claim-preview-status <?php echo bk_e($activityStatusClass); ?>"><?php echo bk_e($activityStatusLabel); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-summary-line"><?php echo bk_e($summaryPreview); ?></div>
                                        <?php if ($metaSummary !== ''): ?>
                                            <div class="activity-summary-note">Metadata recorded for this event.</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="activity-actions">
                                        <?php udcs_claims_list_render_expand_button($expandPanelId, ['label' => 'More']); ?>
                                    </div>
                                </td>
                            </tr>
                            <?php
                            udcs_claims_list_render_expand_row($expandPanelId, 6, [
                                [
                                    'title' => 'Event Context',
                                    'lines' => [
                                        ['label' => 'Event #', 'value' => (string) ((int) ($row['id'] ?? 0))],
                                        ['label' => 'Time', 'value' => (string) ($row['created_label'] ?? '-')],
                                        ['label' => 'Actor', 'value' => $actorLabel],
                                        ['label' => 'Role', 'value' => ucfirst($roleClass)],
                                        ['label' => 'Action', 'value' => $actionDisplay],
                                    ],
                                ],
                                [
                                    'title' => 'Claim Context',
                                    'html' => $claimContextHtml,
                                ],
                                [
                                    'title' => 'Event Details',
                                    'html' => '<div class="udcs-expand-copy activity-expand-copy">' . nl2br(bk_e($detailsDisplay !== '' ? $detailsDisplay : '-')) . '</div>',
                                ],
                                [
                                    'title' => 'Metadata',
                                    'html' => '<div class="udcs-expand-copy activity-expand-copy">' . nl2br(bk_e($metaSummary !== '' ? $metaSummary : 'No extra metadata recorded.')) . '</div>',
                                ],
                            ]);
                            ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6"><div class="empty"><i class="bi bi-inbox"></i><p>No activity found for this filter.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pager">
                    <?php if ($page > 1): ?><a href="?<?php echo bk_e(http_build_query(array_merge($baseParams, ['page' => $page - 1]))); ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?<?php echo bk_e(http_build_query(array_merge($baseParams, ['page' => $i]))); ?>" class="<?php echo $i === $page ? 'current' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="?<?php echo bk_e(http_build_query(array_merge($baseParams, ['page' => $page + 1]))); ?>"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
                </nav>
                <p class="note">Page <?php echo $page; ?> of <?php echo $totalPages; ?> - <?php echo number_format($totalRows); ?> total events</p>
            <?php endif; ?>
        </div>
</section>
</main>

<section id="claimModal" class="modal" aria-hidden="true">
    <article class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="claimModalTitle">
        <header class="modal-head">
            <h2 id="claimModalTitle">Claim Review</h2>
            <button type="button" id="closeClaimModal" class="ui-btn ui-btn-sm ui-btn-ghost"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="modal-body" id="claimModalContent">
            <div class="empty"><i class="bi bi-hourglass-split"></i><p>Loading claim details...</p></div>
        </div>
    </article>
</section>
<?php udcs_claims_list_render_assets(); ?>
<script>
(() => {
    const modal = document.getElementById('claimModal');
    const closeBtn = document.getElementById('closeClaimModal');
    const modalContent = document.getElementById('claimModalContent');

    if (!modal || !closeBtn || !modalContent) {
        return;
    }

    const openClaimModal = async (claimId) => {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        modalContent.innerHTML = '<div class="empty"><i class="bi bi-hourglass-split"></i><p>Loading claim details...</p></div>';

        try {
            const response = await fetch(`load_claim_details.php?id=${encodeURIComponent(claimId)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error('Could not load claim.');
            }
            modalContent.innerHTML = await response.text();
        } catch (error) {
            modalContent.innerHTML = '<div class="empty"><i class="bi bi-exclamation-circle"></i><p>Claim details could not be loaded right now.</p></div>';
        }
    };

    document.querySelectorAll('[data-open="claim"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const claimId = btn.dataset.claimId || '';
            if (claimId !== '') {
                openClaimModal(claimId);
            }
        });
    });

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('open')) {
            closeModal();
        }
    });
})();
</script>
</body>
</html>

