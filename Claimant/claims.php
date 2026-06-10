<?php
// Tags: [CLAIMANT] [CLAIM] [STATUS]
require_once '../security.php';
secure_session_start();
include '../connect.php';

require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/button.php';
require_once dirname(__DIR__) . '/components/input.php';
require_once dirname(__DIR__) . '/components/select.php';
require_once dirname(__DIR__) . '/components/alert.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claims_list_ui.php';

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
$claimDeleteCsrfToken = udcs_csrf_get('claim_delete');
$rawPhoto = (string) ($user_data['photo'] ?? ($user_data['profile_photo'] ?? ''));
$photo = $rawPhoto !== '' ? '../uploads/' . ltrim($rawPhoto, '/\\') : '../Images/logo.png';

$status = trim((string) ($_GET['status'] ?? ''));
udcs_claims_v2_ensure_schema($conn);
$search = trim((string) ($_GET['search'] ?? ''));

$whereParts = ['COALESCE(NULLIF(c.claimant_user_id, 0), c.claimant_id) = ?'];
$types = 'i';
$params = [$claimant_id];

if ($search !== '') {
    $whereParts[] = '(
        COALESCE(NULLIF(c.deceased_full_name, \'\'), c.deceased_name) LIKE ? OR
        COALESCE(NULLIF(c.claim_type, \'\'), \'\') LIKE ? OR
        COALESCE(NULLIF(c.relationship, \'\'), \'\') LIKE ? OR
        COALESCE(NULLIF(c.marital_status, \'\'), \'\') LIKE ? OR
        COALESCE(NULLIF(c.spouse_status, \'\'), \'\') LIKE ? OR
        COALESCE(NULLIF(c.children_status, \'\'), \'\') LIKE ? OR
        COALESCE(NULLIF(c.preferred_payout_method, \'\'), \'\') LIKE ? OR
        COALESCE(NULLIF(c.distribution_method, \'\'), \'\') LIKE ? OR
        COALESCE(NULLIF(c.distribution_details, \'\'), \'\') LIKE ? OR
        COALESCE(ca.asset_classes, \'\') LIKE ? OR
        COALESCE(ca.asset_terms, \'\') LIKE ? OR
        COALESCE(cp.people_terms, \'\') LIKE ? OR
        COALESCE(dc.document_terms, \'\') LIKE ? OR
        CAST(c.claim_amount AS CHAR) LIKE ? OR
        CAST(c.id AS CHAR) LIKE ?
    )';
    $searchTerm = '%' . $search . '%';
    $types .= str_repeat('s', 15);
    for ($i = 0; $i < 15; $i++) {
        $params[] = $searchTerm;
    }
}

if ($status !== '') {
    $whereParts[] = 'COALESCE(NULLIF(c.status, \'\'), c.claim_status) = ?';
    $types .= 's';
    $params[] = $status;
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
        c.*,
        COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
        COALESCE(ca.asset_classes, '') AS asset_classes
    FROM claims c
    LEFT JOIN ($assetJoinSql) ca ON ca.claim_id = c.id
    LEFT JOIN ($peopleJoinSql) cp ON cp.claim_id = c.id
    LEFT JOIN ($documentJoinSql) dc ON dc.claim_id = c.id
    WHERE $whereSql
    ORDER BY COALESCE(c.updated_at, c.submitted_at) DESC
";
$stmt = mysqli_prepare($conn, $sql);
$result = false;
$queryError = '';
if ($stmt && udcs_db_stmt_bind($stmt, $types, $params) && mysqli_stmt_execute($stmt)) {
    $result = mysqli_stmt_get_result($stmt);
} else {
    $queryError = mysqli_error($conn);
}

$claims = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        if (claimant_claim_is_blank_draft($row)) {
            continue;
        }
        $claims[] = $row;
    }
}

$statusOptions = [
    'Draft' => 'Draft',
    'OCR Validation Failed' => 'OCR Validation Failed',
    'Pending Legal Review' => 'Pending Legal Review',
    'Manual Legal Review Required' => 'Manual Legal Review Required',
    'More Information Required' => 'More Information Required',
    'Pending Finance Review' => 'Pending Finance Review',
    'Returned by Finance' => 'Returned by Finance',
    'Approved for Disbursement' => 'Approved for Disbursement',
    'Disbursed' => 'Disbursed',
    'Closed' => 'Closed',
    'pending' => 'Legacy Pending',
    'transferred to finance' => 'Legacy Finance Review',
    'approved by finance' => 'Legacy Approved by Finance',
    'rejected by legal' => 'Legacy Rejected by Legal',
    'rejected by finance' => 'Legacy Returned by Finance',
];

function claim_distribution_label(?string $method): string
{
    return bk_distribution_method_label($method);
}

function claimant_claim_is_blank_draft(array $claim): bool
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

function claim_status_meta(?string $status): array
{
    $raw = trim((string) $status);
    $normalized = strtolower($raw);
    $label = udcs_claim_status_label($raw);
    $class = 'status-neutral';

    if (in_array($raw, ['Draft'], true)) {
        $class = 'status-neutral';
        $label = 'Draft';
    } elseif (in_array($raw, ['Pending Legal Review', 'Pending Finance Review'], true) || $normalized === 'pending') {
        $class = 'status-pending';
    } elseif (in_array($raw, ['Manual Legal Review Required', 'More Information Required', 'Returned by Finance'], true) || strpos($normalized, 'review') !== false || strpos($normalized, 'transferred') !== false) {
        $class = 'status-review';
    } elseif (in_array($raw, ['Approved for Disbursement', 'Disbursed', 'Closed'], true) || strpos($normalized, 'approved') !== false) {
        $class = 'status-approved';
    } elseif (strpos($normalized, 'rejected') !== false || $raw === 'OCR Validation Failed') {
        $class = 'status-rejected';
    }

    return [
        'label' => $label,
        'class' => $class,
    ];
}

$totalClaims = count($claims);
$pendingCount = 0;
$inReviewCount = 0;
$approvedCount = 0;
$rejectedCount = 0;

foreach ($claims as $claim) {
    $currentStatus = trim((string) ($claim['effective_status'] ?? $claim['claim_status'] ?? ''));
    $normalizedStatus = strtolower($currentStatus);

    if (in_array($currentStatus, ['Draft', 'Pending Legal Review', 'Pending Finance Review'], true) || $normalizedStatus === 'pending') {
        $pendingCount++;
    } elseif (in_array($currentStatus, ['Manual Legal Review Required', 'More Information Required', 'Returned by Finance'], true) || strpos($normalizedStatus, 'review') !== false || strpos($normalizedStatus, 'transferred') !== false) {
        $inReviewCount++;
    } elseif (in_array($currentStatus, ['Approved for Disbursement', 'Disbursed', 'Closed'], true) || strpos($normalizedStatus, 'approved') !== false) {
        $approvedCount++;
    } elseif ($currentStatus === 'OCR Validation Failed' || strpos($normalizedStatus, 'rejected') !== false) {
        $rejectedCount++;
    }
}

$resolvedCount = $approvedCount + $rejectedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    render_head(
        'My Claims | UNIFIED DIGITAL CLAIMS SYSTEM',
        '..',
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">'
    );
    ?>
    <style>
        .claims-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.25rem;
            background:
                linear-gradient(140deg, rgba(var(--bk-primary-rgb), 0.14), rgba(var(--bk-primary-rgb), 0.03) 46%, rgba(var(--bk-surface-rgb), 0.98)),
                rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
        }

        .claims-hero::after {
            content: "";
            position: absolute;
            width: 16rem;
            height: 16rem;
            right: -5rem;
            top: -6rem;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(var(--bk-primary-rgb), 0.22), rgba(var(--bk-primary-rgb), 0));
            animation: float 7s ease-in-out infinite;
            pointer-events: none;
        }

        .stat-chip {
            border: 1px solid rgba(var(--bk-border-rgb), 0.9);
            border-radius: 0.95rem;
            background: rgba(var(--bk-surface-rgb), 0.86);
            padding: 0.8rem 0.95rem;
            box-shadow: var(--shadow-soft);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .stat-chip:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .claim-stats-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        @media (max-width: 900px) {
            .claim-stats-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 560px) {
            .claim-stats-row {
                grid-template-columns: 1fr;
            }
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.26rem 0.62rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .status-pending {
            background: rgba(var(--bk-warning-rgb), 0.12);
            border-color: rgba(var(--bk-warning-rgb), 0.26);
            color: rgb(var(--bk-warning-rgb));
        }

        .status-review {
            background: rgba(var(--bk-primary-rgb), 0.11);
            border-color: rgba(var(--bk-primary-rgb), 0.25);
            color: rgb(var(--bk-primary-rgb));
        }

        .status-approved {
            background: rgba(var(--bk-success-rgb), 0.12);
            border-color: rgba(var(--bk-success-rgb), 0.25);
            color: rgb(var(--bk-success-rgb));
        }

        .status-rejected {
            background: rgba(var(--bk-danger-rgb), 0.11);
            border-color: rgba(var(--bk-danger-rgb), 0.24);
            color: rgb(var(--bk-danger-rgb));
        }

        .status-neutral {
            background: rgba(var(--bk-muted-rgb), 0.11);
            border-color: rgba(var(--bk-muted-rgb), 0.2);
            color: rgb(var(--bk-muted-rgb));
        }

        .decision-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-primary-rgb), 0.08);
            color: rgb(var(--bk-text-rgb));
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.24rem 0.58rem;
        }

        .data-table tbody tr td {
            transition: background-color 0.18s ease;
        }

        .data-table tbody tr:hover td {
            background: rgba(var(--bk-primary-rgb), 0.05);
        }

        .claimant-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.38rem;
            align-items: center;
            min-width: 9.5rem;
        }

        .modal-panel-scroll::-webkit-scrollbar {
            width: 10px;
        }

        .modal-panel-scroll::-webkit-scrollbar-thumb {
            border-radius: 999px;
            border: 2px solid transparent;
            background: rgba(var(--bk-primary-rgb), 0.35);
            background-clip: padding-box;
        }

        .distribution-option {
            display: flex;
            gap: 0.6rem;
            align-items: flex-start;
            padding: 0.68rem 0.75rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.85rem;
            background: rgba(var(--bk-surface-rgb), 1);
            cursor: pointer;
            transition: border-color 0.15s ease, background-color 0.15s ease, transform 0.15s ease;
        }

        .distribution-option:hover {
            border-color: rgba(var(--bk-primary-rgb), 0.6);
            background: rgba(var(--bk-primary-rgb), 0.07);
            transform: translateY(-1px);
        }

        .distribution-option input {
            margin-top: 0.16rem;
            accent-color: rgb(var(--bk-primary-rgb));
        }

        .doc-card {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.88rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.78rem;
            display: grid;
            gap: 0.72rem;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content claims-shell px-4 pb-8 pt-4 sm:px-6 lg:px-8">
    <section class="claims-hero p-6 sm:p-7">
        <div class="claims-page-header">
            <div>
                <h2 class="font-display">My Claims</h2>
                <p>
                    Follow your claim progress, update pending requests, and keep supporting documents current in one secure timeline.
                </p>
            </div>
            <div class="claims-tools">
                <?php render_button('Export PDF', [
                    'type' => 'button',
                    'onclick' => 'exportToPDF(this)',
                    'icon' => '<i class="fa-solid fa-file-pdf"></i>',
                ]); ?>
            </div>
        </div>
        <div class="claim-stats-row">
                <article class="stat-chip">
                    <p class="text-xs uppercase tracking-wide text-bk-muted">Total</p>
                    <p class="mt-1 text-2xl font-semibold text-bk-text"><?php echo $totalClaims; ?></p>
                </article>
                <article class="stat-chip">
                    <p class="text-xs uppercase tracking-wide text-bk-muted">Pending</p>
                    <p class="mt-1 text-2xl font-semibold text-bk-warning"><?php echo $pendingCount; ?></p>
                </article>
                <article class="stat-chip">
                    <p class="text-xs uppercase tracking-wide text-bk-muted">In Review</p>
                    <p class="mt-1 text-2xl font-semibold text-bk-primary"><?php echo $inReviewCount; ?></p>
                </article>
                <article class="stat-chip">
                    <p class="text-xs uppercase tracking-wide text-bk-muted">Resolved</p>
                    <p class="mt-1 text-2xl font-semibold text-bk-success"><?php echo $resolvedCount; ?></p>
                </article>
        </div>
    </section>

    <section class="mt-6 ui-card p-5 sm:p-6">
        <form method="GET" class="grid gap-4 lg:grid-cols-[1.6fr_1fr_auto]">
            <?php
            render_input('search', [
                'id' => 'search',
                'label' => 'Search',
                'value' => $search,
                'placeholder' => 'Deceased name, claim type, amount, or claim ID',
            ]);
            ?>

            <?php
            render_select('status', $statusOptions, [
                'id' => 'status',
                'label' => 'Status',
                'value' => $status,
                'placeholder' => 'All statuses',
            ]);
            ?>

            <div class="flex flex-wrap items-end gap-2">
                <?php render_button('Apply Filters', ['type' => 'submit']); ?>
                <?php render_button('Clear', ['variant' => 'secondary', 'href' => 'claims.php']); ?>
            </div>
        </form>

        <div class="mt-4 flex flex-wrap gap-2 border-t border-bk-border pt-4">
            <?php render_button('Refresh', ['variant' => 'secondary', 'type' => 'button', 'onclick' => 'refreshPage(this)']); ?>
            <?php render_button('Submit New Claim', ['variant' => 'ghost', 'href' => 'form_v2.php']); ?>
        </div>
    </section>

    <section class="mt-6 ui-card overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-bk-border px-5 py-4">
            <div>
                <h2 class="font-display text-xl font-semibold text-bk-text">Submitted Claims</h2>
                <p class="text-sm text-bk-muted">Showing <?php echo $totalClaims; ?> claim<?php echo $totalClaims === 1 ? '' : 's'; ?></p>
            </div>
            <span class="status-pill status-neutral"><?php echo $claimant_name; ?></span>
        </header>

        <?php if ($queryError !== ''): ?>
            <div class="px-5 py-5">
                <?php render_alert('We could not load your claims right now. Please refresh and try again.', ['type' => 'danger']); ?>
            </div>
        <?php else: ?>
            <div class="table-wrap bk-table-shell overflow-x-auto">
                <table id="claimsTable" class="data-table dash-table min-w-full text-left text-sm" data-udcs-expand-group data-udcs-expand-single="true">
                    <thead class="bg-bk-primary/10 text-xs uppercase tracking-wide text-bk-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3"><span class="table-entity-label"><i class="fa-solid fa-folder-open"></i>Claim ID</span></th>
                        <th scope="col" class="px-4 py-3"><span class="table-entity-label"><i class="fa-solid fa-id-card"></i>Deceased</span></th>
                        <th scope="col" class="px-4 py-3"><span class="table-entity-label"><i class="fa-solid fa-tags"></i>BK Asset Classes</span></th>
                        <th scope="col" class="px-4 py-3"><span class="table-entity-label"><i class="fa-solid fa-coins"></i>Amount</span></th>
                        <th scope="col" class="px-4 py-3"><span class="table-entity-label"><i class="fa-solid fa-circle-check"></i>Status</span></th>
                        <th scope="col" class="px-4 py-3"><span class="table-entity-label"><i class="fa-solid fa-scale-balanced"></i>Decision</span></th>
                        <th scope="col" class="px-4 py-3"><span class="table-entity-label"><i class="fa-solid fa-sliders"></i>Actions</span></th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-bk-border">
                    <?php if ($totalClaims === 0): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-8">
                                <?php render_alert('No claims match your current filters. Try clearing filters to view all submissions.', ['type' => 'info']); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($claims as $row): ?>
                            <?php
                            $claimId = (int) ($row['id'] ?? 0);
                            $reviewContract = udcs_claim_fetch_review_contract($conn, $claimId, $row);
                            if (!is_array($reviewContract)) {
                                $reviewContract = [];
                            }
                            $claimRow = (array) ($reviewContract['claim'] ?? $row);
                            $peopleSummary = (array) ($reviewContract['people']['summary'] ?? []);
                            $assetSummary = (array) ($reviewContract['assets']['summary'] ?? []);
                            $payoutSummary = (array) ($reviewContract['payout'] ?? []);
                            $effectiveStatus = (string) ($reviewContract['status']['key'] ?? ($row['effective_status'] ?? $row['claim_status'] ?? ''));
                            $effectiveStatusKey = udcs_claim_status_key($effectiveStatus);
                            $isLegacy = udcs_claim_legacy_flag($claimRow);
                            $isEditable = !$isLegacy && in_array($effectiveStatusKey, ['draft', 'ocr validation failed', 'more information required', 'returned by finance'], true);
                            $statusMeta = claim_status_meta($effectiveStatus);
                            $distribution = (string) ($payoutSummary['preferred_label'] ?? claim_distribution_label((string) ($claimRow['distribution_method'] ?? '')));
                            $destinationSummary = bk_claim_destination_summary(
                                bk_claim_account_reference($claimRow),
                                (string) ($claimRow['distribution_method'] ?? ''),
                                (string) ($claimRow['distribution_details'] ?? '')
                            );
                            $verifiedAmountLabel = udcs_claim_contract_value_label($reviewContract, 'verified');
                            $claimComment = trim((string) ($claimRow['comment'] ?? ''));
                            if ($claimComment === '') {
                                $claimComment = 'No review comment yet';
                            }
                            $deceasedDisplay = (string) (($peopleSummary['deceased_name'] ?? '') !== '' ? $peopleSummary['deceased_name'] : ($row['deceased_full_name'] ?? $row['deceased_name'] ?? '-'));
                            $assetLabel = (string) ($assetSummary['label'] ?? udcs_claim_asset_summary_label((string) ($row['asset_classes'] ?? ''), (string) ($claimRow['claim_type'] ?? '')));
                            $claimAmountLabel = udcs_claim_contract_value_label($reviewContract, 'estimated');
                            $expandPanelId = 'claimant-claim-expand-' . $claimId;
                            ?>
                            <tr>
                                <td class="px-4 py-3 font-semibold text-bk-text">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?></td>
                                <td class="px-4 py-3 text-bk-text"><?php echo bk_e($deceasedDisplay); ?></td>
                                <td class="px-4 py-3 text-bk-text"><?php echo bk_e($assetLabel); ?></td>
                                <td class="px-4 py-3 text-bk-text">
                                    <?php echo bk_e($claimAmountLabel); ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="status-pill <?php echo bk_e($statusMeta['class']); ?>">
                                        <?php echo bk_e($statusMeta['label']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="decision-pill"><?php echo bk_e($distribution); ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="claimant-actions">
                                        <?php udcs_claims_list_render_expand_button($expandPanelId, [
                                            'label' => 'More',
                                            'title' => 'Show or hide row details',
                                        ]); ?>
                                        <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost !h-8 !px-2.5" onclick="viewClaim(<?php echo $claimId; ?>)">
                                            View
                                        </button>
                                        <button
                                            type="button"
                                            class="ui-btn ui-btn-sm ui-btn-secondary !h-8 !px-2.5"
                                            onclick="<?php echo $isEditable ? 'window.location.href=\'form_v2.php?claim_id=' . $claimId . '\'' : 'return false'; ?>;"
                                            <?php echo $isEditable ? '' : 'disabled'; ?>
                                            title="<?php echo $isEditable ? 'Continue this redesigned claim' : 'Only active redesigned draft-style claims can be edited'; ?>"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="ui-btn ui-btn-sm !h-8 !px-2.5 <?php echo $isEditable ? 'ui-btn-primary' : 'ui-btn-secondary'; ?>"
                                            onclick="<?php echo !$isLegacy && $effectiveStatusKey === 'draft' ? 'deleteClaim(' . $claimId . ')' : 'return false'; ?>"
                                            <?php echo (!$isLegacy && $effectiveStatusKey === 'draft') ? '' : 'disabled'; ?>
                                            title="<?php echo (!$isLegacy && $effectiveStatusKey === 'draft') ? 'Delete draft claim' : 'Only redesigned draft claims can be deleted'; ?>"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php
                            udcs_claims_list_render_expand_row($expandPanelId, 7, [
                                [
                                    'title' => 'Family Path',
                                    'lines' => [
                                        ['label' => 'Relationship', 'value' => udcs_claim_relationship_label((string) ($claimRow['relationship'] ?? ''))],
                                        ['label' => 'Marital status', 'value' => ucwords(strtolower(str_replace('_', ' ', (string) ($claimRow['marital_status'] ?? 'Not specified'))))],
                                        ['label' => 'Spouse status', 'value' => ucwords(strtolower(str_replace('_', ' ', (string) ($claimRow['spouse_status'] ?? 'Not specified'))))],
                                        ['label' => 'Children status', 'value' => ucwords(strtolower(str_replace('_', ' ', (string) ($claimRow['children_status'] ?? 'Not specified'))))],
                                    ],
                                ],
                                [
                                    'title' => 'Assets and Value',
                                    'lines' => [
                                        ['label' => 'BK assets', 'value' => $assetLabel],
                                        ['label' => 'Claimant estimate', 'value' => $claimAmountLabel],
                                        ['label' => 'Finance confirmed', 'value' => $verifiedAmountLabel],
                                    ],
                                ],
                                [
                                    'title' => 'Settlement',
                                    'lines' => [
                                        ['label' => 'Preferred method', 'value' => $distribution],
                                        ['label' => 'Destination summary', 'value' => $destinationSummary],
                                        ['label' => 'Destination details', 'value' => !empty($payoutSummary['destination_complete']) ? 'Captured' : 'Needs completion'],
                                    ],
                                ],
                                [
                                    'title' => 'Review Context',
                                    'lines' => [
                                        ['label' => 'Current comment', 'value' => $claimComment],
                                        ['label' => 'Date', 'value' => (string) ($claimRow['submitted_at'] ?? 'Not submitted')],
                                        ['label' => 'Last updated', 'value' => (string) ($claimRow['updated_at'] ?? 'Not updated')],
                                    ],
                                ],
                            ]);
                            ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<div id="toastStack" class="ui-toast-stack pointer-events-none" aria-live="polite" aria-atomic="true"></div>

<div id="deleteModal" class="fixed inset-0 z-[1300] hidden items-center justify-center p-4" aria-hidden="true">
    <div class="absolute inset-0 bg-bk-primary/70" data-modal-close="delete"></div>
    <section class="relative z-10 w-full max-w-lg rounded-2xl border border-bk-border bg-bk-surface shadow-app" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <header class="border-b border-bk-border px-5 py-4">
            <h2 id="deleteModalTitle" class="font-display text-xl font-semibold text-bk-text">Delete Claim</h2>
            <p class="text-sm text-bk-muted">This action is permanent and removes the claim with related documents.</p>
        </header>
        <div class="px-5 py-5">
            <?php render_alert('Delete this pending claim only if it was submitted by mistake.', ['type' => 'warning']); ?>
        </div>
        <footer class="flex justify-end gap-2 border-t border-bk-border px-5 py-4">
            <?php render_button('Cancel', ['variant' => 'secondary', 'type' => 'button', 'onclick' => 'closeDeleteModal()']); ?>
            <button id="confirmDeleteBtn" type="button" class="ui-btn ui-btn-md ui-btn-primary">Delete Claim</button>
        </footer>
    </section>
</div>

<script>
(() => {
    const deleteModal = document.getElementById('deleteModal');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    const toastStack = document.getElementById('toastStack');
    let deleteClaimId = null;

    function setModalOpen(modal, isOpen) {
        if (!modal) return;
        modal.classList.toggle('hidden', !isOpen);
        modal.classList.toggle('flex', isOpen);
        modal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        document.body.classList.toggle('overflow-hidden', isOpen);
    }

    function setLoadingButton(button, isLoading, loadingText, normalText) {
        if (!button) return;
        if (isLoading) {
            button.disabled = true;
            button.innerHTML = `<span class="ui-spinner" aria-hidden="true"></span><span>${loadingText}</span>`;
            return;
        }
        button.disabled = false;
        button.textContent = normalText;
    }

    function showToast(type, title, message) {
        if (!toastStack) return;

        const safeType = ['success', 'warning', 'error'].includes(type) ? type : 'info';
        const toneClass = safeType === 'success'
            ? 'ui-toast-success'
            : safeType === 'warning'
                ? 'ui-toast-warning'
                : safeType === 'error'
                    ? 'ui-toast-danger'
                    : 'ui-toast-info';

        const toast = document.createElement('article');
        toast.className = `ui-toast ${toneClass} pointer-events-auto`;
        toast.setAttribute('role', 'status');
        toast.innerHTML = `
            <p class="text-sm font-semibold text-bk-text">${title}</p>
            <p class="mt-1 text-sm text-bk-muted">${message}</p>
            <button type="button" class="mt-2 text-xs font-medium text-bk-primary">Dismiss</button>
        `;

        const dismissBtn = toast.querySelector('button');
        const removeToast = () => {
            if (toast.parentElement) {
                toast.parentElement.removeChild(toast);
            }
        };

        dismissBtn?.addEventListener('click', removeToast);
        toastStack.appendChild(toast);
        setTimeout(removeToast, 5000);
    }

    window.refreshPage = function refreshPage(button) {
        if (button) {
            setLoadingButton(button, true, 'Refreshing...', 'Refresh');
        }
        window.location.reload();
    };

    window.exportToPDF = function exportToPDF(button) {
        if (button) {
            setLoadingButton(button, true, 'Exporting...', 'Export PDF');
        }

        const activeQuery = new URLSearchParams(window.location.search);
        const query = new URLSearchParams({
            search: activeQuery.get('search') || '',
            status: activeQuery.get('status') || '',
        });

        window.open(`export_claims.php?${query.toString()}`, '_blank', 'noopener');
        setTimeout(() => {
            if (button) {
                setLoadingButton(button, false, '', 'Export PDF');
            }
            showToast('success', 'Export started', 'Your claim report opened in a new tab.');
        }, 500);
    };

    window.viewClaim = function viewClaim(claimId) {
        window.open(`view_claim.php?id=${encodeURIComponent(claimId)}`, '_blank', 'noopener');
    };

    window.closeDeleteModal = function closeDeleteModal() {
        deleteClaimId = null;
        setLoadingButton(confirmDeleteBtn, false, '', 'Delete Claim');
        setModalOpen(deleteModal, false);
    };

    window.deleteClaim = function deleteClaim(claimId) {
        deleteClaimId = claimId;
        setModalOpen(deleteModal, true);
    };

    confirmDeleteBtn?.addEventListener('click', async () => {
        if (!deleteClaimId) {
            return;
        }

        setLoadingButton(confirmDeleteBtn, true, 'Deleting...', 'Delete Claim');

        try {
            const response = await fetch('delete_claim.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    claim_id: deleteClaimId,
                    csrf_token: <?php echo json_encode($claimDeleteCsrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                }),
            });
            const result = await response.json();

            if (result.success) {
                showToast('success', 'Claim deleted', 'Your claim was deleted successfully.');
                window.closeDeleteModal();
                setTimeout(() => window.location.reload(), 800);
            } else {
                showToast('error', 'Delete failed', result.message || 'We could not delete your claim.');
                setLoadingButton(confirmDeleteBtn, false, '', 'Delete Claim');
            }
        } catch (error) {
            console.error(error);
            showToast('error', 'Network issue', 'We could not delete your claim. Please try again.');
            setLoadingButton(confirmDeleteBtn, false, '', 'Delete Claim');
        }
    });

    document.querySelectorAll('[data-modal-close]').forEach((node) => {
        node.addEventListener('click', () => {
            if (node.dataset.modalClose === 'delete') {
                window.closeDeleteModal();
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && deleteModal && !deleteModal.classList.contains('hidden')) {
            window.closeDeleteModal();
        }
    });
})();
</script>
<?php udcs_claims_list_render_assets(); ?>
</body>
</html>

