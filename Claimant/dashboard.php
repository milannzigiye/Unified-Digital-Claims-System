<?php
// Tags: [CLAIMANT] [DASH]
require_once '../security.php';
secure_session_start();
include '../connect.php';

require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/button.php';
require_once dirname(__DIR__) . '/components/alert.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'claimant') {
    header('Location: ../claimant-access.php');
    exit();
}

$sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $sessionEmail, 'claimant');
if (!$user_data) {
    header('Location: ../claimant-access.php');
    exit();
}

$user_email = $sessionEmail;
$claimant_id = (int) ($user_data['id'] ?? 0);
$claimant_name = (string) ($user_data['full_name'] ?? 'Claimant');
$rawPhoto = (string) ($user_data['photo'] ?? ($user_data['profile_photo'] ?? ''));
$photo = $rawPhoto !== '' ? '../uploads/' . ltrim($rawPhoto, '/\\') : '../Images/logo.png';

function claimant_dashboard_is_blank_draft(array $claim): bool
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

$total_claims = 0;
$pending_claims = 0;
$approved_claims = 0;
$rejected_claims = 0;
$recent_claims = [];
$all_claim_rows = [];
$filed_claim_rows = [];
$claimsFeedStmt = mysqli_prepare(
    $conn,
    "
    SELECT
        c.*,
        COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name) AS deceased_name,
        COALESCE(ca.asset_classes, '') AS asset_classes,
        COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
        COALESCE(dc.document_count, 0) AS document_count,
        GREATEST(COALESCE(c.submitted_at, '1970-01-01 00:00:00'), COALESCE(c.updated_at, '1970-01-01 00:00:00')) AS activity_time
    FROM claims c
    LEFT JOIN (
        SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
        FROM claim_assets
        GROUP BY claim_id
    ) ca ON ca.claim_id = c.id
    LEFT JOIN (
        SELECT claim_id, COUNT(*) AS document_count
        FROM documents
        GROUP BY claim_id
    ) dc ON dc.claim_id = c.id
    WHERE COALESCE(c.claimant_user_id, c.claimant_id) = ?
    ORDER BY activity_time DESC, c.id DESC
"
);
if ($claimsFeedStmt) {
    mysqli_stmt_bind_param($claimsFeedStmt, 'i', $claimant_id);
    if (mysqli_stmt_execute($claimsFeedStmt)) {
        $claimsFeedResult = mysqli_stmt_get_result($claimsFeedStmt);
        if ($claimsFeedResult) {
            while ($row = mysqli_fetch_assoc($claimsFeedResult)) {
                if (claimant_dashboard_is_blank_draft($row)) {
                    continue;
                }
                $all_claim_rows[] = $row;
            }
        }
    }
}

$filed_claim_rows = array_values(array_filter(
    $all_claim_rows,
    static fn(array $row): bool => trim((string) ($row['effective_status'] ?? $row['claim_status'] ?? '')) !== 'Draft'
));

$total_claims = count($filed_claim_rows);
foreach ($filed_claim_rows as $row) {
    $statusRaw = trim((string) ($row['effective_status'] ?? $row['claim_status'] ?? ''));
    $status = strtolower($statusRaw);

    if (in_array($statusRaw, [
        'OCR Validation Failed',
        'Ready for Submission',
        'Submitted',
        'Pending Legal Review',
        'More Information Required',
        'Manual Legal Review Required',
        'Pending Finance Review',
        'Returned by Finance',
        'Approved for Disbursement',
        'pending',
        'transferred to finance',
    ], true)) {
        $pending_claims++;
    } elseif (in_array($statusRaw, ['Disbursed', 'Closed', 'approved by finance'], true)) {
        $approved_claims++;
    } elseif (strpos($status, 'rejected') !== false) {
        $rejected_claims++;
    }
}

$recent_claims = array_slice($filed_claim_rows, 0, 6);

$documents_summary = [];
$documents_query = false;
$documentsStmt = mysqli_prepare(
    $conn,
    "
    SELECT d.document_type, COUNT(d.id) AS count, MAX(d.uploaded_at) AS latest_upload
    FROM documents d
    JOIN claims c ON d.claim_id = c.id
    WHERE COALESCE(c.claimant_user_id, c.claimant_id) = ?
      AND COALESCE(NULLIF(c.status, ''), c.claim_status) <> 'Draft'
    GROUP BY d.document_type
    ORDER BY latest_upload DESC
    LIMIT 6
"
);
if ($documentsStmt) {
    mysqli_stmt_bind_param($documentsStmt, 'i', $claimant_id);
    if (mysqli_stmt_execute($documentsStmt)) {
        $documents_query = mysqli_stmt_get_result($documentsStmt);
    }
}

if ($documents_query) {
    while ($row = mysqli_fetch_assoc($documents_query)) {
        $documents_summary[] = $row;
    }
}

$status_chart_labels = ['Pending', 'Approved', 'Rejected'];
$status_chart_counts = [$pending_claims, $approved_claims, $rejected_claims];

$doc_chart_labels = [];
$doc_chart_counts = [];
foreach ($documents_summary as $doc_row) {
    $doc_chart_labels[] = ucwords(str_replace('_', ' ', (string) ($doc_row['document_type'] ?? 'Document')));
    $doc_chart_counts[] = (int) ($doc_row['count'] ?? 0);
}
if (count($doc_chart_labels) === 0) {
    $doc_chart_labels = ['No Documents Yet'];
    $doc_chart_counts = [0];
}

$activities = [];
foreach (array_slice($filed_claim_rows, 0, 6) as $row) {
        $claimCode = 'CL-' . str_pad((string) ($row['id'] ?? 0), 6, '0', STR_PAD_LEFT);
        $statusRaw = trim((string) ($row['effective_status'] ?? ''));
        $status = strtolower($statusRaw);
        $type = 'Updated';
        $message = 'Claim ' . $claimCode . ' status was updated.';

        if (in_array($statusRaw, ['Submitted', 'Pending Legal Review', 'pending'], true)) {
            $type = 'Submitted';
            $message = 'Your claim for ' . ($row['deceased_name'] ?? 'the deceased estate') . ' was received and is awaiting review.';
        } elseif ($statusRaw === 'Returned by Finance') {
            $type = 'Action Required';
            $message = 'Finance returned your claim for clarification. Open the claim to review the reason and update the requested details.';
        } elseif (in_array($statusRaw, ['Manual Legal Review Required', 'More Information Required', 'Pending Finance Review', 'transferred to finance'], true)) {
            $type = 'Under Review';
            $message = 'Your claim for ' . ($row['deceased_name'] ?? 'this estate') . ' is moving through legal or finance review.';
        } elseif (in_array($statusRaw, ['Approved for Disbursement', 'Disbursed', 'Closed', 'approved by finance'], true)) {
            $type = 'Approved';
            $message = 'Claim ' . $claimCode . ' was approved. We will notify you when payout steps are completed.';
        } elseif (strpos($status, 'rejected') !== false) {
            $type = 'Rejected';
            $message = 'Claim ' . $claimCode . ' needs updates. Open the claim details to view the reason and next steps.';
        }

        $row['activity_type'] = $type;
        $row['activity_description'] = $message;
        $activities[] = $row;
}

function claimant_status_meta(?string $status): array
{
    $raw = trim((string) $status);
    $normalized = strtolower($raw);
    $class = 'status-neutral';
    $label = ucwords(str_replace('_', ' ', $normalized ?: 'unknown'));

    if (in_array($raw, ['Draft', 'Submitted', 'Pending Legal Review', 'pending'], true)) {
        $class = 'status-pending';
        $label = $raw === 'Draft' ? 'Draft' : 'Pending';
    } elseif (in_array($raw, ['Manual Legal Review Required', 'More Information Required', 'Pending Finance Review', 'Returned by Finance', 'transferred to finance'], true)) {
        $class = 'status-review';
        $label = 'Under Review';
    } elseif (in_array($raw, ['Approved for Disbursement', 'Disbursed', 'Closed', 'approved by finance'], true)) {
        $class = 'status-approved';
        $label = 'Approved';
    } elseif (strpos($normalized, 'rejected') !== false) {
        $class = 'status-rejected';
        $label = 'Rejected';
    }

    return ['class' => $class, 'label' => $label];
}

function time_elapsed_string(string $datetime): string
{
    try {
        $now = new DateTime();
        $ago = new DateTime($datetime);
    } catch (Exception $e) {
        return 'just now';
    }

    $diff = $now->diff($ago);
    $weeks = (int) floor($diff->d / 7);
    $days = $diff->d - ($weeks * 7);

    $parts = [
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];

    foreach ($parts as $key => &$label) {
        $value = $key === 'w' ? $weeks : ($key === 'd' ? $days : (int) $diff->$key);
        if ($value > 0) {
            $label = $value . ' ' . $label . ($value > 1 ? 's' : '');
        } else {
            unset($parts[$key]);
        }
    }

    $parts = array_slice($parts, 0, 1);
    return $parts ? implode(', ', $parts) . ' ago' : 'just now';
}

function claimant_action_required_meta(array $claim): ?array
{
    $status = trim((string) ($claim['effective_status'] ?? $claim['claim_status'] ?? ''));

    return match ($status) {
        'Ready for Submission' => [
            'title' => 'Finish and submit this claim',
            'message' => 'This claim is almost ready. Complete the remaining details and submit it to start formal review.',
            'type' => 'Resume submission',
        ],
        'OCR Validation Failed' => [
            'title' => 'Fix document or OCR issues',
            'message' => 'One or more required files failed OCR or intake validation. Open the claim and replace the affected document.',
            'type' => 'Document action',
        ],
        'More Information Required' => [
            'title' => 'Provide the requested clarification',
            'message' => 'Legal asked for more information or supporting evidence before the claim can continue.',
            'type' => 'Legal follow-up',
        ],
        'Returned by Finance' => [
            'title' => 'Update payout or finance details',
            'message' => 'Finance returned the claim for clarification. Review the return reason and update the requested details.',
            'type' => 'Finance follow-up',
        ],
        default => null,
    };
}

$action_needed_rows = [];
foreach ($filed_claim_rows as $row) {
    $actionMeta = claimant_action_required_meta($row);
    if ($actionMeta === null) {
        continue;
    }
    $row['action_meta'] = $actionMeta;
    $action_needed_rows[] = $row;
}
$action_needed_claims = count($action_needed_rows);
$active_review_claims = max(0, $pending_claims - $action_needed_claims);
$total_uploaded_documents = 0;
foreach ($documents_summary as $docRow) {
    $total_uploaded_documents += (int) ($docRow['count'] ?? 0);
}
$document_type_count = count($documents_summary);
$claim_health_total = max(1, $total_claims);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    render_head(
        'Claimant Dashboard | UNIFIED DIGITAL CLAIMS SYSTEM',
        '..',
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">'
    );
    ?>
    <style>
        .dash-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.25rem;
            background:
                linear-gradient(140deg, rgba(var(--bk-primary-rgb), 0.2), rgba(var(--bk-primary-rgb), 0.03) 45%, rgba(var(--bk-surface-rgb), 0.99)),
                rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
        }

        .dash-hero::before {
            content: "";
            position: absolute;
            width: 17rem;
            height: 17rem;
            right: -4rem;
            top: -7rem;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(var(--bk-primary-rgb), 0.24), rgba(var(--bk-primary-rgb), 0));
            animation: float 7s ease-in-out infinite;
            pointer-events: none;
        }

        .metric-card {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgba(var(--bk-surface-rgb), 0.9);
            box-shadow: var(--shadow-soft);
            padding: 1rem;
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }

        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .metric-value {
            font-size: 1.95rem;
            font-weight: 700;
            line-height: 1.1;
            color: rgb(var(--bk-text-rgb));
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid transparent;
            padding: 0.24rem 0.64rem;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            white-space: nowrap;
        }

        .status-pending {
            color: rgb(var(--bk-warning-rgb));
            background: rgba(var(--bk-warning-rgb), 0.13);
            border-color: rgba(var(--bk-warning-rgb), 0.26);
        }

        .status-review {
            color: rgb(var(--bk-primary-rgb));
            background: rgba(var(--bk-primary-rgb), 0.12);
            border-color: rgba(var(--bk-primary-rgb), 0.24);
        }

        .status-approved {
            color: rgb(var(--bk-success-rgb));
            background: rgba(var(--bk-success-rgb), 0.13);
            border-color: rgba(var(--bk-success-rgb), 0.24);
        }

        .status-rejected {
            color: rgb(var(--bk-danger-rgb));
            background: rgba(var(--bk-danger-rgb), 0.12);
            border-color: rgba(var(--bk-danger-rgb), 0.24);
        }

        .status-neutral {
            color: rgb(var(--bk-muted-rgb));
            background: rgba(var(--bk-muted-rgb), 0.12);
            border-color: rgba(var(--bk-muted-rgb), 0.2);
        }

        .dash-table tbody tr td {
            transition: background-color 0.16s ease;
        }

        .dash-table tbody tr:hover td {
            background: rgba(var(--bk-primary-rgb), 0.05);
        }

        .timeline-item {
            position: relative;
            border-left: 2px solid rgba(var(--bk-border-rgb), 1);
            padding-left: 0.9rem;
            padding-bottom: 0.95rem;
            margin-left: 0.3rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-item::before {
            content: "";
            position: absolute;
            left: -0.46rem;
            top: 0.16rem;
            width: 0.72rem;
            height: 0.72rem;
            border-radius: 999px;
            border: 2px solid rgb(var(--bk-surface-rgb));
            background: rgb(var(--bk-primary-rgb));
            box-shadow: 0 0 0 2px rgba(var(--bk-primary-rgb), 0.2);
        }

        .quick-action-grid {
            display: grid;
            gap: 0.7rem;
        }

        .quick-action-tile {
            display: flex;
            align-items: flex-start;
            gap: 0.72rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.92rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.76rem 0.82rem;
            text-decoration: none;
            color: rgb(var(--bk-text-rgb));
            transition: transform 0.16s ease, border-color 0.16s ease, background-color 0.16s ease;
        }

        .quick-action-tile:hover {
            transform: translateY(-1px);
            border-color: rgba(var(--bk-primary-rgb), 0.5);
            background: rgba(var(--bk-primary-rgb), 0.07);
            color: rgb(var(--bk-text-rgb));
        }

        .quick-action-icon {
            width: 2.15rem;
            height: 2.15rem;
            border-radius: 0.7rem;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.32);
            background: rgba(var(--bk-primary-rgb), 0.13);
            color: rgb(var(--bk-primary-rgb));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.95rem;
        }

        .quick-action-copy {
            flex: 1;
            min-width: 0;
        }

        .quick-action-title {
            display: block;
            margin: 0;
            font-size: 0.88rem;
            font-weight: 700;
            line-height: 1.2;
            color: rgb(var(--bk-text-rgb));
        }

        .quick-action-note {
            display: block;
            margin-top: 0.2rem;
            font-size: 0.77rem;
            color: rgb(var(--bk-muted-rgb));
            line-height: 1.3;
        }

        .quick-action-arrow {
            color: rgb(var(--bk-muted-rgb));
            margin-top: 0.05rem;
            transition: transform 0.16s ease, color 0.16s ease;
        }

        .quick-action-tile:hover .quick-action-arrow {
            color: rgb(var(--bk-primary-rgb));
            transform: translateX(2px) translateY(-1px);
        }

        .chart-grid {
            display: grid;
            gap: 0.8rem;
        }

        .chart-card {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.95rem;
            background: rgba(var(--bk-surface-rgb), 0.96);
            box-shadow: var(--shadow-soft);
            padding: 0.8rem;
        }

        .chart-card h3 {
            margin: 0;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: rgb(var(--bk-muted-rgb));
            font-weight: 700;
        }

        .chart-canvas-wrap {
            margin-top: 0.55rem;
            min-height: 180px;
        }

        .top-insights-actions {
            display: grid;
            gap: 1.5rem;
            grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
            align-items: stretch;
        }

        .top-side-stack {
            display: grid;
            grid-template-rows: auto auto;
            gap: 1.5rem;
            align-content: start;
        }

        .stack-card {
            display: block;
        }

        .stack-body {
            overflow: visible;
        }

        .claimant-priority-grid,
        .claimant-support-grid {
            display: grid;
            gap: 1.5rem;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
            align-items: start;
        }

        .priority-list,
        .recent-claims-list,
        .health-list {
            display: grid;
            gap: 0.85rem;
        }

        .priority-item {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgba(var(--bk-surface-rgb), 1);
            box-shadow: var(--shadow-soft);
            padding: 0.9rem 0.95rem;
            display: grid;
            gap: 0.58rem;
        }

        .priority-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        .priority-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.95rem;
            font-weight: 800;
            line-height: 1.22;
        }

        .priority-note {
            margin: 0.16rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.8rem;
            line-height: 1.4;
        }

        .priority-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.38rem;
        }

        .mini-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.28rem;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.16);
            background: rgba(var(--bk-primary-rgb), 0.08);
            color: rgb(var(--bk-text-rgb));
            padding: 0.22rem 0.52rem;
            font-size: 0.72rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .claim-health-row {
            display: grid;
            gap: 0.34rem;
        }

        .claim-health-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            font-size: 0.83rem;
            color: rgb(var(--bk-text-rgb));
            font-weight: 700;
        }

        .claim-health-bar {
            height: 0.52rem;
            border-radius: 999px;
            background: rgba(var(--bk-border-rgb), 0.75);
            overflow: hidden;
        }

        .claim-health-fill {
            height: 100%;
            border-radius: 999px;
        }

        .claim-health-fill.is-warning {
            background: rgba(var(--bk-warning-rgb), 0.92);
        }

        .claim-health-fill.is-review {
            background: rgba(var(--bk-primary-rgb), 0.92);
        }

        .claim-health-fill.is-success {
            background: rgba(var(--bk-success-rgb), 0.92);
        }

        .claim-health-fill.is-danger {
            background: rgba(var(--bk-danger-rgb), 0.92);
        }

        .health-summary {
            border-top: 1px solid rgba(var(--bk-border-rgb), 1);
            padding-top: 0.9rem;
            display: grid;
            gap: 0.5rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.8rem;
            line-height: 1.42;
        }

        .claim-zebra-row {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr) auto;
            gap: 1rem;
            align-items: start;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            padding: 0.95rem 1rem;
            box-shadow: var(--shadow-soft);
        }

        .recent-claims-list .claim-zebra-row:nth-child(odd) {
            background: rgba(var(--bk-surface-rgb), 1);
        }

        .recent-claims-list .claim-zebra-row:nth-child(even) {
            background: rgba(var(--bk-primary-rgb), 0.045);
        }

        .claim-zebra-main,
        .claim-zebra-side,
        .claim-zebra-actions {
            min-width: 0;
        }

        .claim-zebra-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.96rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .claim-zebra-note {
            margin-top: 0.2rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.8rem;
            line-height: 1.42;
        }

        .claim-zebra-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.42rem;
            margin-top: 0.48rem;
        }

        .claim-zebra-side {
            display: grid;
            gap: 0.4rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.79rem;
            line-height: 1.35;
        }

        .claim-zebra-side strong {
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .claim-zebra-actions {
            display: grid;
            justify-items: end;
            gap: 0.55rem;
        }

        .section-note {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.82rem;
            line-height: 1.45;
        }

        .insights-grid-late {
            display: grid;
            gap: 0.8rem;
        }

        @media (max-width: 780px) {
            .top-insights-actions {
                grid-template-columns: 1fr;
            }

            .top-side-stack {
                grid-template-rows: auto;
            }

            .claimant-priority-grid,
            .claimant-support-grid,
            .claim-zebra-row {
                grid-template-columns: 1fr;
            }

            .claim-zebra-actions {
                justify-items: start;
            }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content px-4 pb-8 pt-4 sm:px-6 lg:px-8">
    <!-- SECTION: Hero summary with top-level claimant numbers. -->
    <section class="dash-hero p-6 sm:p-7">
        <div class="grid gap-6 lg:grid-cols-[1.45fr_1fr] lg:items-end">
            <div>
                <h1 class="mt-2 font-display text-3xl font-bold text-bk-text sm:text-4xl">Welcome, <?php echo bk_e($claimant_name); ?></h1>
                <p class="mt-2 max-w-2xl text-sm text-white">
                    Track every stage of your claim, monitor document checks, and view clear review outcomes in one place.
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php render_button('Start New Claim', ['href' => 'form_v2.php']); ?>
                    <?php render_button('Open My Claims', ['variant' => 'secondary', 'href' => 'claims.php']); ?>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <article class="metric-card">
                    <p class="text-xs uppercase tracking-wide text-bk-muted">Filed Claims</p>
                    <p class="metric-value mt-1"><?php echo $total_claims; ?></p>
                </article>
                <article class="metric-card">
                    <p class="text-xs uppercase tracking-wide text-bk-muted">Needs Your Update</p>
                    <p class="metric-value mt-1 text-bk-warning"><?php echo $action_needed_claims; ?></p>
                </article>
                <article class="metric-card">
                    <p class="text-xs uppercase tracking-wide text-bk-muted">In Active Review</p>
                    <p class="metric-value mt-1 text-bk-primary"><?php echo $active_review_claims; ?></p>
                </article>
                <article class="metric-card">
                    <p class="text-xs uppercase tracking-wide text-bk-muted">Completed / Closed</p>
                    <p class="metric-value mt-1 text-bk-success"><?php echo $approved_claims; ?></p>
                </article>
            </div>
        </div>
    </section>

    <section class="claimant-priority-grid mt-6">
        <article class="ui-card">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-bk-border px-5 py-4">
                <div>
                    <h2 class="font-display text-xl font-semibold text-bk-text">Action Needed</h2>
                    <p class="section-note">Claims that need your immediate update before review can continue.</p>
                </div>
                <span class="status-pill status-review"><?php echo $action_needed_claims; ?> open</span>
            </header>
            <div class="px-5 py-4">
                <?php if (count($action_needed_rows) === 0): ?>
                    <?php render_alert('No claimant action is required right now. Your submitted claims are either under review or already completed.', ['type' => 'success']); ?>
                <?php else: ?>
                    <div class="priority-list">
                        <?php foreach (array_slice($action_needed_rows, 0, 4) as $claim): ?>
                            <?php
                            $claimId = (int) ($claim['id'] ?? 0);
                            $actionMeta = (array) ($claim['action_meta'] ?? claimant_action_required_meta($claim) ?? []);
                            $statusMeta = claimant_status_meta((string) ($claim['effective_status'] ?? $claim['claim_status'] ?? ''));
                            $assetSummaryLabel = udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''));
                            ?>
                            <article class="priority-item">
                                <div class="priority-head">
                                    <div>
                                        <h3 class="priority-title"><?php echo bk_e((string) ($actionMeta['title'] ?? 'Review this claim')); ?></h3>
                                        <p class="priority-note"><?php echo bk_e((string) ($actionMeta['message'] ?? 'Open the claim to continue.')); ?></p>
                                    </div>
                                    <span class="status-pill <?php echo bk_e($statusMeta['class']); ?>"><?php echo bk_e($statusMeta['label']); ?></span>
                                </div>
                                <div class="priority-meta">
                                    <span class="mini-pill">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?></span>
                                    <span class="mini-pill"><?php echo bk_e((string) ($actionMeta['type'] ?? 'Claim update')); ?></span>
                                    <span class="mini-pill"><?php echo bk_e($assetSummaryLabel); ?></span>
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="text-sm text-bk-muted">Deceased: <?php echo bk_e((string) ($claim['deceased_name'] ?? 'Not recorded')); ?> | Updated <?php echo bk_e(time_elapsed_string((string) ($claim['activity_time'] ?? 'now'))); ?></p>
                                    <a class="ui-btn ui-btn-sm ui-btn-secondary" href="view_claim.php?id=<?php echo $claimId; ?>" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-folder-open"></i>
                                        <span>Open Claim</span>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="ui-card">
            <header class="border-b border-bk-border px-5 py-4">
                <h2 class="font-display text-xl font-semibold text-bk-text">Claim Health</h2>
                <p class="section-note mt-1">A quick read of where your current claims sit right now.</p>
            </header>
            <div class="px-5 py-4">
                <div class="health-list">
                    <div class="claim-health-row">
                        <div class="claim-health-head"><span>Needs Your Update</span><span><?php echo $action_needed_claims; ?></span></div>
                        <div class="claim-health-bar"><div class="claim-health-fill is-warning" style="width: <?php echo min(100, ($action_needed_claims / $claim_health_total) * 100); ?>%;"></div></div>
                    </div>
                    <div class="claim-health-row">
                        <div class="claim-health-head"><span>In Active Review</span><span><?php echo $active_review_claims; ?></span></div>
                        <div class="claim-health-bar"><div class="claim-health-fill is-review" style="width: <?php echo min(100, ($active_review_claims / $claim_health_total) * 100); ?>%;"></div></div>
                    </div>
                    <div class="claim-health-row">
                        <div class="claim-health-head"><span>Completed / Closed</span><span><?php echo $approved_claims; ?></span></div>
                        <div class="claim-health-bar"><div class="claim-health-fill is-success" style="width: <?php echo min(100, ($approved_claims / $claim_health_total) * 100); ?>%;"></div></div>
                    </div>
                    <div class="claim-health-row">
                        <div class="claim-health-head"><span>Rejected</span><span><?php echo $rejected_claims; ?></span></div>
                        <div class="claim-health-bar"><div class="claim-health-fill is-danger" style="width: <?php echo min(100, ($rejected_claims / $claim_health_total) * 100); ?>%;"></div></div>
                    </div>
                </div>
                <div class="health-summary">
                    <p><?php echo number_format($total_claims); ?> filed claim<?php echo $total_claims === 1 ? '' : 's'; ?> are counted here. Draft claims are excluded from this dashboard.</p>
                    <p><?php echo number_format($total_uploaded_documents); ?> uploaded document<?php echo $total_uploaded_documents === 1 ? '' : 's'; ?> across <?php echo number_format($document_type_count); ?> document type<?php echo $document_type_count === 1 ? '' : 's'; ?>.</p>
                    <p><?php echo count($recent_claims); ?> recent claim<?php echo count($recent_claims) === 1 ? '' : 's'; ?> are shown below so you can open the right case quickly.</p>
                </div>
            </div>
        </article>
    </section>

    <section class="mt-6">
        <article class="ui-card overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-bk-border px-5 py-4">
                <div>
                    <h2 class="font-display text-xl font-semibold text-bk-text">Recent Claims</h2>
                    <p class="section-note">Your latest filed claims, with their current review position and document count.</p>
                </div>
                <?php render_button('All Claims', ['variant' => 'ghost', 'href' => 'claims.php', 'size' => 'sm']); ?>
            </header>

            <div class="px-5 py-4">
                <?php if (count($recent_claims) === 0): ?>
                    <?php render_alert('No claims submitted yet. Start your first claim when you are ready.', ['type' => 'info']); ?>
                <?php else: ?>
                    <div class="recent-claims-list">
                        <?php foreach ($recent_claims as $claim): ?>
                            <?php
                            $claimId = (int) ($claim['id'] ?? 0);
                            $statusMeta = claimant_status_meta((string) ($claim['effective_status'] ?? $claim['claim_status'] ?? ''));
                            $assetSummaryLabel = udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''));
                            ?>
                            <article class="claim-zebra-row">
                                <div class="claim-zebra-main">
                                    <h3 class="claim-zebra-title">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?> | <?php echo bk_e((string) ($claim['deceased_name'] ?? 'Deceased not named')); ?></h3>
                                    <p class="claim-zebra-note">Asset scope: <?php echo bk_e($assetSummaryLabel); ?></p>
                                    <div class="claim-zebra-meta">
                                        <span class="mini-pill"><?php echo (int) ($claim['document_count'] ?? 0); ?> file<?php echo ((int) ($claim['document_count'] ?? 0)) === 1 ? '' : 's'; ?></span>
                                        <span class="mini-pill"><?php echo !empty($claim['submitted_at']) ? date('M d, Y', strtotime((string) $claim['submitted_at'])) : 'Not submitted'; ?></span>
                                    </div>
                                </div>
                                <div class="claim-zebra-side">
                                    <div><strong>Latest update:</strong> <?php echo bk_e(time_elapsed_string((string) ($claim['activity_time'] ?? 'now'))); ?></div>
                                    <div><strong>Claim reference:</strong> CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?></div>
                                </div>
                                <div class="claim-zebra-actions">
                                    <span class="status-pill <?php echo bk_e($statusMeta['class']); ?>"><?php echo bk_e($statusMeta['label']); ?></span>
                                    <a class="ui-btn ui-btn-sm ui-btn-secondary" href="view_claim.php?id=<?php echo $claimId; ?>" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-folder-open"></i>
                                        <span>Open Claim</span>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="claimant-support-grid mt-6">
        <article class="ui-card">
            <header class="border-b border-bk-border px-5 py-4">
                <h2 class="font-display text-xl font-semibold text-bk-text">Recent Updates</h2>
                <p class="section-note mt-1">The latest movement across your filed claims.</p>
            </header>
            <div class="space-y-0 px-5 py-4">
                <?php if (count($activities) === 0): ?>
                    <?php render_alert('No activity yet. Updates will appear here as your claims move forward.', ['type' => 'info']); ?>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <article class="timeline-item">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-bk-text"><?php echo bk_e((string) ($activity['activity_type'] ?? 'Updated')); ?></p>
                                    <p class="mt-1 text-sm text-bk-muted"><?php echo bk_e((string) ($activity['activity_description'] ?? '')); ?></p>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-bk-muted"><?php echo bk_e(time_elapsed_string((string) ($activity['activity_time'] ?? 'now'))); ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="ui-card">
            <header class="border-b border-bk-border px-5 py-4">
                <h2 class="font-display text-xl font-semibold text-bk-text">Quick Actions</h2>
                <p class="section-note mt-1">Jump straight into the next claimant task.</p>
            </header>
            <div class="quick-action-grid px-5 py-4">
                <a href="form_v2.php" class="quick-action-tile">
                    <span class="quick-action-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="quick-action-copy">
                        <span class="quick-action-title">Start New Claim</span>
                        <span class="quick-action-note">Submit a new deceased asset claim.</span>
                    </span>
                    <span class="quick-action-arrow"><i class="fas fa-arrow-up-right-from-square"></i></span>
                </a>
                <a href="claims.php" class="quick-action-tile">
                    <span class="quick-action-icon"><i class="fas fa-folder-open"></i></span>
                    <span class="quick-action-copy">
                        <span class="quick-action-title">Review My Claims</span>
                        <span class="quick-action-note">Track current status, uploaded files, and progress.</span>
                    </span>
                    <span class="quick-action-arrow"><i class="fas fa-arrow-up-right-from-square"></i></span>
                </a>
                <a href="messaging.php" class="quick-action-tile">
                    <span class="quick-action-icon"><i class="fas fa-comments"></i></span>
                    <span class="quick-action-copy">
                        <span class="quick-action-title">Open Messages</span>
                        <span class="quick-action-note">Check responses from legal and finance.</span>
                    </span>
                    <span class="quick-action-arrow"><i class="fas fa-arrow-up-right-from-square"></i></span>
                </a>
                <a href="profile.php" class="quick-action-tile">
                    <span class="quick-action-icon"><i class="fas fa-user-cog"></i></span>
                    <span class="quick-action-copy">
                        <span class="quick-action-title">Profile Settings</span>
                        <span class="quick-action-note">Update your account and security info.</span>
                    </span>
                    <span class="quick-action-arrow"><i class="fas fa-arrow-up-right-from-square"></i></span>
                </a>
            </div>
        </article>
    </section>

    <section class="claimant-support-grid mt-6">
        <article class="ui-card">
            <header class="border-b border-bk-border px-5 py-4">
                <h2 class="font-display text-xl font-semibold text-bk-text">Document Summary</h2>
                <p class="section-note mt-1">Uploaded supporting documents by type.</p>
            </header>
            <div class="grid gap-3 px-5 py-4">
                <?php if (count($documents_summary) === 0): ?>
                    <?php render_alert('No supporting documents uploaded yet. They will appear here after your first submission.', ['type' => 'info']); ?>
                <?php else: ?>
                    <?php foreach ($documents_summary as $doc): ?>
                        <article class="flex items-center justify-between gap-3 rounded-app border border-bk-border bg-bk-surface px-4 py-3">
                            <div>
                                <p class="font-medium text-bk-text"><?php echo bk_e(ucwords(str_replace('_', ' ', (string) ($doc['document_type'] ?? 'Document')))); ?></p>
                                <p class="text-xs text-bk-muted">
                                    Latest upload:
                                    <?php echo !empty($doc['latest_upload']) ? date('M d, Y', strtotime((string) $doc['latest_upload'])) : 'N/A'; ?>
                                </p>
                            </div>
                            <span class="status-pill status-neutral"><?php echo (int) ($doc['count'] ?? 0); ?> file<?php echo ((int) ($doc['count'] ?? 0)) === 1 ? '' : 's'; ?></span>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="ui-card">
            <header class="border-b border-bk-border px-5 py-4">
                <h2 class="font-display text-xl font-semibold text-bk-text">Claim Insights</h2>
                <p class="section-note mt-1">Status and document patterns across your filed claims.</p>
            </header>
            <div class="insights-grid-late px-5 py-4">
                <section class="chart-card">
                    <h3>Status Distribution</h3>
                    <div class="chart-canvas-wrap">
                        <canvas id="claimantStatusChart" aria-label="Claim status distribution chart"></canvas>
                    </div>
                </section>
                <section class="chart-card">
                    <h3>Document Mix</h3>
                    <div class="chart-canvas-wrap">
                        <canvas id="claimantDocsChart" aria-label="Document types chart"></canvas>
                    </div>
                </section>
            </div>
        </article>
    </section>
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

    const statusCanvas = document.getElementById('claimantStatusChart');
    if (statusCanvas) {
        new Chart(statusCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($status_chart_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($status_chart_counts); ?>,
                    backgroundColor: [warning, success, danger],
                    borderColor: '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: muted, usePointStyle: true, boxWidth: 9 }
                    }
                }
            }
        });
    }

    const docsCanvas = document.getElementById('claimantDocsChart');
    if (docsCanvas) {
        new Chart(docsCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($doc_chart_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    label: 'Files',
                    data: <?php echo json_encode($doc_chart_counts); ?>,
                    backgroundColor: 'rgba(3, 78, 162, 0.72)',
                    borderColor: primary,
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        ticks: { color: muted, font: { size: 11 } },
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
})();
</script>
</body>
</html>




