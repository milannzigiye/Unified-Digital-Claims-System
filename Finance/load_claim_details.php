<?php
// Tags: [FINANCE] [CLAIM] [STATUS]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claim_v2_detail_view.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'finance') {
    exit('Unauthorized');
}

$financeEmail = trim((string) ($_SESSION['email'] ?? ''));
$financeUserId = udcs_db_fetch_user_id_by_email_role($conn, $financeEmail, 'finance');
if ($financeUserId <= 0) {
    exit('Unauthorized');
}

$claimId = (int) ($_GET['id'] ?? 0);
if ($claimId <= 0) {
    echo '<div class="fcs-empty">Invalid claim reference.</div>';
    exit();
}

$claimStmt = mysqli_prepare(
    $conn,
    'SELECT
        c.*,
        COALESCE(NULLIF(c.status, \'\'), c.claim_status) AS effective_status,
        u.full_name,
        u.email,
        u.phone
     FROM claims c
     JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
     WHERE c.id = ? AND c.assigned_finance_id = ?
     LIMIT 1'
);
$claim = null;
if ($claimStmt) {
    mysqli_stmt_bind_param($claimStmt, 'ii', $claimId, $financeUserId);
    if (mysqli_stmt_execute($claimStmt)) {
        $claimResult = mysqli_stmt_get_result($claimStmt);
        $claim = $claimResult ? mysqli_fetch_assoc($claimResult) : null;
    }
}
if (!$claim) {
    echo '<div class="fcs-empty">Claim not found in your finance queue.</div>';
    exit();
}

if (!udcs_claim_legacy_flag($claim)) {
    udcs_claim_v2_render_detail_sections($conn, $claim, ['context' => 'finance']);
    exit();
}

$claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
$financeCurrency = bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? $claimCurrency));
$claimedAmountLabel = bk_claim_amount_display_for_type(
    $claim['claim_amount'] ?? null,
    (string) ($claim['claim_type'] ?? ''),
    $claimCurrency
);
$hasClaimantValue = bk_claim_amount_numeric($claim['claim_amount'] ?? null) !== null;
$claimantValueStateLabel = $hasClaimantValue ? 'Claimant value provided' : 'Claimant value not provided';
$claimantValueStateClass = $hasClaimantValue ? 'is-ok' : 'is-pending';

$assessedAmountLabel = bk_claim_amount_display(
    $claim['finance_assessed_amount'] ?? null,
    $financeCurrency,
    'Not assessed yet'
);
$hasAssessedValue = bk_claim_amount_numeric($claim['finance_assessed_amount'] ?? null) !== null;
$assessedValueStateLabel = $hasAssessedValue ? 'Finance value assessed' : 'Finance assessment pending';
$assessedValueStateClass = $hasAssessedValue ? 'is-ok' : 'is-pending';
$destinationSummaryLabel = bk_claim_destination_summary(
    bk_claim_account_reference($claim),
    (string) ($claim['distribution_method'] ?? ''),
    (string) ($claim['distribution_details'] ?? '')
);
$distributionMethodLabel = bk_distribution_method_label((string) ($claim['distribution_method'] ?? ''));
$distributionRows = bk_distribution_detail_rows((string) ($claim['distribution_details'] ?? ''));
$settlementPrimaryLabel = '';
$settlementPrimaryValue = '';
if (preg_match('/^([^:]+):\s*(.+)$/', $destinationSummaryLabel, $destinationParts)) {
    $settlementPrimaryLabel = trim((string) ($destinationParts[1] ?? ''));
    $settlementPrimaryValue = trim((string) ($destinationParts[2] ?? ''));
}

$settlementRows = [];
foreach ($distributionRows as $detailRow) {
    $rowLabel = trim((string) ($detailRow['label'] ?? ''));
    $rowValue = trim((string) ($detailRow['value'] ?? ''));
    if ($rowLabel === '' || $rowValue === '') {
        continue;
    }

    if ($settlementPrimaryLabel !== '' && strcasecmp($rowLabel, $settlementPrimaryLabel) === 0) {
        continue;
    }

    if (strcasecmp($rowLabel, 'Destination') === 0 || strcasecmp($rowLabel, 'Method') === 0) {
        continue;
    }

    $settlementRows[] = [
        'label' => $rowLabel,
        'value' => $rowValue,
    ];
}

$statusValue = (string) ($claim['effective_status'] ?? $claim['claim_status'] ?? '');
$statusText = udcs_claim_status_label($statusValue);
$statusKey = strtolower(trim($statusValue));
$statusClass = 'is-default';
if (str_contains($statusKey, 'approved')) {
    $statusClass = 'is-approved';
} elseif (str_contains($statusKey, 'rejected')) {
    $statusClass = 'is-rejected';
} elseif ($statusKey === 'pending' || str_contains($statusKey, 'review') || str_contains($statusKey, 'transferred')) {
    $statusClass = 'is-review';
}

$documents = [];
$hasMarriageCertificate = false;
$docsStmt = mysqli_prepare(
    $conn,
    'SELECT id, document_type, file_path, uploaded_at FROM documents WHERE claim_id = ? ORDER BY uploaded_at DESC'
);
$docsResult = false;
if ($docsStmt) {
    mysqli_stmt_bind_param($docsStmt, 'i', $claimId);
    if (mysqli_stmt_execute($docsStmt)) {
        $docsResult = mysqli_stmt_get_result($docsStmt);
    }
}
if ($docsResult) {
    while ($docRow = mysqli_fetch_assoc($docsResult)) {
        $documents[] = $docRow;
        if (strtolower(trim((string) ($docRow['document_type'] ?? ''))) === 'marriage_certificate') {
            $hasMarriageCertificate = true;
        }
    }
}

$documentCount = count($documents);
$documentTypeLabels = [];
foreach ($documents as $docRow) {
    $documentTypeLabels[] = ucwords(str_replace('_', ' ', (string) ($docRow['document_type'] ?? 'Document')));
}
$documentTypeLabels = array_values(array_unique($documentTypeLabels));
$documentListLabel = !empty($documentTypeLabels) ? implode(', ', $documentTypeLabels) : 'No documents uploaded yet.';
$verificationSummaryLabel = $documentCount > 0
    ? $documentCount . ' document(s) passed initial intake verification during submission.'
    : 'No document has been uploaded yet.';

$relationshipValue = strtolower(trim((string) ($claim['relationship'] ?? '')));
$isSpouseClaim = $relationshipValue === 'spouse';
$relationshipRuleLabel = $isSpouseClaim
    ? ($hasMarriageCertificate
        ? 'Spouse requirement satisfied: marriage certificate present.'
        : 'Spouse requirement pending: marriage certificate missing.')
    : 'Spouse-specific certificate is not required for this relationship.';
?>

<div class="finance-claim-sheet">
    <section class="fcs-section">
        <div class="fcs-head">Claim Overview</div>
        <div class="fcs-grid">
            <article class="fcs-item">
                <div class="fcs-label">Claim ID</div>
                <div class="fcs-value">CL-<?php echo str_pad((string) ($claim['id'] ?? 0), 6, '0', STR_PAD_LEFT); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Status</div>
                <div class="fcs-value">
                    <span class="fcs-status <?php echo bk_e($statusClass); ?>"><?php echo bk_e($statusText); ?></span>
                </div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Submitted</div>
                <div class="fcs-value"><?php echo bk_e(date('d M Y H:i', strtotime((string) ($claim['submitted_at'] ?? 'now')))); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Claimant</div>
                <div class="fcs-value"><?php echo bk_e((string) ($claim['full_name'] ?? 'Unknown')); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Email</div>
                <div class="fcs-value"><?php echo bk_e((string) ($claim['email'] ?? '-')); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Phone</div>
                <div class="fcs-value"><?php echo bk_e((string) ($claim['phone'] ?? '-')); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Claim Type</div>
                <div class="fcs-value"><?php echo bk_e(bk_claim_type_label((string) ($claim['claim_type'] ?? ''))); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Relationship</div>
                <div class="fcs-value"><?php echo bk_e(ucfirst((string) ($claim['relationship'] ?? 'N/A'))); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Claimed Value</div>
                <div class="fcs-value strong"><?php echo bk_e($claimedAmountLabel); ?></div>
                <div class="fcs-value-badge-row">
                    <span class="fcs-value-badge <?php echo bk_e($claimantValueStateClass); ?>">
                        <?php echo bk_e($claimantValueStateLabel); ?>
                    </span>
                </div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Finance Assessed Value</div>
                <div class="fcs-value strong"><?php echo bk_e($assessedAmountLabel); ?></div>
                <div class="fcs-value-badge-row">
                    <span class="fcs-value-badge <?php echo bk_e($assessedValueStateClass); ?>">
                        <?php echo bk_e($assessedValueStateLabel); ?>
                    </span>
                </div>
            </article>
        </div>
    </section>

    <section class="fcs-section">
        <div class="fcs-head">Settlement Details</div>
        <div class="fcs-settlement-wrap">
            <div class="fcs-settlement-line">
                <span class="fcs-settlement-key">Method</span>
                <span class="fcs-settlement-value"><?php echo bk_e($distributionMethodLabel); ?></span>
            </div>
            <div class="fcs-settlement-line">
                <span class="fcs-settlement-key"><?php echo bk_e($settlementPrimaryLabel !== '' ? $settlementPrimaryLabel : 'Destination'); ?></span>
                <span class="fcs-settlement-value"><?php echo bk_e($settlementPrimaryValue !== '' ? $settlementPrimaryValue : $destinationSummaryLabel); ?></span>
            </div>
            <?php if (!empty($settlementRows)): ?>
                <div class="fcs-settlement-lines">
                    <?php foreach ($settlementRows as $detailRow): ?>
                        <div class="fcs-settlement-line">
                            <span class="fcs-settlement-key"><?php echo bk_e((string) ($detailRow['label'] ?? 'Detail')); ?></span>
                            <span class="fcs-settlement-value"><?php echo bk_e((string) ($detailRow['value'] ?? '-')); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="fcs-section">
        <div class="fcs-head">Document Verification</div>
        <div class="fcs-stack">
            <article class="fcs-item">
                <div class="fcs-label">Summary</div>
                <div class="fcs-value"><?php echo bk_e($verificationSummaryLabel); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Uploaded Types</div>
                <div class="fcs-value"><?php echo bk_e($documentListLabel); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Relationship Rule</div>
                <div class="fcs-value"><?php echo bk_e($relationshipRuleLabel); ?></div>
            </article>
        </div>
    </section>

    <section class="fcs-section">
        <div class="fcs-head">Deceased Record</div>
        <div class="fcs-grid">
            <article class="fcs-item">
                <div class="fcs-label">Full Name</div>
                <div class="fcs-value"><?php echo bk_e((string) ($claim['deceased_name'] ?? 'N/A')); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">National ID</div>
                <div class="fcs-value"><?php echo bk_e((string) ($claim['deceased_national_id'] ?? 'N/A')); ?></div>
            </article>
            <article class="fcs-item">
                <div class="fcs-label">Date of Death</div>
                <div class="fcs-value"><?php echo bk_e(!empty($claim['deceased_date']) ? date('d M Y', strtotime((string) $claim['deceased_date'])) : 'N/A'); ?></div>
            </article>
        </div>
    </section>

    <?php if (!empty((string) ($claim['claim_description'] ?? ''))): ?>
    <section class="fcs-section">
        <div class="fcs-head">Claim Description</div>
        <div class="fcs-note"><?php echo nl2br(bk_e((string) $claim['claim_description'])); ?></div>
    </section>
    <?php endif; ?>

    <section class="fcs-section">
        <div class="fcs-head">Uploaded Documents</div>
        <?php if (!empty($documents)): ?>
            <div class="fcs-doc-list">
                <?php foreach ($documents as $doc): ?>
                    <?php
                    $docTypeLabel = ucwords(str_replace('_', ' ', (string) ($doc['document_type'] ?? 'Document')));
                    $docDateLabel = !empty($doc['uploaded_at']) ? date('d M Y', strtotime((string) $doc['uploaded_at'])) : '-';
                    $documentUrl = '../document_access.php?id=' . (int) ($doc['id'] ?? 0);
                    ?>
                    <article class="fcs-doc-item">
                        <div class="fcs-doc-meta">
                            <span class="fcs-doc-icon" aria-hidden="true">
                                <i class="bi bi-file-earmark-text"></i>
                            </span>
                            <div class="fcs-doc-copy">
                                <div class="fcs-doc-type"><?php echo bk_e($docTypeLabel); ?></div>
                                <div class="fcs-doc-date">Uploaded: <?php echo bk_e($docDateLabel); ?></div>
                            </div>
                        </div>
                        <div class="fcs-doc-actions">
                            <a
                                class="fcs-doc-action"
                                href="<?php echo bk_e($documentUrl); ?>"
                                target="_blank"
                                rel="noopener"
                                title="Open document"
                                aria-label="Open <?php echo bk_e($docTypeLabel); ?>"
                            >
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="fcs-empty-lite">No documents uploaded for this claim.</div>
        <?php endif; ?>
    </section>
</div>

<style>
    .finance-claim-sheet {
        display: grid;
        gap: 0.72rem;
    }

    .fcs-section {
        border: 1px solid rgba(var(--bk-border-rgb), 1);
        border-radius: 0.92rem;
        background: rgb(var(--bk-surface-rgb));
        overflow: hidden;
    }

    .fcs-head {
        border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
        background: rgba(var(--bk-primary-rgb), 0.08);
        color: rgb(var(--bk-text-rgb));
        font-size: 0.86rem;
        font-weight: 800;
        letter-spacing: 0.02em;
        padding: 0.56rem 0.72rem;
    }

    .fcs-grid {
        padding: 0.64rem 0.72rem;
        display: grid;
        gap: 0.54rem;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .fcs-stack {
        padding: 0.64rem 0.72rem;
        display: grid;
        gap: 0.54rem;
    }

    .fcs-item {
        border: 1px solid rgba(var(--bk-border-rgb), 1);
        border-radius: 0.72rem;
        background: rgba(var(--bk-bg-rgb), 0.42);
        padding: 0.48rem 0.56rem;
        min-width: 0;
    }

    .fcs-label {
        font-size: 0.67rem;
        color: rgb(var(--bk-muted-rgb));
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 0.18rem;
    }

    .fcs-value {
        font-size: 0.84rem;
        font-weight: 700;
        color: rgb(var(--bk-text-rgb));
        overflow-wrap: anywhere;
        line-height: 1.35;
    }

    .fcs-value.strong {
        color: rgb(var(--bk-primary-rgb));
        font-size: 0.95rem;
    }

    .fcs-value-badge-row {
        margin-top: 0.3rem;
    }

    .fcs-value-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        border: 1px solid rgba(var(--bk-border-rgb), 1);
        padding: 0.16rem 0.52rem;
        font-size: 0.67rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        line-height: 1.2;
    }

    .fcs-value-badge.is-ok {
        border-color: rgba(var(--bk-success-rgb), 0.38);
        background: rgba(var(--bk-success-rgb), 0.14);
        color: rgb(var(--bk-success-rgb));
    }

    .fcs-value-badge.is-pending {
        border-color: rgba(var(--bk-primary-rgb), 0.36);
        background: rgba(var(--bk-primary-rgb), 0.12);
        color: rgb(var(--bk-primary-rgb));
    }

    .fcs-status {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        border: 1px solid rgba(var(--bk-border-rgb), 1);
        padding: 0.24rem 0.58rem;
        font-size: 0.69rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-weight: 800;
    }

    .fcs-status.is-review {
        border-color: rgba(var(--bk-primary-rgb), 0.35);
        background: rgba(var(--bk-primary-rgb), 0.14);
        color: rgb(var(--bk-primary-rgb));
    }

    .fcs-status.is-approved {
        border-color: rgba(var(--bk-success-rgb), 0.35);
        background: rgba(var(--bk-success-rgb), 0.14);
        color: rgb(var(--bk-success-rgb));
    }

    .fcs-status.is-rejected {
        border-color: rgba(var(--bk-danger-rgb), 0.35);
        background: rgba(var(--bk-danger-rgb), 0.14);
        color: rgb(var(--bk-danger-rgb));
    }

    .fcs-status.is-default {
        border-color: rgba(var(--bk-border-rgb), 1);
        background: rgba(var(--bk-muted-rgb), 0.12);
        color: rgb(var(--bk-text-rgb));
    }

    .fcs-settlement-wrap {
        padding: 0.64rem 0.72rem;
        border: 1px solid rgba(var(--bk-border-rgb), 1);
        border-radius: 0.72rem;
        background: rgba(var(--bk-bg-rgb), 0.28);
        display: grid;
        gap: 0;
    }

    .fcs-settlement-lines {
        display: grid;
        gap: 0;
    }

    .fcs-settlement-line {
        display: grid;
        gap: 0.24rem;
        grid-template-columns: minmax(140px, 0.9fr) minmax(0, 1.6fr);
        align-items: start;
        padding: 0.44rem 0.08rem;
        border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
    }

    .fcs-settlement-line:last-child {
        border-bottom: none;
    }

    .fcs-settlement-key {
        font-size: 0.73rem;
        color: rgb(var(--bk-muted-rgb));
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .fcs-settlement-value {
        font-size: 0.8rem;
        color: rgb(var(--bk-text-rgb));
        font-weight: 600;
        overflow-wrap: anywhere;
    }

    .fcs-note {
        padding: 0.64rem 0.72rem;
        font-size: 0.82rem;
        line-height: 1.45;
        color: rgb(var(--bk-text-rgb));
        overflow-wrap: anywhere;
    }

    .fcs-doc-list {
        padding: 0.64rem 0.72rem;
        display: grid;
        gap: 0.46rem;
    }

    .fcs-doc-item {
        border: 1px solid rgba(var(--bk-border-rgb), 1);
        border-radius: 0.72rem;
        background: rgba(var(--bk-bg-rgb), 0.34);
        padding: 0.48rem 0.56rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.62rem;
    }

    .fcs-doc-meta {
        display: flex;
        align-items: center;
        gap: 0.52rem;
        min-width: 0;
    }

    .fcs-doc-icon {
        width: 1.85rem;
        height: 1.85rem;
        border-radius: 0.52rem;
        border: 1px solid rgba(var(--bk-primary-rgb), 0.26);
        background: rgba(var(--bk-primary-rgb), 0.12);
        color: rgb(var(--bk-primary-rgb));
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.88rem;
        flex: 0 0 auto;
    }

    .fcs-doc-copy {
        min-width: 0;
        display: grid;
        gap: 0.08rem;
    }

    .fcs-doc-type {
        font-size: 0.8rem;
        font-weight: 700;
        color: rgb(var(--bk-text-rgb));
        overflow-wrap: anywhere;
    }

    .fcs-doc-date {
        font-size: 0.72rem;
        color: rgb(var(--bk-muted-rgb));
    }

    .fcs-doc-actions {
        flex: 0 0 auto;
    }

    .fcs-doc-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        border-radius: 0.62rem;
        border: 1px solid rgba(var(--bk-primary-rgb), 0.4);
        background: rgba(var(--bk-primary-rgb), 0.12);
        color: rgb(var(--bk-primary-rgb));
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
    }

    .fcs-doc-action:hover {
        background: rgba(var(--bk-primary-rgb), 0.2);
        color: rgb(var(--bk-primary-rgb));
    }

    .fcs-empty,
    .fcs-empty-lite {
        border: 1px dashed rgba(var(--bk-border-rgb), 1);
        border-radius: 0.72rem;
        background: rgba(var(--bk-bg-rgb), 0.32);
        color: rgb(var(--bk-muted-rgb));
        font-size: 0.8rem;
        text-align: center;
        padding: 0.72rem;
    }

    .fcs-empty {
        margin: 0.3rem 0;
    }

    @media (max-width: 900px) {
        .fcs-grid {
            grid-template-columns: 1fr;
        }

        .fcs-settlement-line {
            grid-template-columns: 1fr;
        }
    }
</style>

