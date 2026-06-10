<?php
// Tags: [CLAIMANT] [CLAIM] [STATUS]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claim_v2_detail_view.php';

function claimant_ensure_claim_history_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $schemaSql = "CREATE TABLE IF NOT EXISTS claim_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            claim_id INT NOT NULL,
            actor_role VARCHAR(32) NULL,
            status_label VARCHAR(120) NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_claim_history_claim (claim_id),
            INDEX idx_claim_history_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $schemaStmt = mysqli_prepare($conn, $schemaSql);
        if ($schemaStmt) {
            mysqli_stmt_execute($schemaStmt);
            mysqli_stmt_close($schemaStmt);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('Claimant view_claim schema ensure failed: ' . $e->getMessage());
    }
}

function claimant_status_meta(?string $status): array
{
    $normalized = strtolower(trim((string) $status));
    $label = ucwords(str_replace('_', ' ', $normalized !== '' ? $normalized : 'Unknown'));
    $class = 'status-neutral';

    if ($normalized === 'pending') {
        $class = 'status-pending';
        $label = 'Pending';
    } elseif (str_contains($normalized, 'approved')) {
        $class = 'status-approved';
        $label = 'Approved by Finance';
    } elseif (str_contains($normalized, 'rejected')) {
        $class = 'status-rejected';
    } elseif (str_contains($normalized, 'review') || str_contains($normalized, 'transferred')) {
        $class = 'status-review';
        $label = 'Under Review';
    }

    return ['label' => $label, 'class' => $class];
}

function claimant_file_meta(string $filename): array
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($extension) {
        'pdf' => ['icon' => 'fa-file-pdf', 'label' => 'PDF'],
        'jpg', 'jpeg', 'png', 'gif', 'webp' => ['icon' => 'fa-file-image', 'label' => 'Image'],
        'doc', 'docx' => ['icon' => 'fa-file-word', 'label' => 'Word'],
        default => ['icon' => 'fa-file', 'label' => 'Document'],
    };
}

function claimant_document_owner_meta(array $document): array
{
    $type = strtolower(trim((string) ($document['document_type'] ?? '')));

    return match ($type) {
        'deceased_death_certificate', 'will_copy', 'single_status_evidence', 'single_status_fallback_evidence' => ['label' => 'Deceased / Estate', 'class' => 'is-deceased'],
        'claimant_id', 'relationship_proof', 'representative_authority' => ['label' => 'Claimant / Representative', 'class' => 'is-claimant'],
        'marriage_certificate', 'spouse_id', 'spouse_death_certificate', 'spouse_secondary_death_evidence' => ['label' => 'Spouse / Family Path', 'class' => 'is-spouse'],
        'child_birth_certificate', 'child_id' => ['label' => 'Child', 'class' => 'is-child'],
        default => ['label' => 'Family / Co-Heir', 'class' => 'is-family'],
    };
}

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'claimant')) {
    header('Location: ../claimant-access.php');
    exit();
}

$claimantId = (int) ($_SESSION['user_id'] ?? 0);
$claimId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($claimId <= 0) {
    header('Location: claims.php');
    exit();
}

$claimSql = "
SELECT
    c.*,
    u.full_name AS claimant_name,
    u.email AS claimant_email,
    u.phone AS claimant_phone
FROM claims c
LEFT JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
WHERE c.id = ? AND COALESCE(c.claimant_user_id, c.claimant_id) = ?
LIMIT 1
";
$claimStmt = mysqli_prepare($conn, $claimSql);
if (!$claimStmt) {
    $_SESSION['error'] = 'We could not load your claim details right now.';
    header('Location: claims.php');
    exit();
}
mysqli_stmt_bind_param($claimStmt, 'ii', $claimId, $claimantId);
if (!mysqli_stmt_execute($claimStmt)) {
    $_SESSION['error'] = 'We could not load your claim details right now.';
    header('Location: claims.php');
    exit();
}
$claimResult = mysqli_stmt_get_result($claimStmt);

$claim = mysqli_fetch_assoc($claimResult);
if (!$claim) {
    header('Location: claims.php');
    exit();
}

if (!udcs_claim_legacy_flag($claim)) {
    $status = udcs_claim_effective_status($claim);
    $statusMeta = [
        'label' => udcs_claim_status_label($status),
        'class' => udcs_claim_status_class($status),
    ];
    $headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
HTML;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <?php render_head('Claim View - CL-' . str_pad((string) $claimId, 6, '0', STR_PAD_LEFT) . ' | UNIFIED DIGITAL CLAIMS SYSTEM', '..', $headExtra); ?>
        <style>
            body {
                margin: 0;
                background: radial-gradient(circle at 100% 0%, rgba(var(--bk-primary-rgb), 0.13), transparent 40%), rgb(var(--bk-bg-rgb));
                color: rgb(var(--bk-text-rgb));
                font-family: var(--app-font), Inter, system-ui, sans-serif;
            }
            .page-wrap {
                max-width: 1380px;
                margin: 0 auto;
                padding: 1rem 1.1rem 1.4rem;
            }
            .top-card {
                border: 1px solid rgba(var(--bk-border-rgb), 1);
                border-radius: 1rem;
                background: linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.16), rgba(var(--bk-primary-rgb), 0.05) 55%, rgba(var(--bk-surface-rgb), 1));
                box-shadow: var(--shadow-soft);
                padding: 1rem 1.1rem;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 0.8rem;
            }
            .top-title { display: grid; gap: 0.35rem; }
            .top-title h1 { margin: 0; font-size: clamp(1.38rem, 2.5vw, 1.95rem); font-family: var(--app-display-font), var(--app-font), sans-serif; }
            .status-pill {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                border: 1px solid rgba(var(--bk-border-rgb), 1);
                font-size: 0.74rem;
                font-weight: 700;
                padding: 0.28rem 0.66rem;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            .status-pill.status-pending { background: rgba(var(--bk-primary-rgb), 0.12); color: rgb(var(--bk-primary-rgb)); border-color: rgba(var(--bk-primary-rgb), 0.34); }
            .status-pill.status-review, .status-pill.status-warning { background: rgba(var(--bk-warning-rgb), 0.14); color: rgb(var(--bk-warning-rgb)); border-color: rgba(var(--bk-warning-rgb), 0.34); }
            .status-pill.status-approved { background: rgba(var(--bk-success-rgb), 0.14); color: rgb(var(--bk-success-rgb)); border-color: rgba(var(--bk-success-rgb), 0.34); }
            .status-pill.status-rejected { background: rgba(var(--bk-danger-rgb), 0.12); color: rgb(var(--bk-danger-rgb)); border-color: rgba(var(--bk-danger-rgb), 0.34); }
            .status-pill.status-neutral { background: rgba(var(--bk-muted-rgb), 0.12); color: rgb(var(--bk-muted-rgb)); border-color: rgba(var(--bk-muted-rgb), 0.28); }
            .action-group { display: flex; flex-wrap: wrap; gap: 0.45rem; }
            .btn {
                border: 1px solid rgba(var(--bk-border-rgb), 1);
                border-radius: 0.78rem;
                min-height: 2.5rem;
                padding: 0 0.95rem;
                font-size: 0.86rem;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                text-decoration: none;
                cursor: pointer;
            }
            .btn.secondary { background: rgb(var(--bk-surface-rgb)); color: rgb(var(--bk-text-rgb)); }
            .content-grid { margin-top: 0.9rem; }
        </style>
    </head>
    <body>
    <div class="page-wrap">
        <section class="top-card">
            <div class="top-title">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-bk-primary">Claim View</span>
                <h1>CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?></h1>
                <span class="status-pill <?php echo bk_e($statusMeta['class']); ?>"><?php echo bk_e($statusMeta['label']); ?></span>
            </div>
            <div class="action-group">
                <a class="btn secondary" href="claims.php"><i class="fa-solid fa-arrow-left"></i> Back to Claims</a>
                <button class="btn secondary" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            </div>
        </section>
        <div class="content-grid">
            <?php udcs_claim_v2_render_detail_sections($conn, $claim, ['context' => 'claimant']); ?>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit();
}

$documents = [];
$hasMarriageCertificate = false;
$documentsSql = "SELECT id, document_type, file_path, uploaded_at FROM documents WHERE claim_id = ? ORDER BY uploaded_at DESC";
$documentsStmt = mysqli_prepare($conn, $documentsSql);
$documentsResult = false;
if ($documentsStmt) {
    mysqli_stmt_bind_param($documentsStmt, 'i', $claimId);
    if (mysqli_stmt_execute($documentsStmt)) {
        $documentsResult = mysqli_stmt_get_result($documentsStmt);
    }
}
if ($documentsResult) {
    while ($doc = mysqli_fetch_assoc($documentsResult)) {
        $documents[] = $doc;
        if (strtolower(trim((string) ($doc['document_type'] ?? ''))) === 'marriage_certificate') {
            $hasMarriageCertificate = true;
        }
    }
}

claimant_ensure_claim_history_schema($conn);
$history = [];
$historySql = "SELECT id, actor_role, status_label, message, created_at FROM claim_history WHERE claim_id = ? ORDER BY created_at DESC";
$historyStmt = mysqli_prepare($conn, $historySql);
$historyResult = false;
if ($historyStmt) {
    mysqli_stmt_bind_param($historyStmt, 'i', $claimId);
    if (mysqli_stmt_execute($historyStmt)) {
        $historyResult = mysqli_stmt_get_result($historyStmt);
    }
}
if ($historyResult) {
    while ($item = mysqli_fetch_assoc($historyResult)) {
        $history[] = $item;
    }
}

$documentsTotal = count($documents);
$relationship = strtolower(trim((string) ($claim['relationship'] ?? '')));
$isSpouseClaim = $relationship === 'spouse';
$verificationSummary = $documentsTotal > 0
    ? $documentsTotal . ' uploaded document(s) passed intake checks at submission.'
    : 'No document has been uploaded yet.';
$verificationRule = $isSpouseClaim
    ? ($hasMarriageCertificate
        ? 'Spouse requirement satisfied: marriage certificate is present.'
        : 'Spouse requirement pending: marriage certificate is missing.')
    : 'Spouse-specific certificate is not required for this relationship.';

$claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
$financeCurrency = bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? $claimCurrency));
$claimedAmountLabel = bk_claim_amount_display_for_type(
    $claim['claim_amount'] ?? null,
    (string) ($claim['claim_type'] ?? ''),
    $claimCurrency
);
$assessedAmountLabel = bk_claim_amount_display(
    $claim['finance_assessed_amount'] ?? null,
    $financeCurrency,
    'Not assessed yet'
);
$destinationSummaryLabel = bk_claim_destination_summary(
    bk_claim_account_reference($claim),
    (string) ($claim['distribution_method'] ?? ''),
    (string) ($claim['distribution_details'] ?? '')
);
$distributionMethodLabel = bk_distribution_method_label((string) ($claim['distribution_method'] ?? ''));
$distributionDetailsRows = bk_distribution_detail_rows((string) ($claim['distribution_details'] ?? ''));
$statusMeta = claimant_status_meta((string) udcs_claim_effective_status($claim));

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Claim View - CL-' . str_pad((string) $claimId, 6, '0', STR_PAD_LEFT) . ' | UNIFIED DIGITAL CLAIMS SYSTEM', '..', $headExtra); ?>
    <style>
        body {
            margin: 0;
            background: radial-gradient(circle at 100% 0%, rgba(var(--bk-primary-rgb), 0.13), transparent 40%), rgb(var(--bk-bg-rgb));
            color: rgb(var(--bk-text-rgb));
            font-family: var(--app-font), Inter, system-ui, sans-serif;
        }

        .page-wrap {
            max-width: 1380px;
            margin: 0 auto;
            padding: 1rem 1.1rem 1.4rem;
        }

        .top-card,
        .panel {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: rgb(var(--bk-surface-rgb));
            box-shadow: var(--shadow-soft);
        }

        .top-card {
            padding: 1rem 1.1rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.8rem;
            background: linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.16), rgba(var(--bk-primary-rgb), 0.05) 55%, rgba(var(--bk-surface-rgb), 1));
        }

        .top-title {
            display: grid;
            gap: 0.35rem;
        }

        .top-title h1 {
            margin: 0;
            font-size: clamp(1.38rem, 2.5vw, 1.95rem);
            font-family: var(--app-display-font), var(--app-font), sans-serif;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            font-size: 0.74rem;
            font-weight: 700;
            padding: 0.28rem 0.66rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .status-pending { background: rgba(var(--bk-warning-rgb), 0.12); color: rgb(var(--bk-warning-rgb)); border-color: rgba(var(--bk-warning-rgb), 0.34); }
        .status-review { background: rgba(var(--bk-primary-rgb), 0.12); color: rgb(var(--bk-primary-rgb)); border-color: rgba(var(--bk-primary-rgb), 0.34); }
        .status-approved { background: rgba(var(--bk-success-rgb), 0.12); color: rgb(var(--bk-success-rgb)); border-color: rgba(var(--bk-success-rgb), 0.34); }
        .status-rejected { background: rgba(var(--bk-danger-rgb), 0.12); color: rgb(var(--bk-danger-rgb)); border-color: rgba(var(--bk-danger-rgb), 0.34); }
        .status-neutral { background: rgba(var(--bk-muted-rgb), 0.12); color: rgb(var(--bk-muted-rgb)); border-color: rgba(var(--bk-muted-rgb), 0.28); }

        .action-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .btn {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.78rem;
            min-height: 2.5rem;
            padding: 0 0.95rem;
            font-size: 0.86rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            text-decoration: none;
            cursor: pointer;
        }

        .btn.secondary {
            background: rgb(var(--bk-surface-rgb));
            color: rgb(var(--bk-text-rgb));
        }

        .btn.primary {
            border-color: rgba(var(--bk-primary-rgb), 0.95);
            background: rgb(var(--bk-primary-rgb));
            color: #fff;
        }

        .content-grid {
            margin-top: 0.9rem;
            display: grid;
            gap: 0.8rem;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
        }

        .panel {
            padding: 0.95rem;
        }

        .section {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.88rem;
            overflow: hidden;
            background: rgb(var(--bk-surface-rgb));
            margin-bottom: 0.8rem;
        }

        .claim-fold {
            display: block;
        }

        .claim-fold summary {
            list-style: none;
            cursor: pointer;
        }

        .claim-fold summary::-webkit-details-marker {
            display: none;
        }

        .section:last-child {
            margin-bottom: 0;
        }

        .section-head {
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-primary-rgb), 0.08);
            padding: 0.7rem 0.85rem;
            font-size: 0.92rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .section-head-main {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }

        .claim-fold-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.22);
            background: rgba(var(--bk-primary-rgb), 0.1);
            color: rgb(var(--bk-primary-rgb));
            font-size: 0.84rem;
        }

        .claim-fold[open] .claim-fold-action {
            background: rgba(var(--bk-text-rgb), 0.08);
            border-color: rgba(var(--bk-text-rgb), 0.18);
            color: rgb(var(--bk-text-rgb));
        }

        .claim-fold-action i {
            transition: transform 0.18s ease;
        }

        .claim-fold[open] .claim-fold-action i {
            transform: rotate(180deg);
        }

        .section-body {
            padding: 0.82rem 0.85rem;
            display: grid;
            gap: 0.66rem;
        }

        .kv-grid {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .kv {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.75rem;
            padding: 0.52rem 0.58rem;
            background: rgba(var(--bk-bg-rgb), 0.35);
            min-width: 0;
        }

        .kv .k {
            font-size: 0.7rem;
            color: rgb(var(--bk-muted-rgb));
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.22rem;
        }

        .kv .v {
            font-size: 0.86rem;
            font-weight: 600;
            color: rgb(var(--bk-text-rgb));
            overflow-wrap: anywhere;
        }

        .v.strong {
            color: rgb(var(--bk-primary-rgb));
            font-size: 1rem;
            font-weight: 800;
        }

        .note-box {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.78rem;
            background: rgba(var(--bk-bg-rgb), 0.4);
            padding: 0.65rem 0.7rem;
            font-size: 0.84rem;
            line-height: 1.45;
        }

        .doc-list,
        .history-list {
            display: grid;
            gap: 0.55rem;
        }

        .doc-item,
        .history-item {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.78rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.58rem 0.62rem;
        }

        .doc-item {
            display: grid;
            gap: 0.48rem;
            grid-template-columns: auto 1fr auto;
            align-items: center;
        }

        .doc-icon {
            width: 2.1rem;
            height: 2.1rem;
            border-radius: 0.65rem;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.28);
            background: rgba(var(--bk-primary-rgb), 0.12);
            color: rgb(var(--bk-primary-rgb));
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .doc-meta {
            min-width: 0;
        }

        .doc-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.38rem;
        }

        .doc-title {
            font-size: 0.84rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
            overflow-wrap: anywhere;
        }

        .doc-owner {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            max-width: 100%;
            border-radius: 999px;
            padding: 0.2rem 0.54rem;
            font-size: 0.67rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            line-height: 1.2;
            border: 1px solid transparent;
        }

        .doc-owner.is-deceased {
            background: rgba(var(--bk-danger-rgb), 0.1);
            color: rgb(var(--bk-danger-rgb));
            border-color: rgba(var(--bk-danger-rgb), 0.18);
        }

        .doc-owner.is-claimant {
            background: rgba(var(--bk-primary-rgb), 0.11);
            color: rgb(var(--bk-primary-rgb));
            border-color: rgba(var(--bk-primary-rgb), 0.16);
        }

        .doc-owner.is-spouse {
            background: rgba(var(--bk-success-rgb), 0.12);
            color: rgb(var(--bk-success-rgb));
            border-color: rgba(var(--bk-success-rgb), 0.2);
        }

        .doc-owner.is-child {
            background: rgba(var(--bk-warning-rgb), 0.14);
            color: rgb(var(--bk-warning-rgb));
            border-color: rgba(var(--bk-warning-rgb), 0.22);
        }

        .doc-owner.is-family {
            background: rgba(var(--bk-text-rgb), 0.07);
            color: rgb(var(--bk-text-rgb));
            border-color: rgba(var(--bk-text-rgb), 0.12);
        }

        .doc-sub {
            font-size: 0.76rem;
            color: rgb(var(--bk-muted-rgb));
            margin-top: 0.14rem;
        }

        .doc-actions {
            display: inline-flex;
            gap: 0.35rem;
        }

        .doc-link {
            width: 1.95rem;
            height: 1.95rem;
            border-radius: 0.58rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            color: rgb(var(--bk-text-rgb));
            background: rgb(var(--bk-surface-rgb));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .doc-link:hover {
            border-color: rgba(var(--bk-primary-rgb), 0.65);
            background: rgba(var(--bk-primary-rgb), 0.12);
            color: rgb(var(--bk-primary-rgb));
        }

        .history-item .meta {
            font-size: 0.74rem;
            color: rgb(var(--bk-muted-rgb));
            margin-bottom: 0.2rem;
        }

        .history-item .message {
            font-size: 0.83rem;
            color: rgb(var(--bk-text-rgb));
            line-height: 1.4;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .empty {
            text-align: center;
            padding: 1rem 0.7rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.82rem;
            border: 1px dashed rgba(var(--bk-border-rgb), 0.9);
            border-radius: 0.76rem;
        }

        @media (max-width: 1100px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .page-wrap {
                padding: 0.75rem;
            }

            .top-card {
                padding: 0.82rem;
            }

            .kv-grid {
                grid-template-columns: 1fr;
            }

            .doc-item {
                grid-template-columns: auto 1fr;
            }

            .doc-actions {
                grid-column: 1 / -1;
                justify-content: flex-end;
            }
        }

        @media print {
            body {
                background: #fff !important;
            }

            .btn,
            .action-group {
                display: none !important;
            }

            .top-card,
            .panel,
            .section,
            .doc-item,
            .history-item,
            .kv {
                box-shadow: none !important;
            }

            .claim-fold:not([open]) .section-body {
                display: grid !important;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <section class="top-card">
        <div class="top-title">
            <h1>Claim Details</h1>
            <div style="font-size:0.84rem;color:rgb(var(--bk-muted-rgb));">Reference: CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?></div>
            <span class="status-pill <?php echo bk_e($statusMeta['class']); ?>"><?php echo bk_e($statusMeta['label']); ?></span>
        </div>
        <div class="action-group">
            <a href="claims.php" class="btn secondary"><i class="fa-solid fa-arrow-left"></i>Back to Claims</a>
            <button type="button" class="btn primary" onclick="window.print()"><i class="fa-solid fa-print"></i>Print</button>
        </div>
    </section>

    <section class="content-grid">
        <div class="panel">
            <details class="section claim-fold">
                <summary class="section-head"><span class="section-head-main"><i class="fa-solid fa-circle-info"></i>Claim Overview</span><span class="claim-fold-action" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="section-body">
                    <div class="kv-grid">
                        <div class="kv"><div class="k">Claimant</div><div class="v"><?php echo bk_e((string) ($claim['claimant_name'] ?? 'You')); ?></div></div>
                        <div class="kv"><div class="k">Email</div><div class="v"><?php echo bk_e((string) ($claim['claimant_email'] ?? '-')); ?></div></div>
                        <div class="kv"><div class="k">Phone</div><div class="v"><?php echo bk_e((string) ($claim['claimant_phone'] ?? '-')); ?></div></div>
                        <div class="kv"><div class="k">Submitted</div><div class="v"><?php echo bk_e(date('F j, Y H:i', strtotime((string) ($claim['submitted_at'] ?? 'now')))); ?></div></div>
                        <div class="kv"><div class="k">Last Updated</div><div class="v"><?php echo bk_e(date('F j, Y H:i', strtotime((string) ($claim['updated_at'] ?? 'now')))); ?></div></div>
                        <div class="kv"><div class="k">Relationship</div><div class="v"><?php echo bk_e(ucfirst((string) ($claim['relationship'] ?? 'Not specified'))); ?></div></div>
                    </div>
                </div>
            </details>

            <details class="section claim-fold">
                <summary class="section-head"><span class="section-head-main"><i class="fa-solid fa-user"></i>Deceased Information</span><span class="claim-fold-action" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="section-body">
                    <div class="kv-grid">
                        <div class="kv"><div class="k">Full Name</div><div class="v"><?php echo bk_e((string) ($claim['deceased_name'] ?? 'N/A')); ?></div></div>
                        <div class="kv"><div class="k">National ID</div><div class="v"><?php echo bk_e((string) ($claim['deceased_national_id'] ?? 'Not provided')); ?></div></div>
                        <div class="kv"><div class="k">Date of Death</div><div class="v"><?php echo bk_e(!empty($claim['deceased_date']) ? date('F j, Y', strtotime((string) $claim['deceased_date'])) : 'Not provided'); ?></div></div>
                    </div>
                </div>
            </details>

            <details class="section claim-fold">
                <summary class="section-head"><span class="section-head-main"><i class="fa-solid fa-file-lines"></i>Claim Financials</span><span class="claim-fold-action" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="section-body">
                    <div class="kv-grid">
                        <div class="kv"><div class="k">Claim Type</div><div class="v"><?php echo bk_e(bk_claim_type_label((string) ($claim['claim_type'] ?? ''))); ?></div></div>
                        <div class="kv"><div class="k">Claimed Value</div><div class="v strong"><?php echo bk_e($claimedAmountLabel); ?></div></div>
                        <div class="kv"><div class="k">Finance Assessed Value</div><div class="v strong"><?php echo bk_e($assessedAmountLabel); ?></div></div>
                        <div class="kv" style="grid-column: 1 / -1;"><div class="k">Disbursement Destination</div><div class="v"><?php echo bk_e($destinationSummaryLabel); ?></div></div>
                    </div>
                </div>
            </details>

            <details class="section claim-fold">
                <summary class="section-head"><span class="section-head-main"><i class="fa-solid fa-hand-holding-dollar"></i>Settlement Method</span><span class="claim-fold-action" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="section-body">
                    <div class="kv-grid">
                        <div class="kv" style="grid-column: 1 / -1;"><div class="k">Selected Method</div><div class="v"><?php echo bk_e($distributionMethodLabel); ?></div></div>
                    </div>
                    <?php if (!empty($distributionDetailsRows)): ?>
                        <div class="note-box">
                            <?php foreach ($distributionDetailsRows as $detailRow): ?>
                                <div style="margin-bottom:0.24rem;"><strong><?php echo bk_e((string) ($detailRow['label'] ?? 'Detail')); ?>:</strong> <?php echo bk_e((string) ($detailRow['value'] ?? '-')); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">No additional settlement details were provided.</div>
                    <?php endif; ?>
                </div>
            </details>

            <?php if (!empty((string) ($claim['claim_description'] ?? '')) || !empty((string) ($claim['comment'] ?? ''))): ?>
            <details class="section claim-fold">
                <summary class="section-head"><span class="section-head-main"><i class="fa-solid fa-note-sticky"></i>Notes</span><span class="claim-fold-action" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="section-body">
                    <?php if (!empty((string) ($claim['claim_description'] ?? ''))): ?>
                        <div class="note-box"><strong>Your Description:</strong><br><?php echo nl2br(bk_e((string) $claim['claim_description'])); ?></div>
                    <?php endif; ?>
                    <?php if (!empty((string) ($claim['comment'] ?? ''))): ?>
                        <div class="note-box"><strong>Review Note:</strong><br><?php echo nl2br(bk_e((string) $claim['comment'])); ?></div>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>

        <aside class="panel">
            <details class="section claim-fold">
                <summary class="section-head"><span class="section-head-main"><i class="fa-solid fa-shield-check"></i>Verification Summary</span><span class="claim-fold-action" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="section-body">
                    <div class="note-box"><strong><?php echo bk_e($verificationSummary); ?></strong><br><?php echo bk_e($verificationRule); ?></div>
                    <div class="kv-grid">
                        <div class="kv"><div class="k">Documents Uploaded</div><div class="v"><?php echo (int) $documentsTotal; ?></div></div>
                        <div class="kv"><div class="k">Spouse Rule</div><div class="v"><?php echo bk_e($isSpouseClaim ? ($hasMarriageCertificate ? 'Satisfied' : 'Pending') : 'Not Required'); ?></div></div>
                    </div>
                </div>
            </details>

            <details class="section claim-fold">
                <summary class="section-head"><span class="section-head-main"><i class="fa-solid fa-folder-open"></i>Uploaded Documents</span><span class="claim-fold-action" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="section-body">
                    <?php if (!empty($documents)): ?>
                        <div class="doc-list">
                            <?php foreach ($documents as $doc): ?>
                                <?php $fileMeta = claimant_file_meta((string) ($doc['file_path'] ?? '')); ?>
                                <?php $ownerMeta = claimant_document_owner_meta($doc); ?>
                                <div class="doc-item">
                                    <span class="doc-icon"><i class="fa-solid <?php echo bk_e((string) ($fileMeta['icon'] ?? 'fa-file')); ?>"></i></span>
                                    <div class="doc-meta">
                                        <div class="doc-head">
                                            <div class="doc-title"><?php echo bk_e(udcs_claim_document_label((string) ($doc['document_type'] ?? 'document'))); ?></div>
                                            <span class="doc-owner <?php echo bk_e((string) ($ownerMeta['class'] ?? 'is-family')); ?>"><?php echo bk_e((string) ($ownerMeta['label'] ?? 'Family / Co-Heir')); ?></span>
                                        </div>
                                        <div class="doc-sub"><?php echo bk_e((string) ($fileMeta['label'] ?? 'Document')); ?> | <?php echo bk_e(!empty($doc['uploaded_at']) ? date('M j, Y', strtotime((string) $doc['uploaded_at'])) : '-'); ?></div>
                                    </div>
                                    <div class="doc-actions">
                                        <a class="doc-link" href="<?php echo bk_e('../document_access.php?id=' . (int) ($doc['id'] ?? 0)); ?>" target="_blank" title="Open"><i class="fa-solid fa-eye"></i></a>
                                        <a class="doc-link" href="<?php echo bk_e('../document_access.php?id=' . (int) ($doc['id'] ?? 0) . '&download=1'); ?>" title="Download"><i class="fa-solid fa-download"></i></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">No documents uploaded for this claim.</div>
                    <?php endif; ?>
                </div>
            </details>

            <details class="section claim-fold">
                <summary class="section-head"><span class="section-head-main"><i class="fa-solid fa-clock-rotate-left"></i>Claim Activity</span><span class="claim-fold-action" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span></summary>
                <div class="section-body">
                    <?php if (!empty($history)): ?>
                        <div class="history-list">
                            <?php foreach ($history as $item): ?>
                                <div class="history-item">
                                    <div class="meta">
                                        <?php echo bk_e(!empty($item['created_at']) ? date('M j, Y H:i', strtotime((string) $item['created_at'])) : '-'); ?>
                                        | <?php echo bk_e(ucfirst((string) ($item['actor_role'] ?? 'system'))); ?>
                                        | <?php echo bk_e((string) (($item['status_label'] ?? '') !== '' ? $item['status_label'] : 'Update')); ?>
                                    </div>
                                    <div class="message"><?php echo bk_e((string) ($item['message'] ?? '-')); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">No activity history available yet.</div>
                    <?php endif; ?>
                </div>
            </details>
        </aside>
    </section>
</div>
</body>
</html>

