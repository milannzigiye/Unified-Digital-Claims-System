<?php
include '../connect.php';
require_once '../security.php';
secure_session_start();

require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claim_v2_detail_view.php';

if (!isset($_SESSION['email']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    http_response_code(403);
    exit('<div style="padding:1rem;color:#9f1239;font-weight:700;">You do not have access to this claim view.</div>');
}

$claimId = (int) ($_GET['id'] ?? 0);
if ($claimId <= 0) {
    http_response_code(400);
    exit('<div style="padding:1rem;color:#9f1239;font-weight:700;">Claim reference is missing.</div>');
}

$claim = udcs_claim_fetch_single($conn, $claimId);
if (!$claim) {
    http_response_code(404);
    exit('<div style="padding:1rem;color:#9f1239;font-weight:700;">Claim not found.</div>');
}

if (!udcs_claim_legacy_flag($claim)) {
    udcs_claim_v2_render_detail_sections($conn, $claim, ['context' => 'admin']);
    exit;
}

$documents = udcs_claim_fetch_documents($conn, $claimId);
$history = udcs_claim_fetch_history($conn, $claimId);
$status = udcs_claim_effective_status($claim);
$statusLabel = udcs_claim_status_label($status);
$statusClass = udcs_claim_status_class($status);
$distributionMethodValue = trim((string) ($claim['distribution_method'] ?? ''));
$distributionMethodLabel = $distributionMethodValue !== '' ? bk_distribution_method_label($distributionMethodValue) : 'Not specified';
$distributionDestination = bk_claim_destination_summary(
    bk_claim_account_reference($claim),
    (string) ($claim['distribution_method'] ?? ''),
    (string) ($claim['distribution_details'] ?? '')
);
$distributionDetails = trim((string) ($claim['distribution_details'] ?? ''));
$claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
$claimAmountLabel = bk_claim_amount_display_for_type(
    $claim['claim_amount'] ?? null,
    (string) ($claim['claim_type'] ?? ''),
    $claimCurrency
);
$assetSummary = udcs_claim_asset_summary_label('', (string) ($claim['claim_type'] ?? ''));
$comment = trim((string) ($claim['comment'] ?? ''));
$claimantName = trim((string) ($claim['claimant_name'] ?? ''));
$claimantEmail = trim((string) ($claim['claimant_email'] ?? ''));
$deceasedName = trim((string) ($claim['deceased_name'] ?? ''));
$relationship = trim((string) ($claim['relationship'] ?? ''));
?>
<style>
    .admin-legacy-sheet { display:grid; gap:.78rem; }
    .admin-legacy-section { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:1rem; overflow:hidden; background:rgb(var(--bk-surface-rgb)); }
    .admin-legacy-head { padding:.7rem .84rem; background:rgba(var(--bk-primary-rgb),.08); border-bottom:1px solid rgba(var(--bk-border-rgb),1); color:rgb(var(--bk-text-rgb)); font-size:.9rem; font-weight:800; }
    .admin-legacy-grid { padding:.8rem .84rem; display:grid; gap:.62rem; grid-template-columns:repeat(2,minmax(0,1fr)); }
    .admin-legacy-card { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.9rem; background:rgba(var(--bk-bg-rgb),.34); padding:.62rem .7rem; min-width:0; }
    .admin-legacy-label { margin:0 0 .2rem; color:rgb(var(--bk-muted-rgb)); font-size:.7rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
    .admin-legacy-value { margin:0; color:rgb(var(--bk-text-rgb)); font-size:.93rem; font-weight:700; overflow-wrap:anywhere; }
    .admin-legacy-value.subtle { color:rgb(var(--bk-muted-rgb)); font-weight:600; }
    .admin-legacy-status { display:inline-flex; align-items:center; gap:.35rem; border-radius:999px; padding:.32rem .72rem; border:1px solid rgba(var(--bk-border-rgb),1); font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .admin-legacy-status.status-pending { background:rgba(var(--bk-primary-rgb),.12); color:rgb(var(--bk-primary-rgb)); }
    .admin-legacy-status.status-review, .admin-legacy-status.status-warning { background:rgba(var(--bk-warning-rgb),.14); color:rgb(var(--bk-warning-rgb)); }
    .admin-legacy-status.status-approved { background:rgba(var(--bk-success-rgb),.14); color:rgb(var(--bk-success-rgb)); }
    .admin-legacy-status.status-rejected { background:rgba(var(--bk-danger-rgb),.12); color:rgb(var(--bk-danger-rgb)); }
    .admin-legacy-status.status-neutral { background:rgba(var(--bk-muted-rgb),.12); color:rgb(var(--bk-muted-rgb)); }
    .admin-legacy-stack { padding:.8rem .84rem; display:grid; gap:.62rem; }
    .admin-legacy-row { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.92rem; background:rgba(var(--bk-bg-rgb),.34); padding:.74rem; display:grid; gap:.5rem; }
    .admin-legacy-row-top { display:flex; justify-content:space-between; gap:.7rem; align-items:flex-start; }
    .admin-legacy-row-title { color:rgb(var(--bk-text-rgb)); font-size:.94rem; font-weight:800; }
    .admin-legacy-pill { display:inline-flex; align-items:center; gap:.32rem; border-radius:999px; padding:.24rem .56rem; background:rgba(var(--bk-primary-rgb),.1); color:rgb(var(--bk-primary-rgb)); font-size:.74rem; font-weight:700; }
    .admin-legacy-link { color:rgb(var(--bk-primary-rgb)); font-size:.84rem; font-weight:700; text-decoration:none; }
    .admin-legacy-link:hover { text-decoration:underline; }
    .admin-legacy-note { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.9rem; background:rgba(var(--bk-bg-rgb),.34); padding:.74rem; color:rgb(var(--bk-text-rgb)); font-size:.9rem; line-height:1.55; white-space:pre-wrap; overflow-wrap:anywhere; }
    .admin-legacy-empty { padding:.84rem; color:rgb(var(--bk-muted-rgb)); }
    @media (max-width: 860px) {
        .admin-legacy-grid { grid-template-columns:1fr; }
    }
</style>

<div class="admin-legacy-sheet">
    <section class="admin-legacy-section">
        <div class="admin-legacy-head">Claim Overview</div>
        <div class="admin-legacy-grid">
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Claim Reference</p>
                <p class="admin-legacy-value">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Live Status</p>
                <p class="admin-legacy-value"><span class="admin-legacy-status <?php echo bk_e($statusClass); ?>"><?php echo bk_e($statusLabel); ?></span></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Claimant</p>
                <p class="admin-legacy-value"><?php echo bk_e($claimantName !== '' ? $claimantName : 'Unknown'); ?></p>
                <p class="admin-legacy-value subtle"><?php echo bk_e($claimantEmail !== '' ? $claimantEmail : 'No email recorded'); ?></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Deceased</p>
                <p class="admin-legacy-value"><?php echo bk_e($deceasedName !== '' ? $deceasedName : 'Not recorded'); ?></p>
                <p class="admin-legacy-value subtle"><?php echo bk_e($relationship !== '' ? 'Relationship: ' . $relationship : 'Relationship not recorded'); ?></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">BK Asset Classes</p>
                <p class="admin-legacy-value"><?php echo bk_e($assetSummary); ?></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Claimed Value</p>
                <p class="admin-legacy-value"><?php echo bk_e($claimAmountLabel); ?></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Settlement Method</p>
                <p class="admin-legacy-value"><?php echo bk_e($distributionMethodLabel); ?></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Disbursement Destination</p>
                <p class="admin-legacy-value"><?php echo bk_e($distributionDestination); ?></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Submitted</p>
                <p class="admin-legacy-value"><?php echo bk_e(!empty($claim['submitted_at']) ? date('d M Y H:i', strtotime((string) $claim['submitted_at'])) : 'Not recorded'); ?></p>
            </article>
            <article class="admin-legacy-card">
                <p class="admin-legacy-label">Settlement Details</p>
                <p class="admin-legacy-value"><?php echo bk_e($distributionDetails !== '' ? $distributionDetails : 'No additional settlement details recorded'); ?></p>
            </article>
        </div>
    </section>

    <?php if ($comment !== ''): ?>
        <section class="admin-legacy-section">
            <div class="admin-legacy-head">Claim Notes</div>
            <div class="admin-legacy-stack">
                <div class="admin-legacy-note"><?php echo bk_e($comment); ?></div>
            </div>
        </section>
    <?php endif; ?>

    <section class="admin-legacy-section">
        <div class="admin-legacy-head">Uploaded Documents</div>
        <?php if (!empty($documents)): ?>
            <div class="admin-legacy-stack">
                <?php foreach ($documents as $document): ?>
                    <?php $documentUrl = '../document_access.php?id=' . (int) ($document['id'] ?? 0); ?>
                    <article class="admin-legacy-row">
                        <div class="admin-legacy-row-top">
                            <div class="admin-legacy-row-title"><?php echo bk_e(udcs_claim_document_label((string) ($document['document_type'] ?? ''))); ?></div>
                            <span class="admin-legacy-pill"><?php echo bk_e(trim((string) ($document['ocr_status'] ?? '')) !== '' ? ucwords((string) $document['ocr_status']) : 'Not scanned'); ?></span>
                        </div>
                        <?php if (trim((string) ($document['rejection_reason'] ?? '')) !== ''): ?>
                            <div class="admin-legacy-note"><?php echo bk_e((string) ($document['rejection_reason'] ?? '')); ?></div>
                        <?php endif; ?>
                        <a class="admin-legacy-link" href="<?php echo bk_e($documentUrl); ?>" target="_blank" rel="noopener">Open document</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="admin-legacy-empty">No documents were found for this legacy claim.</div>
        <?php endif; ?>
    </section>

    <section class="admin-legacy-section">
        <div class="admin-legacy-head">Status Timeline</div>
        <?php if (!empty($history)): ?>
            <div class="admin-legacy-stack">
                <?php foreach ($history as $entry): ?>
                    <article class="admin-legacy-row">
                        <div class="admin-legacy-row-top">
                            <div class="admin-legacy-row-title"><?php echo bk_e((string) ($entry['status_label'] ?? 'Activity')); ?></div>
                            <span class="admin-legacy-pill"><?php echo bk_e(!empty($entry['created_at']) ? date('d M Y H:i', strtotime((string) $entry['created_at'])) : 'Not recorded'); ?></span>
                        </div>
                        <div class="admin-legacy-value subtle"><?php echo bk_e((string) ($entry['details'] ?? 'No details recorded.')); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="admin-legacy-empty">No timeline history is stored for this legacy claim yet.</div>
        <?php endif; ?>
    </section>
</div>
