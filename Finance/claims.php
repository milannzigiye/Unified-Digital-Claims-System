<?php
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/alert.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claims_list_ui.php';
require_once dirname(__DIR__) . '/components/claim_email_helper.php';
// Tags: [STATUS] [QUEUE] [HISTORY] [NOTIFY] [AUDIT]
// [STATUS] Finance review state transitions.
/* =========================
   AUTH CHECK
========================= */

// Check if user is logged in using email
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'finance') {
    header("Location: ../login.php");
    exit();
}

$user_email = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $user_email, 'finance');
if (!$user_data) {
    header("Location: ../login.php");
    exit();
}

$claimant_id = $user_data['id'];
$claimant_name = $user_data['full_name'];
$financeClaimsCsrfToken = udcs_csrf_get('finance_claims_action');
$userPhoto = (string) ($user_data['photo'] ?? '');

bk_claims_ensure_workflow_schema($conn);
udcs_claims_v2_ensure_schema($conn);
bk_backfill_unassigned_claims($conn);
// Set the photo path for displaying the image
if (!empty($userPhoto)) {
    $photo = "../uploads/" . $userPhoto;
} else {
    $photo = "../Images/logo.png";
}

function finance_claim_status_key(?string $status): string
{
    $key = strtolower(trim((string) $status));
    $key = str_replace('_', ' ', $key);
    return preg_replace('/\s+/', ' ', $key) ?? '';
}

function finance_claim_status_label(?string $status): string
{
    return udcs_claim_status_label($status);
}

function finance_claim_status_class(?string $status): string
{
    return match (udcs_claim_status_class($status)) {
        'status-pending' => 'badge-transferred',
        'status-review', 'status-warning' => 'badge-rejected',
        'status-approved' => 'badge-approved',
        'status-rejected' => 'badge-rejected',
        default => 'badge-transferred',
    };
}

function finance_claim_review_signal_class(?string $severity): string
{
    return match (strtolower(trim((string) $severity))) {
        'danger', 'error' => 'is-danger',
        'warning' => 'is-warning',
        default => 'is-ok',
    };
}

function finance_claim_asset_state_label(array $assetSummary): string
{
    $count = (int) ($assetSummary['count'] ?? 0);
    if ($count <= 0) {
        return 'No BK asset rows';
    }

    $reviewed = (int) ($assetSummary['reviewed_count'] ?? 0);
    $confirmed = (int) ($assetSummary['confirmed_count'] ?? 0);
    $holds = (int) ($assetSummary['hold_count'] ?? 0);
    $missing = (int) ($assetSummary['missing_count'] ?? 0);
    $manual = (int) ($assetSummary['manual_follow_up_count'] ?? 0);

    if ($reviewed <= 0) {
        return 'Finance verification pending';
    }
    if ($holds > 0) {
        return $holds . ' restriction/hold found';
    }
    if ($manual > 0) {
        return $manual . ' manual follow-up';
    }
    if ($missing > 0) {
        return $missing . ' asset not matched';
    }
    if ($confirmed === $count) {
        return 'All assets confirmed';
    }

    return $reviewed . ' of ' . $count . ' reviewed';
}

function finance_parse_amount_for_currency(string $raw, string $currencyCode, string $fieldLabel, ?string &$errorMessage = null): ?string
{
    $text = str_replace([',', ' '], '', trim($raw));
    if ($text === '') {
        return null;
    }

    $currency = bk_currency_code($currencyCode);
    $decimals = bk_currency_decimals($currency);
    $pattern = $decimals === 0 ? '/^\d+(\.0+)?$/' : '/^\d+(\.\d{1,' . $decimals . '})?$/';
    if (!preg_match($pattern, $text)) {
        $errorMessage = $decimals === 0
            ? $fieldLabel . ' must be a whole ' . $currency . ' amount.'
            : $fieldLabel . ' must be a valid ' . $currency . ' amount with up to ' . $decimals . ' decimal place(s).';
        return null;
    }

    $value = (float) $text;
    if ($value < 0) {
        $errorMessage = $fieldLabel . ' cannot be negative.';
        return null;
    }

    return number_format($value, $decimals, '.', '');
}

function finance_parse_assessed_amount(string $raw, string $currencyCode, ?string &$errorMessage = null): ?string
{
    $amount = finance_parse_amount_for_currency($raw, $currencyCode, 'Final assessed value', $errorMessage);
    if ($amount !== null && (float) $amount <= 0) {
        $errorMessage = 'Final assessed value must be greater than zero.';
        return null;
    }
    return $amount;
}

function finance_parse_optional_verified_amount(string $raw, string $currencyCode, ?string &$errorMessage = null): ?string
{
    if (trim($raw) === '') {
        return null;
    }
    return finance_parse_amount_for_currency($raw, $currencyCode, 'Each asset verified value', $errorMessage);
}

function finance_parse_asset_reviews(array $input, array $claimAssets, ?string &$errorMessage = null): array
{
    $allowedStatuses = [
        'Confirmed in BK records',
        'Restriction or hold found',
        'Manual follow-up required',
        'No matching BK asset found',
    ];
    $parsed = [];

    foreach ($claimAssets as $asset) {
        $assetId = (int) ($asset['claim_asset_id'] ?? 0);
        if ($assetId <= 0) {
            continue;
        }

        $rawRow = (array) ($input[$assetId] ?? []);
        $status = trim((string) ($rawRow['finance_status'] ?? ''));
        $accountReference = trim((string) ($rawRow['account_reference'] ?? ''));
        $currencyCode = bk_asset_currency_code((string) ($asset['asset_class'] ?? ''), (string) ($asset['currency_code'] ?? 'RWF'));
        if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
            $errorMessage = 'One or more asset verification statuses are invalid.';
            return [];
        }
        if ($accountReference !== '' && strlen($accountReference) > 160) {
            $errorMessage = 'A BK asset reference is too long. Keep it within 160 characters.';
            return [];
        }

        $verifiedError = null;
        $verifiedValue = finance_parse_optional_verified_amount((string) ($rawRow['verified_value'] ?? ''), $currencyCode, $verifiedError);
        if ($verifiedError !== null) {
            $errorMessage = $verifiedError;
            return [];
        }

        $parsed[$assetId] = [
            'finance_status' => $status,
            'account_reference' => $accountReference,
            'verified_value' => $verifiedValue,
            'currency_code' => $currencyCode,
        ];
    }

    return $parsed;
}

function finance_validate_asset_reviews_for_approval(array $assetReviews, array $claimAssets, ?string &$errorMessage = null): bool
{
    foreach ($claimAssets as $asset) {
        $assetId = (int) ($asset['claim_asset_id'] ?? 0);
        if ($assetId <= 0) {
            continue;
        }

        $review = (array) ($assetReviews[$assetId] ?? []);
        $financeStatus = trim((string) ($review['finance_status'] ?? ''));
        $accountReference = trim((string) ($review['account_reference'] ?? ''));
        $verifiedValue = $review['verified_value'] ?? null;
        $assetLabel = udcs_claim_asset_label((string) ($asset['asset_class'] ?? ''));
        if ($financeStatus === '') {
            $errorMessage = 'Finance status is required for each claimed asset before closing the claim.';
            return false;
        }

        if ($financeStatus !== 'Confirmed in BK records') {
            $errorMessage = $assetLabel . ' is not ready for disbursement. Only assets confirmed in BK records can be approved and closed.';
            return false;
        }

        if ($accountReference === '') {
            $errorMessage = 'Enter the BK reference for ' . $assetLabel . ' before closing the claim.';
            return false;
        }

        if ($verifiedValue === null) {
            $errorMessage = 'Enter the verified value for ' . $assetLabel . ' before closing the claim.';
            return false;
        }
    }

    return true;
}

function finance_claim_disbursement_blockers(?array $reviewContract, array $assetReviews, array $claimAssets): array
{
    if (empty($reviewContract)) {
        return ['The structured finance case file could not be loaded.'];
    }

    $blockers = [];
    $assetSummary = (array) ($reviewContract['assets']['summary'] ?? []);
    $documentSummary = (array) ($reviewContract['documents']['summary'] ?? []);
    $payoutSummary = (array) ($reviewContract['payout'] ?? []);
    $reviewSummary = (array) ($reviewContract['review'] ?? []);

    if (!empty($reviewContract['status']['is_legacy'])) {
        $blockers[] = 'Legacy claims cannot be closed in the redesigned finance workflow.';
    }

    if ((int) ($assetSummary['count'] ?? 0) <= 0 || empty($claimAssets)) {
        $blockers[] = 'No BK-held asset row is linked to this claim.';
    }

    if ((int) ($documentSummary['count'] ?? 0) <= 0) {
        $blockers[] = 'No supporting document is linked to this claim.';
    }

    if ((int) ($documentSummary['ocr_failed_count'] ?? 0) > 0) {
        $blockers[] = 'One or more documents failed OCR intake checks.';
    }

    if ((int) ($documentSummary['ocr_pending_count'] ?? 0) > 0) {
        $blockers[] = 'One or more documents still have pending OCR status.';
    }

    if (empty($payoutSummary['destination_complete'])) {
        $blockers[] = 'The preferred settlement destination is incomplete or not captured.';
    }

    foreach ((array) ($reviewSummary['flags'] ?? []) as $flag) {
        $key = (string) ($flag['key'] ?? '');
        $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
        if ($severity !== 'danger' || in_array($key, ['no_assets_declared'], true)) {
            continue;
        }
        $label = trim((string) ($flag['label'] ?? 'Critical review blocker'));
        if ($label !== '') {
            $blockers[] = $label;
        }
    }

    foreach ($claimAssets as $asset) {
        $assetId = (int) ($asset['claim_asset_id'] ?? 0);
        if ($assetId <= 0) {
            continue;
        }

        $assetLabel = udcs_claim_asset_label((string) ($asset['asset_class'] ?? ''));
        $review = (array) ($assetReviews[$assetId] ?? []);
        $financeStatus = trim((string) ($review['finance_status'] ?? ''));
        $accountReference = trim((string) ($review['account_reference'] ?? ''));
        $verifiedValue = $review['verified_value'] ?? null;

        if ($financeStatus !== 'Confirmed in BK records') {
            $blockers[] = $assetLabel . ' must be confirmed in BK records before closure.';
        }
        if ($accountReference === '') {
            $blockers[] = $assetLabel . ' needs a BK reference.';
        }
        if ($verifiedValue === null) {
            $blockers[] = $assetLabel . ' needs a verified value.';
        }
    }

    return array_values(array_unique($blockers));
}

function finance_store_asset_reviews(mysqli $conn, int $claimId, array $assetReviews): bool
{
    foreach ($assetReviews as $assetId => $review) {
        $assetId = (int) $assetId;
        if ($assetId <= 0) {
            continue;
        }

        $financeStatus = trim((string) ($review['finance_status'] ?? ''));
        $accountReference = trim((string) ($review['account_reference'] ?? ''));
        $verifiedValue = $review['verified_value'] ?? null;
        $sql = "UPDATE claim_assets
                SET finance_status = NULLIF(?, ''),
                    account_reference = NULLIF(?, ''), ";
        $types = 'ss';
        $params = [$financeStatus, $accountReference];
        if ($verifiedValue === null || $verifiedValue === '') {
            $sql .= "verified_value = NULL, ";
        } else {
            $sql .= "verified_value = ?, ";
            $types .= 'd';
            $params[] = (float) $verifiedValue;
        }
        $sql .= "updated_at = NOW()
                 WHERE claim_id = ?
                   AND claim_asset_id = ?
                 LIMIT 1";
        $types .= 'ii';
        $params[] = $claimId;
        $params[] = $assetId;

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return false;
        }

        $success = udcs_db_stmt_bind($stmt, $types, $params) && mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$success) {
            return false;
        }
    }

    return true;
}

function finance_manual_snapshot_meta(?string $comment): array
{
    $raw = (string) $comment;
    $marker = 'Finance Manual Verification Record';
    $position = strrpos($raw, $marker);
    if ($position === false) {
        $legacyMarker = 'Finance Manual Verification Snapshot';
        $position = strrpos($raw, $legacyMarker);
        if ($position !== false) {
            $marker = $legacyMarker;
        }
    }
    if ($position === false) {
        return [
            'logged' => false,
            'core_ref' => '',
            'verify_source' => '',
            'branch_ref' => '',
            'checked_on' => '',
            'decision' => '',
            'systems' => '',
            'physical' => '',
        ];
    }

    $block = trim(substr($raw, $position + strlen($marker)));
    $coreRef = '';
    $verifySource = '';
    $branchRef = '';
    $checkedOn = '';
    $decision = '';
    $systems = '';
    $physical = '';

    $lines = preg_split('/\R+/', $block) ?: [];
    foreach ($lines as $lineRaw) {
        $line = trim((string) $lineRaw);
        if ($line === '') {
            continue;
        }

        if (stripos($line, 'Decision action:') === 0) {
            $decision = trim(substr($line, strlen('Decision action:')));
            continue;
        }
        if (stripos($line, 'Finance processing reference:') === 0) {
            $coreRef = trim(substr($line, strlen('Finance processing reference:')));
            continue;
        }
        if (stripos($line, 'Core banking/process reference:') === 0) {
            $coreRef = trim(substr($line, strlen('Core banking/process reference:')));
            continue;
        }
        if (stripos($line, 'Verification source:') === 0) {
            $verifySource = trim(substr($line, strlen('Verification source:')));
            continue;
        }
        if (stripos($line, 'Branch handover reference:') === 0) {
            $branchRef = trim(substr($line, strlen('Branch handover reference:')));
            continue;
        }
        if (stripos($line, 'Internal systems reviewed:') === 0) {
            $systems = trim(substr($line, strlen('Internal systems reviewed:')));
            continue;
        }
        if (stripos($line, 'Physical verification notes:') === 0) {
            $physical = trim(substr($line, strlen('Physical verification notes:')));
            continue;
        }
    }

    $prefix = substr($raw, 0, $position);
    if (preg_match_all('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})\s*-\s*(Approved|Rejected|Comment) by Finance Dept/i', $prefix, $matches) && !empty($matches[1])) {
        $lastIndex = count($matches[1]) - 1;
        $checkedOn = (string) ($matches[1][$lastIndex] ?? '');
    }

    return [
        'logged' => true,
        'core_ref' => $coreRef,
        'verify_source' => $verifySource,
        'branch_ref' => $branchRef,
        'checked_on' => $checkedOn,
        'decision' => $decision,
        'systems' => $systems,
        'physical' => $physical,
    ];
}


// ====================================
// HANDLE FORM SUBMISSIONS (APPROVE/REJECT/COMMENT)
// ====================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'finance_claims_action')) {
        $_SESSION['error'] = 'Security validation failed. Please refresh and try again.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit();
    }

    $claim_id = (int) ($_POST['claim_id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));
    $comment = trim((string) ($_POST['comment'] ?? ''));
    $finance_manual_snapshot = trim((string) ($_POST['finance_manual_snapshot'] ?? ''));
    $finance_assessed_amount_raw = trim((string) ($_POST['finance_assessed_amount'] ?? ''));
    $finance_assessed_currency_code = bk_currency_code((string) ($_POST['finance_assessed_currency_code'] ?? 'RWF'));
    $finance_return_route = strtolower(trim((string) ($_POST['finance_return_route'] ?? 'claimant')));
    if (!in_array($finance_return_route, ['claimant', 'legal'], true)) {
        $finance_return_route = 'claimant';
    }
    $user_id = (int) ($user_data['id'] ?? 0);

    if ($claim_id > 0 && $action !== '') {
        $success = false;
        $message = '';
        $assessedAmountError = null;
        $finance_assessed_amount = finance_parse_assessed_amount($finance_assessed_amount_raw, $finance_assessed_currency_code, $assessedAmountError);
        if ($assessedAmountError !== null) {
            $_SESSION['error'] = $assessedAmountError;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        }

        // [QUEUE] Only act on claims assigned to this finance officer.
        $claimStmt = mysqli_prepare(
            $conn,
            'SELECT c.id, c.comment, COALESCE(NULLIF(c.status, \'\'), c.claim_status) AS effective_status, c.claimant_id, c.claimant_user_id, c.assigned_legal_id, c.alt_email, c.finance_assessed_amount, c.finance_assessed_currency_code, c.model_version, u.email
             FROM claims c
             INNER JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
             WHERE c.id = ? AND c.assigned_finance_id = ?
             LIMIT 1'
        );
        $claimResult = false;
        if ($claimStmt) {
            mysqli_stmt_bind_param($claimStmt, 'ii', $claim_id, $user_id);
            if (mysqli_stmt_execute($claimStmt)) {
                $claimResult = mysqli_stmt_get_result($claimStmt);
            }
        }

        if (!$claimResult || mysqli_num_rows($claimResult) === 0) {
            $_SESSION['error'] = 'Claim not found in your finance queue.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        }

        $claimRow = mysqli_fetch_assoc($claimResult);
        $existing_comment = (string) ($claimRow['comment'] ?? '');
        $claim_status = trim((string) ($claimRow['effective_status'] ?? ''));
        $claimStatusKey = finance_claim_status_key($claim_status);
        $isLegacyClaim = strtolower(trim((string) ($claimRow['model_version'] ?? 'legacy'))) !== 'v2';
        $email = (string) ($claimRow['email'] ?? '');
        $alt_email = (string) ($claimRow['alt_email'] ?? '');
        $target_claimant_id = (int) (($claimRow['claimant_user_id'] ?? 0) ?: ($claimRow['claimant_id'] ?? 0));
        $assigned_legal_id = (int) ($claimRow['assigned_legal_id'] ?? 0);
        $manualSnapshotBlock = '';
        if ($finance_manual_snapshot !== '') {
            $manualSnapshotBlock = "\nFinance Manual Verification Record\n" . $finance_manual_snapshot;
        }

        $assessedAmountSql = $finance_assessed_amount;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Claimant email is invalid.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        }

        if ($isLegacyClaim) {
            $_SESSION['error'] = 'Legacy claims are visible for reference only and cannot be actioned in the redesigned finance workflow.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        }

        $claimAssets = udcs_claim_fetch_assets($conn, $claim_id);
        $assetReviewError = null;
        $assetReviews = finance_parse_asset_reviews((array) ($_POST['asset_reviews'] ?? []), $claimAssets, $assetReviewError);
        if ($assetReviewError !== null) {
            $_SESSION['error'] = $assetReviewError;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        }

        switch ($action) {
            case 'approve':
                if ($finance_manual_snapshot === '') {
                    $_SESSION['error'] = 'Before approval, complete the internal review details section.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }
                if ($assessedAmountSql === null) {
                    $_SESSION['error'] = 'Final assessed value is required before approval.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                if (!in_array($claimStatusKey, [
                    finance_claim_status_key('Pending Finance Review'),
                    finance_claim_status_key('Returned by Finance'),
                ], true)) {
                    $_SESSION['error'] = 'Only claims currently in the Finance review queue can be approved.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                if (!finance_validate_asset_reviews_for_approval($assetReviews, $claimAssets, $assetReviewError)) {
                    $_SESSION['error'] = $assetReviewError ?: 'Complete the per-asset finance review before approval.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                $reviewContract = udcs_claim_fetch_review_contract($conn, $claim_id);
                $disbursementBlockers = finance_claim_disbursement_blockers($reviewContract, $assetReviews, $claimAssets);
                if (!empty($disbursementBlockers)) {
                    $_SESSION['error'] = 'This claim cannot be closed by Finance yet: ' . implode(' ', $disbursementBlockers);
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                $new_comment = $existing_comment .
                    "\n" . date('Y-m-d H:i') . " - Approved by Finance Dept\n" .
                    ($comment !== '' ? "Note: $comment" : "Note: BK asset verification, bank-side disbursement, and closure were recorded by Finance.") .
                    $manualSnapshotBlock;
                mysqli_begin_transaction($conn);
                try {
                    if (!finance_store_asset_reviews($conn, $claim_id, $assetReviews)) {
                        throw new RuntimeException('Asset review save failed.');
                    }

                    udcs_claim_history_log($conn, $claim_id, 'finance', 'Approved for Disbursement', 'Finance confirmed payout readiness.');
                    udcs_claim_history_log($conn, $claim_id, 'finance', 'Disbursed', 'Finance recorded manual bank-side disbursement execution.');
                    $success = udcs_claim_set_status($conn, $claim_id, 'Closed', (int) $user_id, 'finance', 'Finance completed disbursement and closed the claim.', [
                        'finance_assessed_amount' => (float) $assessedAmountSql,
                        'finance_assessed_currency_code' => $finance_assessed_currency_code,
                        'assigned_to' => $user_id,
                        'manual_review_flag' => 0,
                        'manual_review_reason' => null,
                        'finance_return_reason' => null,
                        'finance_return_route' => null,
                        'legal_reopen_scope' => null,
                        'legal_reopen_note' => null,
                        'legal_reopen_requested_at' => null,
                        'comment' => $new_comment,
                        'closed_at' => 'NOW()',
                        'updated_at' => 'NOW()',
                    ]);
                    if (!$success) {
                        throw new RuntimeException('Claim close failed.');
                    }

                    mysqli_commit($conn);
                } catch (Throwable $exception) {
                    mysqli_rollback($conn);
                    $_SESSION['error'] = 'We could not finalize this finance approval right now.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }
                $message = 'Claim approved, disbursement recorded, and closed by Finance.';

                if ($success && $target_claimant_id > 0) {
                    $assessedValueForMessage = $finance_assessed_amount !== null
                        ? bk_claim_amount_display($finance_assessed_amount, $finance_assessed_currency_code, '')
                        : '';
                    // [NOTIFY] Claimant about approval.
                    $notif_msg = "Your claim #$claim_id has been approved, disbursement has been recorded, and the claim is now closed by Finance."
                        . ($assessedValueForMessage !== '' ? " Assessed disbursement value: $assessedValueForMessage." : '');
                    udcs_db_insert_notification($conn, (string) $target_claimant_id, (string) $user_id, $notif_msg);

                    bk_activity_log($conn, [
                        'actor_id' => (int) $user_id,
                        'actor_role' => 'finance',
                        'claim_id' => (int) $claim_id,
                        'action_key' => 'finance_approved_claim',
                        'action_label' => 'Finance Approved Claim',
                        'details' => 'Claim approved by finance reviewer.',
                        'meta' => [
                            'finance_assessed_amount' => $finance_assessed_amount,
                            'finance_assessed_currency_code' => $finance_assessed_currency_code,
                        ],
                    ]);
                    if ($assigned_legal_id > 0) {
                        udcs_send_staff_workflow_email(
                            $conn,
                            'finance_closed',
                            $claim_id,
                            [$assigned_legal_id],
                            [
                                'actor_name' => $claimant_name,
                                'note' => $comment !== '' ? $comment : 'Finance recorded bank-side disbursement and closed the claim.',
                            ]
                        );
                    }

                    $emails = [$email];
                    if ($alt_email !== '' && filter_var($alt_email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $alt_email;
                    }

                    $actionToken = udcs_action_token_issue('finance_approval_email', [
                        'claim_id' => (int) $claim_id,
                        'emails' => array_values($emails),
                    ], 300);
                    header("Location: approvalEmail.php?action_token=" . urlencode($actionToken));
                    exit();
                }
                break;

            case 'reject':
                if ($comment === '' || strlen($comment) < 12) {
                    $_SESSION['error'] = 'Please write a clear return reason (at least 12 characters).';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                if (!in_array($claimStatusKey, [
                    finance_claim_status_key('Pending Finance Review'),
                    finance_claim_status_key('Returned by Finance'),
                ], true)) {
                    $_SESSION['error'] = 'Only claims currently in the Finance review queue can be returned.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                if ($finance_return_route === 'legal' && bk_pick_staff_assignee($conn, 'legal', 'legal') === null) {
                    $_SESSION['error'] = 'No approved Legal officer is available right now, so this claim cannot be returned to Legal yet.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                $new_comment = $existing_comment .
                    "\n" . date('Y-m-d H:i') . " - Returned by Finance Dept\n" .
                    "Reason: " . ($comment !== '' ? $comment : "No reason provided") .
                    $manualSnapshotBlock;

                $returnHistoryMessage = $finance_return_route === 'legal'
                    ? 'Finance returned the claim to Legal for re-review.'
                    : 'Finance returned the claim to the claimant for clarification.';
                $returnStatus = $finance_return_route === 'legal'
                    ? 'Pending Legal Review'
                    : 'Returned by Finance';
                $extraFields = [
                    'comment' => $new_comment,
                    'finance_return_reason' => $comment,
                    'finance_return_route' => $finance_return_route,
                    'updated_at' => 'NOW()',
                ];
                if ($finance_return_route === 'claimant') {
                    $extraFields['assigned_to'] = $user_id;
                    $extraFields['legal_reopen_scope'] = 'assets_payout,supporting_documents';
                    $extraFields['legal_reopen_note'] = $comment;
                    $extraFields['legal_reopen_requested_at'] = date('Y-m-d H:i:s');
                } else {
                    $extraFields['assigned_finance_id'] = null;
                    $extraFields['assigned_to'] = null;
                    $extraFields['legal_reopen_scope'] = null;
                    $extraFields['legal_reopen_note'] = null;
                    $extraFields['legal_reopen_requested_at'] = null;
                }
                if ($assessedAmountSql !== null) {
                    $extraFields['finance_assessed_amount'] = (float) $assessedAmountSql;
                    $extraFields['finance_assessed_currency_code'] = $finance_assessed_currency_code;
                }
                $legalAssignee = null;
                mysqli_begin_transaction($conn);
                try {
                    $success = udcs_claim_set_status($conn, $claim_id, $returnStatus, (int) $user_id, 'finance', $returnHistoryMessage, $extraFields);
                    if (!$success) {
                        throw new RuntimeException('Claim return status update failed.');
                    }

                    if (!finance_store_asset_reviews($conn, $claim_id, $assetReviews)) {
                        throw new RuntimeException('Asset review save failed.');
                    }

                    if ($finance_return_route === 'legal') {
                        $legalAssignee = bk_assign_claim_to_legal($conn, $claim_id);
                        if ($legalAssignee === null || $legalAssignee <= 0) {
                            throw new RuntimeException('No legal assignee available.');
                        }
                        udcs_claim_history_log($conn, $claim_id, 'system', 'Re-routed to Legal', 'Finance sent this claim back to Legal for another controlled review.');
                    }

                    mysqli_commit($conn);
                } catch (Throwable $exception) {
                    mysqli_rollback($conn);
                    $_SESSION['error'] = $finance_return_route === 'legal'
                        ? 'We could not return this claim to Legal right now.'
                        : 'We could not return this claim to the claimant right now.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }
                $message = $finance_return_route === 'legal'
                    ? 'Claim returned by Finance and routed back to Legal.'
                    : 'Claim returned by Finance to the claimant.';

                if ($success && $target_claimant_id > 0) {
                    $notif_msg = $finance_return_route === 'legal'
                        ? "Your claim #$claim_id has been sent back from Finance to Legal for another review step. No claimant update is required unless Legal requests one."
                        : "Your claim #$claim_id has been returned by Finance Department. Review the finance reason and update the requested details.";
                    udcs_db_insert_notification($conn, (string) $target_claimant_id, (string) $user_id, $notif_msg);

                    bk_activity_log($conn, [
                        'actor_id' => (int) $user_id,
                        'actor_role' => 'finance',
                        'claim_id' => (int) $claim_id,
                        'action_key' => 'finance_returned_claim',
                        'action_label' => 'Finance Returned Claim',
                        'details' => $finance_return_route === 'legal'
                            ? 'Claim returned by finance reviewer to legal for re-review.'
                            : 'Claim returned by finance reviewer to claimant for clarification.',
                        'meta' => [
                            'return_route' => $finance_return_route,
                        ],
                    ]);

                    if ($finance_return_route === 'legal' && $legalAssignee !== null && $legalAssignee > 0) {
                        udcs_db_insert_notification(
                            $conn,
                            (string) $legalAssignee,
                            (string) $user_id,
                            'Claim CL-' . str_pad((string) $claim_id, 6, '0', STR_PAD_LEFT) . ' has been returned by Finance to Legal for re-review. Check the finance reason and next required step.'
                        );
                        udcs_send_staff_workflow_email(
                            $conn,
                            'finance_returned_to_legal',
                            $claim_id,
                            [$legalAssignee],
                            [
                                'actor_name' => $claimant_name,
                                'note' => $comment,
                            ]
                        );
                    }

                    $emails = [$email];
                    if ($alt_email !== '' && filter_var($alt_email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $alt_email;
                    }

                    $actionToken = udcs_action_token_issue('finance_denial_email', [
                        'claim_id' => (int) $claim_id,
                        'emails' => array_values($emails),
                        'route' => $finance_return_route,
                    ], 300);
                    header("Location: denialEmail.php?action_token=" . urlencode($actionToken));
                    exit();
                }
                break;

            case 'comment':
                if ($comment === '') {
                    $_SESSION['error'] = 'Please enter a review note before saving.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                if (!in_array($claimStatusKey, [
                    finance_claim_status_key('Pending Finance Review'),
                    finance_claim_status_key('Returned by Finance'),
                ], true)) {
                    $_SESSION['error'] = 'Only claims still in the Finance review workflow can receive a new finance note.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                // [HISTORY] Append comment.
                $new_comment = $existing_comment .
                    "\n" . date('Y-m-d H:i') . " - Comment by Finance Dept\n" .
                    $comment .
                    $manualSnapshotBlock;

                $extraFields = [
                    'comment' => $new_comment,
                    'updated_at' => 'NOW()',
                ];
                if ($assessedAmountSql !== null) {
                    $extraFields['finance_assessed_amount'] = (float) $assessedAmountSql;
                    $extraFields['finance_assessed_currency_code'] = $finance_assessed_currency_code;
                }
                mysqli_begin_transaction($conn);
                try {
                    $success = udcs_claim_set_status($conn, $claim_id, $claim_status, (int) $user_id, 'finance', 'Finance review note saved.', $extraFields);
                    if (!$success) {
                        throw new RuntimeException('Finance note save failed.');
                    }
                    if (!finance_store_asset_reviews($conn, $claim_id, $assetReviews)) {
                        throw new RuntimeException('Asset review save failed.');
                    }

                    mysqli_commit($conn);
                } catch (Throwable $exception) {
                    mysqli_rollback($conn);
                    $_SESSION['error'] = 'We could not save this finance review note right now.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }
                $message = 'Comment added successfully.';

                if ($success) {
                    bk_activity_log($conn, [
                        'actor_id' => (int) $user_id,
                        'actor_role' => 'finance',
                        'claim_id' => (int) $claim_id,
                        'action_key' => 'finance_comment_added',
                        'action_label' => 'Finance Comment Added',
                        'details' => 'Finance reviewer added a claim comment.',
                    ]);
                }
                break;
        }

        if ($success) {
            $_SESSION['success'] = $message;
        } else {
            $_SESSION['error'] = 'We could not process this claim action. Please try again.';
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit();
    }
}

// ====================================
// PAGINATION & FILTERS
// ====================================
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// Filters
$status = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));
$claimAccountSql = udcs_claim_account_reference_sql('c');
$assetJoinSql = "
LEFT JOIN (
    SELECT
        claim_id,
        GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes,
        GROUP_CONCAT(DISTINCT NULLIF(account_reference, '') ORDER BY account_reference SEPARATOR '||') AS asset_refs
    FROM claim_assets
    GROUP BY claim_id
) ca ON ca.claim_id = c.id";

// [QUEUE] List only claims assigned to this finance officer.
$whereParts = [
    'c.assigned_finance_id = ?',
];
$filterTypes = 'i';
$filterParams = [(int) $claimant_id];


if ($status && $status !== 'all') {
    $whereParts[] = "COALESCE(NULLIF(c.status, ''), c.claim_status) = ?";
    $filterTypes .= 's';
    $filterParams[] = $status;
}

if ($search) {
    $whereParts[] = '(
        CAST(c.id AS CHAR) LIKE ?
        OR u.full_name LIKE ?
        OR u.email LIKE ?
        OR COALESCE(NULLIF(c.deceased_full_name, \'\'), c.deceased_name) LIKE ?
        OR COALESCE(c.relationship, \'\') LIKE ?
        OR COALESCE(ca.asset_classes, \'\') LIKE ?
        OR COALESCE(ca.asset_refs, \'\') LIKE ?
        OR ' . $claimAccountSql . ' LIKE ?
        OR c.distribution_method LIKE ?
        OR c.distribution_details LIKE ?
        OR c.finance_return_reason LIKE ?
    )';
    $searchTerm = '%' . $search . '%';
    $filterTypes .= str_repeat('s', 11);
    for ($i = 0; $i < 11; $i++) {
        $filterParams[] = $searchTerm;
    }
}

if ($date_from) {
    $whereParts[] = 'DATE(c.submitted_at) >= ?';
    $filterTypes .= 's';
    $filterParams[] = $date_from;
}

if ($date_to) {
    $whereParts[] = 'DATE(c.submitted_at) <= ?';
    $filterTypes .= 's';
    $filterParams[] = $date_to;
}

$where = 'WHERE ' . implode(' AND ', $whereParts);

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM claims c 
               JOIN users u ON COALESCE(c.claimant_user_id, c.claimant_id) = u.id 
               $assetJoinSql
               $where";
$countStmt = mysqli_prepare($conn, $countQuery);
$countResult = false;
if ($countStmt && udcs_db_stmt_bind($countStmt, $filterTypes, $filterParams) && mysqli_stmt_execute($countStmt)) {
    $countResult = mysqli_stmt_get_result($countStmt);
}
$totalRows = (int) (($countResult ? mysqli_fetch_assoc($countResult)['total'] : 0) ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// Fetch claims with pagination
$query = "
SELECT 
    c.*,
    COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
    u.full_name, 
    u.email, 
    u.phone,
    COALESCE(ca.asset_classes, '') AS asset_classes,
    COALESCE(ca.asset_refs, '') AS asset_refs,
    DATE_FORMAT(c.submitted_at, '%d %b %Y') AS submitted_date,
    COUNT(d.id) AS document_count
FROM claims c
JOIN users u ON COALESCE(c.claimant_user_id, c.claimant_id) = u.id
$assetJoinSql
LEFT JOIN documents d ON d.claim_id = c.id
$where
GROUP BY c.id
ORDER BY 
    CASE COALESCE(NULLIF(c.status, ''), c.claim_status)
        WHEN 'Pending Finance Review' THEN 1
        WHEN 'Returned by Finance' THEN 2
        WHEN 'Closed' THEN 4
        ELSE 5
    END,
    c.submitted_at DESC
LIMIT ? OFFSET ?
";

$claimsTypes = $filterTypes . 'ii';
$claimsParams = $filterParams;
$claimsParams[] = $limit;
$claimsParams[] = $offset;
$claimsStmt = mysqli_prepare($conn, $query);
$claims = false;
if ($claimsStmt && udcs_db_stmt_bind($claimsStmt, $claimsTypes, $claimsParams) && mysqli_stmt_execute($claimsStmt)) {
    $claims = mysqli_stmt_get_result($claimsStmt);
}

$financeQueueRows = [];
if ($claims) {
    while ($claimRow = mysqli_fetch_assoc($claims)) {
        $contract = udcs_claim_fetch_review_contract($conn, (int) ($claimRow['id'] ?? 0), $claimRow);
        if (!$contract) {
            continue;
        }
        $financeQueueRows[] = [
            'row' => $claimRow,
            'contract' => $contract,
        ];
    }
}

// Status statistics for filter
$statsQuery = "SELECT 
    COALESCE(NULLIF(status, ''), claim_status) AS effective_status,
    COUNT(*) as count
    FROM claims
    WHERE assigned_finance_id = ?
    GROUP BY COALESCE(NULLIF(status, ''), claim_status)";
$statsStmt = mysqli_prepare($conn, $statsQuery);
$statsResult = false;
if ($statsStmt) {
    mysqli_stmt_bind_param($statsStmt, 'i', $claimant_id);
    if (mysqli_stmt_execute($statsStmt)) {
        $statsResult = mysqli_stmt_get_result($statsStmt);
    }
}
$statusStats = [];
if ($statsResult) {
    while ($row = mysqli_fetch_assoc($statsResult)) {
        $statusStats[(string) ($row['effective_status'] ?? '')] = $row['count'];
    }
}

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Finance Claims | UNIFIED DIGITAL CLAIMS SYSTEM', '..', $headExtra); ?>
    <style>
        .claims-wrapper {
            padding: 1rem 1.25rem 2rem;
        }

        .claims-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.18rem;
            padding: 1.25rem 1.35rem;
            background:
                radial-gradient(circle at 13% 16%, rgba(var(--bk-primary-rgb), 0.18), transparent 34%),
                linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.15), rgba(var(--bk-primary-rgb), 0.04) 52%, rgba(var(--bk-surface-rgb), 1) 100%);
            box-shadow: var(--shadow-soft);
        }

        .claims-hero::after {
            content: '';
            position: absolute;
            width: 15rem;
            height: 15rem;
            right: -4.2rem;
            top: -4.9rem;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(var(--bk-primary-rgb), 0.2), rgba(var(--bk-primary-rgb), 0));
            animation: float 7s ease-in-out infinite;
            pointer-events: none;
        }

        .claims-page-header {
            margin-top: 0;
        }

        .claims-content {
            margin-top: 1rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.24rem;
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.045), rgba(var(--bk-white-rgb), 1));
            box-shadow: 0 18px 42px rgba(var(--bk-primary-rgb), 0.08);
            padding: 1.18rem;
            display: grid;
            gap: 1.08rem;
        }

        .claims-page-header h2 {
            margin-top: 0.45rem;
            font-family: var(--app-display-font), var(--app-font), sans-serif;
            color: rgb(var(--bk-text-rgb));
            letter-spacing: 0.01em;
            font-size: clamp(1.45rem, 2.3vw, 2rem);
            line-height: 1.14;
        }

        .claims-page-header p,
        .claims-wrapper .text-muted {
            color: rgb(var(--bk-muted-rgb)) !important;
        }

        .claims-wrapper .form-label {
            color: rgb(var(--bk-text-rgb)) !important;
            font-weight: 700 !important;
            letter-spacing: 0.01em;
        }

        .claims-wrapper .ui-input,
        .claims-wrapper .ui-select {
            min-height: 2.8rem;
            font-weight: 500;
        }

        .claims-wrapper .ui-input::placeholder {
            color: rgba(var(--bk-muted-rgb), 0.96) !important;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.28rem 0.68rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid transparent;
        }

        .badge-transferred {
            color: rgb(var(--bk-primary-rgb));
            border-color: rgba(var(--bk-primary-rgb), 0.34);
            background: rgba(var(--bk-primary-rgb), 0.12);
        }

        .badge-approved {
            color: rgb(var(--bk-success-rgb));
            border-color: rgba(var(--bk-success-rgb), 0.34);
            background: rgba(var(--bk-success-rgb), 0.13);
        }

        .badge-rejected {
            color: rgb(var(--bk-danger-rgb));
            border-color: rgba(var(--bk-danger-rgb), 0.34);
            background: rgba(var(--bk-danger-rgb), 0.12);
        }

        body.bk-role-page.bk-role-finance .status-badge.badge-transferred {
            color: rgb(var(--bk-primary-rgb)) !important;
            border-color: rgba(var(--bk-primary-rgb), 0.48) !important;
            background: rgba(var(--bk-primary-rgb), 0.16) !important;
        }

        body.bk-role-page.bk-role-finance .status-badge.badge-approved {
            color: rgb(var(--bk-success-rgb)) !important;
            border-color: rgba(var(--bk-success-rgb), 0.46) !important;
            background: rgba(var(--bk-success-rgb), 0.18) !important;
        }

        body.bk-role-page.bk-role-finance .status-badge.badge-rejected {
            color: rgb(var(--bk-danger-rgb)) !important;
            border-color: rgba(var(--bk-danger-rgb), 0.46) !important;
            background: rgba(var(--bk-danger-rgb), 0.16) !important;
        }

        .manual-check-pill {
            margin-top: 0.4rem;
            display: inline-flex;
            align-items: center;
            gap: 0.32rem;
            border-radius: 999px;
            padding: 0.22rem 0.56rem;
            font-size: 0.69rem;
            font-weight: 700;
            border: 1px solid transparent;
            letter-spacing: 0.01em;
        }

        .manual-check-pill.is-ready {
            color: rgb(var(--bk-success-rgb));
            border-color: rgba(var(--bk-success-rgb), 0.4);
            background: rgba(var(--bk-success-rgb), 0.13);
        }

        .manual-check-pill.is-missing {
            color: rgb(var(--bk-warning-rgb));
            border-color: rgba(var(--bk-warning-rgb), 0.45);
            background: rgba(var(--bk-warning-rgb), 0.16);
        }

        .manual-check-meta {
            display: block;
            margin-top: 0.18rem;
            font-size: 0.68rem;
            color: rgb(var(--bk-muted-rgb));
            font-weight: 600;
        }

        .finance-case-title,
        .finance-asset-title,
        .finance-settlement-title {
            font-weight: 850;
            color: rgb(var(--bk-text-rgb));
            line-height: 1.25;
        }

        .finance-case-stack {
            display: grid;
            gap: 0.24rem;
        }

        .finance-case-title {
            display: flex;
            align-items: center;
            gap: 0.36rem;
            flex-wrap: wrap;
        }

        .finance-case-path {
            color: rgb(var(--bk-primary-rgb));
            font-size: 0.72rem;
            font-weight: 800;
            line-height: 1.26;
        }

        .finance-meta {
            display: grid;
            gap: 0.16rem;
            margin-top: 0.25rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.74rem;
            line-height: 1.34;
        }

        .finance-meta-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 0.32rem 0.5rem;
            margin-top: 0.24rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.74rem;
            line-height: 1.3;
        }

        .finance-meta-inline strong {
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .finance-kv-list {
            display: grid;
            gap: 0.28rem;
            margin-top: 0.28rem;
        }

        .finance-kv-line {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.65rem;
            font-size: 0.74rem;
            line-height: 1.3;
            color: rgb(var(--bk-muted-rgb));
        }

        .finance-kv-line strong {
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .finance-mini-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.45rem;
            margin-top: 0.42rem;
        }

        .finance-mini {
            border: 1px solid rgba(var(--bk-border-rgb), 0.95);
            border-radius: 0.66rem;
            background: rgba(var(--bk-bg-rgb), 0.68);
            padding: 0.34rem 0.44rem;
            min-width: 0;
        }

        .finance-mini span {
            display: block;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.64rem;
            font-weight: 850;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .finance-mini strong {
            display: block;
            margin-top: 0.08rem;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.78rem;
            line-height: 1.22;
        }

        .finance-signal-stack {
            display: grid;
            gap: 0.32rem;
        }

        .finance-signal {
            display: inline-flex;
            align-items: flex-start;
            gap: 0.36rem;
            width: 100%;
            max-width: none;
            border-radius: 0.72rem;
            border: 1px solid rgba(var(--bk-border-rgb), 0.95);
            background: rgba(var(--bk-bg-rgb), 0.74);
            color: rgb(var(--bk-text-rgb));
            padding: 0.42rem 0.58rem;
            font-size: 0.7rem;
            font-weight: 780;
            line-height: 1.3;
        }

        .finance-signal.is-compact {
            padding: 0.34rem 0.46rem;
            font-size: 0.69rem;
            line-height: 1.22;
        }

        .finance-signal i {
            flex: 0 0 auto;
            margin-top: 0.04rem;
        }

        .finance-signal.is-danger {
            border-color: rgba(var(--bk-danger-rgb), 0.42);
            background: rgba(var(--bk-danger-rgb), 0.09);
            color: rgb(var(--bk-danger-rgb));
        }

        .finance-signal.is-warning {
            border-color: rgba(var(--bk-warning-rgb), 0.44);
            background: rgba(var(--bk-warning-rgb), 0.12);
            color: #8a5b00;
        }

        .finance-signal.is-ok {
            border-color: rgba(var(--bk-success-rgb), 0.42);
            background: rgba(var(--bk-success-rgb), 0.09);
            color: rgb(var(--bk-success-rgb));
        }

        .finance-docs {
            display: grid;
            gap: 0.32rem;
        }

        .finance-hidden-hint {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.71rem;
            font-weight: 700;
            line-height: 1.24;
        }

        .finance-status-stack {
            display: grid;
            gap: 0.34rem;
        }

        .finance-status-note {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.72rem;
            line-height: 1.3;
            font-weight: 700;
        }

        .finance-doc-line {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.6rem;
            border-bottom: 1px dotted rgba(var(--bk-border-rgb), 0.95);
            padding-bottom: 0.24rem;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.74rem;
            font-weight: 750;
        }

        .finance-doc-line:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .claim-id {
            font-family: "Courier New", monospace;
            font-weight: 700;
            color: rgb(var(--bk-primary-rgb));
        }

        .filter-card {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.95rem;
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.05), rgba(var(--bk-surface-rgb), 1));
            box-shadow: var(--shadow-soft);
        }

        .status-shortcuts a {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
            text-decoration: none;
            padding: 0.36rem 0.66rem;
            border-color: rgba(var(--bk-border-rgb), 1);
            background: rgb(var(--bk-surface-rgb));
            border-width: 1px;
            transition: all 0.15s ease;
        }

        .status-shortcuts a:hover,
        .status-shortcuts a.is-active {
            border-color: rgba(var(--bk-primary-rgb), 0.45);
            background: rgba(var(--bk-primary-rgb), 0.1);
            color: rgb(var(--bk-primary-rgb));
        }

        .table-shell {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.16rem;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.05), rgba(var(--bk-white-rgb), 1));
            box-shadow: 0 18px 40px rgba(var(--bk-primary-rgb), 0.08);
            padding: 0.78rem;
        }

        .table-shell .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0 0.92rem;
        }

        .table-shell .table thead th {
            border: 0 !important;
            padding: 0.94rem 0.9rem;
            background: rgb(var(--bk-primary-rgb)) !important;
            color: #ffffff !important;
            vertical-align: middle;
        }

        .table-shell .table thead th.is-case,
        .table-shell .table tbody td.is-case { width: 23rem; }

        .table-shell .table thead th.is-assets,
        .table-shell .table tbody td.is-assets { width: 16.5rem; }

        .table-shell .table thead th.is-verification,
        .table-shell .table tbody td.is-verification { width: 15rem; max-width: 15rem; }

        .table-shell .table thead th.is-status,
        .table-shell .table tbody td.is-status { width: 12.4rem; }

        .table-shell .table thead th.is-date,
        .table-shell .table tbody td.is-date { width: 8.6rem; }

        .table-shell .table thead th.is-actions,
        .table-shell .table tbody td.is-actions { width: 9.2rem; }

        .table-shell .table thead th:first-child {
            border-top-left-radius: 0.9rem;
            border-bottom-left-radius: 0.9rem;
        }

        .table-shell .table thead th:last-child {
            border-top-right-radius: 0.9rem;
            border-bottom-right-radius: 0.9rem;
        }

        .table-shell .table tbody td {
            background: rgba(var(--bk-white-rgb), 0.98);
            border-top: 1px solid rgba(var(--bk-border-rgb), 0.9);
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.9);
            padding: 1rem 0.9rem;
            vertical-align: top;
            box-shadow: 0 10px 22px rgba(var(--bk-primary-rgb), 0.04);
        }

        .table-shell .table tbody td:first-child {
            border-left: 1px solid rgba(var(--bk-border-rgb), 0.9);
            border-top-left-radius: 0.96rem;
            border-bottom-left-radius: 0.96rem;
        }

        .table-shell .table tbody td:last-child {
            border-right: 1px solid rgba(var(--bk-border-rgb), 0.9);
            border-top-right-radius: 0.96rem;
            border-bottom-right-radius: 0.96rem;
        }

        .table-hover tbody tr:hover td {
            background-color: rgba(var(--bk-primary-rgb), 0.045);
        }

        .action-btn {
            min-width: 2.2rem;
            min-height: 2.2rem;
            border-radius: 0.68rem;
            font-size: 0.84rem;
            padding: 0.3rem 0.56rem;
        }

        .action-btn.is-view {
            border-color: rgba(var(--bk-primary-rgb), 0.88) !important;
            background: rgb(var(--bk-primary-rgb)) !important;
            color: #fff !important;
        }

        .icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.2rem;
            height: 2.2rem;
            border-radius: 0.68rem;
        }

        .pagination .page-link {
            border-radius: 0.58rem;
            margin: 0 0.12rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            color: rgb(var(--bk-text-rgb));
        }

        .pagination .page-link:hover {
            background: rgba(var(--bk-primary-rgb), 0.08);
            border-color: rgba(var(--bk-primary-rgb), 0.45);
            color: rgb(var(--bk-text-rgb));
        }

        .pagination .active .page-link {
            background-color: rgb(var(--bk-primary-rgb));
            border-color: rgb(var(--bk-primary-rgb));
            color: #fff;
        }

        .claims-wrapper .table-shell .table thead th,
        .claims-wrapper .table-shell .table thead th *,
        .claims-wrapper .table-shell .table thead th .table-entity-label,
        .claims-wrapper .table-shell .table thead th .table-entity-label i {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        .modal-xl-custom {
            max-width: min(1600px, calc(100vw - 1.25rem));
        }

        .claim-detail-panel {
            display: block;
            min-width: 0;
            max-width: 100%;
            min-height: 0;
            max-height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 0.14rem;
            scrollbar-gutter: stable;
        }

        .btn-group-xs > .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 0.4rem;
        }

        @media (max-width: 780px) {
            .claims-wrapper {
                padding-left: 0.85rem;
                padding-right: 0.85rem;
            }
        }

        .pre-line {
            white-space: pre-line;
        }

        .review-modal .modal-content {
            border: 1px solid rgba(var(--bk-primary-rgb), 0.16);
            border-radius: 1.42rem;
            background:
                linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-surface-rgb), 1)) !important;
            --review-workspace-height: calc(100vh - 7.1rem);
            box-shadow: 0 34px 80px rgba(3, 78, 162, 0.22), 0 0 0 1px rgba(var(--bk-white-rgb), 0.76) inset;
            overflow-y: hidden !important;
            overflow-x: hidden !important;
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 1rem) !important;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-y;
        }

        .review-modal #reviewForm {
            display: flex;
            flex-direction: column;
            min-height: 0;
            max-height: inherit;
        }

        .review-modal .modal-body {
            background: rgb(var(--bk-bg-rgb));
            overflow: hidden !important;
            padding: 1.08rem 1.16rem 1.16rem;
            flex: 1 1 auto;
            min-height: 0;
        }

        .review-modal .modal-footer {
            border-top: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgb(var(--bk-surface-rgb));
            padding: 0.7rem 1rem;
        }

        .review-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr) clamp(320px, 24vw, 390px);
            gap: 1.12rem;
            align-items: stretch;
            max-width: 100%;
            height: var(--review-workspace-height);
            max-height: var(--review-workspace-height);
            overflow: hidden;
        }

        .review-workspace > * {
            min-width: 0;
        }

        .review-sidebar {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr);
            gap: 1rem;
            align-self: stretch;
            position: sticky;
            top: 0;
            height: 100%;
            min-height: 0;
            max-height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 0.14rem;
            align-content: stretch;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }

        body.bk-role-page .review-modal .claim-detail-panel .cv2-sheet {
            width: 100%;
            max-width: 100%;
            margin: 0;
            box-sizing: border-box;
        }

        body.bk-role-page .review-modal .claim-detail-panel .cv2-command-grid {
            grid-template-columns: 1fr;
        }

        body.bk-role-page .review-modal .claim-detail-panel .cv2-overview-grid,
        body.bk-role-page .review-modal .claim-detail-panel .cv2-overview-rail {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        body.bk-role-page .review-modal .claim-detail-panel .cv2-grid,
        body.bk-role-page .review-modal .claim-detail-panel .cv2-subgrid,
        body.bk-role-page .review-modal .claim-detail-panel .cv2-readiness-grid {
            grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
        }

        .review-sidebar-card,
        .review-action-card {
            border: 1px solid rgba(var(--bk-primary-rgb), 0.14);
            border-radius: 1.22rem;
            background: rgba(var(--bk-white-rgb), 1);
            box-shadow: 0 14px 30px rgba(var(--bk-primary-rgb), 0.07);
            overflow: hidden;
        }

        .review-sidebar-card {
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 0;
            max-height: min(19rem, 38vh);
        }

        .review-sidebar-head {
            display: grid;
            gap: 0.18rem;
            margin: 0;
            padding: 0.92rem 1rem 0.78rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            background:
                radial-gradient(circle at top left, rgba(var(--bk-primary-rgb), 0.13), transparent 34%),
                linear-gradient(135deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.045));
        }

        .review-sidebar-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.95rem;
            font-weight: 900;
        }

        .review-sidebar-note {
            margin: 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.77rem;
            line-height: 1.42;
        }

        .review-modal .comments-box {
            flex: 1 1 auto;
            min-height: 0;
            max-height: 16rem;
            overflow: auto;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.08rem;
            background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.035));
            padding: 0.86rem;
            margin: 0.86rem;
            box-shadow: none;
        }

        .history-list {
            display: grid;
            gap: 0.5rem;
        }

        .history-item {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.75rem;
            background: rgba(var(--bk-bg-rgb), 0.32);
            padding: 0.5rem 0.58rem;
            display: grid;
            gap: 0.28rem;
        }

        .history-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .history-title {
            font-size: 0.8rem;
            font-weight: 800;
            color: rgb(var(--bk-text-rgb));
        }

        .history-time {
            font-size: 0.72rem;
            color: rgb(var(--bk-muted-rgb));
            font-weight: 600;
        }

        .history-notes {
            margin: 0;
            padding: 0 0 0 1rem;
            display: grid;
            gap: 0.16rem;
        }

        .history-notes li {
            font-size: 0.77rem;
            color: rgb(var(--bk-text-rgb));
            line-height: 1.35;
        }

        .history-empty {
            border: 1px dashed rgba(var(--bk-border-rgb), 1);
            border-radius: 0.72rem;
            background: rgba(var(--bk-bg-rgb), 0.28);
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.8rem;
            text-align: center;
            padding: 0.72rem;
        }

        .review-action-card {
            padding: 0;
            max-width: none;
            margin-inline: 0;
            min-height: 0;
            max-height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }

        .review-modal .card {
            background-color: rgb(var(--bk-surface-rgb)) !important;
            background-image: none !important;
            opacity: 1 !important;
            backdrop-filter: none !important;
        }

        .review-action-grid {
            display: grid;
            gap: 0.82rem;
            padding: 0.92rem 1rem 1rem;
            min-height: min-content;
        }

        .review-checklist {
            display: grid;
            gap: 0.45rem;
            margin: 0;
            padding: 0.72rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.82rem;
            background: rgb(var(--bk-surface-rgb));
        }

        .review-checklist-item {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            font-size: 0.82rem;
            color: rgb(var(--bk-text-rgb));
        }

        .review-checklist-item input {
            margin-top: 0.16rem;
            accent-color: rgb(var(--bk-primary-rgb));
        }

        .manual-verification-panel {
            display: grid;
            gap: 0.72rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.9rem;
            background: rgb(var(--bk-surface-rgb));
            padding: 0.85rem;
        }

        .manual-verification-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 0.7rem;
        }

        .manual-verification-panel .ui-label {
            font-size: 0.78rem;
        }

        .manual-help {
            font-size: 0.77rem;
            color: rgb(var(--bk-muted-rgb));
        }

        .label-row {
            display: flex;
            align-items: center;
            gap: 0.36rem;
            flex-wrap: wrap;
        }

        .field-info-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
        }

        .field-info-btn {
            width: 1.18rem;
            height: 1.18rem;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.34);
            background: rgba(var(--bk-primary-rgb), 0.1);
            color: rgb(var(--bk-primary-rgb));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            line-height: 1;
            cursor: pointer;
            transition: background-color 0.16s ease, border-color 0.16s ease;
        }

        .field-info-btn:hover,
        .field-info-btn:focus-visible {
            background: rgba(var(--bk-primary-rgb), 0.18);
            border-color: rgba(var(--bk-primary-rgb), 0.5);
            outline: none;
        }

        .field-info-tip {
            position: absolute;
            right: 0;
            top: calc(100% + 0.32rem);
            z-index: 20;
            min-width: 15rem;
            max-width: 21rem;
            padding: 0.5rem 0.58rem;
            border-radius: 0.58rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgb(var(--bk-surface-rgb));
            color: rgb(var(--bk-text-rgb));
            box-shadow: var(--shadow-soft);
            font-size: 0.74rem;
            line-height: 1.42;
            display: none;
        }

        .field-info-wrap.is-open .field-info-tip {
            display: block;
        }

        .action-summary {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgb(var(--bk-surface-rgb));
            color: rgb(var(--bk-text-rgb));
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.35rem 0.7rem;
        }

        body.bk-role-finance .modal-backdrop.show,
        .review-modal .modal-backdrop.show,
        .modal-backdrop.show {
            z-index: 1290 !important;
            opacity: 0.72 !important;
            background-color: rgba(12, 22, 39, 0.72) !important;
        }

        .review-modal {
            z-index: 1300 !important;
            overflow-y: auto !important;
        }

        .review-modal .modal-dialog {
            margin: 1rem auto;
            max-width: min(1600px, calc(100vw - 1.25rem));
            min-height: 0 !important;
        }

        .review-modal .modal-dialog-scrollable {
            height: auto !important;
            max-height: none !important;
        }

        @media (max-width: 960px) {
            .review-modal .modal-body {
                overflow-y: auto !important;
            }

            .review-workspace {
                grid-template-columns: 1fr;
                height: auto;
                max-height: none;
                overflow: visible;
            }

            .claim-detail-panel,
            .review-sidebar {
                height: auto;
                max-height: none;
                overflow: visible;
                padding-right: 0;
            }

            .review-sidebar {
                position: static;
            }

            .review-sidebar-card,
            .review-action-card {
                max-height: none;
                overflow: visible;
            }
        }

        @media (max-width: 767.98px) {
            .review-modal .modal-dialog {
                margin: 0.5rem;
                max-width: calc(100vw - 1rem);
                min-height: 0 !important;
            }

            .review-modal .modal-dialog-scrollable {
                height: auto !important;
                max-height: none !important;
            }

            .field-info-tip {
                right: auto;
                left: 0;
                max-width: min(88vw, 21rem);
            }
        }
    </style>
</head>
<body class="bk-role-page bk-role-finance">
    <!-- Navigation -->
    <?php include 'navbar.php'; ?>
    <main class="main-content claims-wrapper">
    <section class="claims-hero">
        <div class="claims-page-header mb-0">
            <div>
                <h2 class="fw-bold mb-2">Claims Review</h2>
                <p class="text-muted mb-0">Review, comment, and process claims assigned to your finance account.</p>
            </div>
            <div class="claims-tools">
                <a href="export_report.php?<?php echo bk_e(http_build_query($_GET)); ?>" target="_blank" rel="noopener" class="ui-btn ui-btn-sm ui-btn-secondary">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                </a>
            </div>
        </div>
    </section>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="mt-4">
            <?php render_alert((string) $_SESSION['success'], ['type' => 'success', 'dismissible' => true]); ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="mt-4">
            <?php render_alert((string) $_SESSION['error'], ['type' => 'danger', 'dismissible' => true]); ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="claims-content">
        <!-- Filters -->
        <div class="filter-card p-3 mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label mb-1">Search</label>
                    <input type="text" 
                           class="ui-input" 
                           name="search" 
                           placeholder="ID, Name, or destination detail"
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label mb-1">Status</label>
                    <select class="ui-select" name="status">
                        <option value="all">All Status</option>
                        <option value="Pending Finance Review" <?php echo ($_GET['status'] ?? '') === 'Pending Finance Review' ? 'selected' : ''; ?>>Pending Finance Review</option>
                        <option value="Returned by Finance" <?php echo ($_GET['status'] ?? '') === 'Returned by Finance' ? 'selected' : ''; ?>>Returned by Finance</option>
                        <option value="Closed" <?php echo ($_GET['status'] ?? '') === 'Closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label mb-1">From Date</label>
                    <input type="date" 
                           class="ui-input" 
                           name="date_from" 
                           value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label mb-1">To Date</label>
                    <input type="date" 
                           class="ui-input" 
                           name="date_to" 
                           value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-primary">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <div class="flex-grow-1 text-end">
                        <span class="badge bg-white text-dark border">
                            <?php echo $totalRows; ?> claims
                        </span>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Status Filter Quick Links -->
        <?php
        $currentStatus = (string) ($_GET['status'] ?? '');
        $statusBaseParams = $_GET;
        unset($statusBaseParams['page']);
        $financeStatusUrl = static function (string $status) use ($statusBaseParams): string {
            $params = $statusBaseParams;
            if ($status === '') {
                unset($params['status']);
            } else {
                $params['status'] = $status;
            }
            return '?' . http_build_query($params);
        };
        ?>
        <div class="status-shortcuts mb-3 d-flex flex-wrap gap-2">
            <a href="<?php echo bk_e($financeStatusUrl('')); ?>" class="<?php echo $currentStatus === '' ? 'is-active' : ''; ?>">
                All <span class="badge bg-white text-dark border"><?php echo array_sum($statusStats); ?></span>
            </a>
            <a href="<?php echo bk_e($financeStatusUrl('Pending Finance Review')); ?>" class="<?php echo $currentStatus === 'Pending Finance Review' ? 'is-active' : ''; ?>">
                Pending Review <span class="badge bg-white text-dark border"><?php echo $statusStats['Pending Finance Review'] ?? 0; ?></span>
            </a>
            <a href="<?php echo bk_e($financeStatusUrl('Returned by Finance')); ?>" class="<?php echo $currentStatus === 'Returned by Finance' ? 'is-active' : ''; ?>">
                Returned <span class="badge bg-white text-dark border"><?php echo $statusStats['Returned by Finance'] ?? 0; ?></span>
            </a>
            <a href="<?php echo bk_e($financeStatusUrl('Closed')); ?>" class="<?php echo $currentStatus === 'Closed' ? 'is-active' : ''; ?>">
                Closed <span class="badge bg-white text-dark border"><?php echo $statusStats['Closed'] ?? 0; ?></span>
            </a>
        </div>
        
        <!-- Claims Table -->
        <div class="table-responsive table-shell bk-table-shell">
            <table class="table table-hover dash-table mb-0" data-udcs-expand-group data-udcs-expand-single="true">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3 is-case"><span class="table-entity-label"><i class="bi bi-folder2-open"></i>Case</span></th>
                        <th class="is-assets"><span class="table-entity-label"><i class="bi bi-bank"></i>BK Assets</span></th>
                        <th class="is-verification"><span class="table-entity-label"><i class="bi bi-shield-check"></i>Verification</span></th>
                        <th class="is-status"><span class="table-entity-label"><i class="bi bi-check2-circle"></i>Status</span></th>
                        <th class="is-date"><span class="table-entity-label"><i class="bi bi-calendar2-week"></i>Date</span></th>
                        <th class="text-end pe-3 is-actions"><span class="table-entity-label"><i class="bi bi-sliders"></i>Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($financeQueueRows) > 0): ?>
                        <?php foreach ($financeQueueRows as $queueItem): ?>
                            <?php
                            $row = $queueItem['row'];
                            $contract = $queueItem['contract'];
                            $peopleSummary = (array) ($contract['people']['summary'] ?? []);
                            $assetSummary = (array) ($contract['assets']['summary'] ?? []);
                            $documentSummary = (array) ($contract['documents']['summary'] ?? []);
                            $reviewSummary = (array) ($contract['review'] ?? []);
                            $payoutSummary = (array) ($contract['payout'] ?? []);
                            $statusLabel = (string) ($contract['status']['label'] ?? finance_claim_status_label((string) ($row['effective_status'] ?? '')));
                            $statusClass = finance_claim_status_class($statusLabel);
                            $statusKey = (string) ($contract['status']['key'] ?? finance_claim_status_key($statusLabel));
                            $manualMeta = finance_manual_snapshot_meta((string) ($row['comment'] ?? ''));
                            $manualIsLogged = (bool) ($manualMeta['logged'] ?? false);
                            $manualCoreRef = trim((string) ($manualMeta['core_ref'] ?? ''));
                            $manualSource = trim((string) ($manualMeta['verify_source'] ?? ''));
                            $manualCheckedOn = trim((string) ($manualMeta['checked_on'] ?? ''));
                            $manualDecision = trim((string) ($manualMeta['decision'] ?? ''));
                            $claimantDisplayName = (string) (($peopleSummary['claimant_name'] ?? '') !== '' ? $peopleSummary['claimant_name'] : ($row['full_name'] ?? 'Unknown claimant'));
                            $deceasedDisplayName = (string) (($peopleSummary['deceased_name'] ?? '') !== '' ? $peopleSummary['deceased_name'] : ($row['deceased_full_name'] ?? $row['deceased_name'] ?? 'Deceased person not named'));
                            $relationshipLabel = udcs_claim_relationship_label((string) ($row['relationship'] ?? ''));
                            $assetCount = (int) ($assetSummary['count'] ?? 0);
                            $assetStateLabel = finance_claim_asset_state_label($assetSummary);
                            $estimatedLabel = (string) (($assetSummary['estimated_total_label'] ?? '') !== ''
                                ? $assetSummary['estimated_total_label']
                                : ($contract['summary']['claimant_value_label'] ?? 'Not declared'));
                            $verifiedLabel = (string) (($assetSummary['verified_total_label'] ?? '') !== ''
                                ? $assetSummary['verified_total_label']
                                : ($contract['summary']['finance_value_label'] ?? 'Not assessed yet'));
                            $payoutLabel = (string) ($payoutSummary['preferred_label'] ?? 'Not selected');
                            $destinationComplete = (bool) ($payoutSummary['destination_complete'] ?? false);
                            $destinationSummary = bk_claim_destination_summary(
                                bk_claim_account_reference($row),
                                (string) ($row['distribution_method'] ?? ''),
                                (string) ($row['distribution_details'] ?? '')
                            );
                            $reviewedCount = (int) ($assetSummary['reviewed_count'] ?? 0);
                            $confirmedCount = (int) ($assetSummary['confirmed_count'] ?? 0);
                            $holdCount = (int) ($assetSummary['hold_count'] ?? 0);
                            $missingCount = (int) ($assetSummary['missing_count'] ?? 0);
                            $manualFollowUpCount = (int) ($assetSummary['manual_follow_up_count'] ?? 0);
                            $docCount = (int) ($documentSummary['count'] ?? 0);
                            $ocrPassed = (int) ($documentSummary['ocr_passed_count'] ?? 0);
                            $financeFlags = [];
                            $criticalFlags = 0;
                            $warningFlags = 0;
                            foreach ((array) ($reviewSummary['flags'] ?? []) as $flag) {
                                $key = (string) ($flag['key'] ?? '');
                                $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
                                if ($severity === 'danger') {
                                    $criticalFlags++;
                                } elseif ($severity === 'warning') {
                                    $warningFlags++;
                                }
                                if ($severity === 'danger' || in_array($key, [
                                    'asset_holds',
                                    'asset_manual_follow_up',
                                    'asset_not_found',
                                    'payout_destination_incomplete',
                                    'no_assets_declared',
                                    'ocr_failures',
                                    'document_rejections',
                                ], true)) {
                                    $financeFlags[] = $flag;
                                }
                            }
                            $financeFlagSummary = !empty($financeFlags)
                                ? implode(' | ', array_map(
                                    static fn(array $flag): string => trim((string) ($flag['label'] ?? 'Finance review signal')),
                                    $financeFlags
                                ))
                                : 'No finance blockers detected.';
                            $financeFlagCount = count($financeFlags);
                            $financeFlags = array_slice($financeFlags, 0, 1);
                            $topFinanceFlag = !empty($financeFlags) ? $financeFlags[0] : null;
                            $submittedDate = (string) (($row['submitted_date'] ?? '') !== '' ? $row['submitted_date'] : 'Not recorded');
                            $statusSupportLabel = $payoutLabel . ' | ' . ($destinationComplete ? 'Destination captured' : 'Destination needs clarification');
                            $expandPanelId = 'finance-claim-expand-' . (int) ($row['id'] ?? 0);
                            ?>
                            <tr>
                                <td class="ps-3 is-case">
                                    <div class="finance-case-stack">
                                        <div class="finance-case-title">
                                            <span class="claim-id">CL-<?php echo str_pad((string) ($row['id'] ?? 0), 6, '0', STR_PAD_LEFT); ?></span>
                                            <?php if (!empty($contract['status']['is_legacy'])): ?>
                                                <span class="manual-check-pill is-missing">Legacy</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="finance-case-path"><?php echo bk_e($relationshipLabel); ?></div>
                                        <div class="finance-meta">
                                            <span><strong><?php echo bk_e($claimantDisplayName); ?></strong></span>
                                            <span><?php echo bk_e((string) ($row['email'] ?? '')); ?></span>
                                            <span>Deceased: <?php echo bk_e($deceasedDisplayName); ?></span>
                                        </div>
                                    </div>
                                </td>

                                <td class="is-assets">
                                    <div class="finance-asset-title"><?php echo bk_e((string) ($assetSummary['label'] ?? 'No BK assets linked')); ?></div>
                                    <div class="finance-kv-list">
                                        <div class="finance-kv-line"><strong>Assets</strong><span><?php echo number_format($assetCount); ?></span></div>
                                        <div class="finance-kv-line"><strong>Estimate</strong><span><?php echo bk_e($estimatedLabel); ?></span></div>
                                        <div class="finance-kv-line"><strong>Verified</strong><span><?php echo bk_e($verifiedLabel); ?></span></div>
                                    </div>
                                </td>

                                <td class="is-verification">
                                    <div class="finance-signal-stack">
                                        <span class="manual-check-pill <?php echo ($holdCount > 0 || $missingCount > 0) ? 'is-missing' : ($manualFollowUpCount > 0 || $reviewedCount < $assetCount ? 'is-missing' : 'is-ready'); ?>">
                                            <?php echo number_format($criticalFlags); ?> critical / <?php echo number_format($warningFlags); ?> warning
                                        </span>
                                        <span class="finance-signal is-compact <?php echo ($holdCount > 0 || $missingCount > 0) ? 'is-danger' : ($manualFollowUpCount > 0 || $reviewedCount < $assetCount ? 'is-warning' : 'is-ok'); ?>">
                                            <i class="bi <?php echo ($holdCount > 0 || $missingCount > 0) ? 'bi-x-octagon-fill' : ($manualFollowUpCount > 0 || $reviewedCount < $assetCount ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'); ?>"></i>
                                            <?php echo bk_e($assetStateLabel); ?>
                                        </span>
                                        <?php if ($topFinanceFlag !== null): ?>
                                            <?php $signalClass = finance_claim_review_signal_class((string) ($topFinanceFlag['severity'] ?? '')); ?>
                                            <span class="finance-signal is-compact <?php echo bk_e($signalClass); ?>">
                                                <i class="bi <?php echo $signalClass === 'is-danger' ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill'; ?>"></i>
                                                <?php echo bk_e((string) ($topFinanceFlag['label'] ?? 'Finance review signal')); ?>
                                            </span>
                                            <?php if ($financeFlagCount > 1): ?>
                                                <div class="finance-hidden-hint">Open claim to view <?php echo number_format($financeFlagCount - 1); ?> more signal(s).</div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="is-status">
                                    <div class="finance-status-stack">
                                        <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                            <?php echo htmlspecialchars($statusLabel); ?>
                                        </span>
                                        <div class="finance-status-note"><?php echo bk_e($statusSupportLabel); ?></div>
                                        <small class="manual-check-meta"><?php echo $manualIsLogged ? 'Verification record logged' : 'Verification record pending'; ?></small>
                                    </div>
                                </td>

                                <td class="is-date">
                                    <div class="subtle"><?php echo bk_e($submittedDate); ?></div>
                                </td>

                                <td class="text-end pe-3 is-actions">
                                    <div class="actions">
                                        <?php udcs_claims_list_render_expand_button($expandPanelId, ['label' => 'More']); ?>
                                        <button type="button"
                                                class="ui-btn ui-btn-sm ui-btn-secondary action-btn is-view"
                                                onclick='openReviewModal(
                                                    <?php echo (int) $row["id"]; ?>,
                                                    <?php echo json_encode($statusKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                                                    <?php echo json_encode((string) ($row["finance_assessed_amount"] ?? ""), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                                                    <?php echo json_encode(bk_currency_code((string) ($row["finance_assessed_currency_code"] ?? "RWF")), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
                                                    <?php echo json_encode([
                                                        "core_ref" => (string) ($manualMeta["core_ref"] ?? ""),
                                                        "verify_source" => (string) ($manualMeta["verify_source"] ?? ""),
                                                        "branch_ref" => (string) ($manualMeta["branch_ref"] ?? ""),
                                                        "checked_on" => (string) ($manualMeta["checked_on"] ?? ""),
                                                        "decision" => (string) ($manualMeta["decision"] ?? ""),
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
                                                )'>
                                            <i class="bi bi-eye"></i>
                                            <span>Open</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php
                            udcs_claims_list_render_expand_row($expandPanelId, 6, [
                                [
                                    'title' => 'Case Context',
                                    'lines' => [
                                        ['label' => 'Claimant', 'value' => $claimantDisplayName],
                                        ['label' => 'Deceased', 'value' => $deceasedDisplayName],
                                        ['label' => 'Date', 'value' => $submittedDate],
                                        ['label' => 'Status', 'value' => $statusLabel],
                                    ],
                                ],
                                [
                                    'title' => 'Asset Verification',
                                    'lines' => [
                                        ['label' => 'BK assets', 'value' => (string) ($assetSummary['label'] ?? 'No BK assets linked')],
                                        ['label' => 'Claimant estimate', 'value' => $estimatedLabel],
                                        ['label' => 'Verified total', 'value' => $verifiedLabel],
                                        ['label' => 'Reviewed / confirmed', 'value' => number_format($reviewedCount) . ' reviewed | ' . number_format($confirmedCount) . ' confirmed'],
                                    ],
                                ],
                                [
                                    'title' => 'Settlement Readiness',
                                    'lines' => [
                                        ['label' => 'Preferred settlement', 'value' => $payoutLabel],
                                        ['label' => 'Destination summary', 'value' => $destinationSummary],
                                        ['label' => 'Destination state', 'value' => $destinationComplete ? 'Captured' : 'Needs clarification'],
                                        ['label' => 'Verification record', 'value' => $manualIsLogged ? ($manualCoreRef !== '' ? 'Logged | Ref: ' . $manualCoreRef : 'Logged') : 'Pending'],
                                    ],
                                ],
                                [
                                    'title' => 'Signals and Documents',
                                    'lines' => [
                                        ['label' => 'Finance signals', 'value' => $financeFlagSummary],
                                        ['label' => 'OCR passed', 'value' => number_format($ocrPassed) . '/' . number_format($docCount)],
                                        ['label' => 'Holds / missing', 'value' => number_format($holdCount) . ' hold | ' . number_format($missingCount) . ' missing'],
                                        ['label' => 'Manual follow-up', 'value' => number_format($manualFollowUpCount)],
                                    ],
                                ],
                            ]);
                            ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No claims found for your current filters.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="mt-4">
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($_GET); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query($_GET); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($_GET); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="text-center mt-1">
                    <small class="text-muted">
                        Page <?php echo $page; ?> of <?php echo $totalPages; ?> 
                        (Showing <?php echo min($limit, $totalRows - $offset); ?> of <?php echo $totalRows; ?> claims)
                    </small>
                </div>
            </nav>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Review Modal -->
    <div class="modal fade review-modal" id="reviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl-custom modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content review-modal-content">
                <form method="POST" id="reviewForm">
                    <input type="hidden" name="claim_id" id="modalClaimId">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($financeClaimsCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" id="modalActionInput" value="comment">
                    <input type="hidden" name="finance_manual_snapshot" id="financeManualSnapshot" value="">

                    <div class="modal-body">
                        <div class="review-workspace">
                            <div id="claimDetails" class="claim-detail-panel">
                                <div class="text-muted">Loading claim details...</div>
                            </div>

                            <aside class="review-sidebar">
                                <section class="review-sidebar-card">
                                    <div class="review-sidebar-head">
                                        <h6 class="review-sidebar-title">Review History</h6>
                                        <p class="review-sidebar-note">Keep previous legal and finance context visible while you verify BK records and record the settlement outcome.</p>
                                    </div>
                                    <div id="commentsSection" class="comments-box">
                                        <p class="text-muted mb-0">Loading comments...</p>
                                    </div>
                                </section>

                                <section class="review-action-card" id="takeActionSection">
                                    <div class="review-action-grid">
                                        <h6 class="fw-semibold mb-0">Take Action</h6>

                                <div class="ui-field">
                                    <div class="label-row">
                                        <label class="ui-label mb-0" for="modalActionSelect">Decision</label>
                                        <span class="field-info-wrap">
                                            <button type="button" class="field-info-btn" aria-label="What this field means">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                            <span class="field-info-tip">Choose whether to save a note, return the claim for clarification, or record final finance disbursement and closure.</span>
                                        </span>
                                    </div>
                                    <select id="modalActionSelect" class="ui-select">
                                        <option value="comment">Save Review Note (No Final Decision)</option>
                                        <option value="approve">Record Disbursement & Close</option>
                                        <option value="reject">Return for Clarification</option>
                                    </select>
                                    <p id="actionGuide" class="text-muted mb-0 small">Save your note without giving final approval or rejection.</p>
                                </div>

                                <div id="returnRouteBlock" class="ui-field" style="display:none;">
                                    <div class="label-row">
                                        <label class="ui-label mb-0" for="financeReturnRoute">Send clarification to</label>
                                        <span class="field-info-wrap">
                                            <button type="button" class="field-info-btn" aria-label="Where a returned finance claim should go">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                            <span class="field-info-tip">Choose whether the claimant must update payout/supporting details directly, or whether the claim should go back to Legal for another controlled review step.</span>
                                        </span>
                                    </div>
                                    <select id="financeReturnRoute" name="finance_return_route" class="ui-select">
                                        <option value="claimant">Return to Claimant for Update</option>
                                        <option value="legal">Return to Legal for Re-Review</option>
                                    </select>
                                    <p id="returnRouteHint" class="text-muted mb-0 small">Use claimant return when settlement details or supporting uploads need correction. Use legal return when the clarification belongs in the legal review path.</p>
                                </div>

                                <div id="reviewChecklist" class="review-checklist">
                                    <label class="review-checklist-item">
                                        <input type="checkbox" class="review-gate">
                                        <span>I confirmed every claimed BK asset in BK records and entered its verified reference and value.</span>
                                    </label>
                                    <label class="review-checklist-item">
                                        <input type="checkbox" class="review-gate">
                                        <span>I checked restrictions, holds, and operational flags before settlement.</span>
                                    </label>
                                    <label class="review-checklist-item">
                                        <input type="checkbox" class="review-gate">
                                        <span>I completed the manual settlement in bank operations and kept the internal reference for audit traceability.</span>
                                    </label>
                                </div>

                                <section id="manualVerificationPanel" class="manual-verification-panel">
                                    <h6 class="fw-semibold mb-0" id="manualPanelTitle">Bank-side Verification &amp; Disbursement Record</h6>
                                    <p class="manual-help mb-0" id="manualPanelHelp">
                                        Fill these from the bank-side review and settlement record so the closure can be traced.
                                    </p>
                                    <div class="manual-verification-grid">
                                        <div class="ui-field">
                                            <div class="label-row">
                                                <label class="ui-label mb-0" for="manualProcessingRef">Internal finance reference</label>
                                                <span class="field-info-wrap">
                                                    <button type="button" class="field-info-btn" aria-label="Where to find internal finance reference number">
                                                        <i class="bi bi-info-circle"></i>
                                                    </button>
                                                <span class="field-info-tip">This is generated automatically. Adjust it only if the bank record, worksheet, or settlement note uses a different internal reference.</span>
                                            </span>
                                        </div>
                                            <input id="manualProcessingRef" class="ui-input" type="text" placeholder="Generated automatically. Adjust only if your bank record uses a different reference.">
                                        </div>
                                        <div class="ui-field">
                                            <div class="label-row">
                                                <label class="ui-label mb-0" for="manualVerificationSource">Main source used for bank-side verification</label>
                                                <span class="field-info-wrap">
                                                    <button type="button" class="field-info-btn" aria-label="Where to find verification source">
                                                        <i class="bi bi-info-circle"></i>
                                                    </button>
                                                    <span class="field-info-tip">Select where you confirmed the BK asset, balance, holding, or settlement detail for this review step.</span>
                                                </span>
                                            </div>
                                            <select id="manualVerificationSource" class="ui-select">
                                                <option value="">Select source</option>
                                                <option value="Core banking records">Core banking records</option>
                                            <option value="Branch file/register">Branch file or register</option>
                                                <option value="Core banking + branch file">Both core banking and branch file</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="ui-field">
                                        <div class="label-row">
                                            <label class="ui-label mb-0" for="manualBranchRef">Branch file/register reference (optional)</label>
                                            <span class="field-info-wrap">
                                                <button type="button" class="field-info-btn" aria-label="Where to find branch file reference">
                                                    <i class="bi bi-info-circle"></i>
                                                </button>
                                                <span class="field-info-tip">If the branch gave you a file number, register line, or handover note ID, add it here. Leave blank if not available.</span>
                                            </span>
                                        </div>
                                        <input id="manualBranchRef" class="ui-input" type="text" placeholder="Example: Remera-File-112">
                                    </div>
                                </section>

                                <div class="ui-field">
                                    <div class="label-row">
                                        <label class="ui-label mb-0" for="financeAssessedAmount">Final settlement value</label>
                                        <span class="field-info-wrap">
                                            <button type="button" class="field-info-btn" aria-label="How to fill final amount value">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                            <span class="field-info-tip">Enter the final value and currency Finance is recording after BK verification. For multi-currency claims, use the final settlement currency recorded by the bank.</span>
                                        </span>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <select id="financeAssessedCurrency" class="ui-select" name="finance_assessed_currency_code">
                                                <?php foreach (bk_supported_currency_options() as $currencyCode => $currencyLabel): ?>
                                                    <option value="<?php echo bk_e($currencyCode); ?>"><?php echo bk_e($currencyLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <input
                                                id="financeAssessedAmount"
                                                class="ui-input"
                                                type="text"
                                                name="finance_assessed_amount"
                                                inputmode="decimal"
                                                placeholder="e.g., 1250000"
                                            >
                                        </div>
                                    </div>
                                    <p id="assessedAmountHint" class="text-muted mb-0 small">
                                        Required before closing a claim. RWF must be whole-number; foreign currencies may use cents.
                                    </p>
                                </div>

                                <div class="ui-field">
                                    <div class="label-row">
                                        <label class="ui-label mb-0" for="modalComment" id="modalCommentLabel">Finance note / return reason</label>
                                        <span class="field-info-wrap">
                                            <button type="button" class="field-info-btn" aria-label="How to fill review note">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                            <span class="field-info-tip">Write a clear explanation. If you return the claim, state what is operationally missing or inconsistent.</span>
                                        </span>
                                    </div>
                                    <textarea
                                        id="modalComment"
                                        class="ui-input"
                                        name="comment"
                                        rows="4"
                                        placeholder="Write your review note."
                                    ></textarea>
                                    <p id="commentHint" class="text-muted mb-0 small">Add a concise finance note for audit traceability.</p>
                                </div>

                                        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                            <span id="actionSummary" class="action-summary">
                                                <i class="bi bi-chat-left-text"></i>
                                                Review note only
                                            </span>
                                            <button type="submit" id="modalSubmitBtn" class="ui-btn ui-btn-sm ui-btn-primary">
                                                <i id="modalSubmitIcon" class="bi bi-chat-left-text"></i>
                                                <span id="modalSubmitLabel">Save Review Note</span>
                                            </button>
                                        </div>
                                    </div>
                                </section>
                            </aside>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="ui-btn ui-btn-sm ui-btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </main>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    const reviewModalElement = document.getElementById('reviewModal');
    const reviewModal = new bootstrap.Modal(reviewModalElement);
    const reviewForm = document.getElementById('reviewForm');
    const modalClaimId = document.getElementById('modalClaimId');
    const claimDetailsBox = document.getElementById('claimDetails');
    const commentsSection = document.getElementById('commentsSection');
    const actionSelect = document.getElementById('modalActionSelect');
    const actionInput = document.getElementById('modalActionInput');
    const commentInput = document.getElementById('modalComment');
    const actionGuide = document.getElementById('actionGuide');
    const commentHint = document.getElementById('commentHint');
    const commentLabel = document.getElementById('modalCommentLabel');
    const manualPanelTitle = document.getElementById('manualPanelTitle');
    const manualPanelHelp = document.getElementById('manualPanelHelp');
    const submitBtn = document.getElementById('modalSubmitBtn');
    const submitIcon = document.getElementById('modalSubmitIcon');
    const submitLabel = document.getElementById('modalSubmitLabel');
    const actionSummary = document.getElementById('actionSummary');
    const takeActionSection = document.getElementById('takeActionSection');
    const checklistContainer = document.getElementById('reviewChecklist');
    const manualPanel = document.getElementById('manualVerificationPanel');
    const returnRouteBlock = document.getElementById('returnRouteBlock');
    const returnRouteSelect = document.getElementById('financeReturnRoute');
    const returnRouteHint = document.getElementById('returnRouteHint');
    const manualSnapshotInput = document.getElementById('financeManualSnapshot');
    const manualProcessingRefInput = document.getElementById('manualProcessingRef');
    const manualVerificationSourceInput = document.getElementById('manualVerificationSource');
    const manualBranchRefInput = document.getElementById('manualBranchRef');
    const financeAssessedAmountInput = document.getElementById('financeAssessedAmount');
    const financeAssessedCurrencyInput = document.getElementById('financeAssessedCurrency');
    const assessedAmountHint = document.getElementById('assessedAmountHint');
    const reviewChecks = Array.from(document.querySelectorAll('.review-gate'));
    const infoWraps = Array.from(document.querySelectorAll('.field-info-wrap'));
    const currencyDecimals = <?php
        echo json_encode(array_map(static fn ($meta) => (int) ($meta['decimals'] ?? 2), bk_supported_currencies()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>;
    const FINAL_STATUSES = new Set(['closed', 'approved for disbursement', 'disbursed', 'approved by finance']);
    let isFinalDecisionLocked = false;
    let assetReviewInputs = [];

    const ACTION_CONFIG = {
        comment: {
            submit: 'Save Review Note',
            icon: 'bi-chat-left-text',
            summary: 'Review note only',
            guide: 'Save your note without final approval or rejection.',
            hint: 'Write a short, clear note in plain language.',
            commentLabel: 'Finance note (optional)',
            manualTitle: 'Finance Context',
            manualHelp: 'Optional internal context. Use it only if you want to capture where your review note came from.',
            requireChecklist: false,
            requireManualRecord: false,
            showManualPanel: false,
            requireReason: false,
            requireAssessedAmount: false,
            buttonClass: 'ui-btn-primary',
            placeholder: 'Write your review note.'
        },
        approve: {
            submit: 'Record Disbursement & Close',
            icon: 'bi-check-circle',
            summary: 'Final step: disburse and close',
            guide: 'This records that Finance confirmed the BK assets, manually executed settlement in bank operations, and closed the claim.',
            hint: 'Complete asset verification, bank-side checks, and settlement reference before closing.',
            commentLabel: 'Finance note (optional)',
            manualTitle: 'Bank-Side Verification & Disbursement Record',
            manualHelp: 'Capture the internal finance trace for the bank-side verification and completed settlement.',
            requireChecklist: true,
            requireManualRecord: true,
            showManualPanel: true,
            requireReason: false,
            requireAssessedAmount: true,
            buttonClass: 'ui-btn-primary',
            placeholder: 'Add optional settlement note for audit trail.'
        },
        reject: {
            submit: 'Return Claim',
            icon: 'bi-arrow-counterclockwise',
            summary: 'Return for clarification',
            guide: 'This returns the claim because Finance cannot complete bank-side verification or settlement yet.',
            hint: 'Explain exactly what is operationally missing, inconsistent, held, or not found.',
            commentLabel: 'Return reason',
            manualTitle: 'Finance Clarification Context',
            manualHelp: 'Use these fields only if you want to record where Finance found the issue. They are optional for a return decision.',
            requireChecklist: false,
            requireManualRecord: false,
            showManualPanel: true,
            requireReason: true,
            requireAssessedAmount: false,
            buttonClass: 'ui-btn-secondary text-bk-danger border-bk-danger/30',
            placeholder: 'State exactly why Finance is returning this claim.'
        }
    };

    function isChecklistComplete() {
        return reviewChecks.every((box) => box.checked);
    }

    function buildDefaultProcessingReference(claimId) {
        const numericId = String(claimId || '').replace(/\D+/g, '');
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        const paddedId = String(numericId || '0').padStart(6, '0');
        return `FIN-${yyyy}${mm}${dd}-CL${paddedId}`;
    }

    function closeInfoTips(exceptWrap = null) {
        infoWraps.forEach((wrap) => {
            if (exceptWrap && wrap === exceptWrap) {
                return;
            }
            wrap.classList.remove('is-open');
            const btn = wrap.querySelector('.field-info-btn');
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function isManualRecordComplete() {
        const processingRef = manualProcessingRefInput.value.trim();
        const verificationSource = manualVerificationSourceInput.value.trim();
        return processingRef.length >= 4 && verificationSource !== '';
    }

    function hasManualRecordData() {
        return [
            manualProcessingRefInput.value.trim(),
            manualVerificationSourceInput.value.trim(),
            manualBranchRefInput.value.trim(),
        ].some((value) => value !== '');
    }

    function normalizeAmountInput(raw) {
        return String(raw || '').replace(/,/g, '').trim();
    }

    function currencyDecimalPlaces(currencyCode) {
        const code = String(currencyCode || 'RWF').trim().toUpperCase();
        return Number.isInteger(currencyDecimals?.[code]) ? currencyDecimals[code] : 2;
    }

    function isValidAmountForCurrency(raw, currencyCode, allowZero = false) {
        const normalized = normalizeAmountInput(raw);
        if (normalized === '') {
            return false;
        }
        const decimals = currencyDecimalPlaces(currencyCode);
        const pattern = decimals === 0
            ? /^\d+(\.0+)?$/
            : new RegExp(`^\\d+(\\.\\d{1,${decimals}})?$`);
        if (!pattern.test(normalized)) {
            return false;
        }
        const numeric = Number(normalized);
        return Number.isFinite(numeric) && (allowZero ? numeric >= 0 : numeric > 0);
    }

    function parseAssessedAmount() {
        const normalized = normalizeAmountInput(financeAssessedAmountInput.value);
        if (normalized === '') {
            return null;
        }

        const currency = String(financeAssessedCurrencyInput?.value || 'RWF').trim().toUpperCase();
        if (!isValidAmountForCurrency(normalized, currency, false)) {
            return null;
        }

        const numeric = Number(normalized);
        if (!Number.isFinite(numeric) || numeric <= 0) {
            return null;
        }

        return numeric;
    }

    function isAssessedAmountValid() {
        return parseAssessedAmount() !== null;
    }

    function findFormFieldByName(name) {
        return Array.from(reviewForm.elements).find((field) => field.name === name) || null;
    }

    function syncAssetReviewRow(statusField) {
        if (!statusField) {
            return;
        }

        const prefix = statusField.name.replace('[finance_status]', '');
        const referenceField = findFormFieldByName(`${prefix}[account_reference]`);
        const valueField = findFormFieldByName(`${prefix}[verified_value]`);
        const currencyField = findFormFieldByName(`${prefix}[currency_code]`);
        const currencyCode = String(currencyField?.value || 'RWF').trim().toUpperCase();
        const normalizedStatus = String(statusField.value || '').trim();
        const statusKey = normalizedStatus.toLowerCase();

        if (!referenceField || !valueField) {
            return;
        }

        const isConfirmed = statusKey === 'confirmed in bk records' || statusKey === 'asset confirmed in bk records';
        const isNoMatch = statusKey === 'no matching bk asset found' || statusKey === 'no matching bk asset located';
        const isRestriction = statusKey === 'restriction or hold found' || statusKey === 'asset found with restriction or hold';
        const isManualFollowUp = statusKey === 'manual follow-up required' || statusKey === 'needs manual follow-up';

        const enableReference = normalizedStatus !== '' && !isNoMatch;
        const enableValue = isConfirmed;

        referenceField.disabled = !enableReference;
        valueField.disabled = !enableValue;

        if (!enableReference) {
            referenceField.value = '';
        }
        if (!enableValue && !isRestriction) {
            valueField.value = '';
        }

        if (isConfirmed) {
            referenceField.placeholder = 'Enter confirmed BK account or product reference';
            valueField.placeholder = currencyDecimalPlaces(currencyCode) === 0
                ? `Enter whole ${currencyCode} value from BK records`
                : `Enter ${currencyCode} value from BK records`;
        } else if (isRestriction) {
            referenceField.placeholder = 'Enter the BK reference that is under hold/restriction';
            valueField.placeholder = 'Value not needed until the restriction is cleared';
        } else if (isManualFollowUp) {
            referenceField.placeholder = 'Optional reference if already known';
            valueField.placeholder = 'Value waits until follow-up is complete';
        } else if (isNoMatch) {
            referenceField.placeholder = 'No BK reference available';
            valueField.placeholder = 'No confirmed value available';
        } else {
            referenceField.placeholder = 'Enter the confirmed BK account or product reference';
            valueField.placeholder = 'Enter confirmed value';
        }
    }

    function syncAllAssetReviewRows() {
        const statusFields = Array.from(reviewForm.querySelectorAll('[data-finance-review="status"]'));
        statusFields.forEach(syncAssetReviewRow);
    }

    function getAssetReviewRows() {
        const statusFields = Array.from(reviewForm.querySelectorAll('[name^="asset_reviews"][name$="[finance_status]"]'));
        return statusFields.map((statusField, index) => {
            const prefix = statusField.name.replace('[finance_status]', '');
            const referenceField = findFormFieldByName(`${prefix}[account_reference]`);
            const valueField = findFormFieldByName(`${prefix}[verified_value]`);
            const currencyField = findFormFieldByName(`${prefix}[currency_code]`);

            return {
                index: index + 1,
                status: String(statusField.value || '').trim(),
                reference: String(referenceField ? referenceField.value : '').trim(),
                verifiedValue: normalizeAmountInput(valueField ? valueField.value : ''),
                currency: String(currencyField ? currencyField.value : 'RWF').trim().toUpperCase(),
            };
        });
    }

    function getAssetApprovalErrors() {
        const rows = getAssetReviewRows();
        if (rows.length === 0) {
            return ['No structured BK asset rows are available for finance approval.'];
        }

        const errors = [];
        rows.forEach((row) => {
            if (row.status !== 'Confirmed in BK records') {
                errors.push(`Asset ${row.index}: choose "Asset confirmed in BK records" before closing.`);
            }
            if (row.reference === '') {
                errors.push(`Asset ${row.index}: enter the BK account/product reference.`);
            }
            if (!isValidAmountForCurrency(row.verifiedValue, row.currency, true)) {
                const decimals = currencyDecimalPlaces(row.currency);
                errors.push(decimals === 0
                    ? `Asset ${row.index}: enter a whole ${row.currency} verified value.`
                    : `Asset ${row.index}: enter a valid ${row.currency} verified value.`);
            }
        });

        return errors;
    }

    function buildManualSnapshot(action) {
        const checklistLabel = reviewChecks.map((checkbox, index) => {
            const labels = [
                'Every BK asset confirmed with reference and verified value',
                'Restrictions, holds, and operational flags checked',
                'Manual bank-side settlement executed and reference recorded',
            ];
            return `${labels[index] || `Checklist item ${index + 1}`}: ${checkbox.checked ? 'YES' : 'NO'}`;
        });

        const lines = [
            `Decision action: ${String(action || 'comment').toUpperCase()}`,
            ...checklistLabel,
        ];

        const processingRef = manualProcessingRefInput.value.trim();
        const verificationSource = manualVerificationSourceInput.value.trim();
        const branchRef = manualBranchRefInput.value.trim();
        const assessedValue = parseAssessedAmount();
        const assessedCurrency = String(financeAssessedCurrencyInput?.value || 'RWF').trim().toUpperCase();
        const returnRoute = String(returnRouteSelect?.value || 'claimant').trim();

        lines.push(`Finance processing reference: ${processingRef !== '' ? processingRef : 'Not provided'}`);
        lines.push(`Verification source: ${verificationSource !== '' ? verificationSource : 'Not provided'}`);
        lines.push(`Branch handover reference: ${branchRef !== '' ? branchRef : 'Not provided'}`);
        lines.push(`Final settlement value: ${assessedValue !== null ? `${assessedCurrency} ${assessedValue.toLocaleString(undefined, { minimumFractionDigits: currencyDecimalPlaces(assessedCurrency), maximumFractionDigits: currencyDecimalPlaces(assessedCurrency) })}` : 'Not provided'}`);
        if (action === 'reject') {
            lines.push(`Clarification route: ${returnRoute === 'legal' ? 'Returned to Legal for re-review' : 'Returned to claimant for update'}`);
        }

        return lines.join('\n');
    }

    function applyStatusConstraints(statusKey) {
        const normalized = String(statusKey || '')
            .toLowerCase()
            .replace(/_/g, ' ')
            .trim();
        isFinalDecisionLocked = FINAL_STATUSES.has(normalized);

        Array.from(actionSelect.options).forEach((option) => {
            if (option.value === 'approve' || option.value === 'reject') {
                option.disabled = isFinalDecisionLocked;
            }
        });

        if (isFinalDecisionLocked) {
            actionSelect.value = 'comment';
        }
    }

    function syncActionState() {
        const action = actionSelect.value in ACTION_CONFIG ? actionSelect.value : 'comment';
        const config = ACTION_CONFIG[action];
        actionInput.value = action;

        actionGuide.textContent = config.guide;
        commentHint.textContent = config.hint;
        if (commentLabel) {
            commentLabel.textContent = config.commentLabel || 'Finance note';
        }
        if (manualPanelTitle) {
            manualPanelTitle.textContent = config.manualTitle || 'Finance Context';
        }
        if (manualPanelHelp) {
            manualPanelHelp.textContent = config.manualHelp || 'Use this area only when extra internal context needs to be captured.';
        }
        commentInput.placeholder = config.placeholder;
        actionSummary.innerHTML = `<i class="bi ${config.icon}"></i>${config.summary}`;
        if (assessedAmountHint) {
            assessedAmountHint.textContent = config.requireAssessedAmount
                ? 'Required before closure. This exact value is sent to the claimant in the approval email.'
                : 'Optional for notes/returns. Leave blank if no final value is being recorded yet.';
        }

        submitLabel.textContent = config.submit;
        submitIcon.className = `bi ${config.icon}`;
        submitBtn.className = `ui-btn ui-btn-sm ${config.buttonClass}`;

        if (isFinalDecisionLocked) {
            takeActionSection.style.display = 'none';
            checklistContainer.style.display = 'none';
            manualPanel.style.display = 'none';
            if (returnRouteBlock) {
                returnRouteBlock.style.display = 'none';
            }
            actionGuide.textContent = 'This claim already has a final finance decision. You can only add an additional note.';
            commentHint.textContent = 'Add a short clarification note if needed.';
            actionSummary.innerHTML = '<i class="bi bi-lock"></i>Final finance decision already recorded';
            submitLabel.textContent = 'Add Follow-up Note';
            submitIcon.className = 'bi bi-chat-left-text';
            submitBtn.className = 'ui-btn ui-btn-sm ui-btn-secondary';
            if (assessedAmountHint) {
                assessedAmountHint.textContent = 'This claim already has a final finance decision. You can still update value only for correction.';
            }
            submitBtn.disabled = commentInput.value.trim() === '';
            return;
        }

        takeActionSection.style.display = 'block';
        checklistContainer.style.display = config.requireChecklist ? 'grid' : 'none';
        manualPanel.style.display = config.showManualPanel ? 'grid' : 'none';
        if (returnRouteBlock && returnRouteSelect && returnRouteHint) {
            const showReturnRoute = action === 'reject';
            returnRouteBlock.style.display = showReturnRoute ? 'grid' : 'none';
            if (showReturnRoute) {
                returnRouteHint.textContent = returnRouteSelect.value === 'legal'
                    ? 'This sends the claim back into Legal review for another controlled decision step.'
                    : 'This keeps the claim in a claimant-correction path so the user can update settlement details or supporting uploads.';
            }
        }

        const reason = commentInput.value.trim();
        const reasonOk = !config.requireReason || reason.length >= 12;
        const checksOk = !config.requireChecklist || isChecklistComplete();
        const manualOk = !config.requireManualRecord || isManualRecordComplete();
        const assessedOk = !config.requireAssessedAmount || isAssessedAmountValid();
        const assetOk = action !== 'approve' || getAssetApprovalErrors().length === 0;
        submitBtn.disabled = !(reasonOk && checksOk && manualOk && assessedOk && assetOk);
    }

    actionSelect.addEventListener('change', syncActionState);
    commentInput.addEventListener('input', syncActionState);
    reviewChecks.forEach((box) => box.addEventListener('change', syncActionState));
    if (returnRouteSelect) {
        returnRouteSelect.addEventListener('change', syncActionState);
    }
    [manualProcessingRefInput, manualVerificationSourceInput, manualBranchRefInput, financeAssessedAmountInput, financeAssessedCurrencyInput].forEach((field) => {
        field.addEventListener('input', syncActionState);
        field.addEventListener('change', syncActionState);
    });

    function bindAssetReviewInputs() {
        assetReviewInputs = Array.from(reviewForm.querySelectorAll('[name^="asset_reviews"]'));
        assetReviewInputs.forEach((field) => {
            field.removeEventListener('input', syncActionState);
            field.removeEventListener('change', syncActionState);
            if (field.matches('[name$="[finance_status]"]')) {
                field.removeEventListener('change', handleAssetStatusChange);
                field.removeEventListener('input', handleAssetStatusChange);
                field.addEventListener('change', handleAssetStatusChange);
                field.addEventListener('input', handleAssetStatusChange);
            }
            field.addEventListener('input', syncActionState);
            field.addEventListener('change', syncActionState);
        });
        syncAllAssetReviewRows();
        syncActionState();
    }

    function handleAssetStatusChange(event) {
        syncAssetReviewRow(event.target);
        syncActionState();
    }

    function resetReviewFormState() {
        actionSelect.value = 'comment';
        commentInput.value = '';
        manualSnapshotInput.value = '';
        manualProcessingRefInput.value = '';
        manualVerificationSourceInput.value = '';
        manualBranchRefInput.value = '';
        if (returnRouteSelect) {
            returnRouteSelect.value = 'claimant';
        }
        financeAssessedAmountInput.value = '';
        financeAssessedCurrencyInput.value = 'RWF';
        reviewChecks.forEach((box) => { box.checked = false; });
        closeInfoTips();
    }

    async function loadPanelData(claimId) {
        claimDetailsBox.innerHTML = '<div class="text-muted">Loading claim details...</div>';
        commentsSection.innerHTML = '<div class="history-empty">Loading review history...</div>';

        try {
            const [detailsResponse, commentsResponse] = await Promise.all([
                fetch(`load_claim_details.php?id=${encodeURIComponent(claimId)}`),
                fetch(`load_comments.php?claim_id=${encodeURIComponent(claimId)}`)
            ]);
            claimDetailsBox.innerHTML = await detailsResponse.text();
            commentsSection.innerHTML = await commentsResponse.text() || '<div class="history-empty">No review history yet.</div>';
            bindAssetReviewInputs();
        } catch (error) {
            claimDetailsBox.innerHTML = '<div class="text-danger">Could not load claim details. Please retry.</div>';
            commentsSection.innerHTML = '<div class="history-empty">Could not load review history.</div>';
        }
    }

    function openReviewModal(claimId, statusKey = '', assessedAmount = '', assessedCurrency = 'RWF', manualMeta = {}) {
        modalClaimId.value = String(claimId);
        resetReviewFormState();
        financeAssessedAmountInput.value = normalizeAmountInput(assessedAmount);
        financeAssessedCurrencyInput.value = String(assessedCurrency || 'RWF').trim().toUpperCase();
        manualProcessingRefInput.value = String(manualMeta?.core_ref || '').trim() || buildDefaultProcessingReference(claimId);
        manualVerificationSourceInput.value = String(manualMeta?.verify_source || '').trim();
        manualBranchRefInput.value = String(manualMeta?.branch_ref || '').trim();
        applyStatusConstraints(statusKey);
        syncActionState();
        loadPanelData(claimId);
        reviewModal.show();
    }

    reviewForm.addEventListener('submit', function (e) {
        const action = actionInput.value;
        const config = ACTION_CONFIG[action] || ACTION_CONFIG.comment;
        const reason = commentInput.value.trim();

        if (isFinalDecisionLocked && action !== 'comment') {
            e.preventDefault();
            alert('This claim already has a final finance decision. Add an additional note instead.');
            return;
        }

        if (action === 'comment' && reason === '') {
            e.preventDefault();
            alert('Please enter a review note before saving.');
            return;
        }

        if (config.requireReason && reason.length < 12) {
            e.preventDefault();
            alert('Please provide a clear return reason (minimum 12 characters).');
            return;
        }

        if (config.requireChecklist && !isChecklistComplete()) {
            e.preventDefault();
            alert('Please tick all three review checks before finalizing this decision.');
            return;
        }

        if (config.requireManualRecord && !isManualRecordComplete()) {
            e.preventDefault();
            alert('Please fill the required internal review fields before finalizing this decision.');
            return;
        }

        if (config.requireAssessedAmount && !isAssessedAmountValid()) {
            e.preventDefault();
            alert('Enter a valid final settlement value before closing this claim.');
            return;
        }

        if (action === 'approve') {
            const assetErrors = getAssetApprovalErrors();
            if (assetErrors.length > 0) {
                e.preventDefault();
                alert(assetErrors.join('\n'));
                return;
            }
        }

        if (financeAssessedAmountInput.value.trim() !== '') {
            financeAssessedAmountInput.value = normalizeAmountInput(financeAssessedAmountInput.value);
        }

        if (config.requireChecklist || hasManualRecordData()) {
            manualSnapshotInput.value = buildManualSnapshot(action);
        } else {
            manualSnapshotInput.value = '';
        }

        const confirmText = {
            comment: 'Save this review note?',
            approve: 'Record bank-side disbursement, close this claim, and notify the claimant?',
            reject: returnRouteSelect && returnRouteSelect.value === 'legal'
                ? 'Return this claim to Legal for re-review and notify the claimant?'
                : 'Return this claim to the claimant for clarification and notify the claimant?'
        };
        if (!confirm(confirmText[action] || 'Submit this action?')) {
            e.preventDefault();
        }
    });

    infoWraps.forEach((wrap) => {
        const btn = wrap.querySelector('.field-info-btn');
        if (!btn) {
            return;
        }
        btn.setAttribute('aria-expanded', 'false');
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            const currentlyOpen = wrap.classList.contains('is-open');
            closeInfoTips();
            if (!currentlyOpen) {
                wrap.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.field-info-wrap')) {
            closeInfoTips();
        }
    });

    applyStatusConstraints('');
    syncActionState();
    </script>
<?php udcs_claims_list_render_assets(); ?>
</body>
</html>


