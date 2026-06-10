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
// Tags: [STATUS] [QUEUE] [ASSIGN] [HISTORY] [NOTIFY] [AUDIT]
// [STATUS] Legal review state transitions.

/* =========================
   AUTH CHECK
========================= */

// Check if user is logged in using email
if (!isset($_SESSION['email']) || $_SESSION['role'] !== 'legal') {
    header("Location: ../login.php");
    exit();
}

$user_email = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $user_email, 'legal');
if (!$user_data) {
    header("Location: ../login.php");
    exit();
}

$user_id = $user_data['id'];
$claimant_name = $user_data['full_name'];
$legalClaimsCsrfToken = udcs_csrf_get('legal_claims_action');
$reopenSectionLabels = udcs_claim_reopen_section_labels();

bk_claims_ensure_workflow_schema($conn);
udcs_claims_v2_ensure_schema($conn);
bk_backfill_unassigned_claims($conn);

$legalQueueNotificationStats = [
    'unread_total' => 0,
    'new_assignments' => 0,
    'manual_flags' => 0,
    'claimant_updates' => 0,
];
$notificationSummaryStmt = mysqli_prepare(
    $conn,
    "SELECT
        SUM(CASE WHEN LOWER(COALESCE(n.status, '')) = 'unread' THEN 1 ELSE 0 END) AS unread_total,
        SUM(CASE WHEN LOWER(COALESCE(n.status, '')) = 'unread' AND n.message LIKE 'A new claim CL-% has been assigned to your legal queue.%' THEN 1 ELSE 0 END) AS new_assignments,
        SUM(CASE WHEN LOWER(COALESCE(n.status, '')) = 'unread' AND n.message LIKE '%Manual review flag:%' THEN 1 ELSE 0 END) AS manual_flags,
        SUM(CASE WHEN LOWER(COALESCE(n.status, '')) = 'unread' AND n.message LIKE 'Claim CL-% has been updated by the claimant after your information request.%' THEN 1 ELSE 0 END) AS claimant_updates
     FROM notifications n
     WHERE n.receiver = ? OR n.receiver = ?"
);
if ($notificationSummaryStmt) {
    $userIdAsString = (string) $user_id;
    mysqli_stmt_bind_param($notificationSummaryStmt, 'ss', $userIdAsString, $user_email);
    if (mysqli_stmt_execute($notificationSummaryStmt)) {
        $notificationSummaryResult = mysqli_stmt_get_result($notificationSummaryStmt);
        $notificationSummaryRow = $notificationSummaryResult ? mysqli_fetch_assoc($notificationSummaryResult) : null;
        if (is_array($notificationSummaryRow)) {
            $legalQueueNotificationStats['unread_total'] = (int) ($notificationSummaryRow['unread_total'] ?? 0);
            $legalQueueNotificationStats['new_assignments'] = (int) ($notificationSummaryRow['new_assignments'] ?? 0);
            $legalQueueNotificationStats['manual_flags'] = (int) ($notificationSummaryRow['manual_flags'] ?? 0);
            $legalQueueNotificationStats['claimant_updates'] = (int) ($notificationSummaryRow['claimant_updates'] ?? 0);
        }
    }
    mysqli_stmt_close($notificationSummaryStmt);
}

// Set the photo path for displaying the image
if (!empty($user_data['photo'])) {
    $photo = "../uploads/" . $user_data['photo'];
} else {
    $photo = "../Images/logo.png";
}

function legal_claim_status_key(?string $status): string
{
    $key = strtolower(trim((string) $status));
    $key = str_replace('_', ' ', $key);
    return preg_replace('/\s+/', ' ', $key) ?? '';
}

function legal_claim_status_label(?string $status): string
{
    return udcs_claim_status_label($status);
}

function legal_claim_status_class(?string $status): string
{
    return match (udcs_claim_status_class($status)) {
        'status-pending' => 'badge-pending',
        'status-review', 'status-warning' => 'badge-review',
        'status-approved' => 'badge-transferred',
        'status-rejected' => 'badge-rejected',
        default => 'badge-default',
    };
}

function legal_claim_page_url(int $page, array $params): string
{
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

function legal_claim_review_signal_class(?string $severity): string
{
    return match (strtolower(trim((string) $severity))) {
        'danger', 'error' => 'is-danger',
        'warning' => 'is-warning',
        default => 'is-ok',
    };
}

function legal_claim_transfer_blockers(?array $reviewContract): array
{
    if (empty($reviewContract)) {
        return ['The structured claim file could not be loaded.'];
    }

    $blockers = [];
    $assetSummary = (array) ($reviewContract['assets']['summary'] ?? []);
    $documentSummary = (array) ($reviewContract['documents']['summary'] ?? []);
    $reviewSummary = (array) ($reviewContract['review'] ?? []);

    if (!empty($reviewContract['status']['is_legacy'])) {
        $blockers[] = 'Legacy claims cannot be approved in the redesigned workflow.';
    }

    if ((int) ($assetSummary['count'] ?? 0) <= 0) {
        $blockers[] = 'No BK-held asset class is linked to this claim.';
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

    foreach ((array) ($reviewSummary['flags'] ?? []) as $flag) {
        if (strtolower(trim((string) ($flag['severity'] ?? ''))) !== 'danger') {
            continue;
        }
        $label = trim((string) ($flag['label'] ?? 'Critical review blocker'));
        if ($label !== '') {
            $blockers[] = $label;
        }
    }

    return array_values(array_unique($blockers));
}

// ====================================
// HANDLE FORM SUBMISSIONS (APPROVE/REJECT/COMMENT)
// ====================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'legal_claims_action')) {
        $_SESSION['error'] = 'Security validation failed. Please refresh and try again.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit();
    }

    $claim_id = (int) ($_POST['claim_id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));
    $comment = trim((string) ($_POST['comment'] ?? ''));

    if ($claim_id > 0 && $action !== '') {
        $success = false;
        $message = '';

        // [QUEUE] Only act on claims assigned to this legal officer.
        $claimStmt = mysqli_prepare(
            $conn,
            'SELECT c.id, c.comment, COALESCE(NULLIF(c.status, \'\'), c.claim_status) AS effective_status, c.claimant_id, c.claimant_user_id, c.alt_email, c.model_version, u.email
             FROM claims c
             INNER JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
             WHERE c.id = ? AND c.assigned_legal_id = ?
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
            $_SESSION['error'] = 'This claim is not in your legal queue.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        }

        $claimRow = mysqli_fetch_assoc($claimResult);
        $existing_comment = (string) ($claimRow['comment'] ?? '');
        $claim_status = trim((string) ($claimRow['effective_status'] ?? ''));
        $claimStatusKey = legal_claim_status_key($claim_status);
        $isLegacyClaim = strtolower(trim((string) ($claimRow['model_version'] ?? 'legacy'))) !== 'v2';
        $email = (string) ($claimRow['email'] ?? '');
        $alt_email = (string) ($claimRow['alt_email'] ?? '');
        $claimant_id = (int) (($claimRow['claimant_user_id'] ?? 0) ?: ($claimRow['claimant_id'] ?? 0));

        if ($isLegacyClaim) {
            $_SESSION['error'] = 'Legacy claims are visible for reference only and cannot be actioned in the redesigned workflow.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'The claimant email is missing or invalid.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
            exit();
        }

        switch ($action) {
            case 'transfer':
                if (!in_array($claimStatusKey, [
                    legal_claim_status_key('Pending Legal Review'),
                    legal_claim_status_key('Manual Legal Review Required'),
                    legal_claim_status_key('More Information Required'),
                ], true)) {
                    $_SESSION['error'] = 'Only claims currently in legal review can be sent to Finance.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                $reviewContract = udcs_claim_fetch_review_contract($conn, $claim_id);
                $transferBlockers = legal_claim_transfer_blockers($reviewContract);
                if (!empty($transferBlockers)) {
                    $_SESSION['error'] = 'This claim cannot be transferred to Finance yet: ' . implode(' ', $transferBlockers);
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                if (bk_pick_staff_assignee($conn, 'finance', 'finance') === null) {
                    $_SESSION['error'] = 'No approved Finance officer is available right now, so this claim cannot be transferred yet.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                $finance_assignee_id = null;
                $new_comment = trim($existing_comment . "\n" . date('Y-m-d H:i') . " - Approved by Legal\n" . ($comment !== '' ? "Note: $comment" : ''));
                mysqli_begin_transaction($conn);
                try {
                    $finance_assignee_id = bk_assign_claim_to_finance($conn, $claim_id);
                    if ($finance_assignee_id === null || $finance_assignee_id <= 0) {
                        throw new RuntimeException('No finance assignee available.');
                    }

                    udcs_claim_history_log($conn, $claim_id, 'legal', 'Approved by Legal', $comment !== '' ? $comment : 'Legal approved the claim for finance processing.');
                    $success = udcs_claim_set_status($conn, $claim_id, 'Pending Finance Review', (int) $user_id, 'legal', 'Claim approved by Legal and routed to Finance.', [
                        'assigned_finance_id' => $finance_assignee_id,
                        'assigned_to' => $finance_assignee_id,
                        'legal_reopen_scope' => null,
                        'legal_reopen_note' => null,
                        'legal_reopen_requested_at' => null,
                        'comment' => $new_comment,
                        'updated_at' => 'NOW()',
                    ]);
                    if (!$success) {
                        throw new RuntimeException('Claim status update failed.');
                    }

                    mysqli_commit($conn);
                } catch (Throwable $exception) {
                    mysqli_rollback($conn);
                    $_SESSION['error'] = 'We could not transfer this claim to Finance right now.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }
                $message = 'Claim approved and sent to Finance.';

                if ($success && $claimant_id > 0) {
                    // [NOTIFY] Claimant + finance assignee.
                    $claimCode = 'CL-' . str_pad((string) $claim_id, 6, '0', STR_PAD_LEFT);
                    $claimantNotif = "Your claim $claimCode has been approved by Legal and transferred to Finance.";
                    udcs_db_insert_notification($conn, (string) $claimant_id, (string) $user_id, $claimantNotif);

                    $financeNotif = "Claim $claimCode has been assigned to your finance queue.";
                    if ($finance_assignee_id !== null) {
                        udcs_db_insert_notification($conn, (string) $finance_assignee_id, (string) $user_id, $financeNotif);
                        udcs_send_staff_workflow_email(
                            $conn,
                            'finance_assigned',
                            $claim_id,
                            [$finance_assignee_id],
                            [
                                'actor_name' => $claimant_name,
                                'note' => $comment !== '' ? $comment : $financeNotif,
                            ]
                        );
                    }

                    // [AUDIT] Record transfer event.
                    bk_activity_log($conn, [
                        'actor_id' => (int) $user_id,
                        'actor_role' => 'legal',
                        'claim_id' => (int) $claim_id,
                        'action_key' => 'legal_transferred_to_finance',
                        'action_label' => 'Legal Transferred Claim To Finance',
                        'details' => 'Claim approved by legal and routed to finance queue.',
                        'meta' => [
                            'finance_assignee_id' => $finance_assignee_id,
                        ],
                    ]);

                    $emails = [$email];
                    if ($alt_email !== '' && filter_var($alt_email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $alt_email;
                    }

                    $actionToken = udcs_action_token_issue('legal_transfer_email', [
                        'claim_id' => (int) $claim_id,
                        'emails' => array_values($emails),
                    ], 300);
                    header("Location: transferEmail.php?action_token=" . urlencode($actionToken));
                    exit();
                }
                break;

            case 'request_info':
                if ($comment === '' || strlen($comment) < 12) {
                    $_SESSION['error'] = 'Please explain clearly what the claimant must update (at least 12 characters).';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                if (!in_array($claimStatusKey, [
                    legal_claim_status_key('Pending Legal Review'),
                    legal_claim_status_key('Manual Legal Review Required'),
                    legal_claim_status_key('More Information Required'),
                ], true)) {
                    $_SESSION['error'] = 'Only claims in the legal queue can be returned for more information.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                $requestedSections = udcs_claim_reopen_scope_decode(
                    udcs_claim_reopen_scope_encode((array) ($_POST['reopen_sections'] ?? []))
                );
                if (empty($requestedSections)) {
                    $_SESSION['error'] = 'Select at least one section for the claimant to update.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                $scopeLabels = array_map('udcs_claim_reopen_section_label', $requestedSections);
                $scopeSummary = implode(', ', $scopeLabels);
                $reopenNote = $comment;
                $reopenRequestedAt = date('Y-m-d H:i:s');
                $new_comment = trim($existing_comment . "\n" . date('Y-m-d H:i') . " - More Information Requested by Legal Dept\nRequested sections: " . $scopeSummary . "\nReason: " . $reopenNote);

                $success = udcs_claim_set_status($conn, $claim_id, 'More Information Required', (int) $user_id, 'legal', 'Legal requested claimant updates for: ' . $scopeSummary . '.', [
                    'assigned_to' => $user_id,
                    'legal_reopen_scope' => udcs_claim_reopen_scope_encode($requestedSections),
                    'legal_reopen_note' => $reopenNote,
                    'legal_reopen_requested_at' => $reopenRequestedAt,
                    'comment' => $new_comment,
                    'updated_at' => 'NOW()',
                ]);
                $message = 'Claim returned to claimant for targeted corrections.';

                if ($success && $claimant_id > 0) {
                    $notifMsg = "Your claim #$claim_id needs more information. Update only these sections: $scopeSummary.";
                    udcs_db_insert_notification($conn, (string) $claimant_id, (string) $user_id, $notifMsg);

                    bk_activity_log($conn, [
                        'actor_id' => (int) $user_id,
                        'actor_role' => 'legal',
                        'claim_id' => (int) $claim_id,
                        'action_key' => 'legal_requested_more_information',
                        'action_label' => 'Legal Requested More Information',
                        'details' => 'Claim returned to claimant for targeted corrections.',
                        'meta' => [
                            'reopen_sections' => $requestedSections,
                            'note' => $reopenNote,
                        ],
                    ]);
                }
                break;

            case 'reject':
                if ($comment === '' || strlen($comment) < 12) {
                    $_SESSION['error'] = 'Please write a clear rejection reason (at least 12 characters).';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                if (!in_array($claimStatusKey, [
                    legal_claim_status_key('Pending Legal Review'),
                    legal_claim_status_key('Manual Legal Review Required'),
                    legal_claim_status_key('More Information Required'),
                ], true)) {
                    $_SESSION['error'] = 'Only claims currently in legal review can be rejected.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                $new_comment = $existing_comment .
                    "\n" . date('Y-m-d H:i') . " - Rejected by Legal Dept\n" .
                    "Reason: " . ($comment !== '' ? $comment : "No reason provided");

                $success = udcs_claim_set_status($conn, $claim_id, 'Rejected by Legal', (int) $user_id, 'legal', 'Claim rejected by Legal.', [
                    'assigned_to' => $user_id,
                    'legal_reopen_scope' => null,
                    'legal_reopen_note' => null,
                    'legal_reopen_requested_at' => null,
                    'comment' => $new_comment,
                    'updated_at' => 'NOW()',
                ]);
                $message = 'Claim rejected by Legal.';

                if ($success && $claimant_id > 0) {
                    // [NOTIFY] Claimant about rejection.
                    $notif_msg = "Your claim #$claim_id has been REJECTED by Legal Department.";
                    udcs_db_insert_notification($conn, (string) $claimant_id, (string) $user_id, $notif_msg);

                    bk_activity_log($conn, [
                        'actor_id' => (int) $user_id,
                        'actor_role' => 'legal',
                        'claim_id' => (int) $claim_id,
                        'action_key' => 'legal_rejected_claim',
                        'action_label' => 'Legal Rejected Claim',
                        'details' => 'Claim rejected during legal review.',
                    ]);

                    $emails = [$email];
                    if ($alt_email !== '' && filter_var($alt_email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $alt_email;
                    }

                    $actionToken = udcs_action_token_issue('legal_denial_email', [
                        'claim_id' => (int) $claim_id,
                        'emails' => array_values($emails),
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
                    legal_claim_status_key('Pending Legal Review'),
                    legal_claim_status_key('Manual Legal Review Required'),
                    legal_claim_status_key('More Information Required'),
                ], true)) {
                    $_SESSION['error'] = 'Only claims still in the Legal review workflow can receive a new legal note.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
                    exit();
                }

                // [HISTORY] Append comment.
                $new_comment = $existing_comment .
                    "\n" . date('Y-m-d H:i') . " - Comment by Legal Dept\n" .
                    $comment;

                $nextStatus = in_array($claimStatusKey, [
                    legal_claim_status_key('Pending Legal Review'),
                    legal_claim_status_key('Manual Legal Review Required'),
                ], true) ? 'Manual Legal Review Required' : $claim_status;
                $success = udcs_claim_set_status($conn, $claim_id, $nextStatus, (int) $user_id, 'legal', 'Legal review note saved.', [
                    'comment' => $new_comment,
                    'updated_at' => 'NOW()',
                ]);
                $message = $nextStatus === 'Manual Legal Review Required'
                    ? 'Review note saved and claim remains in manual legal review.'
                    : 'Review note saved.';

                if ($success) {
                    bk_activity_log($conn, [
                        'actor_id' => (int) $user_id,
                        'actor_role' => 'legal',
                        'claim_id' => (int) $claim_id,
                        'action_key' => 'legal_comment_added',
                        'action_label' => 'Legal Comment Added',
                        'details' => 'Legal reviewer added a claim comment.',
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
$limit = 8;
$offset = ($page - 1) * $limit;

// Filters
$statusInput = trim((string) ($_GET['status'] ?? ''));
$searchInput = trim((string) ($_GET['search'] ?? ''));
$dateFromInput = trim((string) ($_GET['date_from'] ?? ''));
$dateToInput = trim((string) ($_GET['date_to'] ?? ''));
$statusKey = legal_claim_status_key($statusInput);
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

// [QUEUE] List only claims assigned to this legal officer.
$whereParts = [
    'c.assigned_legal_id = ?',
];
$filterTypes = 'i';
$filterParams = [$user_id];

if ($statusKey !== '' && $statusKey !== 'all') {
    $whereParts[] = "LOWER(REPLACE(COALESCE(NULLIF(c.status, ''), c.claim_status), '_', ' ')) = ?";
    $filterTypes .= 's';
    $filterParams[] = $statusKey;
}

if ($searchInput !== '') {
    $whereParts[] = '(
        CAST(c.id AS CHAR) LIKE ?
        OR u.full_name LIKE ?
        OR u.email LIKE ?
        OR COALESCE(NULLIF(c.deceased_full_name, \'\'), c.deceased_name) LIKE ?
        OR COALESCE(c.relationship, \'\') LIKE ?
        OR COALESCE(c.marital_status, \'\') LIKE ?
        OR COALESCE(c.spouse_status, \'\') LIKE ?
        OR COALESCE(c.children_status, \'\') LIKE ?
        OR COALESCE(c.manual_review_reason, \'\') LIKE ?
        OR COALESCE(ca.asset_classes, \'\') LIKE ?
        OR COALESCE(ca.asset_refs, \'\') LIKE ?
        OR ' . $claimAccountSql . ' LIKE ?
        OR c.distribution_method LIKE ?
        OR c.distribution_details LIKE ?
    )';
    $searchTerm = '%' . $searchInput . '%';
    $filterTypes .= str_repeat('s', 14);
    for ($i = 0; $i < 14; $i++) {
        $filterParams[] = $searchTerm;
    }
}

if ($dateFromInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromInput)) {
    $whereParts[] = 'DATE(c.submitted_at) >= ?';
    $filterTypes .= 's';
    $filterParams[] = $dateFromInput;
}

if ($dateToInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToInput)) {
    $whereParts[] = 'DATE(c.submitted_at) <= ?';
    $filterTypes .= 's';
    $filterParams[] = $dateToInput;
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
        WHEN 'Pending Legal Review' THEN 1
        WHEN 'Manual Legal Review Required' THEN 2
        WHEN 'More Information Required' THEN 3
        WHEN 'Rejected by Legal' THEN 4
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

$legalQueueRows = [];
if ($claims) {
    while ($claimRow = mysqli_fetch_assoc($claims)) {
        $contract = udcs_claim_fetch_review_contract($conn, (int) ($claimRow['id'] ?? 0), $claimRow);
        if (!$contract) {
            continue;
        }
        $legalQueueRows[] = [
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
    WHERE assigned_legal_id = ?
    GROUP BY COALESCE(NULLIF(status, ''), claim_status)";
$statsStmt = mysqli_prepare($conn, $statsQuery);
$statsResult = false;
if ($statsStmt) {
    mysqli_stmt_bind_param($statsStmt, 'i', $user_id);
    if (mysqli_stmt_execute($statsStmt)) {
        $statsResult = mysqli_stmt_get_result($statsStmt);
    }
}
$statusStats = [];
if ($statsResult) {
    while ($row = mysqli_fetch_assoc($statsResult)) {
        $key = legal_claim_status_key((string) ($row['effective_status'] ?? ''));
        $statusStats[$key] = (int) ($statusStats[$key] ?? 0) + (int) ($row['count'] ?? 0);
    }
}
$currentStatusKey = legal_claim_status_key($statusInput);

$baseParams = $_GET;
unset($baseParams['page']);

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Review Claims | Legal Department', '..', $headExtra); ?>
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
            padding: 1.18rem 1.18rem 1.28rem;
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

        .filter-card {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.95rem;
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.05), rgba(var(--bk-surface-rgb), 1));
            box-shadow: var(--shadow-soft);
            padding: 0.9rem;
            margin-bottom: 1rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1.7fr 1fr 1fr 1fr auto;
            gap: 0.7rem;
            align-items: end;
        }

        .field {
            display: grid;
            gap: 0.3rem;
            min-width: 0;
        }

        .field label {
            color: rgb(var(--bk-text-rgb));
            font-weight: 700;
            letter-spacing: 0.01em;
            font-size: 0.78rem;
            text-transform: uppercase;
        }

        .filter-actions {
            display: flex;
            align-items: center;
            gap: 0.42rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .claims-total {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            padding: 0.45rem 0.72rem;
            min-height: 2.6rem;
            background: rgba(var(--bk-surface-rgb), 0.95);
            font-size: 0.76rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
        }

        .status-shortcuts {
            margin: 0 0 0.95rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }

        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.42rem 0.68rem;
            font-size: 0.76rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
            transition: border-color 0.15s ease, background-color 0.15s ease, transform 0.15s ease;
        }

        .status-chip:hover {
            border-color: rgba(var(--bk-primary-rgb), 0.5);
            background: rgba(var(--bk-primary-rgb), 0.08);
            transform: translateY(-1px);
            color: rgb(var(--bk-text-rgb));
        }

        .status-chip.active {
            color: #fff;
            border-color: rgba(var(--bk-primary-rgb), 0.95);
            background: rgb(var(--bk-primary-rgb));
        }

        .chip-count {
            min-width: 1.45rem;
            min-height: 1.45rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            border: 1px solid rgba(var(--bk-border-rgb), 0.95);
            background: rgba(var(--bk-bg-rgb), 0.78);
            color: rgb(var(--bk-text-rgb));
            padding: 0 0.28rem;
        }

        .status-chip.active .chip-count {
            border-color: rgba(255, 255, 255, 0.5);
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
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

        .badge-pending {
            color: rgb(var(--bk-warning-rgb));
            border-color: rgba(var(--bk-warning-rgb), 0.38);
            background: rgba(var(--bk-warning-rgb), 0.14);
        }

        .badge-review {
            color: rgb(var(--bk-primary-rgb));
            border-color: rgba(var(--bk-primary-rgb), 0.34);
            background: rgba(var(--bk-primary-rgb), 0.12);
        }

        .badge-transferred {
            color: rgb(var(--bk-success-rgb));
            border-color: rgba(var(--bk-success-rgb), 0.34);
            background: rgba(var(--bk-success-rgb), 0.13);
        }

        .badge-rejected {
            color: rgb(var(--bk-danger-rgb));
            border-color: rgba(var(--bk-danger-rgb), 0.34);
            background: rgba(var(--bk-danger-rgb), 0.12);
        }

        .badge-default {
            color: rgb(var(--bk-text-rgb));
            border-color: rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-muted-rgb), 0.12);
        }

        body.bk-role-page.bk-role-legal .status-badge.badge-pending {
            color: rgb(var(--bk-warning-rgb)) !important;
            border-color: rgba(var(--bk-warning-rgb), 0.46) !important;
            background: rgba(var(--bk-warning-rgb), 0.18) !important;
        }

        body.bk-role-page.bk-role-legal .status-badge.badge-review {
            color: rgb(var(--bk-primary-rgb)) !important;
            border-color: rgba(var(--bk-primary-rgb), 0.48) !important;
            background: rgba(var(--bk-primary-rgb), 0.16) !important;
        }

        body.bk-role-page.bk-role-legal .status-badge.badge-transferred {
            color: rgb(var(--bk-success-rgb)) !important;
            border-color: rgba(var(--bk-success-rgb), 0.46) !important;
            background: rgba(var(--bk-success-rgb), 0.18) !important;
        }

        body.bk-role-page.bk-role-legal .status-badge.badge-rejected {
            color: rgb(var(--bk-danger-rgb)) !important;
            border-color: rgba(var(--bk-danger-rgb), 0.46) !important;
            background: rgba(var(--bk-danger-rgb), 0.16) !important;
        }

        body.bk-role-page.bk-role-legal .status-badge.badge-default {
            color: rgb(var(--bk-text-rgb)) !important;
            border-color: rgba(var(--bk-border-rgb), 1) !important;
            background: rgba(var(--bk-muted-rgb), 0.16) !important;
        }

        .claim-id {
            font-family: "Courier New", monospace;
            font-weight: 700;
            color: rgb(var(--bk-primary-rgb));
        }

        .table-shell {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.15rem;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.05), rgba(var(--bk-white-rgb), 1));
            box-shadow: 0 18px 40px rgba(var(--bk-primary-rgb), 0.08);
            padding: 0.78rem;
        }

        .table-scroll {
            overflow-x: auto;
            padding-bottom: 0.15rem;
        }

        .claims-table {
            width: 100%;
            min-width: 1020px;
            border-collapse: separate;
            border-spacing: 0 0.92rem;
        }

        .claims-table th {
            background: rgb(var(--bk-primary-rgb));
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: left;
            padding: 0.94rem 0.9rem;
            border-bottom: 0;
            color: rgb(var(--bk-text-rgb));
        }

        .claims-table th.is-case,
        .claims-table td.is-case { width: 23rem; }

        .claims-table th.is-assets,
        .claims-table td.is-assets { width: 17rem; }

        .claims-table th.is-signals,
        .claims-table td.is-signals { width: 15rem; max-width: 15rem; }

        .claims-table th.is-status,
        .claims-table td.is-status { width: 11rem; }

        .claims-table th.is-date,
        .claims-table td.is-date { width: 8.8rem; }

        .claims-table th.is-actions,
        .claims-table td.is-actions { width: 9.4rem; }

        .claims-table td {
            padding: 1rem 0.9rem;
            border-top: 1px solid rgba(var(--bk-border-rgb), 0.9);
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.9);
            vertical-align: top;
            font-size: 0.84rem;
            color: rgb(var(--bk-text-rgb));
            word-break: break-word;
            overflow-wrap: anywhere;
            background: rgba(var(--bk-white-rgb), 0.98);
            box-shadow: 0 10px 22px rgba(var(--bk-primary-rgb), 0.04);
        }

        .claims-table th:first-child {
            border-top-left-radius: 0.92rem;
            border-bottom-left-radius: 0.92rem;
        }

        .claims-table th:last-child {
            border-top-right-radius: 0.92rem;
            border-bottom-right-radius: 0.92rem;
        }

        .claims-table td:first-child {
            border-left: 1px solid rgba(var(--bk-border-rgb), 0.9);
            border-top-left-radius: 0.96rem;
            border-bottom-left-radius: 0.96rem;
        }

        .claims-table td:last-child {
            border-right: 1px solid rgba(var(--bk-border-rgb), 0.9);
            border-top-right-radius: 0.96rem;
            border-bottom-right-radius: 0.96rem;
        }

        .claims-table tbody tr:hover td {
            background: rgba(var(--bk-primary-rgb), 0.045);
        }

        .subtle {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.76rem;
            margin-top: 0.18rem;
        }

        .amount {
            font-weight: 700;
            white-space: nowrap;
        }

        .doc-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-primary-rgb), 0.08);
            color: rgb(var(--bk-text-rgb));
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.2rem 0.54rem;
        }

        .queue-case-title,
        .queue-family-title,
        .queue-asset-title {
            font-weight: 800;
            color: rgb(var(--bk-text-rgb));
            line-height: 1.25;
        }

        .legal-case-stack {
            display: grid;
            gap: 0.34rem;
            min-width: 0;
        }

        .queue-case-title {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
        }

        .legal-case-path {
            color: rgb(var(--bk-text-rgb));
            font-size: 0.78rem;
            font-weight: 800;
            line-height: 1.25;
        }

        .queue-case-meta,
        .queue-family-meta,
        .queue-asset-meta {
            display: grid;
            gap: 0.14rem;
            margin-top: 0.26rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.74rem;
            line-height: 1.34;
        }

        .queue-meta-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 0.32rem 0.5rem;
            margin-top: 0.24rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.74rem;
            line-height: 1.3;
        }

        .queue-meta-inline strong {
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .legal-kv-list {
            display: grid;
            gap: 0.26rem;
            margin-top: 0.18rem;
        }

        .legal-kv-line {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.55rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.75rem;
            line-height: 1.32;
        }

        .legal-kv-line strong {
            color: rgb(var(--bk-text-rgb));
            font-weight: 800;
        }

        .legal-kv-line span:last-child {
            text-align: right;
        }

        .queue-mini-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.45rem;
            margin-top: 0.42rem;
        }

        .queue-mini {
            border: 1px solid rgba(var(--bk-border-rgb), 0.92);
            border-radius: 0.66rem;
            background: rgba(var(--bk-bg-rgb), 0.7);
            padding: 0.34rem 0.44rem;
            min-width: 0;
        }

        .queue-mini span {
            display: block;
            font-size: 0.64rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: rgb(var(--bk-muted-rgb));
        }

        .queue-mini strong {
            display: block;
            margin-top: 0.08rem;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.78rem;
            line-height: 1.22;
        }

        .queue-signal-stack {
            display: grid;
            gap: 0.32rem;
        }

        .queue-signal {
            display: inline-flex;
            align-items: flex-start;
            gap: 0.36rem;
            width: 100%;
            max-width: none;
            border-radius: 0.72rem;
            border: 1px solid rgba(var(--bk-border-rgb), 0.95);
            background: rgba(var(--bk-bg-rgb), 0.78);
            color: rgb(var(--bk-text-rgb));
            padding: 0.42rem 0.58rem;
            font-size: 0.7rem;
            font-weight: 750;
            line-height: 1.3;
        }

        .queue-signal.is-compact {
            padding: 0.34rem 0.46rem;
            font-size: 0.69rem;
            line-height: 1.22;
        }

        .queue-signal i {
            margin-top: 0.04rem;
            flex: 0 0 auto;
        }

        .queue-signal.is-danger {
            border-color: rgba(var(--bk-danger-rgb), 0.42);
            background: rgba(var(--bk-danger-rgb), 0.1);
            color: rgb(var(--bk-danger-rgb));
        }

        .queue-signal.is-warning {
            border-color: rgba(var(--bk-warning-rgb), 0.44);
            background: rgba(var(--bk-warning-rgb), 0.12);
            color: #8a5b00;
        }

        .queue-signal.is-ok {
            border-color: rgba(var(--bk-success-rgb), 0.4);
            background: rgba(var(--bk-success-rgb), 0.1);
            color: rgb(var(--bk-success-rgb));
        }

        .queue-docs {
            display: grid;
            gap: 0.32rem;
        }

        .queue-hidden-hint {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.71rem;
            font-weight: 700;
            line-height: 1.24;
        }

        .legal-status-stack {
            display: grid;
            gap: 0.3rem;
            align-content: start;
        }

        .legal-status-note {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.75rem;
            line-height: 1.3;
            font-weight: 700;
        }

        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.42rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.1rem;
            min-height: 2.1rem;
            border-radius: 0.62rem;
            font-size: 0.82rem;
            padding: 0.2rem 0.55rem;
            text-decoration: none;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-surface-rgb), 1);
            color: rgb(var(--bk-text-rgb));
            transition: transform 0.14s ease, border-color 0.14s ease, background-color 0.14s ease;
        }

        .action-btn.open-btn {
            min-width: 4.2rem;
            padding: 0.28rem 0.72rem;
            gap: 0.34rem;
            font-weight: 700;
            border-color: rgba(var(--bk-primary-rgb), 0.95) !important;
            background: rgb(var(--bk-primary-rgb)) !important;
            color: #fff !important;
            box-shadow: 0 8px 18px rgba(var(--bk-primary-rgb), 0.22);
        }

        .action-btn:hover {
            transform: translateY(-1px);
            color: rgb(var(--bk-text-rgb));
        }

        .action-btn.is-view:hover {
            border-color: rgba(var(--bk-primary-rgb), 0.55);
            background: rgba(var(--bk-primary-rgb), 0.12);
        }

        .action-btn.open-btn:hover {
            filter: brightness(0.96);
            transform: translateY(-1px);
            color: #fff !important;
        }

        .action-btn.is-transfer:hover {
            border-color: rgba(var(--bk-success-rgb), 0.55);
            background: rgba(var(--bk-success-rgb), 0.14);
        }

        .action-btn.is-reject:hover {
            border-color: rgba(var(--bk-danger-rgb), 0.55);
            background: rgba(var(--bk-danger-rgb), 0.14);
        }

        .empty-state {
            text-align: center;
            padding: 2.1rem 1rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.9rem;
        }

        .pager {
            margin-top: 0.95rem;
            display: flex;
            justify-content: center;
            gap: 0.35rem;
            flex-wrap: wrap;
        }

        .pager-link {
            display: inline-flex;
            min-width: 2rem;
            height: 2rem;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.65rem;
            color: rgb(var(--bk-text-rgb));
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0 0.4rem;
            background: rgb(var(--bk-surface-rgb));
        }

        .pager-link:hover {
            background: rgba(var(--bk-primary-rgb), 0.08);
            border-color: rgba(var(--bk-primary-rgb), 0.45);
            color: rgb(var(--bk-text-rgb));
        }

        .pager-link.is-current {
            background-color: rgb(var(--bk-primary-rgb));
            border-color: rgb(var(--bk-primary-rgb));
            color: #fff;
        }

        .pager-note {
            text-align: center;
            margin-top: 0.4rem;
            font-size: 0.78rem;
            color: rgb(var(--bk-muted-rgb));
        }

        .modal-xl-custom {
            max-width: min(1640px, calc(100vw - 1.4rem));
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

        .comment-item {
            border-left: 3px solid rgb(var(--bk-primary-rgb));
            padding-left: 0.82rem;
            margin-bottom: 0.82rem;
        }

        .comment-meta {
            font-size: 0.8rem;
            color: rgb(var(--bk-muted-rgb));
        }

        .btn-group-xs > .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 0.4rem;
        }

        .pre-line {
            white-space: pre-line;
        }

        body.bk-role-page .review-modal .modal-content {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.42rem;
            background-color: rgb(var(--bk-surface-rgb)) !important;
            background-image: none !important;
            opacity: 1 !important;
            --bs-modal-bg: rgb(var(--bk-surface-rgb));
            --review-workspace-height: calc(100vh - 8.8rem);
            box-shadow: 0 34px 80px rgba(3, 78, 162, 0.24);
            overflow-y: hidden !important;
            overflow-x: hidden !important;
            backdrop-filter: none !important;
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 1rem) !important;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-y;
        }

        body.bk-role-page .review-modal .modal-header {
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgb(var(--bk-surface-rgb));
            color: rgb(var(--bk-text-rgb)) !important;
            padding: 0.95rem 1.05rem 0.78rem;
        }

        body.bk-role-page .review-modal #reviewForm {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
        }

        body.bk-role-page .review-modal .modal-body {
            background: rgb(var(--bk-bg-rgb)) !important;
            overflow: hidden !important;
            padding: 1.08rem 1.16rem 1.16rem;
            flex: 1 1 auto;
            min-height: 0;
        }

        body.bk-role-page .review-modal .modal-footer {
            border-top: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgb(var(--bk-surface-rgb)) !important;
        }

        .review-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr) clamp(320px, 25vw, 380px);
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
        body.bk-role-page .review-action-card {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1.22rem;
            background: rgba(var(--bk-white-rgb), 1) !important;
            box-shadow: 0 12px 28px rgba(var(--bk-primary-rgb), 0.06);
        }

        .review-sidebar-card {
            padding: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
            max-height: min(19rem, 38vh);
        }

        .review-sidebar-head {
            display: grid;
            gap: 0.18rem;
            margin-bottom: 0.72rem;
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

        body.bk-role-page .review-modal .comments-box {
            flex: 1 1 auto;
            min-height: 0;
            max-height: 15.8rem;
            overflow: auto;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 1rem;
            background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.035)) !important;
            padding: 0.86rem 0.9rem;
            box-shadow: none;
        }

        body.bk-role-page .review-action-card {
            padding: 0;
            max-width: none;
            margin-inline: 0;
            overflow: hidden;
            min-height: 0;
            max-height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }

        body.bk-role-page .review-modal .card {
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
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.055), rgba(var(--bk-surface-rgb), 0.95));
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

        .legal-card-top {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 0.72rem;
            align-items: start;
            padding: 0.92rem 1rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            background:
                radial-gradient(circle at top left, rgba(var(--bk-primary-rgb), 0.13), transparent 34%),
                linear-gradient(135deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.045));
        }

        .legal-card-top.is-action {
            background:
                radial-gradient(circle at top left, rgba(var(--bk-primary-rgb), 0.16), transparent 34%),
                linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.08), rgba(var(--bk-white-rgb), 1));
        }

        .legal-card-icon {
            width: 2.32rem;
            height: 2.32rem;
            border-radius: 0.85rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            background: rgb(var(--bk-primary-rgb));
            box-shadow: 0 10px 20px rgba(var(--bk-primary-rgb), 0.18);
        }

        .legal-card-title {
            margin: 0;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.98rem;
            font-weight: 950;
            line-height: 1.18;
        }

        .legal-card-note {
            margin: 0.18rem 0 0;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.76rem;
            line-height: 1.42;
        }

        .legal-card-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.2);
            background: rgba(var(--bk-primary-rgb), 0.08);
            color: rgb(var(--bk-primary-rgb));
            font-size: 0.68rem;
            font-weight: 900;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 0.28rem 0.56rem;
        }

        .legal-notes-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.42rem;
            padding: 0.72rem 1rem 0;
        }

        .legal-note-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.34rem;
            border-radius: 999px;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            background: rgba(var(--bk-bg-rgb), 0.72);
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.7rem;
            font-weight: 780;
            padding: 0.34rem 0.58rem;
        }

        .legal-notes-body {
            padding: 0.72rem 1rem 1rem;
        }

        .legal-note-entry {
            display: grid;
            gap: 0.5rem;
        }

        .legal-note-entry-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.65rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 1);
            padding-bottom: 0.48rem;
        }

        .legal-note-entry-title {
            display: inline-flex;
            align-items: center;
            gap: 0.38rem;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.78rem;
            font-weight: 900;
        }

        .legal-note-entry-meta {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.68rem;
            font-weight: 760;
        }

        .legal-note-entry-copy {
            color: rgb(var(--bk-text-rgb));
            font-size: 0.8rem;
            line-height: 1.48;
        }

        .legal-note-empty {
            display: grid;
            gap: 0.36rem;
            place-items: start;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.78rem;
            line-height: 1.42;
        }

        .legal-action-rail {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.46rem;
            padding: 0.76rem 1rem 0;
        }

        .legal-action-step {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.82rem;
            background: rgba(var(--bk-bg-rgb), 0.72);
            padding: 0.56rem 0.58rem;
            min-width: 0;
        }

        .legal-action-step strong {
            display: block;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.72rem;
            font-weight: 900;
            line-height: 1.1;
        }

        .legal-action-step span {
            display: block;
            margin-top: 0.16rem;
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.66rem;
            line-height: 1.25;
        }

        .legal-action-step.is-active {
            border-color: rgba(var(--bk-primary-rgb), 0.34);
            background: rgba(var(--bk-primary-rgb), 0.08);
        }

        .legal-route-grid {
            display: grid;
            gap: 0.52rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .legal-route-option {
            width: 100%;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.96rem;
            background: rgba(var(--bk-white-rgb), 1);
            color: rgb(var(--bk-text-rgb));
            text-align: left;
            padding: 0.72rem;
            display: grid;
            gap: 0.34rem;
            cursor: pointer;
            transition: border-color 0.16s ease, background-color 0.16s ease, transform 0.16s ease, box-shadow 0.16s ease;
        }

        .legal-route-option:hover,
        .legal-route-option:focus-visible {
            outline: none;
            transform: translateY(-1px);
            border-color: rgba(var(--bk-primary-rgb), 0.42);
            box-shadow: 0 10px 20px rgba(var(--bk-primary-rgb), 0.08);
        }

        .legal-route-option.is-active {
            border-color: rgb(var(--bk-primary-rgb));
            background: rgba(var(--bk-primary-rgb), 0.08);
            box-shadow: inset 4px 0 0 rgb(var(--bk-primary-rgb));
        }

        .legal-route-option.is-danger.is-active {
            border-color: rgb(var(--bk-danger-rgb));
            background: rgba(var(--bk-danger-rgb), 0.08);
            box-shadow: inset 4px 0 0 rgb(var(--bk-danger-rgb));
        }

        .legal-route-option.is-warning.is-active {
            border-color: rgb(var(--bk-warning-rgb));
            background: rgba(var(--bk-warning-rgb), 0.1);
            box-shadow: inset 4px 0 0 rgb(var(--bk-warning-rgb));
        }

        .legal-route-option:disabled {
            opacity: 0.46;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .legal-route-top {
            display: flex;
            align-items: center;
            gap: 0.46rem;
            font-size: 0.78rem;
            font-weight: 950;
        }

        .legal-route-top i {
            color: rgb(var(--bk-primary-rgb));
        }

        .legal-route-option.is-danger .legal-route-top i {
            color: rgb(var(--bk-danger-rgb));
        }

        .legal-route-option.is-warning .legal-route-top i {
            color: rgb(var(--bk-warning-rgb));
        }

        .legal-route-option span:last-child {
            color: rgb(var(--bk-muted-rgb));
            font-size: 0.68rem;
            line-height: 1.3;
        }

        .legal-action-field {
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.98rem;
            background: rgba(var(--bk-white-rgb), 1);
            padding: 0.78rem;
        }

        .legal-action-footer {
            border-top: 1px solid rgba(var(--bk-border-rgb), 1);
            margin: 0.1rem -1rem -1rem;
            padding: 0.82rem 1rem 0;
            background: rgba(var(--bk-primary-rgb), 0.035);
        }

        .legal-action-card[data-action="transfer"] .legal-card-badge {
            border-color: rgba(var(--bk-success-rgb), 0.24);
            background: rgba(var(--bk-success-rgb), 0.1);
            color: rgb(var(--bk-success-rgb));
        }

        .legal-action-card[data-action="reject"] .legal-card-badge {
            border-color: rgba(var(--bk-danger-rgb), 0.24);
            background: rgba(var(--bk-danger-rgb), 0.1);
            color: rgb(var(--bk-danger-rgb));
        }

        .legal-action-card[data-action="request_info"] .legal-card-badge {
            border-color: rgba(var(--bk-warning-rgb), 0.28);
            background: rgba(var(--bk-warning-rgb), 0.13);
            color: rgb(var(--bk-warning-rgb));
        }

        .legal-action-card[data-action="locked"] .legal-card-badge {
            border-color: rgba(var(--bk-muted-rgb), 0.24);
            background: rgba(var(--bk-muted-rgb), 0.1);
            color: rgb(var(--bk-muted-rgb));
        }

        .queue-alert-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            margin-top: 0.95rem;
        }

        .queue-alert-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.52rem;
            border: 1px solid rgba(255, 255, 255, 0.24);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff !important;
            padding: 0.42rem 0.78rem;
            font-size: 0.8rem;
            font-weight: 700;
            box-shadow: 0 10px 18px rgba(3, 78, 162, 0.14);
        }

        .queue-alert-chip strong {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.8rem;
            height: 1.8rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.98);
            color: rgb(var(--bk-primary-rgb)) !important;
            font-size: 0.82rem;
        }

        .claims-wrapper .claims-table thead th .table-entity-label,
        .claims-wrapper .claims-table thead th .table-entity-label i,
        .claims-wrapper .claims-table thead th,
        .claims-wrapper .claims-table thead th * {
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
        }

        .request-scope-grid {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin: 0;
            padding: 0.82rem;
            border: 1px solid rgba(var(--bk-border-rgb), 1);
            border-radius: 0.82rem;
            background: rgba(var(--bk-surface-rgb), 0.95);
        }

        .request-scope-item {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            font-size: 0.82rem;
            color: rgb(var(--bk-text-rgb));
        }

        .request-scope-item input {
            margin-top: 0.16rem;
            accent-color: rgb(var(--bk-primary-rgb));
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
            background: rgba(var(--bk-surface-rgb), 0.95);
            color: rgb(var(--bk-text-rgb));
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.35rem 0.7rem;
        }

        body.bk-role-page .review-modal .modal-backdrop.show,
        body.bk-role-page .modal-backdrop.show {
            opacity: 0.74 !important;
            background-color: rgba(12, 22, 39, 0.74) !important;
        }

        body.bk-role-page .review-modal {
            overflow-y: auto !important;
        }

        body.bk-role-page .review-modal .modal-dialog {
            margin: 1rem auto;
            max-width: min(1480px, calc(100vw - 1.25rem));
            min-height: 0 !important;
        }

        body.bk-role-page .review-modal .modal-dialog-scrollable {
            height: auto !important;
            max-height: none !important;
        }

        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 960px) {
            body.bk-role-page .review-modal .modal-body {
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
                position: static;
                height: auto;
                max-height: none;
                overflow: visible;
                padding-right: 0;
            }

            .review-sidebar-card,
            body.bk-role-page .review-action-card {
                max-height: none;
                overflow: visible;
            }
        }

        @media (max-width: 767.98px) {
            .claims-wrapper { padding: 0.85rem 0.75rem 1.3rem; }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                justify-content: stretch;
            }

            .filter-actions .ui-btn,
            .claims-total {
                width: 100%;
                justify-content: center;
            }

            body.bk-role-page .review-modal .modal-dialog {
                margin: 0.5rem;
                max-width: calc(100vw - 1rem);
                min-height: 0 !important;
            }

            body.bk-role-page .review-modal .modal-dialog-scrollable {
                height: auto !important;
                max-height: none !important;
            }

            .field-info-tip {
                right: auto;
                left: 0;
                max-width: min(88vw, 21rem);
            }

            .request-scope-grid {
                grid-template-columns: 1fr;
            }

            .legal-route-grid {
                grid-template-columns: 1fr;
            }

            .legal-card-top {
                grid-template-columns: auto minmax(0, 1fr);
            }

            .legal-card-badge {
                grid-column: 1 / -1;
                justify-self: start;
            }

            .legal-action-rail {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="bk-role-page bk-role-legal">
    <!-- Navigation -->
    <?php include 'navbar.php'; ?>
    <main class="main-content claims-wrapper">
    <section class="claims-hero">
        <div class="claims-page-header mb-0">
            <div>
                <h2 class="fw-bold mb-2">Claims Review</h2>
                <p class="text-muted mb-0">Review claims in your legal queue, record notes, and route approved files to Finance.</p>
            </div>
            <div class="claims-tools">
                <a href="export_report.php?<?php echo bk_e(http_build_query($_GET)); ?>" target="_blank" rel="noopener" class="ui-btn ui-btn-sm ui-btn-secondary">
                    <i class="bi bi-file-earmark-pdf"></i><span>Export PDF</span>
                </a>
            </div>
        </div>
        <div class="queue-alert-row">
            <span class="queue-alert-chip"><strong><?php echo number_format((int) ($legalQueueNotificationStats['unread_total'] ?? 0)); ?></strong>Unread alerts</span>
            <span class="queue-alert-chip"><strong><?php echo number_format((int) ($legalQueueNotificationStats['new_assignments'] ?? 0)); ?></strong>New queue assignments</span>
            <span class="queue-alert-chip"><strong><?php echo number_format((int) ($legalQueueNotificationStats['manual_flags'] ?? 0)); ?></strong>Manual review flags</span>
            <span class="queue-alert-chip"><strong><?php echo number_format((int) ($legalQueueNotificationStats['claimant_updates'] ?? 0)); ?></strong>Claimant follow-ups</span>
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
        <div class="filter-card">
            <form method="GET" class="filter-grid">
                <div class="field">
                    <label for="search">Search</label>
                    <input
                        id="search"
                        type="text"
                        class="ui-input"
                        name="search"
                        placeholder="ID, claimant, or destination detail"
                        value="<?php echo bk_e($searchInput); ?>"
                    >
                </div>

                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" class="ui-select" name="status">
                        <option value="">All statuses</option>
                        <option value="Pending Legal Review" <?php echo $currentStatusKey === legal_claim_status_key('Pending Legal Review') ? 'selected' : ''; ?>>Pending Legal Review</option>
                        <option value="Manual Legal Review Required" <?php echo $currentStatusKey === legal_claim_status_key('Manual Legal Review Required') ? 'selected' : ''; ?>>Manual Legal Review</option>
                        <option value="More Information Required" <?php echo $currentStatusKey === legal_claim_status_key('More Information Required') ? 'selected' : ''; ?>>More Information Required</option>
                        <option value="Rejected by Legal" <?php echo $currentStatusKey === legal_claim_status_key('Rejected by Legal') ? 'selected' : ''; ?>>Rejected by Legal</option>
                    </select>
                </div>

                <div class="field">
                    <label for="date_from">From Date</label>
                    <input id="date_from" type="date" class="ui-input" name="date_from" value="<?php echo bk_e($dateFromInput); ?>">
                </div>

                <div class="field">
                    <label for="date_to">To Date</label>
                    <input id="date_to" type="date" class="ui-input" name="date_to" value="<?php echo bk_e($dateToInput); ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-primary">
                        <i class="bi bi-funnel"></i><span>Apply</span>
                    </button>
                    <a href="claims.php" class="ui-btn ui-btn-sm ui-btn-secondary">Reset</a>
                    <span class="claims-total"><?php echo number_format($totalRows); ?> claims</span>
                </div>
            </form>
        </div>

        <!-- Status Filter Quick Links -->
        <div class="status-shortcuts">
            <?php
            $chipStatuses = [
                '' => ['All', (int) array_sum($statusStats)],
                'Pending Legal Review' => ['Pending Legal Review', (int) ($statusStats[legal_claim_status_key('Pending Legal Review')] ?? 0)],
                'Manual Legal Review Required' => ['Manual Legal Review', (int) ($statusStats[legal_claim_status_key('Manual Legal Review Required')] ?? 0)],
                'More Information Required' => ['More Information Required', (int) ($statusStats[legal_claim_status_key('More Information Required')] ?? 0)],
                'Rejected by Legal' => ['Rejected by Legal', (int) ($statusStats[legal_claim_status_key('Rejected by Legal')] ?? 0)],
            ];
            foreach ($chipStatuses as $chipStatus => $chipConfig):
                [$chipLabel, $chipCount] = $chipConfig;
                $params = $baseParams;
                if ($chipStatus === '') {
                    unset($params['status']);
                } else {
                    $params['status'] = $chipStatus;
                }
                $active = $currentStatusKey === legal_claim_status_key($chipStatus);
                ?>
                <a href="?<?php echo bk_e(http_build_query($params)); ?>" class="status-chip<?php echo $active ? ' active' : ''; ?>">
                    <?php echo bk_e($chipLabel); ?>
                    <span class="chip-count"><?php echo number_format($chipCount); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Claims Table -->
        <div class="table-shell">
            <div class="table-scroll bk-table-shell">
            <table class="claims-table" data-udcs-expand-group data-udcs-expand-single="true">
                <thead>
                    <tr>
                        <th class="is-case"><span class="table-entity-label"><i class="bi bi-folder2-open"></i>Case</span></th>
                        <th class="is-assets"><span class="table-entity-label"><i class="bi bi-bank"></i>BK Assets</span></th>
                        <th class="is-signals"><span class="table-entity-label"><i class="bi bi-shield-check"></i>Review Signals</span></th>
                        <th class="is-status"><span class="table-entity-label"><i class="bi bi-check2-circle"></i>Status</span></th>
                        <th class="is-date"><span class="table-entity-label"><i class="bi bi-calendar2-week"></i>Date</span></th>
                        <th class="is-actions"><span class="table-entity-label"><i class="bi bi-sliders"></i>Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($legalQueueRows) > 0): ?>
                        <?php foreach ($legalQueueRows as $queueItem): ?>
                            <?php
                            $row = $queueItem['row'];
                            $contract = $queueItem['contract'];
                            $peopleSummary = (array) ($contract['people']['summary'] ?? []);
                            $assetSummary = (array) ($contract['assets']['summary'] ?? []);
                            $documentSummary = (array) ($contract['documents']['summary'] ?? []);
                            $reviewSummary = (array) ($contract['review'] ?? []);
                            $payoutSummary = (array) ($contract['payout'] ?? []);
                            $statusLabel = (string) ($contract['status']['label'] ?? legal_claim_status_label((string) ($row['effective_status'] ?? '')));
                            $statusClass = legal_claim_status_class($statusLabel);
                            $statusKey = (string) ($contract['status']['key'] ?? legal_claim_status_key($statusLabel));
                            $claimantDisplayName = (string) (($peopleSummary['claimant_name'] ?? '') !== '' ? $peopleSummary['claimant_name'] : ($row['full_name'] ?? 'Unknown claimant'));
                            $deceasedDisplayName = (string) (($peopleSummary['deceased_name'] ?? '') !== '' ? $peopleSummary['deceased_name'] : ($row['deceased_full_name'] ?? $row['deceased_name'] ?? 'Deceased person not named'));
                            $relationshipLabel = udcs_claim_relationship_label((string) ($row['relationship'] ?? ''));
                            $maritalStatusLabel = ucwords(strtolower(str_replace('_', ' ', (string) ($row['marital_status'] ?? 'Not specified'))));
                            $childrenStatusLabel = ucwords(strtolower(str_replace('_', ' ', (string) ($peopleSummary['children_status'] ?? $row['children_status'] ?? 'Not specified'))));
                            $spouseState = !empty($peopleSummary['spouse_required'])
                                ? (!empty($peopleSummary['spouse_declared']) ? 'Spouse declared' : 'Spouse missing')
                                : 'Spouse not required';
                            $childCount = (int) ($peopleSummary['child_count'] ?? 0);
                            $coHeirCount = (int) ($peopleSummary['co_heir_count'] ?? 0);
                            $assetCount = (int) ($assetSummary['count'] ?? 0);
                            $estimatedLabel = (string) (($assetSummary['estimated_total_label'] ?? '') !== ''
                                ? $assetSummary['estimated_total_label']
                                : ($contract['summary']['claimant_value_label'] ?? 'Not declared'));
                            $payoutLabel = (string) ($payoutSummary['preferred_label'] ?? 'Not selected');
                            $destinationComplete = (bool) ($payoutSummary['destination_complete'] ?? false);
                            $destinationSummary = bk_claim_destination_summary(
                                bk_claim_account_reference($row),
                                (string) ($row['distribution_method'] ?? ''),
                                (string) ($row['distribution_details'] ?? '')
                            );
                            $docCount = (int) ($documentSummary['count'] ?? 0);
                            $ocrPassed = (int) ($documentSummary['ocr_passed_count'] ?? 0);
                            $ocrFailed = (int) ($documentSummary['ocr_failed_count'] ?? 0);
                            $ocrPending = (int) ($documentSummary['ocr_pending_count'] ?? 0);
                            $legalAccepted = (int) ($documentSummary['legal_accepted_count'] ?? 0);
                            $legalRejected = (int) ($documentSummary['legal_rejected_count'] ?? 0);
                            $allFlags = (array) ($reviewSummary['flags'] ?? []);
                            $allFlagSummary = !empty($allFlags)
                                ? implode(' | ', array_map(
                                    static fn(array $flag): string => trim((string) ($flag['label'] ?? 'Review flag')),
                                    $allFlags
                                ))
                                : 'No automatic legal risk flags.';
                            $criticalFlags = 0;
                            $warningFlags = 0;
                            foreach ($allFlags as $flag) {
                                $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
                                if ($severity === 'danger') {
                                    $criticalFlags++;
                                } elseif ($severity === 'warning') {
                                    $warningFlags++;
                                }
                            }
                            $flags = array_slice($allFlags, 0, 1);
                            $topFlag = !empty($flags) ? $flags[0] : null;
                            $submittedDate = (string) (($row['submitted_date'] ?? '') !== '' ? $row['submitted_date'] : ($row['submitted_at'] ?? 'Not submitted'));
                            $expandPanelId = 'legal-claim-expand-' . (int) ($row['id'] ?? 0);
                            $statusSupportLabel = trim((string) ($row['manual_review_reason'] ?? '')) !== ''
                                ? ('Flag: ' . (string) ($row['manual_review_reason'] ?? ''))
                                : ((int) $docCount > 0
                                    ? number_format($docCount) . ' file(s) attached'
                                    : 'No supporting files yet');
                            ?>
                            <tr>
                                <td class="is-case">
                                    <div class="legal-case-stack">
                                        <div class="queue-case-title">
                                            <span class="claim-id">CL-<?php echo str_pad((string) ($row['id'] ?? 0), 6, '0', STR_PAD_LEFT); ?></span>
                                            <?php if (!empty($contract['status']['is_legacy'])): ?>
                                                <span class="doc-pill">Legacy</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="legal-case-path"><?php echo bk_e($relationshipLabel); ?></div>
                                        <div class="queue-case-meta">
                                            <span><strong><?php echo bk_e($claimantDisplayName); ?></strong></span>
                                            <span><?php echo bk_e((string) ($row['email'] ?? '')); ?></span>
                                            <span>Deceased: <?php echo bk_e($deceasedDisplayName); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="is-assets">
                                    <div class="queue-asset-title"><?php echo bk_e((string) ($assetSummary['label'] ?? 'No BK assets linked')); ?></div>
                                    <div class="legal-kv-list">
                                        <div class="legal-kv-line"><strong>Assets</strong><span><?php echo number_format($assetCount); ?></span></div>
                                        <div class="legal-kv-line"><strong>Estimate</strong><span><?php echo bk_e($estimatedLabel); ?></span></div>
                                    </div>
                                </td>
                                <td class="is-signals">
                                    <div class="queue-signal-stack">
                                        <span class="doc-pill"><?php echo number_format($criticalFlags); ?> critical / <?php echo number_format($warningFlags); ?> warning</span>
                                        <?php if ($topFlag !== null): ?>
                                                <?php $signalClass = legal_claim_review_signal_class((string) ($topFlag['severity'] ?? '')); ?>
                                                <span class="queue-signal is-compact <?php echo bk_e($signalClass); ?>">
                                                    <i class="bi <?php echo $signalClass === 'is-danger' ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill'; ?>"></i>
                                                    <?php echo bk_e((string) ($topFlag['label'] ?? 'Review flag')); ?>
                                                </span>
                                            <?php if (count($allFlags) > 1): ?>
                                                <div class="queue-hidden-hint">Open claim to view <?php echo number_format(count($allFlags) - 1); ?> more signal(s).</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="queue-signal is-compact is-ok">
                                                <i class="bi bi-check-circle-fill"></i>
                                                No automatic legal risk flags
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="is-status">
                                    <div class="legal-status-stack">
                                        <span class="status-badge <?php echo bk_e($statusClass); ?>"><?php echo bk_e($statusLabel); ?></span>
                                        <div class="legal-status-note"><?php echo bk_e($statusSupportLabel); ?></div>
                                    </div>
                                </td>
                                <td class="is-date">
                                    <div class="subtle"><?php echo bk_e($submittedDate); ?></div>
                                </td>
                                <td class="is-actions">
                                    <div class="actions">
                                        <?php udcs_claims_list_render_expand_button($expandPanelId, ['label' => 'More']); ?>
                                        <button
                                            type="button"
                                            class="ui-btn ui-btn-sm ui-btn-secondary action-btn open-btn is-view"
                                            onclick='openReviewModal(<?php echo (int) ($row['id'] ?? 0); ?>, <?php echo json_encode($statusKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                            title="Open review panel"
                                            aria-label="Open review panel"
                                        >
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
                                    'title' => 'Family Disclosure',
                                    'lines' => [
                                        ['label' => 'Relationship', 'value' => $relationshipLabel],
                                        ['label' => 'Marital status', 'value' => $maritalStatusLabel],
                                        ['label' => 'Children status', 'value' => $childrenStatusLabel],
                                        ['label' => 'Spouse path', 'value' => $spouseState],
                                    ],
                                ],
                                [
                                    'title' => 'Review Signals',
                                    'lines' => [
                                        ['label' => 'All signals', 'value' => $allFlagSummary],
                                        ['label' => 'Critical flags', 'value' => number_format($criticalFlags)],
                                        ['label' => 'Warning flags', 'value' => number_format($warningFlags)],
                                        ['label' => 'Manual review reason', 'value' => (string) (($row['manual_review_reason'] ?? '') !== '' ? $row['manual_review_reason'] : 'Not flagged')],
                                    ],
                                ],
                                [
                                    'title' => 'Documents and Settlement',
                                    'lines' => [
                                        ['label' => 'Documents uploaded', 'value' => number_format($docCount)],
                                        ['label' => 'OCR passed', 'value' => number_format($ocrPassed) . '/' . number_format($docCount)],
                                        ['label' => 'Preferred settlement', 'value' => $payoutLabel],
                                        ['label' => 'Destination summary', 'value' => $destinationSummary . ($destinationComplete ? ' | Destination captured' : ' | Destination needs review')],
                                    ],
                                ],
                            ]);
                            ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No claims found for your current filters.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="pager" aria-label="Claims pagination">
                    <?php if ($page > 1): ?>
                        <a class="pager-link" href="<?php echo bk_e(legal_claim_page_url($page - 1, $baseParams)); ?>" aria-label="Previous page">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a class="pager-link<?php echo $i === $page ? ' is-current' : ''; ?>" href="<?php echo bk_e(legal_claim_page_url($i, $baseParams)); ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="pager-link" href="<?php echo bk_e(legal_claim_page_url($page + 1, $baseParams)); ?>" aria-label="Next page">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
        </nav>
        <p class="pager-note">
            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
            (Showing <?php echo max(0, min($limit, $totalRows - $offset)); ?> of <?php echo $totalRows; ?> claims)
        </p>
        <?php endif; ?>
    </div>
    
    <!-- Review Modal -->
    <div class="modal fade review-modal" id="reviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl-custom modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content review-modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clipboard-check me-2"></i>
                        Claim Review
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <form method="POST" id="reviewForm">
                    <input type="hidden" name="claim_id" id="modalClaimId">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($legalClaimsCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" id="modalActionInput" value="comment">

                    <div class="modal-body">
                        <div class="review-workspace">
                            <div class="claim-detail-panel" id="claimDetails">
                                <div class="text-muted">Loading claim details...</div>
                            </div>

                            <aside class="review-sidebar">
                                <section class="review-sidebar-card legal-notes-card">
                                    <div class="legal-card-top">
                                        <span class="legal-card-icon" aria-hidden="true"><i class="bi bi-journal-richtext"></i></span>
                                        <div>
                                            <h6 class="legal-card-title">Legal Notes</h6>
                                            <p class="legal-card-note">Reviewer memory, previous decisions, and claimant-facing guidance stay here so the decision area remains focused.</p>
                                        </div>
                                        <span class="legal-card-badge">Context</span>
                                    </div>
                                    <div class="legal-notes-chips" aria-label="Legal notes purpose">
                                        <span class="legal-note-chip"><i class="bi bi-clock-history"></i> Previous notes</span>
                                        <span class="legal-note-chip"><i class="bi bi-person-lines-fill"></i> Claimant guidance</span>
                                        <span class="legal-note-chip"><i class="bi bi-shield-check"></i> Legal trace</span>
                                    </div>
                                    <div class="legal-notes-body">
                                        <div id="commentsSection" class="pre-line comments-box">
                                            <p class="text-muted mb-0">Loading comments...</p>
                                        </div>
                                    </div>
                                </section>

                                <section class="review-action-card legal-action-card" id="legalActionCard" data-action="comment">
                                    <div class="legal-card-top is-action">
                                        <span class="legal-card-icon" aria-hidden="true"><i class="bi bi-compass"></i></span>
                                        <div>
                                            <h6 class="legal-card-title">Take Action</h6>
                                            <p class="legal-card-note">Choose the legal outcome, complete only the required controls, then submit a clean auditable decision.</p>
                                        </div>
                                        <span class="legal-card-badge" id="decisionStateBadge">Draft</span>
                                    </div>
                                    <div class="legal-action-rail" aria-label="Legal action flow">
                                        <div class="legal-action-step is-active">
                                            <strong>1. Decide</strong>
                                            <span>Select the legal route.</span>
                                        </div>
                                        <div class="legal-action-step">
                                            <strong>2. Validate</strong>
                                            <span>Complete gates only when needed.</span>
                                        </div>
                                        <div class="legal-action-step">
                                            <strong>3. Submit</strong>
                                            <span>Send the auditable outcome.</span>
                                        </div>
                                    </div>
                                    <div class="review-action-grid">
                                <div class="legal-route-grid" id="legalRouteGrid" aria-label="Legal decision routes">
                                    <button type="button" class="legal-route-option is-active" data-action-choice="comment">
                                        <span class="legal-route-top"><i class="bi bi-chat-left-text"></i>Note Only</span>
                                        <span>Keep the file moving without changing legal status.</span>
                                    </button>
                                    <button type="button" class="legal-route-option is-warning" data-action-choice="request_info">
                                        <span class="legal-route-top"><i class="bi bi-arrow-counterclockwise"></i>Request Updates</span>
                                        <span>Reopen exact claimant sections and preserve audit clarity.</span>
                                    </button>
                                    <button type="button" class="legal-route-option" data-action-choice="transfer">
                                        <span class="legal-route-top"><i class="bi bi-send-check"></i>Approve</span>
                                        <span>Send the legally cleared claim to Finance.</span>
                                    </button>
                                    <button type="button" class="legal-route-option is-danger" data-action-choice="reject">
                                        <span class="legal-route-top"><i class="bi bi-x-circle"></i>Reject</span>
                                        <span>Close the legal path with a clear claimant-facing reason.</span>
                                    </button>
                                </div>

                                <div class="ui-field legal-action-field">
                                    <div class="label-row">
                                        <label class="ui-label mb-0" for="modalActionSelect">Decision</label>
                                        <span class="field-info-wrap">
                                            <button type="button" class="field-info-btn" aria-label="What this field means">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                            <span class="field-info-tip">Choose whether to save a note, request targeted claimant corrections, approve to Finance, or reject the claim.</span>
                                        </span>
                                    </div>
                                    <select id="modalActionSelect" class="ui-select">
                                        <option value="comment">Save Review Note (No Final Decision)</option>
                                        <option value="request_info">Request More Information</option>
                                        <option value="transfer">Approve & Transfer to Finance</option>
                                        <option value="reject">Reject Claim</option>
                                    </select>
                                    <p id="actionGuide" class="subtle mb-0">Save your note without giving final approval or rejection.</p>
                                </div>

                                <div id="reviewChecklist" class="review-checklist">
                                    <label class="review-checklist-item">
                                        <input type="checkbox" class="review-gate">
                                        <span>I verified the claimant, deceased person, and relationship evidence.</span>
                                    </label>
                                    <label class="review-checklist-item">
                                        <input type="checkbox" class="review-gate">
                                        <span>I reviewed spouse, children, co-heir, will, and representation disclosures.</span>
                                    </label>
                                    <label class="review-checklist-item">
                                        <input type="checkbox" class="review-gate">
                                        <span>I confirmed the claim is legally ready for Finance to verify BK-held assets.</span>
                                    </label>
                                </div>

                                <div id="requestInfoScopeBlock" class="ui-field legal-action-field" style="display:none;">
                                    <div class="label-row">
                                        <label class="ui-label mb-0">Claimant Sections To Reopen</label>
                                        <span class="field-info-wrap">
                                            <button type="button" class="field-info-btn" aria-label="How reopened sections work">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                            <span class="field-info-tip">The claimant will only be able to update the sections you reopen here. Keep this targeted so the claim stays auditable.</span>
                                        </span>
                                    </div>
                                    <div class="request-scope-grid">
                                        <?php foreach ($reopenSectionLabels as $sectionKey => $sectionLabel): ?>
                                            <label class="request-scope-item">
                                                <input type="checkbox" name="reopen_sections[]" value="<?php echo bk_e($sectionKey); ?>" class="request-scope-check">
                                                <span><?php echo bk_e($sectionLabel); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="subtle mb-0">Reopen only what the claimant actually needs to correct.</p>
                                </div>

                                <div class="ui-field legal-action-field">
                                    <div class="label-row">
                                        <label class="ui-label mb-0" for="modalComment">Review note / rejection reason</label>
                                        <span class="field-info-wrap">
                                            <button type="button" class="field-info-btn" aria-label="How to fill review note">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                            <span class="field-info-tip">Write a clear explanation. If you request more information or reject the claim, state exactly what is missing or inconsistent and what the claimant should correct.</span>
                                        </span>
                                    </div>
                                    <textarea
                                        id="modalComment"
                                        class="ui-input"
                                        name="comment"
                                        rows="4"
                                        placeholder="Write your review note."
                                    ></textarea>
                                    <p id="commentHint" class="subtle mb-0">Write a short, clear note in plain language.</p>
                                </div>

                                        <div class="legal-action-footer d-flex flex-wrap gap-2 align-items-center justify-content-between">
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
    const submitBtn = document.getElementById('modalSubmitBtn');
    const submitIcon = document.getElementById('modalSubmitIcon');
    const submitLabel = document.getElementById('modalSubmitLabel');
    const actionSummary = document.getElementById('actionSummary');
    const legalActionCard = document.getElementById('legalActionCard');
    const decisionStateBadge = document.getElementById('decisionStateBadge');
    const routeButtons = Array.from(document.querySelectorAll('[data-action-choice]'));
    const checklistContainer = document.getElementById('reviewChecklist');
    const requestInfoScopeBlock = document.getElementById('requestInfoScopeBlock');
    const reviewChecks = Array.from(document.querySelectorAll('.review-gate'));
    const requestScopeChecks = Array.from(document.querySelectorAll('.request-scope-check'));
    const infoWraps = Array.from(document.querySelectorAll('.field-info-wrap'));
    const ACTIONABLE_STATUSES = new Set(['pending legal review', 'manual legal review required', 'more information required']);
    let isFinalDecisionLocked = false;

    const ACTION_CONFIG = {
        comment: {
            submit: 'Save Review Note',
            icon: 'bi-chat-left-text',
            summary: 'Review note only',
            badge: 'Draft Note',
            guide: 'Save your note without final approval or rejection.',
            hint: 'Write a short, clear note in plain language.',
            requireChecklist: false,
            requireReason: false,
            requireScope: false,
            buttonClass: 'ui-btn-primary',
            placeholder: 'Write your review note.'
        },
        request_info: {
            submit: 'Request More Information',
            icon: 'bi-arrow-counterclockwise',
            summary: 'Return for targeted corrections',
            badge: 'Claimant Update',
            guide: 'This sends the claim back to the claimant and reopens only the sections you choose below.',
            hint: 'State what must be corrected and why. The claimant will see this guidance again inside the form.',
            requireChecklist: false,
            requireReason: true,
            requireScope: true,
            buttonClass: 'ui-btn-primary',
            placeholder: 'Explain what the claimant must update before legal review can continue.'
        },
        transfer: {
            submit: 'Approve & Transfer',
            icon: 'bi-check-circle',
            summary: 'Final decision: approve',
            badge: 'Approve',
            guide: 'This confirms Legal is satisfied with the disclosure, documents, and review path, then sends the claim to Finance for BK asset verification.',
            hint: 'Complete all checks. Add a short note if Finance needs context.',
            requireChecklist: true,
            requireReason: false,
            requireScope: false,
            buttonClass: 'ui-btn-primary',
            placeholder: 'Add optional note for Finance and claimant.'
        },
        reject: {
            submit: 'Reject Claim',
            icon: 'bi-x-circle',
            summary: 'Final decision: reject',
            badge: 'Reject',
            guide: 'This rejects the claim at Legal stage and sends your reason to the claimant.',
            hint: 'Explain clearly what is legally insufficient, contradictory, or unsupported.',
            requireChecklist: true,
            requireReason: true,
            requireScope: false,
            buttonClass: 'ui-btn-secondary text-bk-danger border-bk-danger/30',
            placeholder: 'State exactly why this claim was rejected.'
        }
    };

    function isChecklistComplete() {
        return reviewChecks.every((box) => box.checked);
    }

    function hasRequestScopeSelection() {
        return requestScopeChecks.some((box) => box.checked);
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

    function applyStatusConstraints(statusKey) {
        const normalized = String(statusKey || '')
            .toLowerCase()
            .replace(/_/g, ' ')
            .trim();
        isFinalDecisionLocked = normalized !== '' && !ACTIONABLE_STATUSES.has(normalized);

        Array.from(actionSelect.options).forEach((option) => {
            if (option.value === 'request_info' || option.value === 'transfer' || option.value === 'reject') {
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
        commentInput.placeholder = config.placeholder;
        actionSummary.innerHTML = `<i class="bi ${config.icon}"></i>${config.summary}`;
        if (legalActionCard) {
            legalActionCard.dataset.action = action;
        }
        if (decisionStateBadge) {
            decisionStateBadge.textContent = config.badge;
        }
        routeButtons.forEach((button) => {
            const isActive = button.getAttribute('data-action-choice') === action;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            button.disabled = isFinalDecisionLocked && action !== 'comment';
        });

        submitLabel.textContent = config.submit;
        submitIcon.className = `bi ${config.icon}`;
        submitBtn.className = `ui-btn ui-btn-sm ${config.buttonClass}`;

        if (isFinalDecisionLocked) {
            checklistContainer.style.display = 'none';
            requestInfoScopeBlock.style.display = 'none';
            actionGuide.textContent = 'This claim is not currently in an actionable Legal review state. You can only add an additional note.';
            commentHint.textContent = 'Add a short clarification note if needed.';
            actionSummary.innerHTML = '<i class="bi bi-lock"></i>Legal action locked for this status';
            if (legalActionCard) {
                legalActionCard.dataset.action = 'locked';
            }
            if (decisionStateBadge) {
                decisionStateBadge.textContent = 'Locked';
            }
            routeButtons.forEach((button) => {
                const isComment = button.getAttribute('data-action-choice') === 'comment';
                button.classList.toggle('is-active', isComment);
                button.setAttribute('aria-pressed', isComment ? 'true' : 'false');
                button.disabled = !isComment;
            });
            submitLabel.textContent = 'Add Follow-up Note';
            submitIcon.className = 'bi bi-chat-left-text';
            submitBtn.className = 'ui-btn ui-btn-sm ui-btn-secondary';
            submitBtn.disabled = commentInput.value.trim() === '';
            return;
        }

        checklistContainer.style.display = config.requireChecklist ? 'grid' : 'none';
        requestInfoScopeBlock.style.display = config.requireScope ? 'grid' : 'none';

        const reason = commentInput.value.trim();
        const reasonOk = !config.requireReason || reason.length >= 12;
        const checksOk = !config.requireChecklist || isChecklistComplete();
        const scopeOk = !config.requireScope || hasRequestScopeSelection();
        submitBtn.disabled = !(reasonOk && checksOk && scopeOk);
    }

    actionSelect.addEventListener('change', syncActionState);
    routeButtons.forEach((button) => {
        button.setAttribute('aria-pressed', button.classList.contains('is-active') ? 'true' : 'false');
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }
            const action = button.getAttribute('data-action-choice') || 'comment';
            actionSelect.value = action;
            syncActionState();
        });
    });
    commentInput.addEventListener('input', syncActionState);
    reviewChecks.forEach((box) => box.addEventListener('change', syncActionState));
    requestScopeChecks.forEach((box) => box.addEventListener('change', syncActionState));

    function resetReviewFormState() {
        actionSelect.value = 'comment';
        commentInput.value = '';
        reviewChecks.forEach((box) => { box.checked = false; });
        requestScopeChecks.forEach((box) => { box.checked = false; });
        closeInfoTips();
    }

    async function loadPanelData(claimId) {
        claimDetailsBox.innerHTML = '<div class="text-muted">Loading claim details...</div>';
        commentsSection.innerHTML = '<p class="text-muted mb-0">Loading comments...</p>';

        try {
            const [detailsResponse, commentsResponse] = await Promise.all([
                fetch(`load_claim_details.php?id=${encodeURIComponent(claimId)}`),
                fetch(`load_comments.php?claim_id=${encodeURIComponent(claimId)}`)
            ]);
            claimDetailsBox.innerHTML = await detailsResponse.text();
            commentsSection.innerHTML = await commentsResponse.text() || '<p class="text-muted mb-0">No comments yet.</p>';
        } catch (error) {
            claimDetailsBox.innerHTML = '<div class="text-danger">Could not load claim details. Please retry.</div>';
            commentsSection.innerHTML = '<p class="text-danger mb-0">Could not load comments.</p>';
        }
    }

    function openReviewModal(claimId, statusKey = '') {
        modalClaimId.value = String(claimId);
        resetReviewFormState();
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
            alert('This claim is not currently in an actionable Legal review state. Add an additional note instead.');
            return;
        }

        if (action === 'comment' && reason === '') {
            e.preventDefault();
            alert('Please enter a review note before saving.');
            return;
        }

        if (config.requireReason && reason.length < 12) {
            e.preventDefault();
            alert('Please provide a clear rejection reason (minimum 12 characters).');
            return;
        }

        if (config.requireChecklist && !isChecklistComplete()) {
            e.preventDefault();
            alert('Please tick all three review checks before finalizing this decision.');
            return;
        }

        if (config.requireScope && !hasRequestScopeSelection()) {
            e.preventDefault();
            alert('Select at least one claimant section to reopen.');
            return;
        }

        const confirmText = {
            comment: 'Save this review note?',
            request_info: 'Request more information from the claimant using the selected reopened sections?',
            transfer: 'Approve this claim and transfer it to Finance?',
            reject: 'Reject this claim and notify the claimant?'
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

    // Initialize default UI state.
    applyStatusConstraints('');
    syncActionState();
    </script>
<?php udcs_claims_list_render_assets(); ?>
</body>
</html>


