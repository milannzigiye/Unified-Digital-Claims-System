<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/distribution.php';
require_once __DIR__ . '/claims_v2.php';
require_once dirname(__DIR__) . '/app_config.php';

if (!function_exists('udcs_email_load_smtp')) {
    function udcs_email_load_smtp(): array
    {
        return app_mail_config();
    }
}

if (!function_exists('udcs_email_configure_mailer')) {
    function udcs_email_configure_mailer(\PHPMailer\PHPMailer\PHPMailer $mail, string $fromName): void
    {
        configure_mailer($mail, $fromName !== '' ? $fromName : 'UNIFIED DIGITAL CLAIMS SYSTEM');
    }
}

if (!function_exists('udcs_email_collect_recipients')) {
    function udcs_email_collect_recipients(array $emails): array
    {
        $bucket = [];
        foreach ($emails as $email) {
            $candidate = trim((string) $email);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $bucket[strtolower($candidate)] = $candidate;
        }
        return array_values($bucket);
    }
}

if (!function_exists('udcs_email_ensure_phpmailer')) {
    function udcs_email_ensure_phpmailer(): bool
    {
        if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return true;
        }

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
    }
}

if (!function_exists('udcs_staff_workflow_email_profile')) {
    function udcs_staff_workflow_email_profile(string $event): array
    {
        return match ($event) {
            'legal_assigned' => [
                'subject' => 'UDCS Legal Queue Assignment',
                'headline' => 'Claim assigned to Legal',
                'badge' => 'Legal Review',
                'intro' => 'A submitted claim has been assigned to your legal review queue.',
                'action' => 'Open the Legal portal, review the claim file, and record the next legal decision.',
            ],
            'legal_updated' => [
                'subject' => 'UDCS Claimant Update Returned To Legal',
                'headline' => 'Claimant submitted requested updates',
                'badge' => 'Legal Re-Review',
                'intro' => 'A claimant has submitted requested corrections and the claim is back in your legal queue.',
                'action' => 'Open the Legal portal, compare the updated sections, and continue the review.',
            ],
            'finance_assigned' => [
                'subject' => 'UDCS Finance Queue Assignment',
                'headline' => 'Claim transferred to Finance',
                'badge' => 'Finance Review',
                'intro' => 'Legal approved a claim and assigned it to your finance review queue.',
                'action' => 'Open the Finance portal, verify BK-held assets, record settlement details, and close or return the claim.',
            ],
            'finance_returned_to_legal' => [
                'subject' => 'UDCS Claim Returned From Finance',
                'headline' => 'Finance returned a claim to Legal',
                'badge' => 'Legal Re-Review',
                'intro' => 'Finance returned this claim for another legal review step.',
                'action' => 'Open the Legal portal, read the finance return reason, then decide whether claimant correction or legal action is needed.',
            ],
            'finance_closed' => [
                'subject' => 'UDCS Claim Closed By Finance',
                'headline' => 'Finance closed the claim',
                'badge' => 'Closed',
                'intro' => 'Finance recorded bank-side disbursement and closed a claim you were involved in.',
                'action' => 'No further action is required unless a follow-up is raised. The completed record remains available in the system.',
            ],
            default => [
                'subject' => 'UDCS Claim Workflow Update',
                'headline' => 'Claim workflow update',
                'badge' => 'Workflow Update',
                'intro' => 'A claim you are involved in has moved to a new workflow point.',
                'action' => 'Open your portal and review the latest claim status.',
            ],
        };
    }
}

if (!function_exists('udcs_fetch_staff_email_recipients')) {
    function udcs_fetch_staff_email_recipients(mysqli $conn, array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $sql = 'SELECT id, full_name, email, role FROM users WHERE id IN (' . implode(',', $ids) . ')';
        $result = mysqli_query($conn, $sql);
        if (!$result) {
            return [];
        }

        $recipients = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $recipients[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['full_name'] ?? '')),
                'email' => $email,
                'role' => trim((string) ($row['role'] ?? '')),
            ];
        }

        return $recipients;
    }
}

if (!function_exists('udcs_fetch_staff_workflow_claim_context')) {
    function udcs_fetch_staff_workflow_claim_context(mysqli $conn, int $claimId): array
    {
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return [];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                c.id,
                COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
                COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name) AS deceased_name,
                c.relationship,
                c.claim_type,
                c.claim_amount,
                c.claim_currency_code,
                c.finance_assessed_amount,
                c.finance_assessed_currency_code,
                c.manual_review_flag,
                c.manual_review_reason,
                c.finance_return_reason,
                c.will_exists,
                c.children_status,
                c.acting_on_behalf,
                c.distribution_method,
                c.distribution_details,
                c.submitted_at,
                c.updated_at,
                claimant.full_name AS claimant_name,
                claimant.email AS claimant_email,
                legal.full_name AS legal_name,
                finance.full_name AS finance_name,
                COALESCE(ca.asset_classes, '') AS asset_classes
             FROM claims c
             LEFT JOIN users claimant ON claimant.id = COALESCE(c.claimant_user_id, c.claimant_id)
             LEFT JOIN users legal ON legal.id = c.assigned_legal_id
             LEFT JOIN users finance ON finance.id = c.assigned_finance_id
             LEFT JOIN (
                SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
                FROM claim_assets
                GROUP BY claim_id
             ) ca ON ca.claim_id = c.id
             WHERE c.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'i', $claimId);
        $result = false;
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
        }
        mysqli_stmt_close($stmt);
        if (!$result || mysqli_num_rows($result) === 0) {
            return [];
        }

        $row = mysqli_fetch_assoc($result);
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('udcs_staff_claim_priority')) {
    function udcs_staff_claim_priority(array $claim, string $event): array
    {
        $status = strtolower(trim((string) ($claim['effective_status'] ?? '')));
        $manualReason = trim((string) ($claim['manual_review_reason'] ?? ''));
        $financeReason = trim((string) ($claim['finance_return_reason'] ?? ''));
        $childrenStatus = strtoupper(trim((string) ($claim['children_status'] ?? '')));
        $willExists = (int) ($claim['will_exists'] ?? 0);
        $actingOnBehalf = (int) ($claim['acting_on_behalf'] ?? 0);
        $amount = function_exists('bk_claim_amount_numeric') ? bk_claim_amount_numeric($claim['claim_amount'] ?? null) : null;
        $reasons = [];
        $level = 'Standard priority';
        $accent = '#034EA2';
        $soft = '#e7f0fc';

        if ($event === 'finance_returned_to_legal' || $financeReason !== '' || str_contains($status, 'returned')) {
            $level = 'High priority';
            $accent = '#b42318';
            $soft = '#fff0ee';
            $reasons[] = 'Returned by Finance or requires another controlled legal step.';
        }
        if ((int) ($claim['manual_review_flag'] ?? 0) === 1 || $manualReason !== '') {
            $level = 'High priority';
            $accent = '#b42318';
            $soft = '#fff0ee';
            $reasons[] = $manualReason !== '' ? 'Manual review flag: ' . $manualReason : 'Manual review flag is active.';
        }
        if ($level !== 'High priority' && ($willExists === 1 || $childrenStatus === 'UNKNOWN' || $actingOnBehalf === 1)) {
            $level = 'Elevated priority';
            $accent = '#9a6700';
            $soft = '#fff7df';
            if ($willExists === 1) {
                $reasons[] = 'Will path requires careful document and beneficiary review.';
            }
            if ($childrenStatus === 'UNKNOWN') {
                $reasons[] = 'Children status is unknown.';
            }
            if ($actingOnBehalf === 1) {
                $reasons[] = 'Claimant is acting for other heirs.';
            }
        }
        if ($level === 'Standard priority' && $amount !== null && $amount >= 10000000) {
            $level = 'Elevated priority';
            $accent = '#9a6700';
            $soft = '#fff7df';
            $reasons[] = 'High declared value needs careful verification.';
        }
        if (empty($reasons)) {
            $reasons[] = 'No special blocker was detected from the available claim metadata.';
        }

        return [
            'level' => $level,
            'accent' => $accent,
            'soft' => $soft,
            'reasons' => $reasons,
        ];
    }
}

if (!function_exists('udcs_fetch_staff_dashboard_snapshot')) {
    function udcs_fetch_staff_dashboard_snapshot(mysqli $conn, int $userId, string $role): array
    {
        $userId = (int) $userId;
        $roleKey = strtolower(trim($role));
        if ($userId <= 0 || !in_array($roleKey, ['legal', 'finance'], true)) {
            return [];
        }

        if ($roleKey === 'legal') {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT
                    COUNT(*) AS total_assigned,
                    SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('Pending Legal Review', 'Manual Legal Review Required', 'More Information Required', 'pending', 'under review', 'under_review') THEN 1 ELSE 0 END) AS open_queue,
                    SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) = 'Manual Legal Review Required' OR COALESCE(manual_review_flag, 0) = 1 THEN 1 ELSE 0 END) AS attention_queue,
                    SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('More Information Required', 'under review', 'under_review') THEN 1 ELSE 0 END) AS claimant_follow_up,
                    SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('Pending Legal Review', 'Manual Legal Review Required', 'More Information Required', 'pending', 'under review', 'under_review') AND submitted_at IS NOT NULL AND submitted_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS aging_queue
                 FROM claims
                 WHERE assigned_legal_id = ?"
            );
            $labels = [
                'open_queue' => 'Open legal queue',
                'attention_queue' => 'Needs attention',
                'claimant_follow_up' => 'Claimant follow-up',
                'aging_queue' => 'Aging 7+ days',
            ];
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT
                    COUNT(*) AS total_assigned,
                    SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('Pending Finance Review', 'Returned by Finance', 'Approved for Disbursement', 'transferred to finance', 'approved by legal', 'under_review') THEN 1 ELSE 0 END) AS open_queue,
                    SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('Returned by Finance', 'rejected by finance') THEN 1 ELSE 0 END) AS attention_queue,
                    SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('Disbursed', 'Closed', 'approved by finance') THEN 1 ELSE 0 END) AS closed_queue,
                    SUM(CASE WHEN COALESCE(NULLIF(status, ''), claim_status) IN ('Pending Finance Review', 'Approved for Disbursement', 'transferred to finance', 'approved by legal') AND updated_at IS NOT NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 5 DAY) THEN 1 ELSE 0 END) AS aging_queue
                 FROM claims
                 WHERE assigned_finance_id = ?"
            );
            $labels = [
                'open_queue' => 'Open finance queue',
                'attention_queue' => 'Returned / blocked',
                'closed_queue' => 'Closed by Finance',
                'aging_queue' => 'Aging 5+ days',
            ];
        }

        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        $result = false;
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
        }
        mysqli_stmt_close($stmt);
        if (!$result || mysqli_num_rows($result) === 0) {
            return [];
        }

        $row = mysqli_fetch_assoc($result);
        if (!is_array($row)) {
            return [];
        }

        $cards = [];
        foreach ($labels as $key => $label) {
            $cards[] = [
                'label' => $label,
                'value' => (int) ($row[$key] ?? 0),
            ];
        }
        return $cards;
    }
}

if (!function_exists('udcs_send_staff_workflow_email')) {
    function udcs_send_staff_workflow_email(mysqli $conn, string $event, int $claimId, array $recipientUserIds, array $context = []): array
    {
        $recipients = udcs_fetch_staff_email_recipients($conn, $recipientUserIds);
        if (empty($recipients)) {
            return ['sent' => false, 'count' => 0, 'error' => 'No valid staff recipients'];
        }
        if (!udcs_email_ensure_phpmailer()) {
            return ['sent' => false, 'count' => 0, 'error' => 'PHPMailer unavailable'];
        }

        $profile = udcs_staff_workflow_email_profile($event);
        $claim = udcs_fetch_staff_workflow_claim_context($conn, $claimId);
        $primaryRecipient = $recipients[0] ?? ['id' => 0, 'role' => ''];
        $priority = udcs_staff_claim_priority($claim, $event);
        $dashboardSnapshot = udcs_fetch_staff_dashboard_snapshot(
            $conn,
            (int) ($primaryRecipient['id'] ?? 0),
            (string) ($primaryRecipient['role'] ?? '')
        );
        $reference = 'CL-' . str_pad((string) $claimId, 6, '0', STR_PAD_LEFT);
        $claimantName = trim((string) ($claim['claimant_name'] ?? 'Not recorded'));
        $deceasedName = trim((string) ($claim['deceased_name'] ?? 'Not recorded'));
        $status = trim((string) ($claim['effective_status'] ?? 'Not recorded'));
        $assetLabel = function_exists('udcs_claim_asset_summary_label')
            ? udcs_claim_asset_summary_label((string) ($claim['asset_classes'] ?? ''), (string) ($claim['claim_type'] ?? ''))
            : (string) ($claim['claim_type'] ?? 'Not specified');
        $claimCurrency = function_exists('bk_currency_code') ? bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF')) : 'RWF';
        $financeCurrency = function_exists('bk_currency_code') ? bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? $claimCurrency)) : $claimCurrency;
        $claimantValue = function_exists('bk_claim_amount_display_for_type')
            ? bk_claim_amount_display_for_type($claim['claim_amount'] ?? null, (string) ($claim['claim_type'] ?? ''), $claimCurrency)
            : 'Not recorded';
        $financeValue = function_exists('bk_claim_amount_display')
            ? bk_claim_amount_display($claim['finance_assessed_amount'] ?? null, $financeCurrency, 'Not recorded')
            : 'Not recorded';
        $destination = function_exists('bk_claim_destination_summary')
            ? bk_claim_destination_summary('', (string) ($claim['distribution_method'] ?? ''), (string) ($claim['distribution_details'] ?? ''))
            : 'Not recorded';
        $actorName = trim((string) ($context['actor_name'] ?? 'UDCS'));
        $note = trim((string) ($context['note'] ?? ''));
        $decisionDate = date('F j, Y H:i');
        $e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $subject = (string) ($profile['subject'] ?? 'UDCS Claim Workflow Update') . ' (' . $reference . ')';
        if (($priority['level'] ?? 'Standard priority') !== 'Standard priority') {
            $subject = '[' . (string) ($priority['level'] ?? 'Priority') . '] ' . $subject;
        }
        $rows = [
            'Claim reference' => $reference,
            'Priority level' => (string) ($priority['level'] ?? 'Standard priority'),
            'Current status' => $status,
            'Claimant' => $claimantName,
            'Deceased person' => $deceasedName,
            'Asset class' => $assetLabel,
            'Claimant declared value' => $claimantValue,
            'Finance recorded value' => $financeValue,
            'Settlement destination' => $destination,
            'Triggered by' => $actorName,
            'Event time' => $decisionDate,
        ];
        $factHtml = '';
        $factText = '';
        foreach ($rows as $label => $value) {
            $factHtml .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #e5edf7;color:#32567d;font-weight:700;">' . $e($label) . '</td><td style="padding:8px 10px;border-bottom:1px solid #e5edf7;color:#10233c;">' . $e($value) . '</td></tr>';
            $factText .= '- ' . $label . ': ' . $value . "\n";
        }

        $priorityReasonHtml = '';
        $priorityReasonText = '';
        foreach ((array) ($priority['reasons'] ?? []) as $reason) {
            $priorityReasonHtml .= '<li>' . $e($reason) . '</li>';
            $priorityReasonText .= '- ' . (string) $reason . "\n";
        }
        $snapshotHtml = '';
        $snapshotText = '';
        foreach ($dashboardSnapshot as $card) {
            $snapshotHtml .= "<div style='border:1px solid #d8e2f0;border-radius:12px;background:#fff;padding:12px 14px;'><div style='font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#5f738d;font-weight:800;'>" . $e((string) ($card['label'] ?? 'Metric')) . "</div><div style='margin-top:5px;font-size:24px;line-height:1;font-weight:900;color:#034EA2;'>" . $e((string) ($card['value'] ?? 0)) . "</div></div>";
            $snapshotText .= '- ' . (string) ($card['label'] ?? 'Metric') . ': ' . (string) ($card['value'] ?? 0) . "\n";
        }
        if ($snapshotHtml === '') {
            $snapshotHtml = "<div style='border:1px solid #d8e2f0;border-radius:12px;background:#fff;padding:12px 14px;color:#5f738d;'>No dashboard snapshot was available for this recipient.</div>";
            $snapshotText = "- No dashboard snapshot was available for this recipient.\n";
        }

        $noteHtml = $note !== '' ? '<p style="margin:10px 0 0;"><strong>Context:</strong> ' . $e($note) . '</p>' : '';
        $noteText = $note !== '' ? "\nContext: $note\n" : '';
        $html = "<html><body style='margin:0;padding:24px;background:#eef3f9;color:#10233c;font-family:Helvetica,Arial,sans-serif;line-height:1.55;'>
            <div style='max-width:720px;margin:0 auto;background:#fff;border:1px solid #d7e2f0;border-radius:16px;overflow:hidden;'>
                <div style='background:#034EA2;color:#fff;padding:22px 26px;'>
                    <div style='font-size:12px;letter-spacing:.12em;text-transform:uppercase;font-weight:700;opacity:.85;'>Bank of Kigali | UDCS</div>
                    <h1 style='margin:8px 0 4px;font-size:22px;line-height:1.2;'>" . $e((string) ($profile['headline'] ?? 'Claim workflow update')) . "</h1>
                    <p style='margin:0;font-size:14px;opacity:.95;'>" . $e((string) ($profile['intro'] ?? 'A claim workflow event occurred.')) . "</p>
                </div>
                <div style='padding:22px 26px;background:#f9fbfe;'>
                    <span style='display:inline-block;background:#e7f0fc;color:#034EA2;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:800;text-transform:uppercase;'>" . $e((string) ($profile['badge'] ?? 'Workflow Update')) . "</span>
                    <div style='margin-top:16px;border:1px solid " . $e((string) ($priority['accent'] ?? '#034EA2')) . ";border-left:5px solid " . $e((string) ($priority['accent'] ?? '#034EA2')) . ";border-radius:12px;background:" . $e((string) ($priority['soft'] ?? '#e7f0fc')) . ";padding:14px 16px;'>
                        <div style='font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:900;color:" . $e((string) ($priority['accent'] ?? '#034EA2')) . ";'>Priority signal</div>
                        <h2 style='margin:4px 0 7px;font-size:18px;color:#10233c;'>" . $e((string) ($priority['level'] ?? 'Standard priority')) . "</h2>
                        <ul style='margin:0;padding-left:18px;'>" . $priorityReasonHtml . "</ul>
                    </div>
                    <div style='margin-top:16px;'>
                        <div style='font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:900;color:#034EA2;'>Your dashboard snapshot</div>
                        <div style='display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:9px;'>" . $snapshotHtml . "</div>
                    </div>
                    <table style='width:100%;border-collapse:collapse;margin-top:16px;background:#fff;border:1px solid #d8e2f0;border-radius:12px;overflow:hidden;'>" . $factHtml . "</table>
                    " . $noteHtml . "
                    <div style='margin-top:18px;padding:14px 16px;background:#edf4ff;border-left:4px solid #034EA2;border-radius:10px;'>
                        <strong>Expected action</strong>
                        <p style='margin:6px 0 0;'>" . $e((string) ($profile['action'] ?? 'Open your portal and review the claim.')) . "</p>
                    </div>
                    <p style='margin-top:18px;font-size:12px;color:#5f738d;text-align:center;'>This is an automated workflow email from UNIFIED DIGITAL CLAIMS SYSTEM (UDCS).</p>
                </div>
            </div>
        </body></html>";
        $text = "Bank of Kigali | UDCS\n"
            . ((string) ($profile['headline'] ?? 'Claim workflow update')) . "\n\n"
            . ((string) ($profile['intro'] ?? 'A claim workflow event occurred.')) . "\n\n"
            . "Priority signal\n"
            . "- Level: " . ((string) ($priority['level'] ?? 'Standard priority')) . "\n"
            . $priorityReasonText . "\n"
            . "Your dashboard snapshot\n"
            . $snapshotText . "\n"
            . $factText
            . $noteText . "\n"
            . "Expected action: " . ((string) ($profile['action'] ?? 'Open your portal and review the claim.')) . "\n";

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            udcs_email_configure_mailer($mail, 'UDCS Workflow Notifications');
            foreach ($recipients as $recipient) {
                $mail->addAddress((string) $recipient['email'], (string) ($recipient['name'] ?: $recipient['email']));
            }
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
            return ['sent' => true, 'count' => count($recipients), 'error' => ''];
        } catch (Throwable $exception) {
            error_log('udcs_send_staff_workflow_email failed: ' . $exception->getMessage());
            return ['sent' => false, 'count' => count($recipients), 'error' => $exception->getMessage()];
        }
    }
}

if (!function_exists('udcs_db_has_column')) {
    function udcs_db_has_column(mysqli $conn, string $table, string $column): bool
    {
        $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';
        $columnSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?: '';
        if ($tableSafe === '' || $columnSafe === '') {
            return false;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $tableSafe, $columnSafe);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        return $result !== false && mysqli_num_rows($result) > 0;
    }
}

if (!function_exists('udcs_claim_email_profile')) {
    function udcs_claim_email_profile(string $event): array
    {
        return match ($event) {
            'claim_submitted' => [
                'subject_prefix' => 'UDCS Claim Received',
                'headline' => 'Claim submission recorded',
                'decision_label' => 'Submission accepted and queued for legal review',
                'badge' => 'Submission Received',
                'tone' => 'neutral',
                'intro' => 'Your deceased-assets claim was received successfully. Intake checks passed and the case is now waiting for legal review.',
                'notes_label' => 'Submission note',
                'next_steps' => [
                    'Legal review will verify the relationship path, heir disclosure, and supporting documents.',
                    'If clarification is needed, you will see a clear reason in your portal and in your notification history.',
                    'If Legal approves the case, it moves to Finance for BK asset confirmation and settlement processing.',
                ],
            ],
            'legal_to_finance' => [
                'subject_prefix' => 'UDCS Claim Update',
                'headline' => 'Legal review completed',
                'decision_label' => 'Approved by Legal and moved to Finance',
                'badge' => 'In Finance Queue',
                'tone' => 'positive',
                'intro' => 'Your claim passed legal review. Finance will now confirm BK-held assets, validate settlement details, and prepare the operational payout step.',
                'notes_label' => 'Legal reviewer note',
                'next_steps' => [
                    'Finance checks BK asset existence, value, restrictions, and settlement feasibility.',
                    'If Finance needs clarification, you will be notified with the exact next step.',
                    'Once Finance finishes processing, you will receive a final status update.',
                ],
            ],
            'legal_rejected' => [
                'subject_prefix' => 'UDCS Claim Decision',
                'headline' => 'Legal review decision issued',
                'decision_label' => 'Rejected by Legal',
                'badge' => 'Action Required',
                'tone' => 'negative',
                'intro' => 'Your claim could not move past legal review in its current form.',
                'notes_label' => 'Why legal rejected this claim',
                'next_steps' => [
                    'Review the rejection reason below.',
                    'Correct the requested information or document issue inside your claimant portal.',
                    'Resubmit the claim so it can enter a fresh review cycle.',
                ],
            ],
            'finance_approved' => [
                'subject_prefix' => 'UDCS Claim Decision',
                'headline' => 'Finance processing completed',
                'decision_label' => 'Approved by Finance and closed',
                'badge' => 'Claim Closed',
                'tone' => 'positive',
                'intro' => 'Finance completed the bank-side settlement step, recorded the disbursement, and closed your claim. The settlement details and expected follow-up are listed below.',
                'notes_label' => 'Finance completion note',
                'next_steps' => [
                    'Check the settlement destination listed in this email according to the expected timing below.',
                    'If the funds or settlement instruction are not reflected within the expected period, contact Bank of Kigali with your claim reference.',
                    'Keep the approval email for your records.',
                ],
            ],
            'finance_rejected' => [
                'subject_prefix' => 'UDCS Claim Update',
                'headline' => 'Finance decision issued',
                'decision_label' => 'Returned by Finance',
                'badge' => 'Action Required',
                'tone' => 'negative',
                'intro' => 'Finance could not complete the case as currently recorded and returned it for clarification.',
                'notes_label' => 'Why finance returned this claim',
                'next_steps' => [
                    'Review the return reason below.',
                    'If you are being asked to update details, use your claimant portal to correct the requested information.',
                    'If the case was sent back to Legal, wait for the next review outcome unless further information is requested.',
                ],
            ],
            default => [
                'subject_prefix' => 'UDCS Claim Update',
                'headline' => 'Claim status updated',
                'decision_label' => 'Status update',
                'badge' => 'Claim Update',
                'tone' => 'neutral',
                'intro' => 'Your claim status has changed.',
                'notes_label' => 'Review note',
                'next_steps' => [
                    'Open your claimant portal to review the latest status and required actions.',
                ],
            ],
        };
    }
}

if (!function_exists('udcs_email_tone_palette')) {
    function udcs_email_tone_palette(string $tone): array
    {
        return match ($tone) {
            'negative' => [
                'badge_bg' => '#b71c1c',
                'badge_fg' => '#ffffff',
                'accent' => '#c62828',
                'soft' => '#fdeceb',
            ],
            'positive' => [
                'badge_bg' => '#1b5e20',
                'badge_fg' => '#ffffff',
                'accent' => '#0f9d58',
                'soft' => '#eaf7ef',
            ],
            default => [
                'badge_bg' => '#034EA2',
                'badge_fg' => '#ffffff',
                'accent' => '#0a66d6',
                'soft' => '#edf4ff',
            ],
        };
    }
}

if (!function_exists('udcs_document_type_label')) {
    function udcs_document_type_label(?string $type): string
    {
        $key = strtolower(trim((string) $type));
        if ($key === '') {
            return 'Document';
        }

        $map = [
            'death_certificate' => 'Death Certificate',
            'claimant_id' => 'Claimant ID',
            'relationship_document' => 'Relationship Support Document',
            'marriage_certificate' => 'Marriage Certificate',
            'birth_certificate' => 'Birth Certificate',
            'certificate_of_full_identity' => 'Certificate of Full Identity',
            'family_resolution' => 'Cell Executive Secretary Family Resolution',
            'supporting_document' => 'Additional Supporting Document',
        ];

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('udcs_fetch_claim_document_summary')) {
    function udcs_fetch_claim_document_summary(mysqli $conn, int $claimId): array
    {
        if ($claimId <= 0) {
            return ['count' => 0, 'types' => [], 'summary' => 'No verified document details were found.'];
        }

        $id = (int) $claimId;
        $stmt = mysqli_prepare(
            $conn,
            "SELECT document_type, COUNT(*) AS qty
             FROM documents
             WHERE claim_id = ?
             GROUP BY document_type
             ORDER BY document_type ASC"
        );
        $result = false;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
            }
            mysqli_stmt_close($stmt);
        }

        if (!$result) {
            return ['count' => 0, 'types' => [], 'summary' => 'Document summary is currently unavailable.'];
        }

        $total = 0;
        $labels = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $qty = (int) ($row['qty'] ?? 0);
            $total += max(0, $qty);
            $label = udcs_document_type_label((string) ($row['document_type'] ?? ''));
            if ($qty > 1) {
                $label .= ' (x' . $qty . ')';
            }
            $labels[] = $label;
        }

        if ($total === 0) {
            return ['count' => 0, 'types' => [], 'summary' => 'No supporting documents are attached to this claim yet.'];
        }

        return [
            'count' => $total,
            'types' => $labels,
            'summary' => $total . ' document(s) recorded and verified during intake checks.',
        ];
    }
}

if (!function_exists('udcs_extract_decision_note')) {
    function udcs_extract_decision_note(string $timeline, string $event): string
    {
        $raw = trim($timeline);
        if ($raw === '') {
            return 'No additional note was provided for this decision.';
        }

        $lines = preg_split('/\R+/', $raw) ?: [];
        $lines = array_values(array_filter(array_map(static fn($line): string => trim((string) $line), $lines), static fn($line): bool => $line !== ''));
        if (empty($lines)) {
            return 'No additional note was provided for this decision.';
        }

        $markerMap = [
            'legal_to_finance' => 'Approved & Transferred to Finance by Legal Dept',
            'legal_rejected' => 'Rejected by Legal Dept',
            'finance_approved' => 'Approved by Finance Dept',
            'finance_rejected' => 'Returned by Finance Dept',
        ];
        $marker = strtolower((string) ($markerMap[$event] ?? ''));

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if (stripos($line, 'Reason:') === 0) {
                $value = trim(substr($line, strlen('Reason:')));
                return $value !== '' ? $value : 'No reason details were captured.';
            }
            if (stripos($line, 'Note:') === 0) {
                $value = trim(substr($line, strlen('Note:')));
                return $value !== '' ? $value : 'No additional reviewer note was captured.';
            }
        }

        if ($marker !== '') {
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (strpos(strtolower($lines[$i]), $marker) !== false) {
                    for ($j = $i + 1; $j < min(count($lines), $i + 4); $j++) {
                        $candidate = trim($lines[$j]);
                        if ($candidate === '' || preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $candidate) === 1) {
                            continue;
                        }
                        return $candidate;
                    }
                }
            }
        }

        if ($event === 'finance_rejected') {
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (strpos(strtolower($lines[$i]), 'rejected by finance dept') !== false) {
                    for ($j = $i + 1; $j < min(count($lines), $i + 4); $j++) {
                        $candidate = trim($lines[$j]);
                        if ($candidate === '' || preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $candidate) === 1) {
                            continue;
                        }
                        return $candidate;
                    }
                }
            }
        }

        return end($lines) ?: 'No additional note was provided for this decision.';
    }
}

if (!function_exists('udcs_render_distribution_snapshot')) {
    function udcs_render_distribution_snapshot(?string $method, ?string $detailsRaw): array
    {
        $label = bk_distribution_method_label($method);
        $rows = bk_distribution_detail_rows($detailsRaw);
        $preview = [];
        foreach (array_slice($rows, 0, 3) as $row) {
            $preview[] = trim((string) ($row['label'] ?? 'Detail')) . ': ' . trim((string) ($row['value'] ?? ''));
        }
        if (count($rows) > 3) {
            $preview[] = '+' . (count($rows) - 3) . ' more detail(s) in portal';
        }

        return [
            'method_label' => $label !== '' ? $label : 'Not specified',
            'preview_lines' => $preview,
        ];
    }
}

if (!function_exists('udcs_render_claim_email_payload')) {
    function udcs_render_claim_email_payload(string $event, array $claim): array
    {
        $profile = udcs_claim_email_profile($event);
        foreach (['subject_prefix', 'headline', 'decision_label', 'badge', 'tone', 'intro', 'notes_label'] as $overrideKey) {
            if (array_key_exists($overrideKey, $claim) && trim((string) $claim[$overrideKey]) !== '') {
                $profile[$overrideKey] = (string) $claim[$overrideKey];
            }
        }
        if (!empty($claim['next_steps']) && is_array($claim['next_steps'])) {
            $profile['next_steps'] = array_values(array_filter(array_map(static fn($step): string => trim((string) $step), $claim['next_steps']), static fn($step): bool => $step !== ''));
        }
        $palette = udcs_email_tone_palette((string) ($profile['tone'] ?? 'neutral'));
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $reference = (string) ($claim['reference'] ?? ('BK-' . str_pad((string) ($claim['id'] ?? '0'), 8, '0', STR_PAD_LEFT)));
        $fullName = (string) ($claim['full_name'] ?? 'Claimant');
        $claimType = (string) ($claim['claim_type_label'] ?? 'Not specified');
        $amount = trim((string) ($claim['claim_amount_label'] ?? ''));
        if ($amount === '') {
            $claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
            $amount = bk_claim_amount_display_for_type(
                $claim['claim_amount'] ?? null,
                (string) ($claim['claim_type'] ?? ''),
                $claimCurrency
            );
        }
        $financeAssessedAmount = trim((string) ($claim['finance_assessed_amount_label'] ?? ''));
        if ($financeAssessedAmount === '') {
            $financeCurrency = bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? ($claim['claim_currency_code'] ?? 'RWF')));
            $financeAssessedAmount = bk_claim_amount_display(
                $claim['finance_assessed_amount'] ?? null,
                $financeCurrency,
                ''
            );
        }
        if ($event === 'finance_approved' && $financeAssessedAmount === '') {
            $financeAssessedAmount = 'Pending finance assessment record';
        }
        $submittedDate = (string) ($claim['submitted_date'] ?? 'N/A');
        $decisionDate = (string) ($claim['decision_date'] ?? date('F j, Y'));
        $deceasedName = (string) ($claim['deceased_name'] ?? 'N/A');
        $deceasedNid = (string) ($claim['deceased_national_id'] ?? 'N/A');
        $relationship = (string) ($claim['relationship'] ?? 'N/A');
        $accountNumber = bk_claim_destination_summary(
            bk_claim_account_reference($claim),
            (string) ($claim['distribution_method'] ?? ''),
            (string) ($claim['distribution_details'] ?? '')
        );
        $notesRaw = trim((string) ($claim['notes'] ?? 'No additional note was provided for this decision.'));
        $notesHtml = nl2br($e($notesRaw));

        $distribution = udcs_render_distribution_snapshot(
            (string) ($claim['distribution_method'] ?? ''),
            (string) ($claim['distribution_details'] ?? '')
        );
        $distributionMethodLabel = (string) ($distribution['method_label'] ?? 'Not specified');
        $distributionPreview = (array) ($distribution['preview_lines'] ?? []);

        $documentsCount = (int) ($claim['documents_count'] ?? 0);
        $documentsTypes = array_values(array_filter(array_map('trim', (array) ($claim['documents_types'] ?? [])), static fn($line): bool => $line !== ''));
        $documentsSummary = trim((string) ($claim['documents_summary'] ?? ''));
        if ($documentsSummary === '') {
            $documentsSummary = $documentsCount > 0
                ? $documentsCount . ' document(s) recorded and verified during intake checks.'
                : 'No verified document details were found.';
        }

        $stepHtml = '';
        $stepText = [];
        foreach ((array) ($profile['next_steps'] ?? []) as $idx => $step) {
            $stepHtml .= '<li>' . $e($step) . '</li>';
            $stepText[] = ($idx + 1) . '. ' . (string) $step;
        }

        $docTypeHtml = '';
        $docTypeText = '';
        if (!empty($documentsTypes)) {
            foreach ($documentsTypes as $type) {
                $docTypeHtml .= '<li>' . $e($type) . '</li>';
            }
            $docTypeText = implode('; ', $documentsTypes);
        } else {
            $docTypeHtml = '<li>No document types were listed.</li>';
            $docTypeText = 'No document types were listed.';
        }

        $distributionHtml = '';
        $distributionText = 'No additional disbursement details were provided.';
        if (!empty($distributionPreview)) {
            foreach ($distributionPreview as $line) {
                $distributionHtml .= '<li>' . $e($line) . '</li>';
            }
            $distributionText = implode('; ', $distributionPreview);
        }

        $settlementExpectationHtml = '';
        $settlementExpectationText = '';
        if ($event === 'finance_approved') {
            $methodKey = strtolower(trim((string) ($claim['distribution_method'] ?? '')));
            $timingText = match ($methodKey) {
                'bk_account_transfer', 'transfer_to_claimant_account' => 'Expected timing: check the destination account after closure; internal BK settlement should normally be visible immediately or by the next business day.',
                'other_bank_transfer', 'transfer_to_other_bank', 'sell_shares_cash', 'liquidate_assets' => 'Expected timing: external bank settlement may take one to three business days after Finance closure, depending on the destination institution and final bank processing.',
                'mobile_money', 'mobile_money_wallet' => 'Expected timing: mobile wallet settlement should normally be visible on the wallet the same day or by the next business day after Finance closure.',
                'cash_pickup' => 'Expected timing: visit the listed pickup branch with your ID and claim reference after receiving this closure notice, unless Finance or the branch gives a different collection instruction.',
                'cheque', 'bank_draft' => 'Expected timing: collect the banker\'s instrument from the listed branch with your ID and claim reference during branch working hours.',
                'split_payout_accounts' => 'Expected timing: each listed payout destination follows its own bank processing path; internal BK parts should normally be faster than external transfers.',
                'staged_installments' => 'Expected timing: settlement follows the installment schedule recorded in the disbursement details.',
                'transfer_shares_claimant', 'transfer_shares_nominee', 'partial_sale_partial_transfer' => 'Expected timing: securities or share settlement follows the broker, registry, or CDS processing path recorded in the disbursement details.',
                'inspection_access', 'transfer_ownership' => 'Expected timing: follow the access or ownership-transfer instruction recorded in the settlement details; this is not a direct cash payout.',
                default => 'Expected timing: follow the settlement method and destination details recorded below. If no timing is clear, contact Bank of Kigali with your claim reference.',
            };
            $settlementExpectationItems = [
                'Finance recorded the disbursement and closed this claim on ' . $decisionDate . '.',
                'Expected settlement method: ' . $distributionMethodLabel . '.',
                'Expected destination or collection point: ' . $accountNumber . '.',
                $timingText,
                'For follow-up, provide this claim reference: ' . $reference . '.',
            ];
            foreach ($settlementExpectationItems as $item) {
                $settlementExpectationHtml .= '<li>' . $e($item) . '</li>';
            }
            $settlementExpectationText = implode("\n", array_map(static fn($item): string => '- ' . $item, $settlementExpectationItems));
        }

        $factRows = [
            ['label' => 'Claim reference', 'value' => $reference],
            ['label' => 'Claim type', 'value' => $claimType],
            ['label' => 'Claimant declared value', 'value' => $amount],
        ];
        if ($financeAssessedAmount !== '') {
            $factRows[] = ['label' => 'Finance recorded value', 'value' => $financeAssessedAmount];
        }
        $factRows[] = ['label' => 'Submitted date', 'value' => $submittedDate];
        $factRows[] = ['label' => 'Decision date', 'value' => $decisionDate];
        $factRows[] = ['label' => 'Deceased name', 'value' => $deceasedName];
        $factRows[] = ['label' => 'Deceased ID', 'value' => $deceasedNid];
        $factRows[] = ['label' => 'Relationship', 'value' => $relationship];
        $factRows[] = ['label' => 'Settlement destination', 'value' => $accountNumber];

        $factRowsHtml = '';
        $factRowsText = '';
        foreach ($factRows as $row) {
            $factRowsHtml .= "<div class='fact-row'><span class='fact-label'>" . $e($row['label']) . "</span><span class='fact-value'>" . $e($row['value']) . "</span></div>";
            $factRowsText .= '- ' . $row['label'] . ': ' . $row['value'] . "\n";
        }

        $subject = (string) ($profile['subject_prefix'] ?? 'UDCS Claim Update') . ' (' . $reference . ')';

        $html = "
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { margin: 0; padding: 24px; background: #eef3f9; color: #10233c; font-family: Helvetica, Arial, sans-serif; line-height: 1.58; }
                    .wrapper { max-width: 780px; margin: 0 auto; border: 1px solid #d7e2f0; border-radius: 18px; overflow: hidden; background: #ffffff; box-shadow: 0 18px 42px rgba(3, 78, 162, 0.08); }
                    .header { padding: 24px 28px 22px; color: #ffffff; background: linear-gradient(135deg, #034EA2 0%, #0f5fb8 100%); }
                    .eyebrow { font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase; opacity: 0.84; font-weight: 700; }
                    .header h1 { margin: 8px 0 4px; font-size: 24px; font-weight: 700; line-height: 1.2; }
                    .header p { margin: 0; font-size: 14px; opacity: 0.95; }
                    .content { padding: 24px 28px 28px; background: #f9fbfe; }
                    .badge { display: inline-block; padding: 7px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; background: " . $e((string) ($palette['badge_bg'] ?? '#034EA2')) . "; color: " . $e((string) ($palette['badge_fg'] ?? '#ffffff')) . "; }
                    .intro { margin: 16px 0 18px; font-size: 14px; }
                    .decision { border: 1px solid #d8e2f0; border-left: 4px solid " . $e((string) ($palette['accent'] ?? '#034EA2')) . "; border-radius: 12px; padding: 16px 16px 14px; background: " . $e((string) ($palette['soft'] ?? '#edf4ff')) . "; margin-bottom: 16px; }
                    .decision h3 { margin: 0 0 6px; color: #10233c; font-size: 15px; }
                    .decision p { margin: 0; font-size: 13px; color: #36506f; }
                    .section-title { margin: 18px 0 8px; color: #034EA2; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; }
                    .fact-card, .panel { margin-top: 10px; border: 1px solid #d8e2f0; border-radius: 12px; background: #ffffff; padding: 12px 14px; }
                    .fact-row { display: flex; gap: 14px; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid #e6edf6; }
                    .fact-row:last-child { border-bottom: none; }
                    .fact-label { min-width: 160px; color: #32567d; font-size: 13px; font-weight: 700; }
                    .fact-value { flex: 1; text-align: right; color: #10233c; font-size: 13px; font-weight: 600; }
                    .panel h4 { margin: 0 0 8px; color: #10233c; font-size: 14px; }
                    .panel p { margin: 0; font-size: 14px; }
                    ul { margin: 0; padding-left: 18px; }
                    li { margin-bottom: 5px; }
                    .footer { margin-top: 18px; font-size: 12px; color: #5f738d; text-align: center; }
                </style>
            </head>
            <body>
                <div class='wrapper'>
                    <div class='header'>
                        <div class='eyebrow'>Bank of Kigali</div>
                        <h1>Unified Digital Claims System</h1>
                        <p>" . $e((string) ($profile['headline'] ?? 'Claim status updated')) . "</p>
                    </div>
                    <div class='content'>
                        <span class='badge'>" . $e((string) ($profile['badge'] ?? 'Claim Update')) . "</span>
                        <p class='intro'>Dear <strong>" . $e($fullName) . "</strong>,<br>" . $e((string) ($profile['intro'] ?? 'Your claim status has changed.')) . "</p>

                        <div class='decision'>
                            <h3>Decision Summary</h3>
                            <div><strong>" . $e((string) ($profile['decision_label'] ?? 'Status Updated')) . "</strong></div>
                            <p>Decision date: " . $e($decisionDate) . "<br>Claim reference: <strong>" . $e($reference) . "</strong></p>
                        </div>

                        <div class='section-title'>Claim Overview</div>
                        <div class='fact-card'>" . $factRowsHtml . "</div>

                        <div class='section-title'>Disbursement Snapshot</div>
                        <div class='panel'>
                            <h4>Selected method</h4>
                            <p>" . $e($distributionMethodLabel) . "</p>
                            " . (!empty($distributionHtml) ? "<ul style='margin-top:8px;'>" . $distributionHtml . "</ul>" : "<p style='margin-top:7px;'>No additional disbursement details were provided.</p>") . "
                        </div>

                        " . ($settlementExpectationHtml !== '' ? "
                        <div class='section-title'>Settlement Expectation</div>
                        <div class='panel'>
                            <h4>What to expect after closure</h4>
                            <ul>" . $settlementExpectationHtml . "</ul>
                        </div>
                        " : "") . "

                        <div class='section-title'>Document Verification Snapshot</div>
                        <div class='panel'>
                            <h4>Intake verification summary</h4>
                            <p>" . $e($documentsSummary) . "</p>
                            <ul style='margin-top:8px;'>" . $docTypeHtml . "</ul>
                        </div>

                        <div class='section-title'>" . $e((string) ($profile['notes_label'] ?? 'Reviewer note')) . "</div>
                        <div class='panel'>
                            <p>" . $notesHtml . "</p>
                        </div>

                        <div class='section-title'>What You Should Do Next</div>
                        <div class='panel'>
                            <ul>" . $stepHtml . "</ul>
                        </div>

                        <p class='footer'>This is an automated update from UNIFIED DIGITAL CLAIMS SYSTEM (UDCS).</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        $text = "Bank of Kigali | UNIFIED DIGITAL CLAIMS SYSTEM\n"
            . ((string) ($profile['headline'] ?? 'Claim status updated')) . "\n\n"
            . "Dear $fullName,\n"
            . ((string) ($profile['intro'] ?? 'Your claim status has changed.')) . "\n\n"
            . "Decision summary\n"
            . "- Decision: " . ((string) ($profile['decision_label'] ?? 'Status updated')) . "\n"
            . "- Decision date: $decisionDate\n"
            . "- Claim reference: $reference\n\n"
            . "Claim snapshot\n"
            . $factRowsText . "\n"
            . "Disbursement snapshot\n"
            . "- Selected method: $distributionMethodLabel\n"
            . "- Details: $distributionText\n\n"
            . ($settlementExpectationText !== '' ? "Settlement expectation\n" . $settlementExpectationText . "\n\n" : '')
            . "Document verification snapshot\n"
            . "- Summary: $documentsSummary\n"
            . "- Uploaded document types: $docTypeText\n\n"
            . ((string) ($profile['notes_label'] ?? 'Reviewer note')) . ":\n"
            . $notesRaw . "\n\n"
            . "What you should do next\n"
            . implode("\n", $stepText) . "\n";

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }
}

if (!function_exists('udcs_fetch_claim_for_email')) {
    function udcs_fetch_claim_for_email(mysqli $conn, string $primaryEmail, array $statusKeys, int $claimId = 0): ?array
    {
        udcs_claims_v2_ensure_schema($conn);
        $statuses = [];
        foreach ($statusKeys as $statusKey) {
            $safe = strtolower(trim((string) $statusKey));
            if ($safe !== '') {
                $statuses[] = $safe;
            }
        }
        if (empty($statuses)) {
            return null;
        }
        $statusSql = implode(',', array_fill(0, count($statuses), '?'));
        $statusTypes = str_repeat('s', count($statuses));
        $claimAccountSql = udcs_claim_account_reference_sql('c');
        $financeAssessedSelect = udcs_db_has_column($conn, 'claims', 'finance_assessed_amount')
            ? "c.finance_assessed_amount,"
            : "NULL AS finance_assessed_amount,";

        $baseSelect = "SELECT
                u.id AS user_id,
                u.full_name,
                u.email,
                c.id,
                COALESCE(NULLIF(c.deceased_full_name, ''), c.deceased_name) AS deceased_name,
                COALESCE(NULLIF(c.deceased_id_number, ''), c.deceased_national_id) AS deceased_national_id,
                c.relationship,
                {$claimAccountSql} AS account_number,
                {$claimAccountSql} AS accout_number,
                c.claim_type,
                c.claim_amount,
                c.claim_currency_code,
                $financeAssessedSelect
                c.finance_assessed_currency_code,
                c.submitted_at,
                c.updated_at,
                c.comment,
                c.alt_email,
                COALESCE(NULLIF(c.status, ''), c.claim_status) AS effective_status,
                c.claim_status,
                c.distribution_method,
                c.distribution_details,
                COALESCE(ca.asset_classes, '') AS asset_classes
            FROM claims c
            INNER JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
            LEFT JOIN (
                SELECT claim_id, GROUP_CONCAT(DISTINCT asset_class ORDER BY asset_class SEPARATOR '||') AS asset_classes
                FROM claim_assets
                GROUP BY claim_id
            ) ca ON ca.claim_id = c.id";

        if ($claimId > 0) {
            $id = (int) $claimId;
            $byIdStmt = mysqli_prepare(
                $conn,
                $baseSelect . "
                WHERE c.id = ?
                  AND LOWER(COALESCE(NULLIF(c.status, ''), c.claim_status)) IN ($statusSql)
                LIMIT 1"
            );
            $byIdResult = false;
            if ($byIdStmt) {
                $types = 'i' . $statusTypes;
                $params = array_merge([$id], $statuses);
                if (udcs_db_stmt_bind($byIdStmt, $types, $params) && mysqli_stmt_execute($byIdStmt)) {
                    $byIdResult = mysqli_stmt_get_result($byIdStmt);
                }
                mysqli_stmt_close($byIdStmt);
            }
            if ($byIdResult && mysqli_num_rows($byIdResult) > 0) {
                $row = mysqli_fetch_assoc($byIdResult);
                return is_array($row) ? $row : null;
            }
        }

        $fallbackStmt = mysqli_prepare(
            $conn,
            $baseSelect . "
            WHERE u.email = ?
              AND LOWER(COALESCE(NULLIF(c.status, ''), c.claim_status)) IN ($statusSql)
            ORDER BY c.updated_at DESC, c.submitted_at DESC
            LIMIT 1"
        );
        $fallback = false;
        if ($fallbackStmt) {
            $types = 's' . $statusTypes;
            $params = array_merge([$primaryEmail], $statuses);
            if (udcs_db_stmt_bind($fallbackStmt, $types, $params) && mysqli_stmt_execute($fallbackStmt)) {
                $fallback = mysqli_stmt_get_result($fallbackStmt);
            }
            mysqli_stmt_close($fallbackStmt);
        }
        if (!$fallback || mysqli_num_rows($fallback) === 0) {
            return null;
        }
        $row = mysqli_fetch_assoc($fallback);
        return is_array($row) ? $row : null;
    }
}
