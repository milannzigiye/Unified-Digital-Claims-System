<?php
// Tags: [LEGAL] [DASH]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/alert.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'legal') {
    header('Location: ../login.php');
    exit();
}

$sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $sessionEmail, 'legal');
if (!$user_data) {
    header('Location: ../login.php');
    exit();
}

$legal_id = (int) ($user_data['id'] ?? 0);

bk_claims_ensure_workflow_schema($conn);
udcs_claims_v2_ensure_schema($conn);
bk_backfill_unassigned_claims($conn);
bk_activity_ensure_schema($conn);

function legal_dashboard_stmt_result(mysqli $conn, string $sql, string $types = '', array $params = [])
{
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
}

function legal_dashboard_single_row($result): array
{
    if (!$result) {
        return [];
    }
    $row = mysqli_fetch_assoc($result);
    return is_array($row) ? $row : [];
}

function legal_dashboard_date_label(?string $datetime): string
{
    $value = trim((string) $datetime);
    if ($value === '') {
        return 'Not dated';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Not dated';
    }
    return date('M d, Y', $timestamp);
}

function legal_dashboard_time_ago(?string $datetime): string
{
    $value = trim((string) $datetime);
    if ($value === '') {
        return 'Not dated';
    }

    try {
        $now = new DateTimeImmutable('now');
        $then = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return 'Not dated';
    }

    $diff = $now->diff($then);
    $weeks = (int) floor($diff->d / 7);
    $days = $diff->d - ($weeks * 7);

    $parts = [];
    if ($diff->y > 0) {
        $parts[] = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
    }
    if ($diff->m > 0) {
        $parts[] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    }
    if ($weeks > 0) {
        $parts[] = $weeks . ' week' . ($weeks > 1 ? 's' : '');
    }
    if ($days > 0) {
        $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
    }
    if ($diff->h > 0) {
        $parts[] = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
    }
    if ($diff->i > 0) {
        $parts[] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
    }
    if ($diff->s > 0) {
        $parts[] = $diff->s . ' second' . ($diff->s > 1 ? 's' : '');
    }

    return !empty($parts) ? ($parts[0] . ' ago') : 'just now';
}

function legal_dashboard_status_badge_class(string $status): string
{
    return match (udcs_claim_status_class($status)) {
        'status-pending' => 'is-pending',
        'status-review', 'status-warning' => 'is-review',
        'status-approved' => 'is-approved',
        'status-rejected' => 'is-rejected',
        default => 'is-neutral',
    };
}

function legal_dashboard_family_summary(array $claim): string
{
    $marital = trim((string) ($claim['marital_status'] ?? ''));
    $children = trim((string) ($claim['children_status'] ?? ''));
    $acting = (int) ($claim['acting_on_behalf'] ?? 0) === 1;

    $parts = [];
    $parts[] = 'Marital: ' . ($marital !== '' ? ucwords(strtolower($marital)) : 'Not stated');
    $parts[] = 'Children: ' . ($children !== '' ? ucwords(strtolower(str_replace('_', ' ', $children))) : 'Not stated');
    $parts[] = $acting ? 'Representative path' : 'Self path';

    return implode(' | ', $parts);
}

function legal_dashboard_priority_meta(array $claim): array
{
    $status = trim((string) ($claim['effective_status'] ?? ''));
    $manualReview = (int) ($claim['manual_review_flag'] ?? 0) === 1 || $status === 'Manual Legal Review Required';
    $willExists = (int) ($claim['will_exists'] ?? 0) === 1;
    $childrenUnknown = strtoupper(trim((string) ($claim['children_status'] ?? ''))) === 'UNKNOWN';
    $actingOnBehalf = (int) ($claim['acting_on_behalf'] ?? 0) === 1;
    $submittedAt = trim((string) ($claim['submitted_at'] ?? ''));
    $submittedTs = $submittedAt !== '' ? strtotime($submittedAt) : false;
    $isAging = $submittedTs !== false && $submittedTs < strtotime('-7 days');

    if ($status === 'More Information Required') {
        return [
            'label' => 'Claimant Follow-Up',
            'class' => 'risk-followup',
            'note' => 'The claimant still owes clarification or evidence before legal review can continue.',
        ];
    }

    if ($manualReview) {
        $manualReason = trim((string) ($claim['manual_review_reason'] ?? ''));
        return [
            'label' => 'Manual Review',
            'class' => 'risk-manual',
            'note' => $manualReason !== '' ? udcs_claim_manual_reason_label($manualReason) : 'This case was routed into the manual legal review lane.',
        ];
    }

    if ($willExists) {
        return [
            'label' => 'Will On Record',
            'class' => 'risk-will',
            'note' => 'A will was declared, so legal review must confirm how the case should proceed.',
        ];
    }

    if ($childrenUnknown) {
        return [
            'label' => 'Children Unknown',
            'class' => 'risk-children',
            'note' => 'Children status is still unknown and needs legal scrutiny before entitlement can move forward.',
        ];
    }

    if ($actingOnBehalf) {
        return [
            'label' => 'Representative Path',
            'class' => 'risk-representative',
            'note' => 'The claimant is acting for other heirs, so authority and disclosure need closer review.',
        ];
    }

    if ($isAging) {
        return [
            'label' => 'Aging Queue',
            'class' => 'risk-aging',
            'note' => 'This claim has been sitting in the legal lane for more than 7 days.',
        ];
    }

    return [
        'label' => 'Pending Review',
        'class' => 'risk-neutral',
        'note' => 'This claim is waiting for legal validation before it can move safely to the next stage.',
    ];
}

$stats = [
    'total_assigned' => 0,
    'pending_review' => 0,
    'manual_review' => 0,
    'claimant_follow_up' => 0,
    'transferred' => 0,
    'rejected' => 0,
    'closed' => 0,
];
$riskStats = [
    'will_cases' => 0,
    'unknown_children' => 0,
    'representative_cases' => 0,
    'aging_7' => 0,
];
$queueClaims = [];
$recentActions = [];
$trendDays = [];
for ($dayOffset = 13; $dayOffset >= 0; $dayOffset--) {
    $trendDays[] = date('M d', strtotime('-' . $dayOffset . ' days'));
}
$pendingData = array_fill(0, count($trendDays), 0);
$manualData = array_fill(0, count($trendDays), 0);
$transferredData = array_fill(0, count($trendDays), 0);
$error = '';

$legalActiveStatusesSql = "('Pending Legal Review', 'Manual Legal Review Required', 'More Information Required', 'pending', 'under review', 'under_review')";
$legalTransferredStatusesSql = "('Pending Finance Review', 'Approved by Legal', 'transferred to finance')";
$legalRejectedStatusesSql = "('Rejected by Legal', 'rejected by legal')";
$legalClosedStatusesSql = "('Approved for Disbursement', 'Disbursed', 'Closed', 'approved by finance')";

try {
    $statsResult = legal_dashboard_stmt_result(
        $conn,
        "
        SELECT
            COUNT(*) AS total_assigned,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('Pending Legal Review', 'pending') THEN 1 ELSE 0 END) AS pending_review,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) = 'Manual Legal Review Required' THEN 1 ELSE 0 END) AS manual_review,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('More Information Required', 'under review', 'under_review') THEN 1 ELSE 0 END) AS claimant_follow_up,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $legalTransferredStatusesSql THEN 1 ELSE 0 END) AS transferred,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $legalRejectedStatusesSql THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $legalClosedStatusesSql THEN 1 ELSE 0 END) AS closed
        FROM claims
        WHERE assigned_legal_id = ?
        ",
        'i',
        [$legal_id]
    );
    $stats = array_merge($stats, legal_dashboard_single_row($statsResult));

    $riskResult = legal_dashboard_stmt_result(
        $conn,
        "
        SELECT
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $legalActiveStatusesSql AND COALESCE(will_exists, 0) = 1 THEN 1 ELSE 0 END) AS will_cases,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $legalActiveStatusesSql AND UPPER(COALESCE(children_status, '')) = 'UNKNOWN' THEN 1 ELSE 0 END) AS unknown_children,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $legalActiveStatusesSql AND COALESCE(acting_on_behalf, 0) = 1 THEN 1 ELSE 0 END) AS representative_cases,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $legalActiveStatusesSql AND submitted_at IS NOT NULL AND submitted_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS aging_7
        FROM claims
        WHERE assigned_legal_id = ?
        ",
        'i',
        [$legal_id]
    );
    $riskStats = array_merge($riskStats, legal_dashboard_single_row($riskResult));

    $queueResult = legal_dashboard_stmt_result(
        $conn,
        "
        SELECT
            c.id,
            COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
            c.submitted_at,
            c.updated_at,
            COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name, 'Not recorded') AS deceased_name,
            COALESCE(c.marital_status, '') AS marital_status,
            COALESCE(c.children_status, '') AS children_status,
            COALESCE(c.manual_review_flag, 0) AS manual_review_flag,
            COALESCE(c.manual_review_reason, '') AS manual_review_reason,
            COALESCE(c.will_exists, 0) AS will_exists,
            COALESCE(c.acting_on_behalf, 0) AS acting_on_behalf,
            COALESCE(ca.asset_classes, '') AS asset_classes,
            c.claim_type,
            u.full_name AS claimant_name,
            u.email AS claimant_email
        FROM claims c
        INNER JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
        LEFT JOIN (
            SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
            FROM claim_assets
            GROUP BY claim_id
        ) ca ON ca.claim_id = c.id
        WHERE c.assigned_legal_id = ?
          AND COALESCE(NULLIF(c.status, ''), c.claim_status) IN $legalActiveStatusesSql
        ORDER BY
            CASE
                WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) = 'Manual Legal Review Required' THEN 1
                WHEN COALESCE(c.manual_review_flag, 0) = 1 THEN 2
                WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) = 'More Information Required' THEN 3
                WHEN COALESCE(c.will_exists, 0) = 1 THEN 4
                WHEN UPPER(COALESCE(c.children_status, '')) = 'UNKNOWN' THEN 5
                WHEN COALESCE(c.acting_on_behalf, 0) = 1 THEN 6
                ELSE 7
            END,
            COALESCE(c.submitted_at, c.updated_at) ASC,
            c.id DESC
        LIMIT 10
        ",
        'i',
        [$legal_id]
    );
    if ($queueResult) {
        while ($row = mysqli_fetch_assoc($queueResult)) {
            $queueClaims[] = $row;
        }
    }

    $actionsResult = legal_dashboard_stmt_result(
        $conn,
        "
        SELECT
            a.claim_id,
            a.action_label,
            a.created_at,
            COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name, 'Not recorded') AS deceased_name,
            COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
            cu.full_name AS claimant_name
        FROM activity_logs a
        LEFT JOIN claims c ON c.id = a.claim_id
        LEFT JOIN users cu ON cu.id = COALESCE(c.claimant_user_id, c.claimant_id)
        WHERE a.actor_id = ?
          AND COALESCE(a.actor_role, '') = 'legal'
        ORDER BY a.created_at DESC
        LIMIT 6
        ",
        'i',
        [$legal_id]
    );
    if ($actionsResult) {
        while ($row = mysqli_fetch_assoc($actionsResult)) {
            $recentActions[] = $row;
        }
    }

    $chartDays = array_fill_keys($trendDays, ['pending' => 0, 'manual' => 0, 'transferred' => 0]);
    $chartResult = legal_dashboard_stmt_result(
        $conn,
        "
        SELECT
            DATE(submitted_at) AS trend_date,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('Pending Legal Review', 'pending') THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) = 'Manual Legal Review Required' THEN 1 ELSE 0 END) AS manual_count,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $legalTransferredStatusesSql THEN 1 ELSE 0 END) AS transferred_count
        FROM claims
        WHERE assigned_legal_id = ?
          AND submitted_at IS NOT NULL
          AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(submitted_at)
        ORDER BY DATE(submitted_at)
        ",
        'i',
        [$legal_id]
    );
    if ($chartResult) {
        while ($row = mysqli_fetch_assoc($chartResult)) {
            $trendTimestamp = strtotime((string) ($row['trend_date'] ?? ''));
            if ($trendTimestamp === false) {
                continue;
            }
            $trendKey = date('M d', $trendTimestamp);
            if (!isset($chartDays[$trendKey])) {
                continue;
            }
            $chartDays[$trendKey] = [
                'pending' => (int) ($row['pending_count'] ?? 0),
                'manual' => (int) ($row['manual_count'] ?? 0),
                'transferred' => (int) ($row['transferred_count'] ?? 0),
            ];
        }
    }
    $pendingData = array_column($chartDays, 'pending');
    $manualData = array_column($chartDays, 'manual');
    $transferredData = array_column($chartDays, 'transferred');
} catch (Throwable $e) {
    error_log('Legal Dashboard Error: ' . $e->getMessage());
    $error = 'The legal dashboard could not load completely right now. Refresh and try again.';
}

$heroQueueTotal = (int) ($stats['pending_review'] ?? 0) + (int) ($stats['manual_review'] ?? 0) + (int) ($stats['claimant_follow_up'] ?? 0);
$priorityClaims = array_slice($queueClaims, 0, 5);
$compositionLabels = ['Pending Review', 'Manual Review', 'Claimant Follow-Up', 'Transferred', 'Rejected'];
$compositionCounts = [
    (int) ($stats['pending_review'] ?? 0),
    (int) ($stats['manual_review'] ?? 0),
    (int) ($stats['claimant_follow_up'] ?? 0),
    (int) ($stats['transferred'] ?? 0),
    (int) ($stats['rejected'] ?? 0),
];

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Legal Dashboard | UNIFIED DIGITAL CLAIMS SYSTEM', '..', $headExtra); ?>
    <style>
        .legal-shell {
            padding: 1rem 1.25rem 2rem;
        }

        .legal-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.2rem;
            background:
                radial-gradient(circle at 12% 18%, rgba(var(--bk-primary-rgb), 0.18), transparent 34%),
                linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.16), rgba(var(--bk-primary-rgb), 0.04) 52%, rgba(var(--bk-surface-rgb), 1) 100%);
            box-shadow: var(--shadow-soft);
            padding: 1.3rem 1.35rem;
        }

        .legal-hero::after {
            content: '';
            position: absolute;
            width: 16rem;
            height: 16rem;
            right: -4.4rem;
            top: -5.8rem;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(var(--bk-primary-rgb), 0.22), rgba(var(--bk-primary-rgb), 0));
            pointer-events: none;
            animation: float 7s ease-in-out infinite;
        }

        .legal-hero-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 0.95rem;
            align-items: end;
        }

        .legal-hero h1 {
            margin: 0.45rem 0 0;
            font-family: var(--app-display-font), var(--app-font), sans-serif;
            font-size: clamp(1.58rem, 2.6vw, 2.2rem);
            line-height: 1.15;
            color: rgb(var(--bk-text-rgb));
        }

        .legal-hero p {
            margin: 0.55rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            max-width: 44rem;
            font-size: 0.96rem;
        }

        .hero-mini-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.62rem;
        }

        .hero-mini {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.88rem;
            background: rgba(var(--bk-surface-rgb), 0.9);
            box-shadow: var(--shadow-soft);
            padding: 0.66rem 0.72rem;
        }

        .hero-mini-key {
            margin: 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.71rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
        }

        .hero-mini-value {
            margin: 0.2rem 0 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 1.24rem;
            line-height: 1;
            font-weight: 800;
        }

        .legal-risk-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
        }

        .legal-risk-card,
        .legal-panel,
        .legal-link {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
        }

        .legal-risk-card {
            padding: 0.95rem 1rem;
            display: grid;
            gap: 0.48rem;
        }

        .legal-risk-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.7rem;
        }

        .legal-risk-label {
            margin: 0;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgb(var(--bk-muted-rgb));
        }

        .legal-risk-value {
            margin: 0.18rem 0 0;
            font-size: 1.7rem;
            line-height: 1;
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .legal-risk-note {
            margin: 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.79rem;
            line-height: 1.42;
        }

        .legal-risk-icon {
            width: 2.45rem;
            height: 2.45rem;
            border-radius: 0.76rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .risk-manual .legal-risk-icon,
        .risk-children .legal-risk-icon {
            color: rgb(var(--bk-warning-rgb));
            background: rgba(var(--bk-warning-rgb), 0.14);
        }

        .risk-will .legal-risk-icon,
        .risk-followup .legal-risk-icon {
            color: rgb(var(--bk-danger-rgb));
            background: rgba(var(--bk-danger-rgb), 0.13);
        }

        .risk-representative .legal-risk-icon,
        .risk-neutral .legal-risk-icon {
            color: rgb(var(--bk-primary-rgb));
            background: rgba(var(--bk-primary-rgb), 0.13);
        }

        .risk-aging .legal-risk-icon {
            color: rgb(var(--bk-success-rgb));
            background: rgba(var(--bk-success-rgb), 0.13);
        }

        .legal-main-grid,
        .legal-insights-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr);
            gap: 0.9rem;
            align-items: start;
        }

        .legal-panel {
            overflow: hidden;
        }

        .legal-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            padding: 0.85rem 0.95rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-primary-rgb), 0.05);
        }

        .legal-panel-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 1rem;
            font-weight: 800;
        }

        .legal-panel-subtitle {
            margin: 0.18rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.78rem;
        }

        .legal-panel-body {
            padding: 0.95rem;
        }

        .legal-panel-foot {
            border-top: 1px solid rgba(var(--bk-border-rgb), 1);
            padding: 0.82rem 0.9rem;
            display: flex;
            justify-content: flex-end;
        }

        .metric-pill,
        .status-pill,
        .mini-pill,
        .priority-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.2rem 0.58rem;
            font-size: 0.71rem;
            font-weight: 700;
            white-space: nowrap;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
        }

        .metric-pill,
        .mini-pill,
        .priority-pill {
            background: rgba(var(--bk-primary-rgb), 0.07);
            color: rgb(var(--bk-primary-rgb));
            border-color: rgba(var(--bk-primary-rgb), 0.18);
        }

        .status-pill.is-pending {
            color: rgb(var(--bk-warning-rgb));
            background: rgba(var(--bk-warning-rgb), 0.13);
            border-color: rgba(var(--bk-warning-rgb), 0.28);
        }

        .status-pill.is-review {
            color: rgb(var(--bk-primary-rgb));
            background: rgba(var(--bk-primary-rgb), 0.12);
            border-color: rgba(var(--bk-primary-rgb), 0.26);
        }

        .status-pill.is-approved {
            color: rgb(var(--bk-success-rgb));
            background: rgba(var(--bk-success-rgb), 0.13);
            border-color: rgba(var(--bk-success-rgb), 0.26);
        }

        .status-pill.is-rejected {
            color: rgb(var(--bk-danger-rgb));
            background: rgba(var(--bk-danger-rgb), 0.12);
            border-color: rgba(var(--bk-danger-rgb), 0.26);
        }

        .status-pill.is-neutral {
            color: rgb(var(--bk-muted-rgb));
            background: rgba(var(--bk-muted-rgb), 0.12);
            border-color: rgba(var(--bk-muted-rgb), 0.2);
        }

        .priority-list,
        .action-feed,
        .queue-list {
            display: grid;
            gap: 0.72rem;
        }

        .priority-item,
        .action-feed-item,
        .queue-item {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.92rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.8rem 0.84rem;
        }

        .priority-head,
        .queue-head,
        .action-feed-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.7rem;
        }

        .priority-title,
        .queue-title,
        .action-feed-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.9rem;
            font-weight: 800;
        }

        .priority-note,
        .queue-note,
        .action-feed-note {
            margin: 0.18rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.78rem;
            line-height: 1.38;
        }

        .priority-meta,
        .queue-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.42rem;
            margin-top: 0.58rem;
        }

        .priority-actions,
        .queue-actions {
            margin-top: 0.65rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            flex-wrap: wrap;
        }

        .queue-item:nth-child(odd) {
            background: rgba(var(--bk-primary-rgb), 0.03);
        }

        .queue-right {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.42rem;
            justify-content: flex-end;
        }

        .chart-wrap {
            min-height: 19rem;
        }

        .chart-wrap.is-compact {
            min-height: 18rem;
        }

        .legal-links-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
        }

        .legal-link {
            display: flex;
            align-items: flex-start;
            gap: 0.72rem;
            padding: 0.88rem 0.92rem;
            text-decoration: none;
            color: rgb(var(--bk-text-rgb));
            transition: transform 0.16s ease, border-color 0.16s ease, background-color 0.16s ease;
        }

        .legal-link:hover {
            transform: translateY(-1px);
            border-color: rgba(var(--bk-primary-rgb), 0.48);
            background: rgba(var(--bk-primary-rgb), 0.07);
            color: rgb(var(--bk-text-rgb));
        }

        .legal-link-icon {
            width: 2.3rem;
            height: 2.3rem;
            border-radius: 0.74rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(var(--bk-primary-rgb), 0.12);
            color: rgb(var(--bk-primary-rgb));
            flex-shrink: 0;
            font-size: 0.95rem;
        }

        .legal-link-body {
            flex: 1;
            min-width: 0;
        }

        .legal-link-title {
            display: block;
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.88rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .legal-link-note {
            display: block;
            margin-top: 0.22rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.77rem;
            line-height: 1.34;
        }

        .legal-link-arrow {
            color: rgb(var(--bk-muted-rgb));
            transition: transform 0.16s ease, color 0.16s ease;
        }

        .legal-link:hover .legal-link-arrow {
            color: rgb(var(--bk-primary-rgb));
            transform: translateX(2px) translateY(-1px);
        }

        .empty-state {
            border: 1px dashed rgba(var(--bk-border-rgb), 1);
            border-radius: 0.9rem;
            background: rgba(var(--bk-bg-rgb), 0.55);
            text-align: center;
            color: rgb(var(--bk-muted-rgb));
            padding: 1.45rem 0.9rem;
        }

        .legal-footer {
            margin-top: 1rem;
            border-top: 1px solid rgba(var(--bk-border-rgb), 1);
            padding-top: 0.82rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.78rem;
            display: flex;
            justify-content: space-between;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        @media (max-width: 1180px) {
            .legal-risk-grid,
            .legal-links-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 1024px) {
            .legal-main-grid,
            .legal-insights-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .legal-hero-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 780px) {
            .legal-shell {
                padding-left: 0.82rem;
                padding-right: 0.82rem;
            }

            .hero-mini-grid,
            .legal-risk-grid,
            .legal-links-grid {
                grid-template-columns: 1fr;
            }

            .priority-head,
            .queue-head,
            .action-feed-head,
            .priority-actions,
            .queue-actions {
                align-items: flex-start;
                flex-direction: column;
            }

            .queue-right {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body class="bk-role-page bk-role-legal">
<?php include 'navbar.php'; ?>

<main class="main-content legal-shell">
    <section class="legal-hero">
        <div class="legal-hero-grid">
            <div>
                <h1>Legal Dashboard</h1>
                <p>
                    Keep sensitive claims moving by opening manual-review exceptions, claimant follow-ups,
                    and aging queue items before the rest of the legal workload.
                </p>
            </div>
            <div class="hero-mini-grid">
                <article class="hero-mini">
                    <p class="hero-mini-key">Pending Review</p>
                    <p class="hero-mini-value"><?php echo number_format((int) ($stats['pending_review'] ?? 0)); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="hero-mini-key">Manual Review</p>
                    <p class="hero-mini-value"><?php echo number_format((int) ($stats['manual_review'] ?? 0)); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="hero-mini-key">Claimant Follow-Ups</p>
                    <p class="hero-mini-value"><?php echo number_format((int) ($stats['claimant_follow_up'] ?? 0)); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="hero-mini-key">Transferred</p>
                    <p class="hero-mini-value"><?php echo number_format((int) ($stats['transferred'] ?? 0)); ?></p>
                </article>
            </div>
        </div>
    </section>

    <?php if ($error !== ''): ?>
        <div class="mt-4">
            <?php render_alert($error, ['type' => 'danger']); ?>
        </div>
    <?php endif; ?>

    <section class="legal-risk-grid">
        <article class="legal-risk-card risk-will">
            <div class="legal-risk-top">
                <div>
                    <p class="legal-risk-label">Will On Record</p>
                    <p class="legal-risk-value"><?php echo number_format((int) ($riskStats['will_cases'] ?? 0)); ?></p>
                </div>
                <span class="legal-risk-icon"><i class="bi bi-file-earmark-text"></i></span>
            </div>
            <p class="legal-risk-note">Claims in your active queue that already require careful will-related legal attention.</p>
        </article>

        <article class="legal-risk-card risk-children">
            <div class="legal-risk-top">
                <div>
                    <p class="legal-risk-label">Children Unknown</p>
                    <p class="legal-risk-value"><?php echo number_format((int) ($riskStats['unknown_children'] ?? 0)); ?></p>
                </div>
                <span class="legal-risk-icon"><i class="bi bi-people"></i></span>
            </div>
            <p class="legal-risk-note">Claims where heir disclosure remains incomplete because children status is still unknown.</p>
        </article>

        <article class="legal-risk-card risk-representative">
            <div class="legal-risk-top">
                <div>
                    <p class="legal-risk-label">Representative Path</p>
                    <p class="legal-risk-value"><?php echo number_format((int) ($riskStats['representative_cases'] ?? 0)); ?></p>
                </div>
                <span class="legal-risk-icon"><i class="bi bi-person-check"></i></span>
            </div>
            <p class="legal-risk-note">Claimant is acting for other heirs, so authority and disclosure need closer review.</p>
        </article>

        <article class="legal-risk-card risk-aging">
            <div class="legal-risk-top">
                <div>
                    <p class="legal-risk-label">Aging 7+ Days</p>
                    <p class="legal-risk-value"><?php echo number_format((int) ($riskStats['aging_7'] ?? 0)); ?></p>
                </div>
                <span class="legal-risk-icon"><i class="bi bi-hourglass-split"></i></span>
            </div>
            <p class="legal-risk-note">Active legal claims that have been sitting in queue long enough to deserve fresh attention.</p>
        </article>
    </section>

    <section class="legal-main-grid">
        <article class="legal-panel">
            <header class="legal-panel-head">
                <div>
                    <h2 class="legal-panel-title">Priority Legal Queue</h2>
                    <p class="legal-panel-subtitle">Open these first when you want the highest legal impact on queue safety and flow.</p>
                </div>
                <span class="metric-pill"><?php echo number_format($heroQueueTotal); ?> active</span>
            </header>
            <div class="legal-panel-body">
                <?php if (empty($priorityClaims)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox fs-4"></i>
                        <p class="mt-2 mb-0">No active legal queue items are assigned to you right now.</p>
                    </div>
                <?php else: ?>
                    <div class="priority-list">
                        <?php foreach ($priorityClaims as $claim): ?>
                            <?php
                            $priorityMeta = legal_dashboard_priority_meta($claim);
                            $claimId = (int) ($claim['id'] ?? 0);
                            $status = (string) ($claim['effective_status'] ?? 'Pending Legal Review');
                            $statusLabel = udcs_claim_status_label($status);
                            $assetSummary = udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''));
                            ?>
                            <article class="priority-item">
                                <div class="priority-head">
                                    <div>
                                        <h3 class="priority-title">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?> | <?php echo htmlspecialchars((string) ($claim['claimant_name'] ?? 'Unknown claimant')); ?></h3>
                                        <p class="priority-note">Deceased: <?php echo htmlspecialchars((string) ($claim['deceased_name'] ?? 'Not recorded')); ?> | <?php echo htmlspecialchars($priorityMeta['note']); ?></p>
                                    </div>
                                    <span class="priority-pill <?php echo htmlspecialchars((string) ($priorityMeta['class'] ?? 'risk-neutral')); ?>"><?php echo htmlspecialchars((string) ($priorityMeta['label'] ?? 'Pending Review')); ?></span>
                                </div>
                                <div class="priority-meta">
                                    <span class="mini-pill"><?php echo htmlspecialchars($assetSummary); ?></span>
                                    <span class="mini-pill"><?php echo htmlspecialchars(legal_dashboard_family_summary($claim)); ?></span>
                                    <span class="status-pill <?php echo htmlspecialchars(legal_dashboard_status_badge_class($status)); ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                </div>
                                <div class="priority-actions">
                                    <span class="legal-risk-note">Date: <?php echo htmlspecialchars(legal_dashboard_date_label((string) ($claim['submitted_at'] ?? ''))); ?> | <?php echo htmlspecialchars(legal_dashboard_time_ago((string) ($claim['submitted_at'] ?? ''))); ?></span>
                                    <a class="ui-btn ui-btn-sm ui-btn-secondary" href="claims.php?search=<?php echo $claimId; ?>">
                                        <i class="bi bi-arrow-up-right-circle"></i>
                                        <span>Open Claims Review</span>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="legal-panel">
            <header class="legal-panel-head">
                <div>
                    <h2 class="legal-panel-title">Recent Legal Actions</h2>
                    <p class="legal-panel-subtitle">Your latest recorded review moves, transfers, and follow-up decisions.</p>
                </div>
            </header>
            <div class="legal-panel-body">
                <?php if (empty($recentActions)): ?>
                    <div class="empty-state">
                        <i class="bi bi-journal-check fs-4"></i>
                        <p class="mt-2 mb-0">No legal actions have been logged for your account yet.</p>
                    </div>
                <?php else: ?>
                    <div class="action-feed">
                        <?php foreach ($recentActions as $action): ?>
                            <?php
                            $actionClaimId = (int) ($action['claim_id'] ?? 0);
                            $actionStatus = (string) ($action['effective_status'] ?? '');
                            ?>
                            <article class="action-feed-item">
                                <div class="action-feed-head">
                                    <div>
                                        <h3 class="action-feed-title"><?php echo htmlspecialchars((string) ($action['action_label'] ?? 'Legal update')); ?></h3>
                                        <p class="action-feed-note"><?php echo htmlspecialchars((string) ($action['claimant_name'] ?? 'Unknown claimant')); ?> | <?php echo htmlspecialchars((string) ($action['deceased_name'] ?? 'Not recorded')); ?></p>
                                    </div>
                                    <?php if ($actionClaimId > 0): ?>
                                        <span class="mini-pill">CL-<?php echo str_pad((string) $actionClaimId, 6, '0', STR_PAD_LEFT); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="priority-meta">
                                    <?php if ($actionStatus !== ''): ?>
                                        <span class="status-pill <?php echo htmlspecialchars(legal_dashboard_status_badge_class($actionStatus)); ?>"><?php echo htmlspecialchars(udcs_claim_status_label($actionStatus)); ?></span>
                                    <?php endif; ?>
                                    <span class="mini-pill"><?php echo htmlspecialchars(legal_dashboard_time_ago((string) ($action['created_at'] ?? ''))); ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="legal-panel-foot">
                <a class="ui-btn ui-btn-sm ui-btn-secondary" href="claims.php">
                    <i class="bi bi-list-task"></i>
                    <span>Open Claims Review</span>
                </a>
            </footer>
        </article>
    </section>

    <section class="legal-panel" style="margin-top:1rem; overflow:hidden;">
        <header class="legal-panel-head">
            <div>
                <h2 class="legal-panel-title">Assigned Action Queue</h2>
                <p class="legal-panel-subtitle">The claims still sitting in your active legal lane, arranged so the highest-risk work stays visible.</p>
            </div>
            <span class="metric-pill"><?php echo number_format(count($queueClaims)); ?> queued</span>
        </header>
        <div class="legal-panel-body">
            <?php if (empty($queueClaims)): ?>
                <div class="empty-state">
                    <i class="bi bi-folder2-open fs-4"></i>
                    <p class="mt-2 mb-0">No claims are waiting in your active legal queue.</p>
                </div>
            <?php else: ?>
                <div class="queue-list">
                    <?php foreach ($queueClaims as $claim): ?>
                        <?php
                        $claimId = (int) ($claim['id'] ?? 0);
                        $status = (string) ($claim['effective_status'] ?? 'Pending Legal Review');
                        $statusLabel = udcs_claim_status_label($status);
                        $assetSummary = udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''));
                        $priorityMeta = legal_dashboard_priority_meta($claim);
                        ?>
                        <article class="queue-item">
                            <div class="queue-head">
                                <div>
                                    <h3 class="queue-title">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?> | <?php echo htmlspecialchars((string) ($claim['claimant_name'] ?? 'Unknown claimant')); ?></h3>
                                    <p class="queue-note">Deceased: <?php echo htmlspecialchars((string) ($claim['deceased_name'] ?? 'Not recorded')); ?> | <?php echo htmlspecialchars(legal_dashboard_family_summary($claim)); ?></p>
                                </div>
                                <div class="queue-right">
                                    <span class="priority-pill <?php echo htmlspecialchars((string) ($priorityMeta['class'] ?? 'risk-neutral')); ?>"><?php echo htmlspecialchars((string) ($priorityMeta['label'] ?? 'Pending Review')); ?></span>
                                    <span class="status-pill <?php echo htmlspecialchars(legal_dashboard_status_badge_class($status)); ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                </div>
                            </div>
                            <div class="queue-meta">
                                <span class="mini-pill"><?php echo htmlspecialchars($assetSummary); ?></span>
                                <span class="mini-pill"><?php echo htmlspecialchars(legal_dashboard_date_label((string) ($claim['submitted_at'] ?? ''))); ?></span>
                                <span class="mini-pill"><?php echo htmlspecialchars(legal_dashboard_time_ago((string) ($claim['submitted_at'] ?? ''))); ?></span>
                            </div>
                            <div class="queue-actions">
                                <span class="legal-risk-note"><?php echo htmlspecialchars($priorityMeta['note']); ?></span>
                                <a class="ui-btn ui-btn-sm ui-btn-secondary" href="claims.php?search=<?php echo $claimId; ?>">
                                    <i class="bi bi-arrow-up-right-circle"></i>
                                    <span>Open Claims Review</span>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="legal-insights-grid">
        <article class="legal-panel">
            <header class="legal-panel-head">
                <div>
                    <h2 class="legal-panel-title">Legal Decision Trend</h2>
                    <p class="legal-panel-subtitle">Daily movement through pending review, manual review, and transfers over the last 14 days.</p>
                </div>
            </header>
            <div class="legal-panel-body">
                <div class="chart-wrap">
                    <canvas id="legalTrendChart" aria-label="Legal decision trend chart"></canvas>
                </div>
            </div>
        </article>

        <article class="legal-panel">
            <header class="legal-panel-head">
                <div>
                    <h2 class="legal-panel-title">Queue Composition</h2>
                    <p class="legal-panel-subtitle">How your assigned legal workload is currently distributed across review lanes.</p>
                </div>
            </header>
            <div class="legal-panel-body">
                <div class="chart-wrap is-compact">
                    <canvas id="legalCompositionChart" aria-label="Legal queue composition chart"></canvas>
                </div>
            </div>
        </article>
    </section>

    <section class="legal-links-grid">
        <a class="legal-link" href="claims.php">
            <span class="legal-link-icon"><i class="bi bi-files"></i></span>
            <span class="legal-link-body">
                <span class="legal-link-title">Claims Review</span>
                <span class="legal-link-note">Open the full queue, search claims, and make legal decisions.</span>
            </span>
            <span class="legal-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="legal-link" href="claims.php">
            <span class="legal-link-icon"><i class="bi bi-bar-chart"></i></span>
            <span class="legal-link-body">
                <span class="legal-link-title">Reports</span>
                <span class="legal-link-note">Open Claims Review and export the current legal queue picture.</span>
            </span>
            <span class="legal-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="legal-link" href="messaging.php">
            <span class="legal-link-icon"><i class="bi bi-chat-dots"></i></span>
            <span class="legal-link-body">
                <span class="legal-link-title">Messaging</span>
                <span class="legal-link-note">Send or review claimant and staff follow-up messages tied to legal review.</span>
            </span>
            <span class="legal-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="legal-link" href="profile.php">
            <span class="legal-link-icon"><i class="bi bi-person-gear"></i></span>
            <span class="legal-link-body">
                <span class="legal-link-title">Profile</span>
                <span class="legal-link-note">Update account details without leaving the legal workspace.</span>
            </span>
            <span class="legal-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>
    </section>

    <footer class="legal-footer">
        <span><i class="bi bi-shield-check me-1"></i> UNIFIED DIGITAL CLAIMS SYSTEM</span>
        <span><?php echo number_format((int) ($stats['total_assigned'] ?? 0)); ?> total assigned claim(s) | <?php echo number_format((int) ($stats['closed'] ?? 0)); ?> already beyond legal review.</span>
    </footer>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    if (typeof Chart === 'undefined') {
        return;
    }

    const rootStyles = getComputedStyle(document.documentElement);
    const primary = `rgb(${rootStyles.getPropertyValue('--bk-primary-rgb').trim()})`;
    const success = `rgb(${rootStyles.getPropertyValue('--bk-success-rgb').trim()})`;
    const warning = `rgb(${rootStyles.getPropertyValue('--bk-warning-rgb').trim()})`;
    const danger = `rgb(${rootStyles.getPropertyValue('--bk-danger-rgb').trim()})`;
    const muted = `rgb(${rootStyles.getPropertyValue('--bk-muted-rgb').trim()})`;

    const trendCanvas = document.getElementById('legalTrendChart');
    if (trendCanvas) {
        new Chart(trendCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($trendDays, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [
                    {
                        label: 'Pending Review',
                        data: <?php echo json_encode($pendingData); ?>,
                        backgroundColor: 'rgba(180, 83, 9, 0.86)',
                        borderColor: warning,
                        borderWidth: 1.5,
                        borderRadius: 6,
                    },
                    {
                        label: 'Manual Review',
                        data: <?php echo json_encode($manualData); ?>,
                        backgroundColor: 'rgba(3, 78, 162, 0.82)',
                        borderColor: primary,
                        borderWidth: 1.5,
                        borderRadius: 6,
                    },
                    {
                        label: 'Transferred',
                        data: <?php echo json_encode($transferredData); ?>,
                        backgroundColor: 'rgba(21, 128, 61, 0.82)',
                        borderColor: success,
                        borderWidth: 1.5,
                        borderRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: muted,
                            usePointStyle: true,
                            boxWidth: 9,
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: muted },
                        grid: { color: 'rgba(3, 78, 162, 0.08)' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: muted, precision: 0 },
                        grid: { color: 'rgba(3, 78, 162, 0.08)' }
                    }
                }
            }
        });
    }

    const compositionCanvas = document.getElementById('legalCompositionChart');
    if (compositionCanvas) {
        new Chart(compositionCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($compositionLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($compositionCounts); ?>,
                    backgroundColor: [warning, primary, danger, success, 'rgba(185, 28, 28, 0.82)'],
                    borderColor: '#ffffff',
                    borderWidth: 2,
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: muted,
                            usePointStyle: true,
                            boxWidth: 9,
                        }
                    }
                }
            }
        });
    }
})();
</script>
</body>
</html>
