<?php
// Tags: [ADMIN] [DASH]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';

if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'admin') {
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

$dashboardStmtResult = static function (mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }
    if (!udcs_db_stmt_bind($stmt, $types, $params)) {
        mysqli_stmt_close($stmt);
        return false;
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    return $result;
};
$dashboardCountValue = static function ($result): int {
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return (int) ($row['total'] ?? 0);
};
$dashboardClaimDateSql = static function (string $alias = '') use ($conn): string {
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $candidates = [];
    foreach (['submitted_at', 'updated_at', 'closed_at', 'legal_reopen_requested_at'] as $column) {
        if (udcs_schema_has_column($conn, 'claims', $column)) {
            $candidates[] = $prefix . $column;
        }
    }
    if (empty($candidates)) {
        return 'NOW()';
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }
    return 'COALESCE(' . implode(', ', $candidates) . ')';
};

$userRoleQuery = $dashboardStmtResult(
    $conn,
    "
    SELECT role, COUNT(*) AS total
    FROM users
    WHERE role != ?
    GROUP BY role
",
    's',
    ['admin']
);

$userRoles = [];
$userCounts = [];
if ($userRoleQuery) {
    while ($row = mysqli_fetch_assoc($userRoleQuery)) {
        $userRoles[] = ucfirst((string) ($row['role'] ?? 'User'));
        $userCounts[] = (int) ($row['total'] ?? 0);
    }
}

$totalClaimsQuery = $dashboardStmtResult($conn, "SELECT COUNT(*) as total FROM claims");
$totalClaims = $dashboardCountValue($totalClaimsQuery);

$pendingClaimsQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) as total
     FROM claims
     WHERE COALESCE(NULLIF(status, ''), claim_status) IN (
        'Draft',
        'Ready for Submission',
        'Submitted',
        'Pending Legal Review',
        'Manual Legal Review Required',
        'More Information Required',
        'Pending Finance Review',
        'Returned by Finance',
        'Approved for Disbursement',
        'pending',
        'under review',
        'under_review',
        'transferred to finance'
     )"
);
$pendingClaims = $dashboardCountValue($pendingClaimsQuery);

$totalUsersQuery = $dashboardStmtResult($conn, "SELECT COUNT(*) as total FROM users");
$totalUsers = $dashboardCountValue($totalUsersQuery);

$recentClaimsQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) as total
     FROM claims
     WHERE " . $dashboardClaimDateSql() . " >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$recentClaims = $dashboardCountValue($recentClaimsQuery);

$pendingLegalQueueQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims
     WHERE COALESCE(NULLIF(status, ''), claim_status) IN (
        'Pending Legal Review',
        'Manual Legal Review Required',
        'More Information Required',
        'pending',
        'under review',
        'under_review'
     )"
);
$pendingLegalQueue = $dashboardCountValue($pendingLegalQueueQuery);

$pendingFinanceQueueQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims
     WHERE COALESCE(NULLIF(status, ''), claim_status) IN (
        'Pending Finance Review',
        'Returned by Finance',
        'Approved for Disbursement',
        'transferred to finance',
        'approved by legal'
     )"
);
$pendingFinanceQueue = $dashboardCountValue($pendingFinanceQueueQuery);

$manualReviewClaimsQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims
     WHERE COALESCE(manual_review_flag, 0) = 1
        OR COALESCE(NULLIF(status, ''), claim_status) = 'Manual Legal Review Required'"
);
$manualReviewClaims = $dashboardCountValue($manualReviewClaimsQuery);

$returnedClaimsQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims
     WHERE COALESCE(NULLIF(status, ''), claim_status) = 'Returned by Finance'"
);
$returnedClaims = $dashboardCountValue($returnedClaimsQuery);

$ocrFailureClaimsQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(DISTINCT d.claim_id) AS total
     FROM documents d
     JOIN claims c ON c.id = d.claim_id
     WHERE LOWER(COALESCE(d.ocr_status, '')) = 'failed'
       AND COALESCE(NULLIF(c.status, ''), c.claim_status) NOT IN ('Closed', 'Disbursed', 'approved by finance')"
);
$ocrFailureClaims = $dashboardCountValue($ocrFailureClaimsQuery);

$unassignedLegalClaimsQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims
     WHERE COALESCE(NULLIF(status, ''), claim_status) IN (
        'Pending Legal Review',
        'Manual Legal Review Required',
        'More Information Required',
        'pending',
        'under review',
        'under_review'
     )
       AND COALESCE(assigned_legal_id, 0) = 0"
);
$unassignedLegalClaims = $dashboardCountValue($unassignedLegalClaimsQuery);

$unassignedFinanceClaimsQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims
     WHERE COALESCE(NULLIF(status, ''), claim_status) IN (
        'Pending Finance Review',
        'Returned by Finance',
        'Approved for Disbursement',
        'transferred to finance',
        'approved by legal'
     )
       AND COALESCE(assigned_finance_id, 0) = 0"
);
$unassignedFinanceClaims = $dashboardCountValue($unassignedFinanceClaimsQuery);

$stalledClaimsQuery = $dashboardStmtResult(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims
     WHERE COALESCE(NULLIF(status, ''), claim_status) NOT IN (
        'Closed',
        'Disbursed',
        'approved by finance',
        'Rejected by Legal'
     )
       AND " . $dashboardClaimDateSql() . " < DATE_SUB(NOW(), INTERVAL 14 DAY)"
);
$stalledClaims = $dashboardCountValue($stalledClaimsQuery);

$adminInsightLabels = [
    'Legal Queue',
    'Finance Queue',
    'Manual Review',
    'Finance Returns',
    'OCR Failures',
    'Stalled 14+ Days',
];
$adminInsightCounts = [
    $pendingLegalQueue,
    $pendingFinanceQueue,
    $manualReviewClaims,
    $returnedClaims,
    $ocrFailureClaims,
    $stalledClaims,
];

$priorityClaims = [];
$priorityClaimsQuery = $dashboardStmtResult(
    $conn,
    "
    SELECT
        c.id,
        COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name, 'Not recorded') AS deceased_name,
        COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
        COALESCE(c.manual_review_flag, 0) AS manual_review_flag,
        COALESCE(c.manual_review_reason, '') AS manual_review_reason,
        COALESCE(c.finance_return_reason, '') AS finance_return_reason,
        COALESCE(c.assigned_legal_id, 0) AS assigned_legal_id,
        COALESCE(c.assigned_finance_id, 0) AS assigned_finance_id,
        COALESCE(dc.failed_ocr_count, 0) AS failed_ocr_count,
        COALESCE(dc.document_count, 0) AS document_count,
        " . $dashboardClaimDateSql('c') . " AS submitted_time
    FROM claims c
    LEFT JOIN (
        SELECT claim_id,
               SUM(CASE WHEN LOWER(COALESCE(ocr_status, '')) = 'failed' THEN 1 ELSE 0 END) AS failed_ocr_count,
               COUNT(*) AS document_count
        FROM documents
        GROUP BY claim_id
    ) dc ON dc.claim_id = c.id
    WHERE (
        COALESCE(c.manual_review_flag, 0) = 1
        OR COALESCE(NULLIF(c.status, ''), c.claim_status) IN (
            'Returned by Finance',
            'Manual Legal Review Required',
            'More Information Required',
            'Pending Legal Review',
            'Pending Finance Review'
        )
        OR COALESCE(dc.failed_ocr_count, 0) > 0
        OR (
            COALESCE(NULLIF(c.status, ''), c.claim_status) IN ('Pending Legal Review', 'Manual Legal Review Required', 'More Information Required')
            AND COALESCE(c.assigned_legal_id, 0) = 0
        )
        OR (
            COALESCE(NULLIF(c.status, ''), c.claim_status) IN ('Pending Finance Review', 'Returned by Finance', 'Approved for Disbursement')
            AND COALESCE(c.assigned_finance_id, 0) = 0
        )
    )
    ORDER BY
        CASE
            WHEN COALESCE(c.manual_review_flag, 0) = 1 THEN 1
            WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) = 'Returned by Finance' THEN 2
            WHEN COALESCE(dc.failed_ocr_count, 0) > 0 THEN 3
            WHEN COALESCE(c.assigned_legal_id, 0) = 0 OR COALESCE(c.assigned_finance_id, 0) = 0 THEN 4
            ELSE 5
        END,
        submitted_time ASC,
        c.id DESC
    LIMIT 6
"
);
if ($priorityClaimsQuery) {
    while ($row = mysqli_fetch_assoc($priorityClaimsQuery)) {
        $priorityClaims[] = $row;
    }
}

bk_activity_ensure_schema($conn);

$recentActivity = [];
$recentActivityQuery = $dashboardStmtResult(
    $conn,
    "
    SELECT
        a.id,
        a.claim_id,
        a.action_label,
        a.created_at,
        COALESCE(a.actor_role, 'system') AS actor_role,
        COALESCE(u.full_name, 'System') AS actor_name
    FROM activity_logs a
    LEFT JOIN users u ON u.id = a.actor_id
    ORDER BY a.created_at DESC
    LIMIT 6
"
);
if ($recentActivityQuery) {
    while ($row = mysqli_fetch_assoc($recentActivityQuery)) {
        $recentActivity[] = $row;
    }
}

$adminPriorityMeta = static function (array $claim): array {
    $status = trim((string) ($claim['effective_status'] ?? ''));
    $manualReview = (int) ($claim['manual_review_flag'] ?? 0) === 1;
    $failedOcr = (int) ($claim['failed_ocr_count'] ?? 0);
    $legalUnassigned = (int) ($claim['assigned_legal_id'] ?? 0) === 0
        && in_array($status, ['Pending Legal Review', 'Manual Legal Review Required', 'More Information Required', 'pending', 'under review', 'under_review'], true);
    $financeUnassigned = (int) ($claim['assigned_finance_id'] ?? 0) === 0
        && in_array($status, ['Pending Finance Review', 'Returned by Finance', 'Approved for Disbursement', 'transferred to finance', 'approved by legal'], true);

    if ($manualReview) {
        return [
            'label' => 'Manual Review',
            'class' => 'risk-manual',
            'note' => trim((string) ($claim['manual_review_reason'] ?? '')) !== '' ? (string) $claim['manual_review_reason'] : 'Manual review flag is active.',
        ];
    }

    if ($status === 'Returned by Finance') {
        return [
            'label' => 'Finance Return',
            'class' => 'risk-return',
            'note' => trim((string) ($claim['finance_return_reason'] ?? '')) !== '' ? (string) $claim['finance_return_reason'] : 'Finance returned this claim for clarification.',
        ];
    }

    if ($failedOcr > 0) {
        return [
            'label' => 'OCR Failure',
            'class' => 'risk-ocr',
            'note' => number_format($failedOcr) . ' document OCR failure(s) need review.',
        ];
    }

    if ($legalUnassigned || $financeUnassigned) {
        return [
            'label' => 'Unassigned Queue',
            'class' => 'risk-unassigned',
            'note' => $legalUnassigned ? 'Legal assignment is still missing.' : 'Finance assignment is still missing.',
        ];
    }

    return [
        'label' => 'Queue Attention',
        'class' => 'risk-neutral',
        'note' => 'This claim still needs admin visibility while it moves through the workflow.',
    ];
};

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Admin Dashboard | UNIFIED DIGITAL CLAIMS SYSTEM', '..', $headExtra); ?>
    <style>
        .admin-shell {
            padding: 1rem 1.25rem 2rem;
        }

        .admin-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.2rem;
            background:
                linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.17), rgba(var(--bk-primary-rgb), 0.04) 48%, rgba(var(--bk-surface-rgb), 0.98)),
                rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
            padding: 1.3rem 1.35rem;
        }

        .admin-hero::before {
            content: '';
            position: absolute;
            width: 15rem;
            height: 15rem;
            right: -4rem;
            top: -5.8rem;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(var(--bk-primary-rgb), 0.2), rgba(var(--bk-primary-rgb), 0));
            pointer-events: none;
            animation: float 7s ease-in-out infinite;
        }

        .admin-hero-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 1rem;
            align-items: end;
        }

        .admin-hero h1 {
            margin: 0.45rem 0 0;
            font-family: var(--app-display-font), var(--app-font), sans-serif;
            font-size: clamp(1.58rem, 2.6vw, 2.2rem);
            line-height: 1.2;
            color: rgb(var(--bk-text-rgb));
        }

        .admin-hero p {
            margin: 0.55rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            max-width: 44rem;
            font-size: 0.96rem;
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.65rem;
        }

        .hero-mini {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.9rem;
            background: rgba(var(--bk-surface-rgb), 0.85);
            padding: 0.66rem 0.7rem;
            box-shadow: var(--shadow-soft);
        }

        .hero-mini .k {
            font-size: 0.73rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: rgb(var(--bk-muted-rgb));
            margin: 0;
        }

        .hero-mini .v {
            font-size: 1.25rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
            margin: 0.2rem 0 0;
        }
        .admin-risk-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
        }
        .kpi-label {
            margin: 0;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgb(var(--bk-muted-rgb));
        }

        .kpi-value {
            margin: 0.2rem 0 0;
            font-size: 1.65rem;
            line-height: 1;
            color: rgb(var(--bk-text-rgb));
            font-weight: 700;
        }

        .kpi-icon {
            width: 2.7rem;
            height: 2.7rem;
            border-radius: 0.78rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }

        .kpi-total .kpi-icon {
            color: rgb(var(--bk-primary-rgb));
            background: rgba(var(--bk-primary-rgb), 0.14);
        }

        .kpi-pending .kpi-icon {
            color: rgb(var(--bk-warning-rgb));
            background: rgba(var(--bk-warning-rgb), 0.14);
        }

        .kpi-users .kpi-icon {
            color: rgb(var(--bk-success-rgb));
            background: rgba(var(--bk-success-rgb), 0.14);
        }

        .kpi-recent .kpi-icon {
            color: rgb(var(--bk-primary-rgb));
            background: rgba(var(--bk-primary-rgb), 0.14);
        }

        .risk-card {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
            padding: 0.95rem 1rem;
            display: grid;
            gap: 0.5rem;
        }

        .risk-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.7rem;
        }

        .risk-label {
            margin: 0;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgb(var(--bk-muted-rgb));
        }

        .risk-value {
            margin: 0.18rem 0 0;
            font-size: 1.7rem;
            line-height: 1;
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .risk-note {
            margin: 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.79rem;
            line-height: 1.42;
        }

        .risk-icon {
            width: 2.45rem;
            height: 2.45rem;
            border-radius: 0.76rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .risk-manual .risk-icon {
            color: rgb(var(--bk-warning-rgb));
            background: rgba(var(--bk-warning-rgb), 0.14);
        }

        .risk-return .risk-icon {
            color: rgb(var(--bk-danger-rgb));
            background: rgba(var(--bk-danger-rgb), 0.14);
        }

        .risk-unassigned .risk-icon {
            color: rgb(var(--bk-primary-rgb));
            background: rgba(var(--bk-primary-rgb), 0.14);
        }

        .risk-ocr .risk-icon {
            color: rgb(var(--bk-danger-rgb));
            background: rgba(var(--bk-danger-rgb), 0.14);
        }

        .admin-priority-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: minmax(0, 1.28fr) minmax(0, 1fr);
            gap: 0.9rem;
            align-items: start;
        }

        .admin-panels {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.9rem;
        }
        .admin-panel {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
            overflow: hidden;
        }

        .admin-panel-head {
            padding: 0.8rem 0.95rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.7rem;
        }

        .admin-panel-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 1rem;
            font-weight: 700;
        }

        .admin-panel-subtitle {
            margin: 0.15rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.8rem;
        }

        .panel-chart {
            min-height: 330px;
            padding: 0.6rem 0.45rem 0.4rem;
        }

        .admin-panel-body {
            overflow: visible;
        }

        .admin-priority-list {
            display: grid;
            gap: 0.72rem;
            padding: 0.75rem;
        }

        .admin-priority-item {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.96rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.8rem 0.85rem;
            display: grid;
            gap: 0.56rem;
            transition: transform 0.16s ease, border-color 0.16s ease, background-color 0.16s ease;
        }

        .admin-priority-item:hover {
            transform: translateY(-1px);
            border-color: rgba(var(--bk-primary-rgb), 0.45);
            background: rgba(var(--bk-primary-rgb), 0.06);
        }

        .admin-priority-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .admin-priority-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.9rem;
            font-weight: 800;
            line-height: 1.22;
        }

        .admin-priority-note {
            margin: 0.14rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.78rem;
            line-height: 1.4;
        }

        .admin-priority-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.38rem;
        }

        .admin-priority-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.26rem;
            border-radius: 999px;
            padding: 0.2rem 0.52rem;
            font-size: 0.72rem;
            font-weight: 700;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.16);
            background: rgba(var(--bk-primary-rgb), 0.08);
            color: rgb(var(--bk-text-rgb));
        }

        .admin-priority-pill.risk-manual {
            border-color: rgba(var(--bk-warning-rgb), 0.3);
            background: rgba(var(--bk-warning-rgb), 0.14);
            color: rgb(var(--bk-warning-rgb));
        }

        .admin-priority-pill.risk-return,
        .admin-priority-pill.risk-ocr {
            border-color: rgba(var(--bk-danger-rgb), 0.3);
            background: rgba(var(--bk-danger-rgb), 0.12);
            color: rgb(var(--bk-danger-rgb));
        }

        .admin-priority-pill.risk-unassigned {
            border-color: rgba(var(--bk-primary-rgb), 0.3);
            background: rgba(var(--bk-primary-rgb), 0.12);
            color: rgb(var(--bk-primary-rgb));
        }

        .admin-priority-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            flex-wrap: wrap;
        }

        .admin-activity-list {
            display: grid;
            gap: 0.56rem;
            padding: 0.75rem;
        }

        .admin-activity-item {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.86rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.64rem 0.68rem;
            transition: transform 0.16s ease, border-color 0.16s ease, background-color 0.16s ease;
        }

        .admin-activity-item:hover {
            transform: translateY(-1px);
            border-color: rgba(var(--bk-primary-rgb), 0.45);
            background: rgba(var(--bk-primary-rgb), 0.06);
        }

        .admin-activity-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .admin-activity-title {
            margin: 0;
            font-size: 0.84rem;
            color: rgb(var(--bk-text-rgb));
            font-weight: 700;
            line-height: 1.25;
        }

        .admin-activity-meta {
            margin: 0.35rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.74rem;
            line-height: 1.3;
        }

        .admin-role-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.14rem 0.44rem;
            font-size: 0.69rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .admin-role-pill.role-claimant {
            color: rgb(var(--bk-primary-rgb));
            border-color: rgba(var(--bk-primary-rgb), 0.34);
            background: rgba(var(--bk-primary-rgb), 0.13);
        }

        .admin-role-pill.role-legal {
            color: rgb(var(--bk-warning-rgb));
            border-color: rgba(var(--bk-warning-rgb), 0.34);
            background: rgba(var(--bk-warning-rgb), 0.14);
        }

        .admin-role-pill.role-finance {
            color: rgb(var(--bk-success-rgb));
            border-color: rgba(var(--bk-success-rgb), 0.34);
            background: rgba(var(--bk-success-rgb), 0.14);
        }

        .admin-role-pill.role-admin {
            color: rgb(var(--bk-danger-rgb));
            border-color: rgba(var(--bk-danger-rgb), 0.34);
            background: rgba(var(--bk-danger-rgb), 0.14);
        }

        .admin-role-pill.role-system {
            color: rgb(var(--bk-text-rgb));
            border-color: rgba(var(--bk-muted-rgb), 0.3);
            background: rgba(var(--bk-muted-rgb), 0.17);
        }

        .admin-panel-foot {
            border-top: 1px solid rgba(var(--bk-border-rgb), 1);
            padding: 0.75rem 0.85rem;
            display: flex;
            justify-content: flex-end;
        }

        .admin-panel-foot-link {
            width: 100%;
            padding: 0.55rem 0.72rem;
            border-radius: 0.72rem;
        }

        .admin-panel-foot-link .admin-link-icon {
            width: 1.9rem;
            height: 1.9rem;
        }

        .admin-link {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
            padding: 0.85rem 0.9rem;
            text-decoration: none;
            color: rgb(var(--bk-text-rgb));
            transition: transform 0.16s ease, border-color 0.16s ease, background-color 0.16s ease, box-shadow 0.16s ease;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .admin-link:hover {
            transform: translateY(-2px);
            border-color: rgba(var(--bk-primary-rgb), 0.5);
            background: rgba(var(--bk-primary-rgb), 0.06);
            color: rgb(var(--bk-text-rgb));
            box-shadow: var(--shadow);
        }

        .admin-link-icon {
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 0.72rem;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.3);
            background: rgba(var(--bk-primary-rgb), 0.12);
            color: rgb(var(--bk-primary-rgb));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1rem;
        }

        .admin-link-body {
            min-width: 0;
            flex: 1;
        }

        .admin-link-title {
            display: block;
            margin: 0;
            font-size: 0.92rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
            line-height: 1.2;
        }

        .admin-link .label {
            display: block;
            margin-top: 0.3rem;
            font-size: 0.8rem;
            color: rgb(var(--bk-muted-rgb));
            line-height: 1.3;
        }

        .admin-link-arrow {
            color: rgb(var(--bk-muted-rgb));
            margin-top: 0.08rem;
            transition: transform 0.16s ease, color 0.16s ease;
        }

        .admin-link:hover .admin-link-arrow {
            color: rgb(var(--bk-primary-rgb));
            transform: translateX(2px) translateY(-1px);
        }

        .admin-links-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.9rem;
        }

        @media (max-width: 1180px) {
            .admin-risk-grid,
            .admin-links-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 980px) {
            .admin-hero-grid,
            .admin-panels,
            .admin-priority-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 680px) {
            .admin-shell {
                padding-left: 0.8rem;
                padding-right: 0.8rem;
            }
            .hero-stats,
            .admin-risk-grid,
            .admin-links-grid {
                grid-template-columns: 1fr;
            }

            .panel-chart {
                min-height: 285px;
            }
        }

        /* SECTION: BK-blue dominance pass for the Admin dashboard. */
        body.bk-role-admin {
            background:
                radial-gradient(circle at 86% 8%, rgba(255, 255, 255, 0.18), transparent 26rem),
                radial-gradient(circle at 12% 86%, rgba(255, 255, 255, 0.1), transparent 24rem),
                linear-gradient(145deg, #012a5c 0%, #034ea2 48%, #063b7d 100%) !important;
        }

        body.bk-role-admin .main-content.admin-shell {
            position: relative;
            min-height: calc(100vh - 1.9rem);
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            background:
                radial-gradient(circle at 18% 14%, rgba(255, 255, 255, 0.16), transparent 22rem),
                linear-gradient(155deg, rgba(3, 78, 162, 0.98) 0%, rgba(1, 42, 92, 0.98) 100%) !important;
            box-shadow: 0 24px 52px rgba(3, 78, 162, 0.28) !important;
            color: #ffffff !important;
        }

        body.bk-role-admin .main-content.admin-shell::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: inherit;
            background:
                linear-gradient(90deg, rgba(255, 255, 255, 0.055) 1px, transparent 1px),
                linear-gradient(180deg, rgba(255, 255, 255, 0.045) 1px, transparent 1px);
            background-size: 58px 58px;
            mask-image: linear-gradient(180deg, rgba(3, 78, 162, 0.7), transparent 78%);
        }

        body.bk-role-admin .admin-hero,
        body.bk-role-admin .admin-panel {
            position: relative;
            border: 1px solid rgba(255, 255, 255, 0.24) !important;
            background:
                linear-gradient(145deg, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0.075)),
                rgba(1, 42, 92, 0.58) !important;
            box-shadow: 0 18px 36px rgba(3, 78, 162, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.16) !important;
            color: #ffffff !important;
        }

        body.bk-role-admin .admin-hero {
            background:
                radial-gradient(circle at 82% 18%, rgba(255, 255, 255, 0.22), transparent 18rem),
                linear-gradient(135deg, #034ea2 0%, #012a5c 72%) !important;
            padding: 1.55rem 1.6rem;
        }

        body.bk-role-admin .admin-hero::before {
            background: radial-gradient(circle, rgba(255, 255, 255, 0.28), rgba(255, 255, 255, 0));
        }

        body.bk-role-admin .admin-hero h1,
        body.bk-role-admin .admin-panel-title,
        body.bk-role-admin .admin-link-title,
        body.bk-role-admin .admin-activity-title {
            color: #ffffff !important;
        }

        body.bk-role-admin .admin-hero p,
        body.bk-role-admin .admin-panel-subtitle,
        body.bk-role-admin .admin-link .label,
        body.bk-role-admin .admin-activity-meta,
        body.bk-role-admin .hero-mini .k {
            color: rgba(255, 255, 255, 0.78) !important;
        }

        body.bk-role-admin .hero-mini {
            border-color: rgba(255, 255, 255, 0.22) !important;
            background: rgba(255, 255, 255, 0.12) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12) !important;
        }

        body.bk-role-admin .hero-mini .v {
            color: #ffffff !important;
            font-weight: 800;
        }

        body.bk-role-admin .kpi-icon,
        body.bk-role-admin .admin-link-icon {
            border-color: rgba(255, 255, 255, 0.26) !important;
            background: rgba(255, 255, 255, 0.16) !important;
            color: #ffffff !important;
        }

        body.bk-role-admin .admin-activity-item,
        body.bk-role-admin .admin-link {
            border-color: rgba(255, 255, 255, 0.18) !important;
            background: rgba(255, 255, 255, 0.1) !important;
            color: #ffffff !important;
            box-shadow: none !important;
        }

        body.bk-role-admin .admin-activity-item:hover,
        body.bk-role-admin .admin-link:hover {
            border-color: rgba(255, 255, 255, 0.36) !important;
            background: rgba(255, 255, 255, 0.16) !important;
            box-shadow: 0 14px 28px rgba(3, 78, 162, 0.18) !important;
        }

        body.bk-role-admin .admin-link-arrow {
            color: rgba(255, 255, 255, 0.72) !important;
        }

        body.bk-role-admin .admin-link:hover .admin-link-arrow {
            color: #ffffff !important;
        }

        body.bk-role-admin .admin-role-pill {
            border-color: rgba(255, 255, 255, 0.26) !important;
            background: rgba(255, 255, 255, 0.14) !important;
            color: #ffffff !important;
        }

        body.bk-role-admin .apexcharts-text,
        body.bk-role-admin .apexcharts-legend-text {
            fill: rgba(255, 255, 255, 0.86) !important;
            color: rgba(255, 255, 255, 0.86) !important;
        }

        body.bk-role-admin .apexcharts-gridline {
            stroke: rgba(255, 255, 255, 0.14) !important;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content admin-shell">
    <!-- SECTION: Admin header and quick oversight metrics. -->
    <section class="admin-hero">
        <div class="admin-hero-grid">
            <div>
                <h1>Operational Dashboard</h1>
                <p>
                    Central oversight for claims, users, and activity in the UNIFIED DIGITAL CLAIMS SYSTEM.
                    Monitor review pressure and keep decisions moving.
                </p>
            </div>
            <div class="hero-stats">
                <article class="hero-mini">
                    <p class="k">Open Queue</p>
                    <p class="v"><?php echo number_format($pendingClaims); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="k">Last 7 Days</p>
                    <p class="v"><?php echo number_format($recentClaims); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="k">Users</p>
                    <p class="v"><?php echo number_format($totalUsers); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="k">Claims</p>
                    <p class="v"><?php echo number_format($totalClaims); ?></p>
                </article>
            </div>
        </div>
    </section>

    <!-- SECTION: System pressure. -->
    <section class="admin-risk-grid">
        <article class="risk-card risk-unassigned">
            <div class="risk-top">
                <div>
                    <p class="risk-label">Legal Queue</p>
                    <p class="risk-value"><?php echo number_format($pendingLegalQueue); ?></p>
                </div>
                <span class="risk-icon"><i class="bi bi-briefcase"></i></span>
            </div>
            <p class="risk-note"><?php echo number_format($unassignedLegalClaims); ?> unassigned legal claim(s) still need coverage.</p>
        </article>

        <article class="risk-card risk-unassigned">
            <div class="risk-top">
                <div>
                    <p class="risk-label">Finance Queue</p>
                    <p class="risk-value"><?php echo number_format($pendingFinanceQueue); ?></p>
                </div>
                <span class="risk-icon"><i class="bi bi-bank"></i></span>
            </div>
            <p class="risk-note"><?php echo number_format($unassignedFinanceClaims); ?> unassigned finance claim(s) still need routing.</p>
        </article>

        <article class="risk-card risk-manual">
            <div class="risk-top">
                <div>
                    <p class="risk-label">Manual Review</p>
                    <p class="risk-value"><?php echo number_format($manualReviewClaims); ?></p>
                </div>
                <span class="risk-icon"><i class="bi bi-shield-exclamation"></i></span>
            </div>
            <p class="risk-note"><?php echo number_format($returnedClaims); ?> finance return(s) are also circulating in sensitive review lanes.</p>
        </article>

        <article class="risk-card risk-ocr">
            <div class="risk-top">
                <div>
                    <p class="risk-label">OCR / Stalled</p>
                    <p class="risk-value"><?php echo number_format($ocrFailureClaims); ?></p>
                </div>
                <span class="risk-icon"><i class="bi bi-file-earmark-x"></i></span>
            </div>
            <p class="risk-note"><?php echo number_format($stalledClaims); ?> stalled claim(s) are older than 14 days and need admin attention.</p>
        </article>
    </section>

    <!-- SECTION: Priority claims and recent system activity. -->
    <section class="admin-priority-grid">
        <article class="admin-panel">
            <header class="admin-panel-head">
                <div>
                    <h2 class="admin-panel-title">Priority Claims</h2>
                    <p class="admin-panel-subtitle">The cases most likely to need admin intervention before the queues stay healthy.</p>
                </div>
            </header>
            <div class="admin-panel-body">
                <?php if (empty($priorityClaims)): ?>
                    <div class="admin-priority-list">
                        <article class="admin-priority-item">
                            <div class="admin-priority-top">
                                <div>
                                    <p class="admin-priority-title">No priority claims right now.</p>
                                    <p class="admin-priority-note">Manual review, OCR, return, and assignment exceptions will surface here automatically.</p>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php else: ?>
                    <div class="admin-priority-list">
                        <?php foreach ($priorityClaims as $claim): ?>
                            <?php
                            $priorityMeta = $adminPriorityMeta($claim);
                            $priorityClaimId = (int) ($claim['id'] ?? 0);
                            $priorityStatus = (string) ($claim['effective_status'] ?? '');
                            $priorityStatusLabel = udcs_claim_status_label($priorityStatus);
                            $priorityStatusClass = udcs_claim_status_class($priorityStatus);
                            $prioritySubmittedRaw = trim((string) ($claim['submitted_time'] ?? ''));
                            $prioritySubmittedLabel = 'Not dated';
                            if ($prioritySubmittedRaw !== '') {
                                $priorityTimestamp = strtotime($prioritySubmittedRaw);
                                if ($priorityTimestamp !== false) {
                                    $prioritySubmittedLabel = date('M d, Y', $priorityTimestamp);
                                }
                            }
                            $priorityDocuments = (int) ($claim['document_count'] ?? 0);
                            $priorityFailedOcr = (int) ($claim['failed_ocr_count'] ?? 0);
                            ?>
                            <article class="admin-priority-item">
                                <div class="admin-priority-top">
                                    <div>
                                        <p class="admin-priority-title">CL-<?php echo str_pad((string) $priorityClaimId, 6, '0', STR_PAD_LEFT); ?> | <?php echo htmlspecialchars((string) ($claim['deceased_name'] ?? 'Not recorded')); ?></p>
                                        <p class="admin-priority-note"><?php echo htmlspecialchars((string) ($priorityMeta['note'] ?? '')); ?></p>
                                    </div>
                                    <span class="admin-priority-pill <?php echo htmlspecialchars((string) ($priorityMeta['class'] ?? 'risk-neutral')); ?>">
                                        <?php echo htmlspecialchars((string) ($priorityMeta['label'] ?? 'Queue Attention')); ?>
                                    </span>
                                </div>
                                <div class="admin-priority-meta">
                                    <span class="status-pill <?php echo htmlspecialchars((string) $priorityStatusClass); ?>"><?php echo htmlspecialchars($priorityStatusLabel); ?></span>
                                    <span class="admin-priority-pill"><i class="bi bi-calendar3"></i><span><?php echo htmlspecialchars($prioritySubmittedLabel); ?></span></span>
                                    <span class="admin-priority-pill"><i class="bi bi-folder2-open"></i><span><?php echo number_format($priorityDocuments); ?> document(s)</span></span>
                                    <?php if ($priorityFailedOcr > 0): ?>
                                        <span class="admin-priority-pill risk-ocr"><i class="bi bi-exclamation-diamond"></i><span><?php echo number_format($priorityFailedOcr); ?> OCR failure(s)</span></span>
                                    <?php endif; ?>
                                </div>
                                <div class="admin-priority-actions">
                                    <span class="admin-priority-note">Search-ready in Claims Review for full routing, documents, and decision history.</span>
                                    <a class="ui-btn ui-btn-sm ui-btn-secondary" href="claims.php?search=<?php echo $priorityClaimId; ?>">
                                        <i class="bi bi-arrow-up-right-circle"></i><span>Open Claims Review</span>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="admin-panel-foot">
                <a class="admin-link admin-panel-foot-link" href="claims.php">
                    <span class="admin-link-icon"><i class="bi bi-files"></i></span>
                    <span class="admin-link-body">
                        <span class="admin-link-title">Open Claims Review</span>
                    </span>
                    <span class="admin-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </a>
            </footer>
        </article>

        <article class="admin-panel">
            <header class="admin-panel-head">
                <div>
                    <h2 class="admin-panel-title">Recent Activity</h2>
                    <p class="admin-panel-subtitle">Latest workflow actions across claimant, legal, finance, and admin spaces.</p>
                </div>
            </header>
            <div class="admin-panel-body">
                <?php if (empty($recentActivity)): ?>
                    <div class="admin-activity-list">
                        <div class="admin-activity-item">
                            <p class="admin-activity-title">No recent activity available.</p>
                            <p class="admin-activity-meta">System actions will appear here once events are logged.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="admin-activity-list">
                        <?php foreach ($recentActivity as $activity): ?>
                            <?php
                            $roleRaw = strtolower(trim((string) ($activity['actor_role'] ?? 'system')));
                            $roleClass = in_array($roleRaw, ['claimant', 'legal', 'finance', 'admin'], true) ? $roleRaw : 'system';
                            $activityClaimId = (int) ($activity['claim_id'] ?? 0);
                            $activityTimeRaw = trim((string) ($activity['created_at'] ?? ''));
                            $activityTimeLabel = 'Time unavailable';
                            if ($activityTimeRaw !== '') {
                                $activityTimestamp = strtotime($activityTimeRaw);
                                if ($activityTimestamp !== false) {
                                    $activityTimeLabel = date('M d, Y H:i', $activityTimestamp);
                                }
                            }
                            ?>
                            <div class="admin-activity-item">
                                <div class="admin-activity-top">
                                    <p class="admin-activity-title"><?php echo htmlspecialchars((string) ($activity['action_label'] ?? 'Activity update')); ?></p>
                                    <span class="admin-role-pill role-<?php echo htmlspecialchars($roleClass); ?>">
                                        <?php echo htmlspecialchars($roleClass); ?>
                                    </span>
                                </div>
                                <p class="admin-activity-meta">
                                    <?php echo htmlspecialchars((string) ($activity['actor_name'] ?? 'System')); ?>
                                    |
                                    <?php echo htmlspecialchars($activityTimeLabel); ?>
                                    <?php if ($activityClaimId > 0): ?>
                                        | CL-<?php echo str_pad((string) $activityClaimId, 6, '0', STR_PAD_LEFT); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="admin-panel-foot">
                <a class="admin-link admin-panel-foot-link" href="activity.php">
                    <span class="admin-link-icon"><i class="bi bi-clock-history"></i></span>
                    <span class="admin-link-body">
                        <span class="admin-link-title">Open Activity Trail</span>
                    </span>
                    <span class="admin-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </a>
            </footer>
        </article>
    </section>

    <!-- SECTION: Operational insights. -->
    <section class="admin-panels">
        <article class="admin-panel">
            <header class="admin-panel-head">
                <div>
                    <h2 class="admin-panel-title">Operational Pressure Mix</h2>
                    <p class="admin-panel-subtitle">The admin-side breakdown of queue pressure, exceptions, and stalled work.</p>
                </div>
            </header>
            <div id="claimsPieChart" class="panel-chart" aria-label="Operational pressure chart"></div>
        </article>

        <article class="admin-panel">
            <header class="admin-panel-head">
                <div>
                    <h2 class="admin-panel-title">Users by Role</h2>
                    <p class="admin-panel-subtitle">Current staffing and claimant account mix.</p>
                </div>
            </header>
            <div id="usersBarChart" class="panel-chart" aria-label="Users by role chart"></div>
        </article>
    </section>

    <!-- SECTION: Quick oversight links. -->
    <section class="admin-links-grid">
        <a class="admin-link" href="claims.php">
            <span class="admin-link-icon"><i class="bi bi-files"></i></span>
            <span class="admin-link-body">
                <span class="admin-link-title">Claims Review</span>
                <span class="label">Open queues, apply filters, and move into the full claim workspace.</span>
            </span>
            <span class="admin-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="admin-link" href="activity.php">
            <span class="admin-link-icon"><i class="bi bi-clock-history"></i></span>
            <span class="admin-link-body">
                <span class="admin-link-title">Activity Trail</span>
                <span class="label">Inspect system actions, workflow changes, and decision history.</span>
            </span>
            <span class="admin-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="admin-link" href="accounts.php">
            <span class="admin-link-icon"><i class="bi bi-people"></i></span>
            <span class="admin-link-body">
                <span class="admin-link-title">Staff Accounts</span>
                <span class="label">Manage accepted legal and finance reviewers and keep coverage healthy.</span>
            </span>
            <span class="admin-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="admin-link" href="messaging.php">
            <span class="admin-link-icon"><i class="bi bi-chat-dots"></i></span>
            <span class="admin-link-body">
                <span class="admin-link-title">Messaging</span>
                <span class="label">Review system conversations and follow-up traffic that may unblock claims.</span>
            </span>
            <span class="admin-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>
    </section>
</main>

<script>
(() => {
    const primary = '#034EA2';
    const bkMid = '#0A5BB4';
    const bkSky = '#38A9E6';
    const bkPale = '#BFDFFF';
    const bkOrange = '#F97316';
    const bkDanger = '#DC2626';
    const bkSlate = '#64748B';
    const chartText = '#FFFFFF';
    const chartMuted = 'rgba(255, 255, 255, 0.78)';

    const claimLabels = <?php echo json_encode($adminInsightLabels, JSON_UNESCAPED_UNICODE); ?>;
    const claimCounts = <?php echo json_encode($adminInsightCounts); ?>;

    const pieOptions = {
        chart: {
            type: 'donut',
            height: 320,
            toolbar: { show: false }
        },
        labels: claimLabels,
        series: claimCounts,
        colors: [primary, bkMid, bkSky, bkOrange, bkDanger, bkSlate],
        stroke: {
            colors: ['rgba(1, 42, 92, 0.75)'],
            width: 2
        },
        plotOptions: {
            pie: {
                donut: {
                    size: '62%'
                }
            }
        },
        legend: {
            position: 'bottom',
            labels: { colors: chartText }
        },
        dataLabels: {
            style: { fontSize: '12px', fontWeight: 700, colors: [chartText] },
            dropShadow: { enabled: true, blur: 2, opacity: 0.28 }
        },
        noData: {
            text: 'No operational pressure data yet',
            align: 'center',
            verticalAlign: 'middle',
            style: { color: chartMuted }
        }
    };

    new ApexCharts(document.querySelector('#claimsPieChart'), pieOptions).render();

    const userRoles = <?php echo json_encode($userRoles, JSON_UNESCAPED_UNICODE); ?>;
    const userCounts = <?php echo json_encode($userCounts); ?>;

    const barOptions = {
        chart: {
            type: 'bar',
            height: 320,
            toolbar: { show: false }
        },
        series: [{
            name: 'Users',
            data: userCounts
        }],
        colors: [bkSky],
        plotOptions: {
            bar: {
                borderRadius: 8,
                columnWidth: '48%'
            }
        },
        dataLabels: {
            enabled: true,
            style: { colors: [chartText], fontWeight: 700 },
            dropShadow: { enabled: true, blur: 2, opacity: 0.26 }
        },
        xaxis: {
            categories: userRoles,
            labels: { style: { colors: chartMuted } },
            axisBorder: { color: 'rgba(255, 255, 255, 0.22)' },
            axisTicks: { color: 'rgba(255, 255, 255, 0.22)' }
        },
        yaxis: {
            min: 0,
            labels: { style: { colors: chartMuted } }
        },
        grid: {
            borderColor: 'rgba(255, 255, 255, 0.14)'
        },
        tooltip: {
            theme: 'dark'
        },
        noData: {
            text: 'No user data yet',
            align: 'center',
            verticalAlign: 'middle',
            style: { color: chartMuted }
        }
    };

    new ApexCharts(document.querySelector('#usersBarChart'), barOptions).render();
})();
</script>
</body>
</html>



