<?php
// Tags: [LEGAL] [CLAIM] [STATUS]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claim_v2_detail_view.php';

if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'legal') {
    exit('Unauthorized');
}

$legalEmail = trim((string) ($_SESSION['email'] ?? ''));
$legalUserId = udcs_db_fetch_user_id_by_email_role($conn, $legalEmail, 'legal');
if ($legalUserId <= 0) {
    exit('Unauthorized');
}

$claim_id = (int) ($_GET['id'] ?? 0);
if ($claim_id <= 0) {
    echo '<p class="text-danger mb-0">Invalid claim reference.</p>';
    exit();
}

/* =========================
   CLAIM DETAILS
========================= */
$claimStmt = mysqli_prepare(
    $conn,
    'SELECT c.*, COALESCE(NULLIF(c.status, \'\'), c.claim_status) AS effective_status, u.full_name, u.email, u.phone
     FROM claims c
     JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
     WHERE c.id = ? AND c.assigned_legal_id = ?
     LIMIT 1'
);
$row = null;
if ($claimStmt) {
    mysqli_stmt_bind_param($claimStmt, 'ii', $claim_id, $legalUserId);
    if (mysqli_stmt_execute($claimStmt)) {
        $claimResult = mysqli_stmt_get_result($claimStmt);
        $row = $claimResult ? mysqli_fetch_assoc($claimResult) : null;
    }
}

if (!$row) {
    echo '<p class="text-danger mb-0">Claim not found in your legal queue.</p>';
    exit();
}

if (!udcs_claim_legacy_flag($row)) {
    udcs_claim_v2_render_detail_sections($conn, $row, ['context' => 'legal']);
    exit();
}

$claimCurrency = bk_currency_code((string) ($row['claim_currency_code'] ?? 'RWF'));
$claimedAmountLabel = bk_claim_amount_display_for_type(
    $row['claim_amount'] ?? null,
    (string) ($row['claim_type'] ?? ''),
    $claimCurrency
);
$destinationSummaryLabel = bk_claim_destination_summary(
    bk_claim_account_reference($row),
    (string) ($row['distribution_method'] ?? ''),
    (string) ($row['distribution_details'] ?? '')
);

/* =========================
   STATUS BADGE COLOR LOGIC
========================= */
$status = (string) ($row['effective_status'] ?? $row['claim_status'] ?? '');
$badgeClass = 'bk-badge-status is-default';

if ($status === 'rejected by legal') {
    $badgeClass = 'bk-badge-status is-rejected';
} elseif ($status === 'pending') {
    $badgeClass = 'bk-badge-status is-review';
} elseif ($status === 'transferred to finance' || $status === 'approved by legal') {
    $badgeClass = 'bk-badge-status is-approved';
}

/* =========================
   FETCH DOCUMENTS
========================= */
$docsStmt = mysqli_prepare(
    $conn,
    'SELECT id, document_type, file_path, uploaded_at
     FROM documents
     WHERE claim_id = ?
     ORDER BY uploaded_at DESC'
);
$docs = null;
if ($docsStmt) {
    mysqli_stmt_bind_param($docsStmt, 'i', $claim_id);
    if (mysqli_stmt_execute($docsStmt)) {
        $docs = mysqli_stmt_get_result($docsStmt);
    }
}

$documents = [];
$hasMarriageCertificate = false;
if ($docs) {
    while ($docRow = mysqli_fetch_assoc($docs)) {
        $documents[] = $docRow;
        if (strtolower(trim((string) ($docRow['document_type'] ?? ''))) === 'marriage_certificate') {
            $hasMarriageCertificate = true;
        }
    }
}

$relationshipValue = strtolower(trim((string) ($row['relationship'] ?? '')));
$isSpouseClaim = $relationshipValue === 'spouse';
$documentCount = count($documents);
$documentTypeLabels = [];
foreach ($documents as $docRow) {
    $documentTypeLabels[] = ucwords(str_replace('_', ' ', (string) ($docRow['document_type'] ?? 'Document')));
}
$documentTypeLabels = array_values(array_unique($documentTypeLabels));
$documentListLabel = !empty($documentTypeLabels)
    ? implode(', ', $documentTypeLabels)
    : 'No documents uploaded yet.';
$verificationSummaryLabel = $documentCount > 0
    ? $documentCount . ' document(s) passed initial intake verification during submission.'
    : 'No document has been uploaded yet.';
$relationshipVerificationLabel = $isSpouseClaim
    ? ($hasMarriageCertificate
        ? 'Spouse rule satisfied: marriage certificate is present.'
        : 'Spouse rule not satisfied: marriage certificate is missing and requires follow-up.')
    : 'Relationship-specific spouse certificate is not required for this claimant.';
$verificationIcon = 'bi-check-circle-fill text-success';
if ($documentCount === 0 || ($isSpouseClaim && !$hasMarriageCertificate)) {
    $verificationIcon = 'bi-exclamation-triangle-fill text-danger';
}

/* =========================
   DISTRIBUTION HELPER FUNCTION
========================= */
function getDistributionLabel($method) {
    return bk_distribution_method_label((string) $method);
}

function getDistributionIcon($method) {
    $icons = [
        'cash_pickup' => 'bi-cash',
        'transfer_to_claimant_account' => 'bi-arrow-left-right',
        'transfer_to_other_bank' => 'bi-bank2',
        'mobile_money_wallet' => 'bi-phone',
        'transfer_to_deceased_account' => 'bi-bank',
        'bank_draft' => 'bi-file-text',
        'split_payout_accounts' => 'bi-diagram-3',
        'staged_installments' => 'bi-calendar-week',
        'sell_shares_cash' => 'bi-graph-up',
        'transfer_shares_claimant' => 'bi-pie-chart',
        'partial_sale_partial_transfer' => 'bi-bar-chart-steps',
        'transfer_shares_nominee' => 'bi-person-check',
        'hold_shares' => 'bi-pause-circle',
        'inspection_access' => 'bi-search',
        'transfer_ownership' => 'bi-file-earmark-text',
        'liquidate_assets' => 'bi-coin',
        'consultation_required' => 'bi-chat-dots',
        'mixed_distribution' => 'bi-cubes'
    ];
    
    return $icons[$method] ?? 'bi-question-circle';
}

function getDistributionColor($method) {
    $colors = [
        'cash_pickup' => '#0A5BB4',
        'transfer_to_claimant_account' => '#034EA2',
        'transfer_to_other_bank' => '#0B67C6',
        'mobile_money_wallet' => '#2B7ACD',
        'transfer_to_deceased_account' => '#5E84B8',
        'bank_draft' => '#42536F',
        'split_payout_accounts' => '#1060B6',
        'staged_installments' => '#0F62B8',
        'sell_shares_cash' => '#0A5BB4',
        'transfer_shares_claimant' => '#034EA2',
        'partial_sale_partial_transfer' => '#2B7ACD',
        'transfer_shares_nominee' => '#5E84B8',
        'hold_shares' => '#42536F',
        'inspection_access' => '#2279D0',
        'transfer_ownership' => '#0B67C6',
        'liquidate_assets' => '#103E82',
        'consultation_required' => '#5E84B8',
        'mixed_distribution' => '#111827'
    ];
    
    return $colors[$method] ?? '#42536F';
}

function isMoneyDistributionMethod($method) {
    return in_array((string) $method, [
        'cash_pickup',
        'transfer_to_claimant_account',
        'transfer_to_other_bank',
        'mobile_money_wallet',
        'bank_draft',
        'split_payout_accounts',
        'staged_installments',
    ], true);
}

function isSharesDistributionMethod($method) {
    return in_array((string) $method, [
        'sell_shares_cash',
        'transfer_shares_claimant',
        'partial_sale_partial_transfer',
        'transfer_shares_nominee',
    ], true);
}

function isPhysicalDistributionMethod($method) {
    return in_array((string) $method, [
        'inspection_access',
        'transfer_ownership',
        'liquidate_assets',
    ], true);
}
?>

<!-- CLAIM HEADER -->
<div class="row g-3">
    <div class="col-md-6">
        <strong>Claimant:</strong> <?= htmlspecialchars($row['full_name']) ?><br>
        <small><?= htmlspecialchars($row['email']) ?> | <?= htmlspecialchars($row['phone']) ?></small>
    </div>

    <div class="col-md-6 text-end">
        <strong>Status:</strong><br>
        <span class="<?= $badgeClass ?>">
            <?= htmlspecialchars(udcs_claim_status_label((string) ($row['effective_status'] ?? $row['claim_status'] ?? ''))) ?>
        </span><br>
        <small class="text-muted">
            Submitted: <?= date('d M Y', strtotime($row['submitted_at'])) ?>
        </small>
    </div>
</div>

<!-- REVIEW SNAPSHOT -->
<div class="row mt-3 g-2">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0" style="background: rgba(3, 78, 162, 0.1);">
            <div class="card-body py-2 px-3">
                <div class="small text-muted">Claim Reference</div>
                <div class="fw-semibold">CL-<?= str_pad((string) ($row['id'] ?? 0), 6, '0', STR_PAD_LEFT) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0" style="background: rgba(3, 78, 162, 0.1);">
            <div class="card-body py-2 px-3">
                <div class="small text-muted">Relationship</div>
                <div class="fw-semibold"><?= htmlspecialchars(ucfirst((string) ($row['relationship'] ?? 'N/A'))) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0" style="background: rgba(3, 78, 162, 0.1);">
            <div class="card-body py-2 px-3">
                <div class="small text-muted">Disbursement Destination</div>
                <div class="fw-semibold"><?= htmlspecialchars($destinationSummaryLabel) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0" style="background: rgba(3, 78, 162, 0.1);">
            <div class="card-body py-2 px-3">
                <div class="small text-muted">Documents</div>
                <div class="fw-semibold"><?= count($documents) ?> uploaded</div>
            </div>
        </div>
    </div>
</div>

<hr class="mt-3">

<div class="row">
    <div class="col-md-6">
        <strong>Claim Type:</strong><br>
        <?= htmlspecialchars(bk_claim_type_label((string) ($row['claim_type'] ?? ''))) ?>
    </div>

    <div class="col-md-6">
        <strong>Amount:</strong><br>
        <?= htmlspecialchars($claimedAmountLabel) ?>
    </div>
</div>

<!-- DOCUMENT VERIFICATION SUMMARY -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card border-0 review-info-card">
            <div class="card-body py-3">
                <div class="d-flex align-items-start gap-2">
                    <i class="bi <?= $verificationIcon ?>"></i>
                    <div>
                        <div class="fw-semibold">Document Verification Summary</div>
                        <div class="text-muted small mb-1"><?= htmlspecialchars($verificationSummaryLabel) ?></div>
                        <div class="text-muted small mb-1"><strong>Uploaded types:</strong> <?= htmlspecialchars($documentListLabel) ?></div>
                        <div class="text-muted small"><strong>Relationship rule:</strong> <?= htmlspecialchars($relationshipVerificationLabel) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($row['alt_phone']) || !empty($row['alt_email'])): ?>
<!-- ALTERNATIVE CONTACT SECTION -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-body">
                <h6 class="fw-semibold text-warning mb-3">
                    <i class="bi bi-person-lines-fill me-1"></i>
                    Alternative Contact Information
                </h6>

                <div class="row">
                    <div class="col-md-6">
                        <strong>Alternative Phone:</strong>
                        <div class="text-muted">
                            <?= $row['alt_phone'] ? htmlspecialchars($row['alt_phone']) : 'N/A' ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <strong>Alternative Email:</strong>
                        <div class="text-muted">
                            <?= $row['alt_email'] ? htmlspecialchars($row['alt_email']) : 'N/A' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- DECEASED INFORMATION SECTION -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-light">
                <h6 class="fw-semibold mb-0">
                    <i class="bi bi-person"></i> Deceased Information
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Name:</strong><br>
                        <?= htmlspecialchars($row['deceased_name'] ?? 'N/A') ?>
                    </div>
                    <div class="col-md-4">
                        <strong>National ID:</strong><br>
                        <?= htmlspecialchars($row['deceased_national_id'] ?? 'N/A') ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Date of Death:</strong><br>
                        <?= $row['deceased_date'] ? date('d M Y', strtotime($row['deceased_date'])) : 'N/A' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- âœ… NEW: DISTRIBUTION METHOD SECTION -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card" style="border-left: 4px solid #034EA2; box-shadow: 0 2px 8px rgba(3,78,162,0.08);">
            <div class="card-body">
                <h6 class="fw-semibold mb-3" style="color: #034EA2;">
                    <i class="bi bi-hand-thumbs-up"></i>
                    Distribution Method
                </h6>

                <?php if (!empty($row['distribution_method'])): 
                    $method = $row['distribution_method'];
                    $label = getDistributionLabel($method);
                    $icon = getDistributionIcon($method);
                    $color = getDistributionColor($method);
                ?>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="d-flex align-items-center justify-content-center" 
                             style="width: 50px; height: 50px; background: <?= $color ?>10; border-radius: 10px;">
                            <i class="bi <?= $icon ?>" style="font-size: 24px; color: <?= $color ?>;"></i>
                        </div>
                        <div>
                            <span class="fw-bold"><?= $label ?></span>
                            <?php $distributionRows = bk_distribution_detail_rows((string) ($row['distribution_details'] ?? '')); ?>
                            <?php if (!empty($distributionRows)): ?>
                                <div class="text-muted small mt-1">
                                    <i class="bi bi-info-circle"></i>
                                    <div class="mt-1" style="display: grid; gap: 2px;">
                                        <?php foreach ($distributionRows as $detailRow): ?>
                                            <div>
                                                <strong><?= htmlspecialchars((string) $detailRow['label']) ?>:</strong>
                                                <?= htmlspecialchars((string) $detailRow['value']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Processing Time Hint -->
                    <div class="mt-2 p-2" style="background: rgba(var(--bk-bg-rgb), 0.58); border-radius: 6px;">
                        <small class="text-muted">
                            <i class="bi bi-clock-history"></i>
                            <?php if (isMoneyDistributionMethod($method)): ?>
                                Settlement preference captured. Finance validates destination details and disburses in 3-5 business days after approval.
                            <?php elseif (isSharesDistributionMethod($method)): ?>
                                Internal securities and registrar checks are required before execution (typically 3-5 business days).
                            <?php elseif (isPhysicalDistributionMethod($method)): ?>
                                Physical inspection and branch operations coordination are required (typically 5-7 business days).
                            <?php else: ?>
                                Specialist clarification may be required before final settlement.
                            <?php endif; ?>
                        </small>
                    </div>
                <?php else: ?>
                    <div class="text-muted">
                        <i class="bi bi-exclamation-circle"></i>
                        No distribution method specified
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- DESCRIPTION SECTION -->
<?php if (!empty($row['claim_description'])): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-light">
                <h6 class="fw-semibold mb-0">
                    <i class="bi bi-file-text"></i> Claim Description
                </h6>
            </div>
            <div class="card-body">
                <p class="mb-0"><?= nl2br(htmlspecialchars($row['claim_description'])) ?></p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- DOCUMENTS SECTION -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-light">
                <h6 class="fw-semibold mb-0">
                    <i class="bi bi-paperclip me-1"></i> Submitted Documents
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($documents)): ?>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($documents as $doc):
                            $documentUrl = '../document_access.php?id=' . (int) ($doc['id'] ?? 0);
                            $filePath = htmlspecialchars((string) ($doc['file_path'] ?? ''));
                            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                            $icon = in_array($ext, ['jpg','jpeg','png'])
                                ? 'bi-file-image'
                                : ($ext === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark');
                        ?>
                            <a href="<?= htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="text-decoration-none">
                                <div class="border rounded p-3 text-center bg-light doc-tile">
                                    <i class="bi <?= $icon ?> fs-1 text-primary"></i>
                                    <div class="small fw-semibold mt-2">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($doc['document_type'] ?? '')))) ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= !empty($doc['uploaded_at']) ? date('d M Y', strtotime((string) $doc['uploaded_at'])) : 'N/A' ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No documents uploaded for this claim.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ADD SOME CUSTOM CSS -->
<style>
    .bk-badge-status {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        border: 1px solid rgba(var(--bk-border-rgb), 0.9);
        background: rgba(var(--bk-primary-rgb), 0.12);
        color: rgb(var(--bk-text-rgb));
        font-weight: 700;
        padding: 8px 12px;
        font-size: 12px;
    }
    .bk-badge-status.is-review {
        border-color: rgba(var(--bk-primary-rgb), 0.45);
        background: rgba(var(--bk-primary-rgb), 0.16);
        color: rgb(var(--bk-primary-rgb));
    }
    .bk-badge-status.is-approved {
        border-color: rgba(var(--bk-success-rgb), 0.45);
        background: rgba(var(--bk-success-rgb), 0.16);
        color: rgb(var(--bk-success-rgb));
    }
    .bk-badge-status.is-rejected {
        border-color: rgba(var(--bk-danger-rgb), 0.45);
        background: rgba(var(--bk-danger-rgb), 0.16);
        color: rgb(var(--bk-danger-rgb));
    }
    .card {
        border-radius: 10px;
        border: 1px solid rgba(var(--bk-border-rgb), 0.92);
    }
    .review-info-card {
        background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.12), rgba(var(--bk-surface-rgb), 0.95));
    }
    .card-header {
        background-color: rgba(var(--bk-bg-rgb), 0.58);
        border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.9);
    }
    .badge {
        padding: 8px 12px;
        font-size: 12px;
    }
    .hover-lift:hover,
    .doc-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(var(--bk-primary-rgb), 0.2);
        transition: all 0.2s ease;
    }
    .doc-tile {
        width: 160px;
        transition: all 0.2s ease;
    }
</style>




