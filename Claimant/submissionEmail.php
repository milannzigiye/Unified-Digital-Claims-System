<?php
// Tags: [CLAIMANT] [NOTIFY] [EMAIL]
require_once '../security.php';
secure_session_start();

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'claimant') {
    header('Location: ../claimant-access.php');
    exit();
}

include '../connect.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/claim_email_helper.php';
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!$conn) {
    $_SESSION['toast'] = [
        'type' => 'error',
        'title' => 'Submission Email',
        'message' => 'Could not connect to the database for submission email dispatch.',
    ];
    header('Location: claims.php');
    exit();
}

$claimId = isset($_GET['claim_id']) ? (int) $_GET['claim_id'] : 0;
$emailParam = urldecode((string) ($_GET['email'] ?? ''));
$requestedEmails = array_values(array_filter(array_map('trim', explode(',', $emailParam))));

if ($claimId <= 0 && empty($requestedEmails)) {
    $_SESSION['toast'] = [
        'type' => 'warning',
        'title' => 'Submission Email',
        'message' => 'Claim submission was saved, but no email recipient was provided.',
    ];
    header('Location: claims.php');
    exit();
}

$currentEmail = trim((string) ($_SESSION['email'] ?? ''));
$fetchEmail = $requestedEmails[0] ?? $currentEmail;

$statusKeys = [
    'pending',
    'under_review',
    'under review',
    'transferred to finance',
    'approved by finance',
    'rejected by legal',
    'rejected by finance',
];

$claim = udcs_fetch_claim_for_email($conn, $fetchEmail, $statusKeys, $claimId);
if (!$claim) {
    $_SESSION['toast'] = [
        'type' => 'warning',
        'title' => 'Submission Email',
        'message' => 'Claim submission was saved, but the confirmation email record could not be found.',
    ];
    header('Location: claims.php');
    exit();
}

$claimOwnerEmail = strtolower(trim((string) ($claim['email'] ?? '')));
if ($claimOwnerEmail !== strtolower($currentEmail)) {
    $_SESSION['toast'] = [
        'type' => 'error',
        'title' => 'Submission Email',
        'message' => 'You are not authorized to send email updates for this claim.',
    ];
    header('Location: claims.php');
    exit();
}

$claimId = (int) ($claim['id'] ?? 0);
$reference = 'BK-' . str_pad((string) $claimId, 8, '0', STR_PAD_LEFT);

$docSummary = udcs_fetch_claim_document_summary($conn, $claimId);
$submissionNoteInput = trim((string) ($_GET['msg'] ?? ''));
$submissionNote = $submissionNoteInput !== ''
    ? $submissionNoteInput
    : 'Your claim was received by UDCS and routed to legal review.';
$claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
$financeCurrency = bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? $claimCurrency));

$payload = udcs_render_claim_email_payload('claim_submitted', [
    'id' => $claimId,
    'reference' => $reference,
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
    'decision_date' => date('F j, Y'),
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
    'notes' => $submissionNote,
    'documents_count' => (int) ($docSummary['count'] ?? 0),
    'documents_types' => (array) ($docSummary['types'] ?? []),
    'documents_summary' => (string) ($docSummary['summary'] ?? ''),
]);

$recipients = udcs_email_collect_recipients(array_merge(
    $requestedEmails,
    [(string) ($claim['email'] ?? ''), (string) ($claim['alt_email'] ?? '')]
));

if (empty($recipients)) {
    $_SESSION['toast'] = [
        'type' => 'warning',
        'title' => 'Submission Email',
        'message' => "Claim $reference was submitted, but no valid email recipient was found.",
    ];
    header('Location: claims.php');
    exit();
}

$mail = new PHPMailer(true);

try {
    udcs_email_configure_mailer($mail, 'UNIFIED DIGITAL CLAIMS SYSTEM | Claimant Intake');

    foreach ($recipients as $recipient) {
        $mail->addAddress($recipient);
    }

    $mail->isHTML(true);
    $mail->Subject = (string) ($payload['subject'] ?? ('UDCS Claim Received (' . $reference . ')'));
    $mail->Body = (string) ($payload['html'] ?? '');
    $mail->AltBody = (string) ($payload['text'] ?? '');
    $mail->send();

    $_SESSION['toast'] = [
        'type' => 'success',
        'title' => 'Claim Submitted',
        'message' => "Your claim was submitted successfully.\nReference: $reference\nA confirmation email was sent.",
    ];
    header('Location: claims.php');
    exit();
} catch (Exception $e) {
    error_log('submissionEmail.php send failed: ' . $mail->ErrorInfo);
    $_SESSION['toast'] = [
        'type' => 'warning',
        'title' => 'Claim Submitted',
        'message' => "Your claim was submitted successfully.\nReference: $reference\nWe could not send the confirmation email right now.",
    ];
    header('Location: claims.php');
    exit();
}
?>

