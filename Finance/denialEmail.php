<?php
// Tags: [FINANCE] [NOTIFY] [EMAIL]
require_once '../security.php';
secure_session_start();

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'finance') {
    header('Location: ../login.php');
    exit();
}

include '../connect.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/claim_email_helper.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$actionToken = trim((string) ($_GET['action_token'] ?? ''));
$actionPayload = udcs_action_token_consume('finance_denial_email', $actionToken);
if (!is_array($actionPayload)) {
    $_SESSION['error'] = 'Claim was returned, but the email action token is missing or expired.';
    header('Location: claims.php');
    exit();
}

$claimId = (int) ($actionPayload['claim_id'] ?? 0);
$emailArrayRaw = $actionPayload['emails'] ?? [];
if (!is_array($emailArrayRaw)) {
    $emailArrayRaw = [];
}
$emailArray = array_values(array_filter(array_map(static fn($email): string => trim((string) $email), $emailArrayRaw)));
$primaryEmail = $emailArray[0] ?? '';
$returnRoute = strtolower(trim((string) ($actionPayload['route'] ?? 'claimant')));
if (!in_array($returnRoute, ['claimant', 'legal'], true)) {
    $returnRoute = 'claimant';
}

if ($claimId <= 0 || $primaryEmail === '') {
    $_SESSION['error'] = 'Claim was returned, but required notification payload is incomplete.';
    header('Location: claims.php');
    exit();
}

$allowedStatuses = $returnRoute === 'legal'
    ? ['pending legal review', 'manual legal review required', 'returned by finance', 'rejected by finance']
    : ['returned by finance', 'rejected by finance'];
$claim = udcs_fetch_claim_for_email($conn, $primaryEmail, $allowedStatuses, $claimId);
if (!$claim) {
    $_SESSION['error'] = 'Claim return was saved, but the updated claim record could not be loaded for email notification.';
    header('Location: claims.php');
    exit();
}

$claimId = (int) ($claim['id'] ?? 0);

$recipients = udcs_email_collect_recipients(array_merge(
    $emailArray,
    [(string) ($claim['email'] ?? ''), (string) ($claim['alt_email'] ?? '')]
));

if (empty($recipients)) {
    $_SESSION['error'] = 'Claim return was saved, but no valid recipient email was available for notification.';
    header('Location: claims.php');
    exit();
}

$docSummary = udcs_fetch_claim_document_summary($conn, $claimId);
$decisionNote = udcs_extract_decision_note((string) ($claim['comment'] ?? ''), 'finance_rejected');
$routeHeadline = $returnRoute === 'legal'
    ? 'Finance returned the claim to Legal'
    : 'Finance returned the claim for claimant update';
$routeDecisionLabel = $returnRoute === 'legal'
    ? 'Returned by Finance to Legal for re-review'
    : 'Returned by Finance to claimant';
$routeBadge = $returnRoute === 'legal' ? 'Back In Legal Review' : 'Claimant Update Needed';
$routeIntro = $returnRoute === 'legal'
    ? 'Finance could not complete the bank-side step and sent the claim back to Legal for another controlled review. You do not need to act unless Legal later requests more information.'
    : 'Finance could not complete the bank-side step and returned the claim so you can correct the requested details before processing continues.';
$routeSteps = $returnRoute === 'legal'
    ? [
        'No claimant action is required right now unless Legal asks for more information.',
        'Follow your claimant portal for the next legal review outcome or any clarification request.',
        'Keep this email for reference while the claim returns to the legal review path.',
    ]
    : [
        'Review the finance return reason below.',
        'Update the requested payout detail or supporting information in your claimant portal.',
        'Resubmit the corrected claim so the workflow can continue.',
    ];
$claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
$financeCurrency = bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? $claimCurrency));

$payload = udcs_render_claim_email_payload('finance_rejected', [
    'id' => $claimId,
    'reference' => 'BK-' . str_pad((string) $claimId, 8, '0', STR_PAD_LEFT),
    'headline' => $routeHeadline,
    'decision_label' => $routeDecisionLabel,
    'badge' => $routeBadge,
    'intro' => $routeIntro,
    'next_steps' => $routeSteps,
    'full_name' => (string) ($claim['full_name'] ?? 'Claimant'),
    'claim_type_label' => udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? '')),
    'claim_amount_label' => bk_claim_amount_display_for_type(
        $claim['claim_amount'] ?? null,
        (string) ($claim['claim_type'] ?? ''),
        $claimCurrency
    ),
    'finance_assessed_amount_label' => bk_claim_amount_display(
        $claim['finance_assessed_amount'] ?? null,
        $financeCurrency,
        ''
    ),
    'submitted_date' => !empty($claim['submitted_at']) ? date('F j, Y', strtotime((string) $claim['submitted_at'])) : 'N/A',
    'decision_date' => !empty($claim['updated_at']) ? date('F j, Y', strtotime((string) $claim['updated_at'])) : date('F j, Y'),
    'deceased_name' => (string) ($claim['deceased_name'] ?? 'N/A'),
    'deceased_national_id' => (string) ($claim['deceased_national_id'] ?? 'N/A'),
    'relationship' => ucwords(str_replace('_', ' ', (string) ($claim['relationship'] ?? 'N/A'))),
    'account_number' => bk_claim_destination_summary(
        bk_claim_account_reference($claim),
        (string) ($claim['distribution_method'] ?? ''),
        (string) ($claim['distribution_details'] ?? '')
    ),
    'distribution_method' => (string) ($claim['distribution_method'] ?? ''),
    'distribution_details' => (string) ($claim['distribution_details'] ?? ''),
    'notes' => $decisionNote,
    'documents_count' => (int) ($docSummary['count'] ?? 0),
    'documents_types' => (array) ($docSummary['types'] ?? []),
    'documents_summary' => (string) ($docSummary['summary'] ?? ''),
]);

$mail = new PHPMailer(true);

try {
    udcs_email_configure_mailer($mail, 'Finance Department | UNIFIED DIGITAL CLAIMS SYSTEM');

    foreach ($recipients as $recipient) {
        $mail->addAddress($recipient);
    }

    $mail->isHTML(true);
    $mail->Subject = (string) $payload['subject'];
    $mail->Body = (string) $payload['html'];
    $mail->AltBody = (string) $payload['text'];
    $mail->send();

    $_SESSION['success'] = 'Finance return email sent to ' . count($recipients) . ' recipient(s).';
    header('Location: claims.php');
    exit();
} catch (Exception $e) {
    error_log('denialEmail.php (finance) send failed: ' . $mail->ErrorInfo);
    $_SESSION['success'] = 'Finance return was recorded. Email notification failed to send; the updated status is still visible in the portal.';
    header('Location: claims.php');
    exit();
}
?>

