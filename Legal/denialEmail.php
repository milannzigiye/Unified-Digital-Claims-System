<?php
// Tags: [LEGAL] [NOTIFY] [EMAIL]
require_once '../security.php';
secure_session_start();

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'legal') {
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
$actionPayload = udcs_action_token_consume('legal_denial_email', $actionToken);
if (!is_array($actionPayload)) {
    $_SESSION['alertMessage'] = 'Rejection token is missing or expired.';
    $_SESSION['alertType'] = 'danger';
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

if ($claimId <= 0 || $primaryEmail === '') {
    $_SESSION['alertMessage'] = 'Notification payload is incomplete.';
    $_SESSION['alertType'] = 'danger';
    header('Location: claims.php');
    exit();
}

$claim = udcs_fetch_claim_for_email($conn, $primaryEmail, ['rejected by legal'], $claimId);
if (!$claim) {
    $_SESSION['alertMessage'] = 'No rejected claim was found for this notification.';
    $_SESSION['alertType'] = 'danger';
    header('Location: claims.php');
    exit();
}

$claimId = (int) ($claim['id'] ?? 0);

$recipients = udcs_email_collect_recipients(array_merge(
    $emailArray,
    [(string) ($claim['email'] ?? ''), (string) ($claim['alt_email'] ?? '')]
));

if (empty($recipients)) {
    $_SESSION['alertMessage'] = 'No valid recipient email address was found.';
    $_SESSION['alertType'] = 'danger';
    header('Location: claims.php');
    exit();
}

$docSummary = udcs_fetch_claim_document_summary($conn, $claimId);
$decisionNote = udcs_extract_decision_note((string) ($claim['comment'] ?? ''), 'legal_rejected');
$claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
$financeCurrency = bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? $claimCurrency));

$payload = udcs_render_claim_email_payload('legal_rejected', [
    'id' => $claimId,
    'reference' => 'BK-' . str_pad((string) $claimId, 8, '0', STR_PAD_LEFT),
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
    udcs_email_configure_mailer($mail, 'Legal Department | UNIFIED DIGITAL CLAIMS SYSTEM');

    foreach ($recipients as $recipient) {
        $mail->addAddress($recipient);
    }

    $mail->isHTML(true);
    $mail->Subject = (string) $payload['subject'];
    $mail->Body = (string) $payload['html'];
    $mail->AltBody = (string) $payload['text'];
    $mail->send();

    $_SESSION['alertMessage'] = 'Rejection email sent successfully to ' . count($recipients) . ' recipient(s).';
    $_SESSION['alertType'] = 'success';
    header('Location: claims.php');
    exit();
} catch (Exception $e) {
    error_log('denialEmail.php (legal) send failed: ' . $mail->ErrorInfo);
    $_SESSION['alertMessage'] = 'Rejection email could not be sent. Please try again.';
    $_SESSION['alertType'] = 'danger';
    header('Location: claims.php');
    exit();
}
?>

