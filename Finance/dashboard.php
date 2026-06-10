<?php
// Tags: [FINANCE] [DASH]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/alert.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'finance') {
    header('Location: ../login.php');
    exit();
}

$sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $sessionEmail, 'finance');
if (!$user_data) {
    header('Location: ../login.php');
    exit();
}

$finance_id = (int) ($user_data['id'] ?? 0);
$finance_name = (string) ($user_data['full_name'] ?? 'Finance Officer');
$userPhoto = (string) ($user_data['photo'] ?? '');
$photo = $userPhoto !== '' ? '../uploads/' . ltrim($userPhoto, '/\\') : '../Images/logo.png';

bk_claims_ensure_workflow_schema($conn);
udcs_claims_v2_ensure_schema($conn);
bk_backfill_unassigned_claims($conn);
bk_activity_ensure_schema($conn);
$claimAccountSql = udcs_claim_account_reference_sql('c');

function finance_dashboard_stmt_result(mysqli $conn, string $sql, string $types = '', array $params = [])
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

function finance_dashboard_single_row($result): array
{
    if (!$result) {
        return [];
    }
    $row = mysqli_fetch_assoc($result);
    return is_array($row) ? $row : [];
}

function finance_dashboard_currency($amount, string $currency = 'RWF'): string
{
    return bk_claim_amount_display($amount, $currency, 'Not declared');
}

function finance_dashboard_totals_by_currency(mysqli $conn, int $financeId, string $amountColumn, string $statusWhere = ''): array
{
    if (!in_array($amountColumn, ['estimated_value', 'verified_value'], true)) {
        return [];
    }

    $result = finance_dashboard_stmt_result(
        $conn,
        "
        SELECT
            COALESCE(NULLIF(ca.currency_code, ''), 'RWF') AS currency_code,
            SUM(COALESCE(ca.$amountColumn, 0)) AS total
        FROM claim_assets ca
        INNER JOIN claims c ON c.id = ca.claim_id
        WHERE c.assigned_finance_id = ?
          $statusWhere
        GROUP BY COALESCE(NULLIF(ca.currency_code, ''), 'RWF')
        ORDER BY currency_code
        ",
        'i',
        [$financeId]
    );

    $totals = [];
    if (!$result) {
        return $totals;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $currency = bk_currency_code((string) ($row['currency_code'] ?? 'RWF'));
        $total = (float) ($row['total'] ?? 0);
        if ($total > 0) {
            $totals[$currency] = $total;
        }
    }

    return $totals;
}

function finance_dashboard_totals_label(array $totals, string $fallback = 'No value recorded'): string
{
    return bk_amount_totals_label($totals, $fallback);
}

function finance_dashboard_time_ago(?string $datetime): string
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

function finance_dashboard_date_label(?string $datetime): string
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

function finance_dashboard_status_badge_class(string $status): string
{
    return match (udcs_claim_status_class($status)) {
        'status-pending' => 'is-pending',
        'status-review', 'status-warning' => 'is-review',
        'status-approved' => 'is-approved',
        'status-rejected' => 'is-rejected',
        default => 'is-neutral',
    };
}

function finance_dashboard_event_icon(string $type): string
{
    return match (strtolower(trim($type))) {
        'claim_submitted' => 'bi-plus-circle',
        'status_changed' => 'bi-arrow-repeat',
        'document_uploaded' => 'bi-paperclip',
        default => 'bi-bell',
    };
}

function finance_dashboard_verification_label(array $claim): string
{
    $assetCount = (int) ($claim['asset_count'] ?? 0);
    $reviewed = (int) ($claim['reviewed_assets'] ?? 0);
    $confirmed = (int) ($claim['confirmed_assets'] ?? 0);
    $holds = (int) ($claim['hold_assets'] ?? 0);
    $manual = (int) ($claim['manual_assets'] ?? 0);
    $missing = (int) ($claim['missing_assets'] ?? 0);

    if ($assetCount <= 0) {
        return 'No BK asset rows linked';
    }
    if ($holds > 0) {
        return $holds . ' restriction/hold found';
    }
    if ($manual > 0) {
        return $manual . ' manual follow-up asset(s)';
    }
    if ($missing > 0) {
        return $missing . ' unmatched asset(s)';
    }
    if ($reviewed <= 0) {
        return 'Verification not started';
    }
    if ($confirmed === $assetCount) {
        return 'All assets confirmed';
    }
    return $reviewed . ' of ' . $assetCount . ' asset(s) reviewed';
}

function finance_dashboard_priority_meta(array $claim): array
{
    $status = trim((string) ($claim['effective_status'] ?? ''));
    $assetCount = (int) ($claim['asset_count'] ?? 0);
    $reviewed = (int) ($claim['reviewed_assets'] ?? 0);
    $holds = (int) ($claim['hold_assets'] ?? 0);
    $manual = (int) ($claim['manual_assets'] ?? 0);
    $missing = (int) ($claim['missing_assets'] ?? 0);
    $settlementComplete = (int) ($claim['settlement_complete'] ?? 0) === 1;
    $returnReason = trim((string) ($claim['finance_return_reason'] ?? ''));

    if ($status === 'Returned by Finance') {
        return [
            'label' => 'Returned for Clarification',
            'class' => 'risk-return',
            'note' => $returnReason !== '' ? $returnReason : 'This claim has already been returned from Finance and still needs correction.',
        ];
    }
    if ($holds > 0) {
        return [
            'label' => 'Restriction / Hold',
            'class' => 'risk-hold',
            'note' => 'A hold or restriction was found during BK verification and needs resolution before closure.',
        ];
    }
    if ($missing > 0) {
        return [
            'label' => 'No Asset Match',
            'class' => 'risk-missing',
            'note' => 'At least one declared BK asset still has no matching record in the bank-side verification step.',
        ];
    }
    if ($manual > 0) {
        return [
            'label' => 'Manual Follow-Up',
            'class' => 'risk-manual',
            'note' => 'At least one asset needs manual finance follow-up before the claim can move forward.',
        ];
    }
    if ($assetCount > 0 && $reviewed < $assetCount) {
        return [
            'label' => 'Verification Pending',
            'class' => 'risk-review',
            'note' => 'Not every asset row has been fully verified in BK records yet.',
        ];
    }
    if (!$settlementComplete) {
        return [
            'label' => 'Settlement Gap',
            'class' => 'risk-settlement',
            'note' => 'The payout destination or settlement instruction is still incomplete for finance closure.',
        ];
    }
    if ($assetCount > 1) {
        return [
            'label' => 'Multi-Asset',
            'class' => 'risk-multi',
            'note' => 'This queue item spans multiple BK assets and deserves closer settlement coordination.',
        ];
    }

    return [
        'label' => 'Ready to Close',
        'class' => 'risk-ready',
        'note' => 'Verification and settlement inputs appear complete enough for final finance review.',
    ];
}

$stats = [
    'total_assigned' => 0,
    'pending_review' => 0,
    'returned_count' => 0,
    'closed_count' => 0,
];
$riskStats = [
    'verification_gaps' => 0,
    'settlement_gaps' => 0,
    'multi_asset' => 0,
    'returned_clarification' => 0,
    'ready_to_close' => 0,
];
$queueClaims = [];
$recentEvents = [];
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$pendingValueData = array_fill(0, 12, 0);
$closedValueData = array_fill(0, 12, 0);
$returnedValueData = array_fill(0, 12, 0);
$assignedValueLabel = 'No value recorded';
$disbursedValueLabel = 'No value recorded';
$error = '';

$financeActiveStatusesSql = "('Pending Finance Review', 'Returned by Finance', 'Approved for Disbursement', 'transferred to finance', 'approved by legal', 'under_review')";
$financePendingStatusesSql = "('Pending Finance Review', 'Approved for Disbursement', 'transferred to finance', 'approved by legal')";
$financeReturnedStatusesSql = "('Returned by Finance', 'rejected by finance')";
$financeClosedStatusesSql = "('Disbursed', 'Closed', 'approved by finance')";

$assetAggSql = "
    SELECT
        claim_id,
        COUNT(*) AS asset_count,
        SUM(CASE WHEN COALESCE(NULLIF(finance_status, ''), '') <> '' THEN 1 ELSE 0 END) AS reviewed_assets,
        SUM(CASE WHEN finance_status = 'Confirmed in BK records' THEN 1 ELSE 0 END) AS confirmed_assets,
        SUM(CASE WHEN finance_status = 'Restriction or hold found' THEN 1 ELSE 0 END) AS hold_assets,
        SUM(CASE WHEN finance_status = 'Manual follow-up required' THEN 1 ELSE 0 END) AS manual_assets,
        SUM(CASE WHEN finance_status = 'No matching BK asset found' THEN 1 ELSE 0 END) AS missing_assets,
        SUM(COALESCE(verified_value, 0)) AS verified_total
    FROM claim_assets
    GROUP BY claim_id
";

try {
    $statsResult = finance_dashboard_stmt_result(
        $conn,
        "
        SELECT
            COUNT(*) AS total_assigned,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $financePendingStatusesSql THEN 1 ELSE 0 END) AS pending_review,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $financeReturnedStatusesSql THEN 1 ELSE 0 END) AS returned_count,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $financeClosedStatusesSql THEN 1 ELSE 0 END) AS closed_count
        FROM claims
        WHERE assigned_finance_id = ?
        ",
        'i',
        [$finance_id]
    );
    $stats = array_merge($stats, finance_dashboard_single_row($statsResult));
    $assignedValueLabel = finance_dashboard_totals_label(
        finance_dashboard_totals_by_currency($conn, $finance_id, 'estimated_value'),
        'No declared asset value'
    );
    $disbursedValueLabel = finance_dashboard_totals_label(
        finance_dashboard_totals_by_currency(
            $conn,
            $finance_id,
            'verified_value',
            "AND COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeClosedStatusesSql"
        ),
        'No verified disbursement value'
    );

    $riskResult = finance_dashboard_stmt_result(
        $conn,
        "
        SELECT
            SUM(
                CASE
                    WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeActiveStatusesSql
                         AND COALESCE(fa.asset_count, 0) > 0
                         AND COALESCE(fa.reviewed_assets, 0) < COALESCE(fa.asset_count, 0)
                    THEN 1 ELSE 0
                END
            ) AS verification_gaps,
            SUM(
                CASE
                    WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeActiveStatusesSql
                         AND (
                            COALESCE(NULLIF(c.preferred_payout_method, ''), NULLIF(c.distribution_method, '')) = ''
                            OR (
                                LOWER(COALESCE(NULLIF(c.preferred_payout_method, ''), NULLIF(c.distribution_method, ''))) <> 'hold_pending_instruction'
                                AND TRIM(COALESCE(c.distribution_details, '')) = ''
                            )
                         )
                    THEN 1 ELSE 0
                END
            ) AS settlement_gaps,
            SUM(
                CASE
                    WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeActiveStatusesSql
                         AND COALESCE(fa.asset_count, 0) > 1
                    THEN 1 ELSE 0
                END
            ) AS multi_asset,
            SUM(
                CASE
                    WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeReturnedStatusesSql
                    THEN 1 ELSE 0
                END
            ) AS returned_clarification,
            SUM(
                CASE
                    WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeActiveStatusesSql
                         AND COALESCE(fa.asset_count, 0) > 0
                         AND COALESCE(fa.reviewed_assets, 0) = COALESCE(fa.asset_count, 0)
                         AND COALESCE(fa.hold_assets, 0) = 0
                         AND COALESCE(fa.manual_assets, 0) = 0
                         AND COALESCE(fa.missing_assets, 0) = 0
                         AND (
                            LOWER(COALESCE(NULLIF(c.preferred_payout_method, ''), NULLIF(c.distribution_method, ''))) = 'hold_pending_instruction'
                            OR (
                                COALESCE(NULLIF(c.preferred_payout_method, ''), NULLIF(c.distribution_method, '')) <> ''
                                AND TRIM(COALESCE(c.distribution_details, '')) <> ''
                            )
                         )
                    THEN 1 ELSE 0
                END
            ) AS ready_to_close
        FROM claims c
        LEFT JOIN ($assetAggSql) fa ON fa.claim_id = c.id
        WHERE c.assigned_finance_id = ?
        ",
        'i',
        [$finance_id]
    );
    $riskStats = array_merge($riskStats, finance_dashboard_single_row($riskResult));

    $queueResult = finance_dashboard_stmt_result(
        $conn,
        "
        SELECT
            c.id,
            COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
            c.submitted_at,
            c.updated_at,
            c.claim_amount,
            c.claim_currency_code,
            c.claim_type,
            {$claimAccountSql} AS account_number,
            {$claimAccountSql} AS accout_number,
            c.distribution_method,
            c.distribution_details,
            c.finance_return_reason,
            COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name, 'Not recorded') AS deceased_name,
            u.full_name AS claimant_name,
            COALESCE(ca.asset_classes, '') AS asset_classes,
            COALESCE(fa.asset_count, 0) AS asset_count,
            COALESCE(fa.reviewed_assets, 0) AS reviewed_assets,
            COALESCE(fa.confirmed_assets, 0) AS confirmed_assets,
            COALESCE(fa.hold_assets, 0) AS hold_assets,
            COALESCE(fa.manual_assets, 0) AS manual_assets,
            COALESCE(fa.missing_assets, 0) AS missing_assets,
            COALESCE(fa.verified_total, 0) AS verified_total,
            CASE
                WHEN COALESCE(NULLIF(c.preferred_payout_method, ''), NULLIF(c.distribution_method, '')) = '' THEN 0
                WHEN LOWER(COALESCE(NULLIF(c.preferred_payout_method, ''), NULLIF(c.distribution_method, ''))) = 'hold_pending_instruction' THEN 1
                WHEN TRIM(COALESCE(c.distribution_details, '')) <> '' THEN 1
                ELSE 0
            END AS settlement_complete
        FROM claims c
        INNER JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
        LEFT JOIN (
            SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
            FROM claim_assets
            GROUP BY claim_id
        ) ca ON ca.claim_id = c.id
        LEFT JOIN ($assetAggSql) fa ON fa.claim_id = c.id
        WHERE c.assigned_finance_id = ?
          AND COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeActiveStatusesSql
        ORDER BY
            CASE
                WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) = 'Returned by Finance' THEN 1
                WHEN COALESCE(fa.hold_assets, 0) > 0 THEN 2
                WHEN COALESCE(fa.missing_assets, 0) > 0 THEN 3
                WHEN COALESCE(fa.manual_assets, 0) > 0 THEN 4
                WHEN COALESCE(fa.reviewed_assets, 0) < COALESCE(fa.asset_count, 0) THEN 5
                WHEN COALESCE(fa.asset_count, 0) > 1 THEN 6
                ELSE 7
            END,
            COALESCE(c.updated_at, c.submitted_at) DESC,
            c.id DESC
        LIMIT 10
        ",
        'i',
        [$finance_id]
    );
    if ($queueResult) {
        while ($row = mysqli_fetch_assoc($queueResult)) {
            $queueClaims[] = $row;
        }
    }

    $recentEventsResult = finance_dashboard_stmt_result(
        $conn,
        "
        SELECT
            'claim_submitted' AS event_type,
            CONCAT('New claim entered the finance lane for ', u.full_name) AS message,
            c.submitted_at AS created_at,
            'primary' AS tone,
            c.id AS claim_id
        FROM claims c
        INNER JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
        WHERE c.assigned_finance_id = ?
          AND c.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)

        UNION ALL

        SELECT
            'status_changed' AS event_type,
            CONCAT('Claim CL-', LPAD(c.id, 6, '0'), ' moved to ', UPPER(COALESCE(NULLIF(c.status, ''), c.claim_status))) AS message,
            c.updated_at AS created_at,
            CASE
                WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeClosedStatusesSql THEN 'success'
                WHEN COALESCE(NULLIF(c.status, ''), c.claim_status) IN $financeReturnedStatusesSql THEN 'danger'
                ELSE 'info'
            END AS tone,
            c.id AS claim_id
        FROM claims c
        WHERE c.assigned_finance_id = ?
          AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 5 DAY)
          AND COALESCE(NULLIF(c.status, ''), c.claim_status) IN ('Pending Finance Review', 'Approved for Disbursement', 'Disbursed', 'Closed', 'Returned by Finance', 'transferred to finance', 'approved by finance', 'rejected by finance')

        UNION ALL

        SELECT
            'document_uploaded' AS event_type,
            CONCAT('New supporting documents were uploaded for claim #', c.id) AS message,
            MAX(d.uploaded_at) AS created_at,
            'info' AS tone,
            c.id AS claim_id
        FROM documents d
        INNER JOIN claims c ON c.id = d.claim_id
        WHERE c.assigned_finance_id = ?
          AND d.uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY c.id

        ORDER BY created_at DESC
        LIMIT 8
        ",
        'iii',
        [$finance_id, $finance_id, $finance_id]
    );
    if ($recentEventsResult) {
        while ($row = mysqli_fetch_assoc($recentEventsResult)) {
            $recentEvents[] = $row;
        }
    }

    $chartMonths = array_fill_keys($months, ['pending' => 0, 'closed' => 0, 'returned' => 0]);
    $chartResult = finance_dashboard_stmt_result(
        $conn,
        "
        SELECT
            MONTH(submitted_at) AS month_num,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $financePendingStatusesSql THEN 1 ELSE 0 END) AS pending_value,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $financeClosedStatusesSql THEN 1 ELSE 0 END) AS closed_value,
            SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN $financeReturnedStatusesSql THEN 1 ELSE 0 END) AS returned_value
        FROM claims
        WHERE assigned_finance_id = ?
          AND submitted_at IS NOT NULL
          AND YEAR(submitted_at) = YEAR(CURDATE())
        GROUP BY MONTH(submitted_at)
        ORDER BY MONTH(submitted_at)
        ",
        'i',
        [$finance_id]
    );
    if ($chartResult) {
        while ($row = mysqli_fetch_assoc($chartResult)) {
            $monthNum = (int) ($row['month_num'] ?? 0);
            if ($monthNum < 1 || $monthNum > 12) {
                continue;
            }
            $monthKey = $months[$monthNum - 1];
            $chartMonths[$monthKey] = [
                'pending' => (float) ($row['pending_value'] ?? 0),
                'closed' => (float) ($row['closed_value'] ?? 0),
                'returned' => (float) ($row['returned_value'] ?? 0),
            ];
        }
    }
    $pendingValueData = array_column($chartMonths, 'pending');
    $closedValueData = array_column($chartMonths, 'closed');
    $returnedValueData = array_column($chartMonths, 'returned');
} catch (Throwable $e) {
    error_log('Finance Dashboard Error: ' . $e->getMessage());
    $error = 'The finance dashboard could not load completely right now. Refresh and try again.';
}

$priorityClaims = array_slice($queueClaims, 0, 5);
$compositionLabels = ['Verification Gaps', 'Settlement Gaps', 'Multi-Asset', 'Returned', 'Ready to Close'];
$compositionCounts = [
    (int) ($riskStats['verification_gaps'] ?? 0),
    (int) ($riskStats['settlement_gaps'] ?? 0),
    (int) ($riskStats['multi_asset'] ?? 0),
    (int) ($riskStats['returned_clarification'] ?? 0),
    (int) ($riskStats['ready_to_close'] ?? 0),
];

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Finance Dashboard | UNIFIED DIGITAL CLAIMS SYSTEM', '..', $headExtra); ?>
    <style>
        .finance-shell {
            padding: 1rem 1.25rem 2rem;
        }

        .finance-hero {
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

        .finance-hero::after {
            content: '';
            position: absolute;
            width: 17rem;
            height: 17rem;
            right: -5rem;
            top: -6rem;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(var(--bk-primary-rgb), 0.22), rgba(var(--bk-primary-rgb), 0));
            pointer-events: none;
            animation: float 7s ease-in-out infinite;
        }

        .finance-hero-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 0.95rem;
            align-items: end;
        }

        .finance-hero h1 {
            margin: 0.4rem 0 0;
            font-family: var(--app-display-font), var(--app-font), sans-serif;
            font-size: clamp(1.54rem, 2.55vw, 2.18rem);
            line-height: 1.15;
            color: rgb(var(--bk-text-rgb));
        }

        .finance-hero p {
            margin: 0.55rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            max-width: 45rem;
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

        .hero-mini-value.is-currency {
            font-size: 1rem;
            line-height: 1.2;
            word-break: break-word;
        }

        .finance-risk-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
        }

        .finance-risk-card,
        .finance-panel,
        .finance-link {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
        }

        .finance-risk-card {
            padding: 0.95rem 1rem;
            display: grid;
            gap: 0.48rem;
        }

        .finance-risk-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.7rem;
        }

        .finance-risk-label {
            margin: 0;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgb(var(--bk-muted-rgb));
        }

        .finance-risk-value {
            margin: 0.18rem 0 0;
            font-size: 1.7rem;
            line-height: 1;
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .finance-risk-note {
            margin: 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.79rem;
            line-height: 1.42;
        }

        .finance-risk-icon {
            width: 2.45rem;
            height: 2.45rem;
            border-radius: 0.76rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .risk-review .finance-risk-icon,
        .risk-multi .finance-risk-icon {
            color: rgb(var(--bk-warning-rgb));
            background: rgba(var(--bk-warning-rgb), 0.14);
        }

        .risk-settlement .finance-risk-icon,
        .risk-ready .finance-risk-icon {
            color: rgb(var(--bk-primary-rgb));
            background: rgba(var(--bk-primary-rgb), 0.13);
        }

        .risk-return .finance-risk-icon,
        .risk-missing .finance-risk-icon {
            color: rgb(var(--bk-danger-rgb));
            background: rgba(var(--bk-danger-rgb), 0.13);
        }

        .risk-hold .finance-risk-icon,
        .risk-manual .finance-risk-icon {
            color: rgb(var(--bk-success-rgb));
            background: rgba(var(--bk-success-rgb), 0.13);
        }

        .finance-main-grid,
        .finance-insights-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr);
            gap: 0.9rem;
            align-items: start;
        }

        .finance-panel {
            overflow: hidden;
        }

        .finance-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            padding: 0.85rem 0.95rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-primary-rgb), 0.05);
        }

        .finance-panel-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 1rem;
            font-weight: 800;
        }

        .finance-panel-subtitle {
            margin: 0.18rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.78rem;
        }

        .finance-panel-body {
            padding: 0.95rem;
        }

        .finance-panel-foot {
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
        .event-feed,
        .queue-list {
            display: grid;
            gap: 0.72rem;
        }

        .priority-item,
        .event-feed-item,
        .queue-item {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.92rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.8rem 0.84rem;
        }

        .priority-head,
        .queue-head,
        .event-feed-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.7rem;
        }

        .priority-title,
        .queue-title,
        .event-feed-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.9rem;
            font-weight: 800;
        }

        .priority-note,
        .queue-note,
        .event-feed-note {
            margin: 0.18rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.78rem;
            line-height: 1.38;
        }

        .priority-meta,
        .queue-meta,
        .event-feed-meta {
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

        .finance-links-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
        }

        .finance-link {
            display: flex;
            align-items: flex-start;
            gap: 0.72rem;
            padding: 0.88rem 0.92rem;
            text-decoration: none;
            color: rgb(var(--bk-text-rgb));
            transition: transform 0.16s ease, border-color 0.16s ease, background-color 0.16s ease;
        }

        .finance-link:hover {
            transform: translateY(-1px);
            border-color: rgba(var(--bk-primary-rgb), 0.48);
            background: rgba(var(--bk-primary-rgb), 0.07);
            color: rgb(var(--bk-text-rgb));
        }

        .finance-link-icon {
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

        .finance-link-body {
            flex: 1;
            min-width: 0;
        }

        .finance-link-title {
            display: block;
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.88rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .finance-link-note {
            display: block;
            margin-top: 0.22rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.77rem;
            line-height: 1.34;
        }

        .finance-link-arrow {
            color: rgb(var(--bk-muted-rgb));
            transition: transform 0.16s ease, color 0.16s ease;
        }

        .finance-link:hover .finance-link-arrow {
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

        .finance-footer {
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
            .finance-risk-grid,
            .finance-links-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 1024px) {
            .finance-main-grid,
            .finance-insights-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .finance-hero-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 780px) {
            .finance-shell {
                padding-left: 0.82rem;
                padding-right: 0.82rem;
            }

            .hero-mini-grid,
            .finance-risk-grid,
            .finance-links-grid {
                grid-template-columns: 1fr;
            }

            .priority-head,
            .queue-head,
            .event-feed-head,
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
<body class="bk-role-page bk-role-finance">
<?php include 'navbar.php'; ?>

<main class="main-content finance-shell">
    <section class="finance-hero">
        <div class="finance-hero-grid">
            <div>
                <h1>Finance Dashboard</h1>
                <p>
                    Focus first on asset verification gaps, settlement gaps, and returned clarifications
                    before you spend time on routine closures in the finance queue.
                </p>
            </div>
            <div class="hero-mini-grid">
                <article class="hero-mini">
                    <p class="hero-mini-key">Pending Review</p>
                    <p class="hero-mini-value"><?php echo number_format((int) ($stats['pending_review'] ?? 0)); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="hero-mini-key">Returned</p>
                    <p class="hero-mini-value"><?php echo number_format((int) ($stats['returned_count'] ?? 0)); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="hero-mini-key">Closed</p>
                    <p class="hero-mini-value"><?php echo number_format((int) ($stats['closed_count'] ?? 0)); ?></p>
                </article>
                <article class="hero-mini">
                    <p class="hero-mini-key">Assigned Asset Value</p>
                    <p class="hero-mini-value is-currency"><?php echo htmlspecialchars($assignedValueLabel); ?></p>
                </article>
            </div>
        </div>
    </section>

    <?php if ($error !== ''): ?>
        <div class="mt-4">
            <?php render_alert($error, ['type' => 'danger']); ?>
        </div>
    <?php endif; ?>

    <section class="finance-risk-grid">
        <article class="finance-risk-card risk-review">
            <div class="finance-risk-top">
                <div>
                    <p class="finance-risk-label">Verification Gaps</p>
                    <p class="finance-risk-value"><?php echo number_format((int) ($riskStats['verification_gaps'] ?? 0)); ?></p>
                </div>
                <span class="finance-risk-icon"><i class="bi bi-search"></i></span>
            </div>
            <p class="finance-risk-note">Claims where not every BK asset row has been fully reviewed in the finance stage yet.</p>
        </article>

        <article class="finance-risk-card risk-settlement">
            <div class="finance-risk-top">
                <div>
                    <p class="finance-risk-label">Settlement Gaps</p>
                    <p class="finance-risk-value"><?php echo number_format((int) ($riskStats['settlement_gaps'] ?? 0)); ?></p>
                </div>
                <span class="finance-risk-icon"><i class="bi bi-send-x"></i></span>
            </div>
            <p class="finance-risk-note">Claims where the settlement destination or payout instruction still needs completion.</p>
        </article>

        <article class="finance-risk-card risk-multi">
            <div class="finance-risk-top">
                <div>
                    <p class="finance-risk-label">Multi-Asset Claims</p>
                    <p class="finance-risk-value"><?php echo number_format((int) ($riskStats['multi_asset'] ?? 0)); ?></p>
                </div>
                <span class="finance-risk-icon"><i class="bi bi-collection"></i></span>
            </div>
            <p class="finance-risk-note">Queue items spanning multiple BK asset classes that need tighter finance coordination.</p>
        </article>

        <article class="finance-risk-card risk-return">
            <div class="finance-risk-top">
                <div>
                    <p class="finance-risk-label">Returned Cases</p>
                    <p class="finance-risk-value"><?php echo number_format((int) ($riskStats['returned_clarification'] ?? 0)); ?></p>
                </div>
                <span class="finance-risk-icon"><i class="bi bi-arrow-counterclockwise"></i></span>
            </div>
            <p class="finance-risk-note">Claims that already bounced out of Finance and are still sitting in clarification-heavy workflow lanes.</p>
        </article>
    </section>

    <section class="finance-main-grid">
        <article class="finance-panel">
            <header class="finance-panel-head">
                <div>
                    <h2 class="finance-panel-title">Priority Finance Queue</h2>
                    <p class="finance-panel-subtitle">Open these first when you want the biggest impact on verification readiness and settlement flow.</p>
                </div>
                <span class="metric-pill"><?php echo number_format((int) ($riskStats['ready_to_close'] ?? 0)); ?> Ready to Close</span>
            </header>
            <div class="finance-panel-body">
                <?php if (empty($priorityClaims)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox fs-4"></i>
                        <p class="mt-2 mb-0">No active finance queue items are assigned to you right now.</p>
                    </div>
                <?php else: ?>
                    <div class="priority-list">
                        <?php foreach ($priorityClaims as $claim): ?>
                            <?php
                            $priorityMeta = finance_dashboard_priority_meta($claim);
                            $claimId = (int) ($claim['id'] ?? 0);
                            $status = (string) ($claim['effective_status'] ?? 'Pending Finance Review');
                            $statusLabel = udcs_claim_status_label($status);
                            $assetSummary = udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''));
                            $destinationSummary = bk_claim_destination_summary(
                                bk_claim_account_reference($claim),
                                (string) ($claim['distribution_method'] ?? ''),
                                (string) ($claim['distribution_details'] ?? '')
                            );
                            ?>
                            <article class="priority-item">
                                <div class="priority-head">
                                    <div>
                                        <h3 class="priority-title">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?> | <?php echo htmlspecialchars((string) ($claim['claimant_name'] ?? 'Unknown claimant')); ?></h3>
                                        <p class="priority-note">Deceased: <?php echo htmlspecialchars((string) ($claim['deceased_name'] ?? 'Not recorded')); ?> | <?php echo htmlspecialchars($priorityMeta['note']); ?></p>
                                    </div>
                                    <span class="priority-pill <?php echo htmlspecialchars((string) ($priorityMeta['class'] ?? 'risk-ready')); ?>"><?php echo htmlspecialchars((string) ($priorityMeta['label'] ?? 'Ready to Close')); ?></span>
                                </div>
                                <div class="priority-meta">
                                    <span class="mini-pill"><?php echo htmlspecialchars($assetSummary); ?></span>
                                    <span class="mini-pill"><?php echo htmlspecialchars(finance_dashboard_verification_label($claim)); ?></span>
                                    <span class="status-pill <?php echo htmlspecialchars(finance_dashboard_status_badge_class($status)); ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                </div>
                                <div class="priority-actions">
                                    <span class="finance-risk-note">Destination: <?php echo htmlspecialchars($destinationSummary); ?> | Date: <?php echo htmlspecialchars(finance_dashboard_date_label((string) ($claim['submitted_at'] ?? ''))); ?> | <?php echo htmlspecialchars(finance_dashboard_time_ago((string) ($claim['submitted_at'] ?? ''))); ?></span>
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

        <article class="finance-panel">
            <header class="finance-panel-head">
                <div>
                    <h2 class="finance-panel-title">Recent Finance Events</h2>
                    <p class="finance-panel-subtitle">Fresh queue movement, new uploads, and recent status changes tied to your finance lane.</p>
                </div>
                <span class="metric-pill"><?php echo number_format(count($recentEvents)); ?> events</span>
            </header>
            <div class="finance-panel-body">
                <?php if (empty($recentEvents)): ?>
                    <div class="empty-state">
                        <i class="bi bi-bell-slash fs-4"></i>
                        <p class="mt-2 mb-0">No recent finance events are available right now.</p>
                    </div>
                <?php else: ?>
                    <div class="event-feed">
                        <?php foreach ($recentEvents as $event): ?>
                            <?php $eventClaimId = (int) ($event['claim_id'] ?? 0); ?>
                            <article class="event-feed-item">
                                <div class="event-feed-head">
                                    <div>
                                        <h3 class="event-feed-title">
                                            <i class="bi <?php echo htmlspecialchars(finance_dashboard_event_icon((string) ($event['event_type'] ?? ''))); ?> me-1"></i>
                                            <?php echo htmlspecialchars((string) ($event['message'] ?? 'Finance event')); ?>
                                        </h3>
                                        <p class="event-feed-note"><?php echo htmlspecialchars(finance_dashboard_time_ago((string) ($event['created_at'] ?? ''))); ?></p>
                                    </div>
                                    <?php if ($eventClaimId > 0): ?>
                                        <span class="mini-pill">CL-<?php echo str_pad((string) $eventClaimId, 6, '0', STR_PAD_LEFT); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($eventClaimId > 0): ?>
                                    <div class="event-feed-meta">
                                        <a class="ui-btn ui-btn-sm ui-btn-secondary" href="claims.php?search=<?php echo $eventClaimId; ?>">
                                            <i class="bi bi-arrow-up-right-circle"></i>
                                            <span>Open Claims Review</span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="finance-panel" style="margin-top:1rem; overflow:hidden;">
        <header class="finance-panel-head">
            <div>
                <h2 class="finance-panel-title">My Finance Queue</h2>
                <p class="finance-panel-subtitle">The active claims still waiting for verification, clarification, or final finance closure.</p>
            </div>
            <span class="metric-pill"><?php echo number_format(count($queueClaims)); ?> queued</span>
        </header>
        <div class="finance-panel-body">
            <?php if (empty($queueClaims)): ?>
                <div class="empty-state">
                    <i class="bi bi-folder2-open fs-4"></i>
                    <p class="mt-2 mb-0">No claims are waiting in your active finance queue.</p>
                </div>
            <?php else: ?>
                <div class="queue-list">
                    <?php foreach ($queueClaims as $claim): ?>
                        <?php
                        $claimId = (int) ($claim['id'] ?? 0);
                        $status = (string) ($claim['effective_status'] ?? 'Pending Finance Review');
                        $statusLabel = udcs_claim_status_label($status);
                        $assetSummary = udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''));
                        $priorityMeta = finance_dashboard_priority_meta($claim);
                        $destinationSummary = bk_claim_destination_summary(
                            bk_claim_account_reference($claim),
                            (string) ($claim['distribution_method'] ?? ''),
                            (string) ($claim['distribution_details'] ?? '')
                        );
                        $contract = $claimId > 0 ? udcs_claim_fetch_review_contract($conn, $claimId) : null;
                        $claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
                        $amountLabel = is_array($contract)
                            ? udcs_claim_contract_value_label($contract, 'estimated')
                            : bk_claim_amount_display_for_type(
                                $claim['claim_amount'] ?? null,
                                (string) ($claim['claim_type'] ?? ''),
                                $claimCurrency
                            );
                        ?>
                        <article class="queue-item">
                            <div class="queue-head">
                                <div>
                                    <h3 class="queue-title">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?> | <?php echo htmlspecialchars((string) ($claim['claimant_name'] ?? 'Unknown claimant')); ?></h3>
                                    <p class="queue-note">Deceased: <?php echo htmlspecialchars((string) ($claim['deceased_name'] ?? 'Not recorded')); ?> | Destination: <?php echo htmlspecialchars($destinationSummary); ?></p>
                                </div>
                                <div class="queue-right">
                                    <span class="priority-pill <?php echo htmlspecialchars((string) ($priorityMeta['class'] ?? 'risk-ready')); ?>"><?php echo htmlspecialchars((string) ($priorityMeta['label'] ?? 'Ready to Close')); ?></span>
                                    <span class="status-pill <?php echo htmlspecialchars(finance_dashboard_status_badge_class($status)); ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                </div>
                            </div>
                            <div class="queue-meta">
                                <span class="mini-pill"><?php echo htmlspecialchars($assetSummary); ?></span>
                                <span class="mini-pill"><?php echo htmlspecialchars(finance_dashboard_verification_label($claim)); ?></span>
                                <span class="mini-pill"><?php echo htmlspecialchars($amountLabel); ?></span>
                                <span class="mini-pill"><?php echo htmlspecialchars(finance_dashboard_date_label((string) ($claim['submitted_at'] ?? ''))); ?></span>
                            </div>
                            <div class="queue-actions">
                                <span class="finance-risk-note"><?php echo htmlspecialchars($priorityMeta['note']); ?></span>
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

    <section class="finance-insights-grid">
        <article class="finance-panel">
            <header class="finance-panel-head">
                <div>
                    <h2 class="finance-panel-title">Finance Case Trend</h2>
                    <p class="finance-panel-subtitle">Monthly claim movement across pending finance work, returned cases, and closed outcomes.</p>
                </div>
            </header>
            <div class="finance-panel-body">
                <div class="chart-wrap">
                    <canvas id="financeTrendChart" aria-label="Finance case trend chart"></canvas>
                </div>
            </div>
        </article>

        <article class="finance-panel">
            <header class="finance-panel-head">
                <div>
                    <h2 class="finance-panel-title">Verification Readiness Mix</h2>
                    <p class="finance-panel-subtitle">A quick split of what is blocked, what is complex, and what is ready for closure.</p>
                </div>
            </header>
            <div class="finance-panel-body">
                <div class="chart-wrap is-compact">
                    <canvas id="financeMixChart" aria-label="Finance verification readiness chart"></canvas>
                </div>
            </div>
        </article>
    </section>

    <section class="finance-links-grid">
        <a class="finance-link" href="claims.php">
            <span class="finance-link-icon"><i class="bi bi-files"></i></span>
            <span class="finance-link-body">
                <span class="finance-link-title">Claims Review</span>
                <span class="finance-link-note">Open the finance queue, verify BK asset rows, and record settlement outcomes.</span>
            </span>
            <span class="finance-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="finance-link" href="claims.php">
            <span class="finance-link-icon"><i class="bi bi-bar-chart"></i></span>
            <span class="finance-link-body">
                <span class="finance-link-title">Reports</span>
                <span class="finance-link-note">Open Claims Review and export the current finance queue picture.</span>
            </span>
            <span class="finance-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="finance-link" href="messaging.php">
            <span class="finance-link-icon"><i class="bi bi-chat-dots"></i></span>
            <span class="finance-link-body">
                <span class="finance-link-title">Messaging</span>
                <span class="finance-link-note">Coordinate with claimants and Legal when finance clarification or settlement follow-up is needed.</span>
            </span>
            <span class="finance-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>

        <a class="finance-link" href="profile.php">
            <span class="finance-link-icon"><i class="bi bi-person-gear"></i></span>
            <span class="finance-link-body">
                <span class="finance-link-title">Profile</span>
                <span class="finance-link-note">Update account details without leaving the finance workspace.</span>
            </span>
            <span class="finance-link-arrow"><i class="bi bi-arrow-up-right"></i></span>
        </a>
    </section>

    <footer class="finance-footer">
        <span><i class="bi bi-bank2 me-1"></i> UNIFIED DIGITAL CLAIMS SYSTEM</span>
        <span><?php echo number_format((int) ($stats['total_assigned'] ?? 0)); ?> total assigned claim(s) | <?php echo htmlspecialchars($disbursedValueLabel); ?> already verified for closed/disbursed outcomes.</span>
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

    const trendCanvas = document.getElementById('financeTrendChart');
    if (trendCanvas) {
        new Chart(trendCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($months, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [
                    {
                        label: 'Pending Cases',
                        data: <?php echo json_encode($pendingValueData); ?>,
                        borderColor: warning,
                        backgroundColor: 'rgba(180, 83, 9, 0.16)',
                        fill: true,
                        tension: 0.34,
                        borderWidth: 2.3
                    },
                    {
                        label: 'Closed Cases',
                        data: <?php echo json_encode($closedValueData); ?>,
                        borderColor: success,
                        backgroundColor: 'rgba(21, 128, 61, 0.14)',
                        fill: false,
                        tension: 0.34,
                        borderWidth: 2.3
                    },
                    {
                        label: 'Returned Cases',
                        data: <?php echo json_encode($returnedValueData); ?>,
                        borderColor: danger,
                        backgroundColor: 'rgba(185, 28, 28, 0.14)',
                        fill: false,
                        tension: 0.34,
                        borderWidth: 2.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            color: muted
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                return `${context.dataset.label}: ${Number(context.parsed.y || 0).toLocaleString()}`;
                            }
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
                        ticks: {
                            color: muted,
                            callback(value) {
                                return Number(value).toLocaleString();
                            }
                        },
                        grid: { color: 'rgba(3, 78, 162, 0.08)' }
                    }
                }
            }
        });
    }

    const mixCanvas = document.getElementById('financeMixChart');
    if (mixCanvas) {
        new Chart(mixCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($compositionLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($compositionCounts); ?>,
                    backgroundColor: [warning, primary, 'rgba(14, 165, 233, 0.82)', danger, success],
                    borderColor: '#ffffff',
                    borderWidth: 2
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
                            boxWidth: 9
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
