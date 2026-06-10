<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/claims_v2.php';
require_once __DIR__ . '/distribution.php';

if (!function_exists('udcs_claim_v2_alive_label')) {
    function udcs_claim_v2_alive_label(?string $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'YES' => 'Alive',
            'NO' => 'Deceased',
            default => 'Unknown',
        };
    }
}

if (!function_exists('udcs_claim_v2_role_label')) {
    function udcs_claim_v2_role_label(?string $role, ?string $relationshipType = null): string
    {
        $roleKey = strtoupper(trim((string) $role));
        return match ($roleKey) {
            'CLAIMANT' => 'Claimant',
            'DECEASED' => 'Deceased',
            'SPOUSE' => 'Spouse',
            default => $relationshipType !== null && trim($relationshipType) !== ''
                ? udcs_claim_relationship_label($relationshipType)
                : ucwords(str_replace('_', ' ', strtolower($roleKey ?: 'person'))),
        };
    }
}

if (!function_exists('udcs_claim_v2_payout_label')) {
    function udcs_claim_v2_payout_label(?string $method): string
    {
        $key = strtolower(trim((string) $method));
        if ($key === '') {
            return 'Not recorded';
        }

        $labels = [
            'bk_account_transfer' => 'BK Account Transfer',
            'other_bank_transfer' => 'Other Bank Transfer',
            'mobile_money' => 'Mobile Money',
            'cheque' => 'Cheque / Banker\'s Instrument',
            'hold_pending_instruction' => 'Hold Pending Final Instruction',
        ];

        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('udcs_claim_v2_same_label')) {
    function udcs_claim_v2_same_label(?string $left, ?string $right): bool
    {
        $normalize = static function (?string $value): string {
            $text = strtolower(trim((string) $value));
            $text = str_replace(['_', '-'], ' ', $text);
            $text = preg_replace('/\s+/', ' ', $text ?? '');
            return trim((string) $text);
        };

        $leftValue = $normalize($left);
        $rightValue = $normalize($right);

        return $leftValue !== '' && $leftValue === $rightValue;
    }
}

if (!function_exists('udcs_claim_v2_render_detail_sections')) {
    function udcs_claim_v2_render_detail_sections(mysqli $conn, array $claim, array $options = []): void
    {
        $claimId = (int) ($claim['id'] ?? 0);
        $reviewContract = udcs_claim_fetch_review_contract($conn, $claimId, $claim);
        if ($reviewContract === null) {
            echo '<div class="cv2-empty">This claim could not be loaded.</div>';
            return;
        }

        $claim = (array) ($reviewContract['claim'] ?? $claim);
        $people = (array) ($reviewContract['people']['items'] ?? []);
        $assets = (array) ($reviewContract['assets']['items'] ?? []);
        $documents = (array) ($reviewContract['documents']['items'] ?? []);
        $history = (array) ($reviewContract['history']['items'] ?? []);
        $groups = (array) ($reviewContract['people']['grouped'] ?? udcs_claim_people_grouped($people));
        $peopleSummary = (array) ($reviewContract['people']['summary'] ?? []);
        $assetSummary = (array) ($reviewContract['assets']['summary'] ?? []);
        $documentSummary = (array) ($reviewContract['documents']['summary'] ?? []);
        $reviewSummary = (array) ($reviewContract['review'] ?? []);
        $payoutSummary = (array) ($reviewContract['payout'] ?? []);
        $reviewFlags = (array) ($reviewSummary['flags'] ?? []);
        $personNameMap = [];
        foreach ($people as $personRow) {
            $personNameMap[(int) ($personRow['person_id'] ?? 0)] = (string) ($personRow['full_name'] ?? '');
        }

        $status = udcs_claim_effective_status($claim);
        $statusLabel = (string) ($reviewContract['status']['label'] ?? udcs_claim_status_label($status));
        $statusClass = (string) ($reviewContract['status']['class'] ?? udcs_claim_status_class($status));
        $manualReviewFlag = (bool) ($reviewContract['review']['manual_review_required'] ?? ((int) ($claim['manual_review_flag'] ?? 0) === 1));
        $manualReviewReason = trim((string) ($reviewContract['review']['manual_review_reason_key'] ?? ($claim['manual_review_reason'] ?? '')));
        $reopenScopeSummary = (string) ($reviewContract['routing']['legal_reopen_scope_summary'] ?? udcs_claim_reopen_scope_summary((string) ($claim['legal_reopen_scope'] ?? '')));
        $reopenNote = trim((string) ($reviewContract['routing']['legal_reopen_note'] ?? ($claim['legal_reopen_note'] ?? '')));
        $claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
        $financeCurrency = bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? $claimCurrency));
        $claimantValue = (string) ($reviewContract['summary']['claimant_value_label'] ?? bk_claim_amount_display($claim['claim_amount'] ?? null, $claimCurrency, 'Not declared'));
        $financeValue = (string) ($reviewContract['summary']['finance_value_label'] ?? bk_claim_amount_display($claim['finance_assessed_amount'] ?? null, $financeCurrency, 'Not assessed yet'));
        $context = strtolower(trim((string) ($options['context'] ?? 'default')));
        $claimant = $groups['claimant'] ?? null;
        $deceased = $groups['deceased'] ?? null;
        $spouse = $groups['spouse'] ?? null;
        $notes = trim((string) ($claim['claim_description'] ?? ''));
        $comment = trim((string) ($claim['comment'] ?? ''));
        $distributionMethod = trim((string) ($payoutSummary['preferred_method'] ?? (($claim['preferred_payout_method'] ?? '') !== '' ? ($claim['preferred_payout_method'] ?? '') : ($claim['distribution_method'] ?? ''))));
        $distributionRows = (array) ($payoutSummary['detail_rows'] ?? bk_distribution_detail_rows((string) ($claim['distribution_details'] ?? '')));
        $legalCanSeeReadiness = $context === 'legal';
        $financeCanSeeReadiness = $context === 'finance';
        $criticalFlagCount = 0;
        $warningFlagCount = 0;
        foreach ($reviewFlags as $flag) {
            $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
            if ($severity === 'danger') {
                $criticalFlagCount++;
            } elseif ($severity === 'warning') {
                $warningFlagCount++;
            }
        }
        $ocrPassedCount = (int) ($documentSummary['ocr_passed_count'] ?? 0);
        $ocrFailedCount = (int) ($documentSummary['ocr_failed_count'] ?? 0);
        $ocrPendingCount = (int) ($documentSummary['ocr_pending_count'] ?? 0);
        $documentCount = (int) ($documentSummary['count'] ?? 0);
        $coHeirCount = (int) ($peopleSummary['co_heir_count'] ?? 0);
        $childCount = (int) ($peopleSummary['child_count'] ?? 0);
        $authorityRequired = (bool) ($peopleSummary['acting_on_behalf'] ?? false);
        $authorityPresent = (bool) ($documentSummary['authority_document_present'] ?? false);
        $destinationComplete = (bool) ($payoutSummary['destination_complete'] ?? false);
        $financeRecordLogged = str_contains($comment, 'Finance Manual Verification Record') || str_contains($comment, 'Finance Manual Verification Snapshot');
        $assetCount = (int) ($assetSummary['count'] ?? 0);
        $assetReviewedCount = (int) ($assetSummary['reviewed_count'] ?? 0);
        $assetConfirmedCount = (int) ($assetSummary['confirmed_count'] ?? 0);
        $assetHoldCount = (int) ($assetSummary['hold_count'] ?? 0);
        $assetMissingCount = (int) ($assetSummary['missing_count'] ?? 0);
        $assetManualCount = (int) ($assetSummary['manual_follow_up_count'] ?? 0);
        $assetVerifiedLabel = (string) ($assetSummary['verified_total_label'] ?? 'Not verified');
        $claimantDisplayName = (string) (($peopleSummary['claimant_name'] ?? '') !== '' ? $peopleSummary['claimant_name'] : (($claimant['full_name'] ?? '') !== '' ? $claimant['full_name'] : 'Not recorded'));
        $deceasedDisplayName = (string) (($peopleSummary['deceased_name'] ?? '') !== '' ? $peopleSummary['deceased_name'] : (($deceased['full_name'] ?? '') !== '' ? $deceased['full_name'] : ((string) ($claim['deceased_full_name'] ?? '') !== '' ? (string) ($claim['deceased_full_name'] ?? '') : 'Not recorded')));
        $notesCount = ($notes !== '' ? 1 : 0) + ($comment !== '' ? 1 : 0);
        $timelineCount = count($history);
        $routingHasSignals = in_array($context, ['legal', 'admin'], true) && ($manualReviewFlag || $manualReviewReason !== '' || $criticalFlagCount > 0 || $warningFlagCount > 0);
        $boardFlags = [];
        if (in_array($context, ['finance', 'admin'], true)) {
            foreach ($reviewFlags as $flag) {
                $flagKey = (string) ($flag['key'] ?? '');
                $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
                if ($severity === 'danger' || in_array($flagKey, [
                    'asset_holds',
                    'asset_manual_follow_up',
                    'asset_not_found',
                    'payout_destination_incomplete',
                    'no_assets_declared',
                ], true)) {
                    $boardFlags[] = $flag;
                }
            }
        } else {
            $boardFlags = $reviewFlags;
        }
        $boardFocusFlags = array_values(array_filter($boardFlags, static function (array $flag): bool {
            $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
            return in_array($severity, ['danger', 'warning'], true);
        }));
        if (empty($boardFocusFlags) && !empty($boardFlags)) {
            $boardFocusFlags = $boardFlags;
        }
        $visibleBoardFlags = array_slice($boardFocusFlags, 0, 4);
        $hiddenBoardFlagCount = max(0, count($boardFocusFlags) - count($visibleBoardFlags));
        $nextChecks = [];
        if (in_array($context, ['legal', 'admin'], true)) {
            if ($routingHasSignals) {
                $nextChecks[] = 'Check the routing context to confirm why this claim sits in a manual or exception path.';
            }
            if ($documentCount > 0) {
                $nextChecks[] = 'Open the uploaded documents and compare OCR intake with the latest legal review result.';
            }
            if (!empty($peopleSummary['possible_missing_heirs']) || $coHeirCount > 0 || $childCount > 0) {
                $nextChecks[] = 'Inspect the linked people before approving entitlement or moving the claim forward.';
            }
            if ($authorityRequired && !$authorityPresent) {
                $nextChecks[] = 'Representative authority is still missing and should be resolved before legal approval.';
            }
        }
        if (in_array($context, ['finance', 'admin'], true)) {
            if ($assetReviewedCount < $assetCount || $assetManualCount > 0 || $assetHoldCount > 0 || $assetMissingCount > 0) {
                $nextChecks[] = 'Finish the per-asset verification so each BK-held product has a confirmed finance outcome.';
            }
            if (!$destinationComplete) {
                $nextChecks[] = 'Review settlement destination details before recording disbursement or closure.';
            }
            if (!$financeRecordLogged) {
                $nextChecks[] = 'Record the bank-side verification and disbursement trace before closing the claim.';
            }
            if ($ocrFailedCount > 0) {
                $nextChecks[] = 'Re-check the uploaded evidence where OCR failures may weaken finance confidence.';
            }
        }
        if (empty($nextChecks)) {
            $nextChecks[] = 'No urgent blocker is visible right now. Use the sections below to confirm the claim before taking action.';
        }
        $nextChecks = array_slice(array_values(array_unique($nextChecks)), 0, 4);
        $jumpLinks = [];
        $jumpLinks[] = ['target' => 'cv2-attention', 'label' => 'Attention'];
        if ($legalCanSeeReadiness) {
            $jumpLinks[] = ['target' => 'cv2-legal-readiness', 'label' => 'Legal Readiness'];
        }
        if ($financeCanSeeReadiness) {
            $jumpLinks[] = ['target' => 'cv2-finance-readiness', 'label' => 'Finance Readiness'];
        }
        if ($documentCount > 0) {
            $jumpLinks[] = ['target' => 'cv2-documents', 'label' => 'Documents'];
        }
        if ($routingHasSignals) {
            $jumpLinks[] = ['target' => 'cv2-routing', 'label' => 'Routing'];
        }
        if ($assetCount > 0) {
            $jumpLinks[] = ['target' => 'cv2-assets', 'label' => 'Assets'];
        }
        if ($distributionMethod !== '' || !empty($distributionRows)) {
            $jumpLinks[] = ['target' => 'cv2-settlement', 'label' => 'Settlement'];
        }
        if (!empty($people)) {
            $jumpLinks[] = ['target' => 'cv2-people', 'label' => 'People'];
        }
        if ($notesCount > 0) {
            $jumpLinks[] = ['target' => 'cv2-notes', 'label' => 'Notes'];
        }
        if ($timelineCount > 0) {
            $jumpLinks[] = ['target' => 'cv2-timeline', 'label' => 'Timeline'];
        }
        $sheetContextClass = 'cv2-context-' . preg_replace('/[^a-z0-9_-]/', '', $context !== '' ? $context : 'default');

        ?>
        <style>
            .cv2-sheet { display: grid; gap: 0.88rem; max-width: 1400px; margin: 0 auto; padding: 0.82rem; border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1.36rem; background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.055), rgba(var(--bk-bg-rgb), 0.96)); box-shadow: 0 22px 46px rgba(var(--bk-primary-rgb), 0.08); }
            .cv2-sheet [id] { scroll-margin-top: 1rem; }
            .cv2-command-grid { display: grid; gap: 0.88rem; grid-template-columns: minmax(0, 1.72fr) minmax(320px, 0.98fr); align-items: start; }
            .cv2-overview-card { width: 100%; border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1.22rem; background: rgb(var(--bk-white-rgb)); overflow: hidden; box-shadow: 0 12px 28px rgba(var(--bk-primary-rgb), 0.06); }
            .cv2-overview-head { display: grid; gap: 0.68rem; padding: 0.92rem 1rem 0.88rem; border-bottom: 1px solid rgba(var(--bk-border-rgb), 1); background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.03)); box-shadow: inset 5px 0 0 rgb(var(--bk-primary-rgb)); }
            .cv2-overview-kicker { color: rgb(var(--bk-primary-rgb)); font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.08em; }
            .cv2-overview-title { margin: 0.04rem 0 0; color: rgb(var(--bk-text-rgb)); font-size: 1.18rem; font-weight: 900; line-height: 1.16; }
            .cv2-overview-note { margin: 0.15rem 0 0; color: rgb(var(--bk-muted-rgb)); font-size: 0.76rem; line-height: 1.42; max-width: 52rem; }
            .cv2-status-line { display: flex; flex-wrap: wrap; gap: 0.38rem; }
            .cv2-jumpbar { display: flex; flex-wrap: wrap; gap: 0.42rem; }
            .cv2-jump { display: inline-flex; align-items: center; justify-content: center; gap: 0.34rem; border-radius: 999px; border: 1px solid rgba(var(--bk-primary-rgb), 0.18); background: rgba(var(--bk-primary-rgb), 0.07); color: rgb(var(--bk-text-rgb)); text-decoration: none; font-size: 0.74rem; font-weight: 780; padding: 0.34rem 0.66rem; transition: transform 0.16s ease, border-color 0.16s ease, background-color 0.16s ease; }
            .cv2-jump:hover, .cv2-jump:focus, .cv2-jump:focus-visible { color: rgb(var(--bk-text-rgb)); text-decoration: none; transform: translateY(-1px); border-color: rgba(var(--bk-primary-rgb), 0.36); background: rgba(var(--bk-primary-rgb), 0.12); }
            .cv2-overview-body { padding: 0.92rem 1rem 1rem; display: grid; gap: 0.74rem; background: rgba(var(--bk-primary-rgb), 0.026); }
            .cv2-overview-grid { display: grid; gap: 0.62rem; grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .cv2-overview-tile { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 0.94rem; padding: 0.72rem 0.78rem; background: rgba(var(--bk-white-rgb), 1); min-width: 0; box-shadow: 0 3px 10px rgba(var(--bk-primary-rgb), 0.028); }
            .cv2-overview-label { font-size: 0.68rem; color: rgb(var(--bk-muted-rgb)); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 900; }
            .cv2-overview-value { margin-top: 0.18rem; color: rgb(var(--bk-text-rgb)); font-size: 0.92rem; font-weight: 860; line-height: 1.24; overflow-wrap: anywhere; }
            .cv2-overview-value.is-strong { color: rgb(var(--bk-primary-rgb)); font-size: 0.98rem; }
            .cv2-overview-rail { display: grid; gap: 0.62rem; grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .cv2-overview-cell { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 0.94rem; background: rgba(var(--bk-bg-rgb), 0.42); padding: 0.72rem 0.78rem; display: grid; gap: 0.24rem; min-width: 0; }
            .cv2-overview-cell-value { color: rgb(var(--bk-text-rgb)); font-size: 0.84rem; font-weight: 760; line-height: 1.36; overflow-wrap: anywhere; }
            .cv2-focus-card { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1.22rem; background: rgb(var(--bk-white-rgb)); overflow: hidden; box-shadow: 0 12px 28px rgba(var(--bk-primary-rgb), 0.06); }
            .cv2-focus-head { display: flex; align-items: center; justify-content: space-between; gap: 0.76rem; padding: 0.92rem 1rem 0.88rem; border-bottom: 1px solid rgba(var(--bk-border-rgb), 1); background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.03)); box-shadow: inset 5px 0 0 rgb(var(--bk-primary-rgb)); }
            .cv2-focus-title { color: rgb(var(--bk-text-rgb)); font-size: 0.98rem; font-weight: 900; }
            .cv2-focus-body { padding: 0.92rem 1rem 1rem; display: grid; gap: 0.88rem; background: rgba(var(--bk-primary-rgb), 0.026); }
            .cv2-focus-group { display: grid; gap: 0.56rem; }
            .cv2-focus-label { color: rgb(var(--bk-muted-rgb)); font-size: 0.72rem; font-weight: 900; letter-spacing: 0.06em; text-transform: uppercase; }
            .cv2-checklist { display: grid; gap: 0.48rem; }
            .cv2-check { display: flex; align-items: flex-start; gap: 0.5rem; border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 0.9rem; background: rgba(var(--bk-white-rgb), 1); padding: 0.58rem 0.66rem; color: rgb(var(--bk-text-rgb)); font-size: 0.8rem; font-weight: 700; line-height: 1.38; }
            .cv2-check i { color: rgb(var(--bk-primary-rgb)); margin-top: 0.06rem; flex: 0 0 auto; }
            .cv2-section { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1.08rem; background: rgb(var(--bk-white-rgb)); overflow: hidden; box-shadow: 0 6px 16px rgba(var(--bk-primary-rgb), 0.03); }
            .cv2-head { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; padding: 0.82rem 0.96rem; border-bottom: 1px solid rgba(var(--bk-border-rgb), 1); background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.035)); color: rgb(var(--bk-text-rgb)); font-size: 0.9rem; font-weight: 900; letter-spacing: 0.01em; box-shadow: inset 5px 0 0 rgb(var(--bk-primary-rgb)); }
            .cv2-head-meta { display: flex; flex-wrap: wrap; gap: 0.38rem; }
            .cv2-grid { padding: 0.86rem 0.92rem 0.92rem; display: grid; gap: 0.62rem; grid-template-columns: repeat(2, minmax(0, 1fr)); background: rgba(var(--bk-primary-rgb), 0.03); }
            .cv2-item { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 0.92rem; padding: 0.72rem 0.8rem; background: rgba(var(--bk-white-rgb), 1); min-width: 0; box-shadow: none; }
            .cv2-label { font-size: 0.72rem; color: rgb(var(--bk-text-rgb)); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.2rem; font-weight: 800; opacity: 0.9; }
            .cv2-value { color: rgb(var(--bk-text-rgb)); font-size: 0.92rem; font-weight: 700; overflow-wrap: anywhere; }
            .cv2-value.subtle { font-weight: 600; color: rgb(var(--bk-muted-rgb)); }
            .cv2-value.strong { color: rgb(var(--bk-primary-rgb)); font-size: 1rem; }
            .cv2-status { display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 999px; padding: 0.32rem 0.7rem; border: 1px solid rgba(var(--bk-border-rgb), 1); font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
            .cv2-status.status-pending { background: rgba(var(--bk-primary-rgb), 0.12); color: rgb(var(--bk-primary-rgb)); }
            .cv2-status.status-review, .cv2-status.status-warning { background: rgba(var(--bk-warning-rgb), 0.14); color: rgb(var(--bk-warning-rgb)); }
            .cv2-status.status-approved { background: rgba(var(--bk-success-rgb), 0.14); color: rgb(var(--bk-success-rgb)); }
            .cv2-status.status-rejected { background: rgba(var(--bk-danger-rgb), 0.12); color: rgb(var(--bk-danger-rgb)); }
            .cv2-status.status-neutral { background: rgba(var(--bk-muted-rgb), 0.12); color: rgb(var(--bk-muted-rgb)); }
            .cv2-badges { display: flex; flex-wrap: wrap; gap: 0.45rem; }
            .cv2-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.65rem; border-radius: 999px; background: rgba(var(--bk-primary-rgb), 0.11); color: rgb(var(--bk-primary-rgb)); font-size: 0.76rem; font-weight: 700; }
            .cv2-readiness { padding: 0.86rem 0.92rem 0.92rem; display: grid; gap: 0.68rem; background: rgba(var(--bk-primary-rgb), 0.03); }
            .cv2-readiness-grid { display: grid; gap: 0.62rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .cv2-readiness-card { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1rem; background: rgba(var(--bk-white-rgb), 1); padding: 0.82rem; min-width: 0; }
            .cv2-readiness-card.is-ok { border-color: rgba(var(--bk-success-rgb), 0.42); background: rgba(var(--bk-white-rgb), 1); box-shadow: inset 4px 0 0 rgb(var(--bk-success-rgb)); }
            .cv2-readiness-card.is-warning { border-color: rgba(var(--bk-warning-rgb), 0.44); background: rgba(var(--bk-white-rgb), 1); box-shadow: inset 4px 0 0 rgb(var(--bk-warning-rgb)); }
            .cv2-readiness-card.is-danger { border-color: rgba(var(--bk-danger-rgb), 0.42); background: rgba(var(--bk-white-rgb), 1); box-shadow: inset 4px 0 0 rgb(var(--bk-danger-rgb)); }
            .cv2-readiness-kicker { display: flex; align-items: center; gap: 0.35rem; color: rgb(var(--bk-text-rgb)); font-size: 0.7rem; font-weight: 900; letter-spacing: 0.06em; text-transform: uppercase; }
            .cv2-readiness-value { margin-top: 0.28rem; color: rgb(var(--bk-text-rgb)); font-size: 0.94rem; font-weight: 850; line-height: 1.25; }
            .cv2-readiness-note { margin-top: 0.18rem; color: rgb(var(--bk-muted-rgb)); font-size: 0.74rem; line-height: 1.38; }
            .cv2-flag-stack { display: grid; gap: 0.38rem; }
            .cv2-flag { display: flex; align-items: flex-start; gap: 0.44rem; border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 0.9rem; background: rgba(var(--bk-white-rgb), 1); padding: 0.58rem 0.66rem; color: rgb(var(--bk-text-rgb)); font-size: 0.82rem; font-weight: 700; line-height: 1.38; }
            .cv2-flag i { margin-top: 0.05rem; flex: 0 0 auto; }
            .cv2-flag.is-danger { border-color: rgba(var(--bk-danger-rgb), 0.42); background: rgba(var(--bk-danger-rgb), 0.09); color: rgb(var(--bk-danger-rgb)); }
            .cv2-flag.is-warning { border-color: rgba(var(--bk-warning-rgb), 0.44); background: rgba(var(--bk-warning-rgb), 0.11); color: #8a5b00; }
            .cv2-flag.is-ok { border-color: rgba(var(--bk-success-rgb), 0.42); background: rgba(var(--bk-success-rgb), 0.09); color: rgb(var(--bk-success-rgb)); }
            .cv2-rows { padding: 0.86rem 0.92rem 0.92rem; display: grid; gap: 0.68rem; background: rgba(var(--bk-primary-rgb), 0.03); }
            .cv2-row { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1rem; background: rgba(var(--bk-white-rgb), 1); padding: 0.78rem 0.84rem; display: grid; gap: 0.68rem; box-shadow: 0 4px 14px rgba(var(--bk-primary-rgb), 0.03); }
            .cv2-row-top { display: flex; justify-content: space-between; gap: 0.72rem; align-items: flex-start; padding-bottom: 0.52rem; border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.9); }
            .cv2-row-title { font-size: 0.98rem; font-weight: 900; color: rgb(var(--bk-text-rgb)); }
            .cv2-row-meta { display: flex; flex-wrap: wrap; gap: 0.4rem; }
            .cv2-mini { display: inline-flex; align-items: center; gap: 0.32rem; padding: 0.24rem 0.58rem; border-radius: 999px; background: rgba(var(--bk-primary-rgb), 0.08); border: 1px solid rgba(var(--bk-primary-rgb), 0.16); color: rgb(var(--bk-text-rgb)); font-size: 0.74rem; font-weight: 700; }
            .cv2-subgrid { display: grid; gap: 0.55rem; grid-template-columns: repeat(2, minmax(0, 1fr)); border: none; background: transparent; }
            .cv2-subgrid .cv2-item { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 0.86rem; box-shadow: none; padding: 0.62rem 0.7rem; }
            .cv2-doc-status-row { display: flex; align-items: center; justify-content: space-between; gap: 0.7rem; flex-wrap: wrap; }
            .cv2-doc-link {
                display: inline-flex;
                width: 2cm;
                height: 2cm;
                min-width: 2cm;
                min-height: 2cm;
                align-items: center;
                justify-content: center;
                align-self: center;
                flex: 0 0 2cm;
                padding: 0.16rem;
                border: 1px solid rgb(var(--bk-primary-rgb));
                border-radius: 0.72rem;
                background: rgb(var(--bk-primary-rgb));
                color: rgba(var(--bk-white-rgb), 1) !important;
                -webkit-text-fill-color: rgba(var(--bk-white-rgb), 1);
                font-size: 0.76rem;
                font-weight: 900;
                text-decoration: none;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                box-shadow: none;
            }
            .cv2-doc-link span {
                color: rgba(var(--bk-white-rgb), 1) !important;
                -webkit-text-fill-color: rgba(var(--bk-white-rgb), 1);
            }
            .cv2-doc-link:hover,
            .cv2-doc-link:focus,
            .cv2-doc-link:focus-visible,
            .cv2-doc-link:active {
                background: rgb(var(--bk-primary-rgb));
                border-color: rgb(var(--bk-primary-rgb));
                color: rgba(var(--bk-white-rgb), 1) !important;
                -webkit-text-fill-color: rgba(var(--bk-white-rgb), 1);
                text-decoration: none;
                outline: none;
                box-shadow: none;
            }
            .cv2-stack { padding: 0.86rem 0.92rem 0.92rem; display: grid; gap: 0.68rem; background: rgba(var(--bk-primary-rgb), 0.03); }
            .cv2-note { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 0.92rem; background: rgba(var(--bk-white-rgb), 1); padding: 0.74rem 0.82rem; color: rgb(var(--bk-text-rgb)); font-size: 0.84rem; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; }
            .cv2-input, .cv2-select { width: 100%; min-height: 2.7rem; border: 1.5px solid rgba(var(--bk-primary-rgb), 0.24); border-radius: 0.76rem; padding: 0.66rem 0.76rem; background: rgba(var(--bk-white-rgb), 1); color: rgb(var(--bk-text-rgb)); font-size: 0.86rem; box-shadow: none; }
            .cv2-input::placeholder { color: rgb(var(--bk-muted-rgb) / 0.88); opacity: 1; }
            .cv2-input:focus, .cv2-select:focus, .cv2-input:focus-visible, .cv2-select:focus-visible { outline: none; border-color: rgb(var(--bk-primary-rgb)); box-shadow: 0 0 0 4px rgba(var(--bk-primary-rgb), 0.14); }
            .cv2-input:disabled, .cv2-select:disabled, .cv2-input[readonly] { opacity: 1; color: rgb(var(--bk-text-rgb)); -webkit-text-fill-color: rgb(var(--bk-text-rgb)); background: linear-gradient(180deg, rgba(var(--bk-bg-rgb), 0.66), rgba(var(--bk-white-rgb), 0.98)); border-color: rgba(var(--bk-primary-rgb), 0.24); }
            .cv2-field-help { margin-top: 0.28rem; color: rgb(var(--bk-text-rgb)); font-size: 0.76rem; line-height: 1.45; opacity: 0.78; }
            .cv2-fold { border: 1px solid rgba(var(--bk-border-rgb), 1); border-radius: 1.08rem; background: rgb(var(--bk-white-rgb)); overflow: hidden; box-shadow: 0 6px 16px rgba(var(--bk-primary-rgb), 0.03); }
            .cv2-fold[open] { box-shadow: 0 8px 18px rgba(var(--bk-primary-rgb), 0.05); }
            .cv2-fold-summary { list-style: none; display: flex; align-items: center; justify-content: space-between; gap: 0.9rem; cursor: pointer; padding: 0.86rem 0.96rem; background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.034)); box-shadow: inset 5px 0 0 rgb(var(--bk-primary-rgb)); }
            .cv2-fold-summary::-webkit-details-marker { display: none; }
            .cv2-fold-summary:focus-visible { outline: none; box-shadow: inset 5px 0 0 rgb(var(--bk-primary-rgb)), 0 0 0 3px rgba(var(--bk-primary-rgb), 0.12); }
            .cv2-fold-heading { display: flex; align-items: center; gap: 0.66rem; min-width: 0; }
            .cv2-fold-icon { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 0.72rem; background: rgba(var(--bk-primary-rgb), 0.1); color: rgb(var(--bk-primary-rgb)); border: 1px solid rgba(var(--bk-primary-rgb), 0.16); flex: 0 0 auto; }
            .cv2-fold-copy { display: grid; gap: 0.12rem; min-width: 0; }
            .cv2-fold-title { color: rgb(var(--bk-text-rgb)); font-size: 0.92rem; font-weight: 900; line-height: 1.2; }
            .cv2-fold-text { color: rgb(var(--bk-muted-rgb)); font-size: 0.76rem; line-height: 1.36; }
            .cv2-fold-meta { display: flex; align-items: center; gap: 0.42rem; flex-wrap: wrap; justify-content: flex-end; }
            .cv2-fold-pill { display: inline-flex; align-items: center; gap: 0.28rem; padding: 0.24rem 0.56rem; border-radius: 999px; background: rgba(var(--bk-primary-rgb), 0.08); border: 1px solid rgba(var(--bk-primary-rgb), 0.12); color: rgb(var(--bk-text-rgb)); font-size: 0.73rem; font-weight: 750; }
            .cv2-fold-chevron { font-size: 0.92rem; color: rgb(var(--bk-primary-rgb)); transition: transform 0.2s ease; }
            .cv2-fold[open] .cv2-fold-chevron { transform: rotate(180deg); }
            .cv2-fold-body { border-top: 1px solid rgba(var(--bk-border-rgb), 1); background: rgba(var(--bk-primary-rgb), 0.03); }
            .cv2-flow { display: grid; gap: 0.88rem; }
            .cv2-fold.is-primary,
            .cv2-section.is-primary { box-shadow: 0 10px 24px rgba(var(--bk-primary-rgb), 0.05); }
            .cv2-fold.is-primary .cv2-fold-summary,
            .cv2-section.is-primary .cv2-head { background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.055)); }
            .cv2-fold.is-secondary { box-shadow: 0 4px 12px rgba(var(--bk-primary-rgb), 0.025); }
            .cv2-context-default #cv2-documents,
            .cv2-context-admin #cv2-documents,
            .cv2-context-legal #cv2-documents,
            .cv2-context-finance #cv2-documents { order: 10; }
            .cv2-context-legal #cv2-legal-readiness { order: 15; }
            .cv2-context-finance #cv2-finance-readiness { order: 15; }
            .cv2-context-legal #cv2-routing,
            .cv2-context-admin #cv2-routing { order: 20; }
            .cv2-context-finance #cv2-assets,
            .cv2-context-admin #cv2-assets { order: 30; }
            .cv2-context-finance #cv2-settlement,
            .cv2-context-admin #cv2-settlement { order: 40; }
            .cv2-context-legal #cv2-people,
            .cv2-context-admin #cv2-people,
            .cv2-context-finance #cv2-people { order: 50; }
            .cv2-context-legal #cv2-assets { order: 60; }
            .cv2-context-legal #cv2-settlement { order: 70; }
            .cv2-context-finance #cv2-routing { order: 80; }
            .cv2-context-default #cv2-notes,
            .cv2-context-admin #cv2-notes,
            .cv2-context-legal #cv2-notes,
            .cv2-context-finance #cv2-notes { order: 90; }
            .cv2-context-default #cv2-timeline,
            .cv2-context-admin #cv2-timeline,
            .cv2-context-legal #cv2-timeline,
            .cv2-context-finance #cv2-timeline { order: 100; }
            .cv2-empty { padding: 0.78rem 0.82rem; color: rgb(var(--bk-muted-rgb)); }
            @media (max-width: 1120px) {
                .cv2-sheet { gap: 0.85rem; padding: 0.68rem; }
                .cv2-command-grid { grid-template-columns: 1fr; }
                .cv2-overview-grid,
                .cv2-overview-rail { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            @media (max-width: 860px) {
                .cv2-overview-grid,
                .cv2-overview-rail,
                .cv2-fold-summary { grid-template-columns: 1fr; }
                .cv2-fold-summary { flex-direction: column; align-items: stretch; }
                .cv2-fold-meta { justify-content: flex-start; }
                .cv2-grid, .cv2-subgrid, .cv2-readiness-grid { grid-template-columns: 1fr; }
                .cv2-row-top { flex-direction: column; }
            }
        </style>

        <div class="cv2-sheet <?php echo bk_e($sheetContextClass); ?>">
            <div class="cv2-command-grid">
            <section class="cv2-overview-card">
                <div class="cv2-overview-head">
                    <div class="cv2-overview-kicker">Claim Review Workspace</div>
                    <h3 class="cv2-overview-title"><?php echo bk_e($deceasedDisplayName); ?></h3>
                    <p class="cv2-overview-note"><?php echo bk_e(in_array($context, ['finance', 'admin'], true) ? 'Use this workspace to confirm BK-held assets, settlement readiness, and the exact document path before closure.' : 'Use this workspace to understand the family path, evidence position, and legal routing context before you decide.'); ?></p>
                    <div class="cv2-status-line">
                        <span class="cv2-mini">CL-<?php echo str_pad((string) $claimId, 6, '0', STR_PAD_LEFT); ?></span>
                        <span class="cv2-status <?php echo bk_e($statusClass); ?>"><?php echo bk_e($statusLabel); ?></span>
                        <?php if ($manualReviewFlag || $manualReviewReason !== ''): ?>
                            <span class="cv2-pill"><?php echo bk_e($manualReviewReason !== '' ? udcs_claim_manual_reason_label($manualReviewReason) : 'Manual review required'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($jumpLinks)): ?>
                        <div class="cv2-jumpbar">
                            <?php foreach ($jumpLinks as $jump): ?>
                                <a class="cv2-jump" href="#<?php echo bk_e((string) ($jump['target'] ?? '')); ?>"><?php echo bk_e((string) ($jump['label'] ?? 'Section')); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="cv2-overview-body">
                    <div class="cv2-overview-grid">
                        <article class="cv2-overview-tile">
                            <div class="cv2-overview-label">Claimant</div>
                            <div class="cv2-overview-value"><?php echo bk_e($claimantDisplayName); ?></div>
                        </article>
                        <article class="cv2-overview-tile">
                            <div class="cv2-overview-label">Relationship</div>
                            <div class="cv2-overview-value"><?php echo bk_e(udcs_claim_relationship_label((string) ($claim['relationship'] ?? ''))); ?></div>
                        </article>
                        <article class="cv2-overview-tile">
                            <div class="cv2-overview-label">Submitted</div>
                            <div class="cv2-overview-value"><?php echo bk_e(!empty($claim['submitted_at']) ? date('d M Y H:i', strtotime((string) $claim['submitted_at'])) : 'Not recorded'); ?></div>
                        </article>
                        <article class="cv2-overview-tile">
                            <div class="cv2-overview-label">BK Assets</div>
                            <div class="cv2-overview-value"><?php echo number_format($assetCount); ?></div>
                        </article>
                        <article class="cv2-overview-tile">
                            <div class="cv2-overview-label"><?php echo in_array($context, ['finance', 'admin'], true) ? 'Verified Value' : 'Claimed Value'; ?></div>
                            <div class="cv2-overview-value is-strong"><?php echo bk_e(in_array($context, ['finance', 'admin'], true) ? $assetVerifiedLabel : $claimantValue); ?></div>
                        </article>
                        <article class="cv2-overview-tile">
                            <div class="cv2-overview-label">Settlement Preference</div>
                            <div class="cv2-overview-value"><?php echo bk_e(udcs_claim_v2_payout_label($distributionMethod)); ?></div>
                        </article>
                    </div>
                    <div class="cv2-overview-rail">
                        <article class="cv2-overview-cell">
                            <div class="cv2-overview-label">Family Path</div>
                            <div class="cv2-overview-cell-value"><?php echo bk_e(ucwords(strtolower((string) ($claim['marital_status'] ?? 'Not recorded')))); ?> / <?php echo bk_e(ucwords(strtolower(str_replace('_', ' ', (string) ($claim['children_status'] ?? 'Not recorded'))))); ?></div>
                        </article>
                        <article class="cv2-overview-cell">
                            <div class="cv2-overview-label">Heir Disclosure</div>
                            <div class="cv2-overview-cell-value"><?php echo number_format($coHeirCount); ?> co-heir<?php echo $coHeirCount === 1 ? '' : 's'; ?> and <?php echo number_format($childCount); ?> child record<?php echo $childCount === 1 ? '' : 's'; ?> linked to this claim.</div>
                        </article>
                        <article class="cv2-overview-cell">
                            <div class="cv2-overview-label">OCR Intake</div>
                            <div class="cv2-overview-cell-value"><?php echo number_format($ocrPassedCount); ?> passed, <?php echo number_format($ocrFailedCount); ?> failed, <?php echo number_format($ocrPendingCount); ?> pending across <?php echo number_format($documentCount); ?> document<?php echo $documentCount === 1 ? '' : 's'; ?>.</div>
                        </article>
                        <article class="cv2-overview-cell">
                            <div class="cv2-overview-label"><?php echo in_array($context, ['finance', 'admin'], true) ? 'Settlement Readiness' : 'Authority and Payout'; ?></div>
                            <div class="cv2-overview-cell-value"><?php echo in_array($context, ['finance', 'admin'], true)
                                ? ($destinationComplete ? 'Settlement destination is captured and ready for validation.' : 'Settlement destination still needs review or correction.')
                                : ($authorityRequired ? ($authorityPresent ? 'Representative authority is attached.' : 'Representative authority is still missing.') : 'No extra representative authority is required.'); ?></div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="cv2-focus-card" id="cv2-attention">
                <div class="cv2-focus-head">
                    <span class="cv2-focus-title">Immediate Attention</span>
                    <div class="cv2-head-meta">
                        <span class="cv2-mini"><?php echo number_format($criticalFlagCount); ?> critical</span>
                        <span class="cv2-mini"><?php echo number_format($warningFlagCount); ?> warning</span>
                    </div>
                </div>
                <div class="cv2-focus-body">
                    <div class="cv2-focus-group">
                        <div class="cv2-focus-label">Blockers and Signals</div>
                    <div class="cv2-flag-stack">
                        <?php if (!empty($visibleBoardFlags)): ?>
                            <?php foreach ($visibleBoardFlags as $flag): ?>
                                <?php
                                $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
                                $flagClass = $severity === 'danger' ? 'is-danger' : ($severity === 'warning' ? 'is-warning' : 'is-ok');
                                $flagIcon = $flagClass === 'is-danger' ? 'bi-x-octagon-fill' : ($flagClass === 'is-warning' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
                                ?>
                                <div class="cv2-flag <?php echo bk_e($flagClass); ?>">
                                    <i class="bi <?php echo bk_e($flagIcon); ?>"></i>
                                    <span><?php echo bk_e((string) ($flag['label'] ?? 'Review flag')); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="cv2-flag is-ok">
                                <i class="bi bi-check-circle-fill"></i>
                                <span>No immediate blocker is standing in the way right now.</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($hiddenBoardFlagCount > 0): ?>
                            <div class="cv2-note">There <?php echo $hiddenBoardFlagCount === 1 ? 'is' : 'are'; ?> <?php echo number_format($hiddenBoardFlagCount); ?> more flagged signal<?php echo $hiddenBoardFlagCount === 1 ? '' : 's'; ?> inside the detailed sections below.</div>
                        <?php endif; ?>
                    </div>
                    </div>
                    <div class="cv2-focus-group">
                        <div class="cv2-focus-label">Next Review Checks</div>
                        <div class="cv2-checklist">
                            <?php foreach ($nextChecks as $nextCheck): ?>
                                <div class="cv2-check">
                                    <i class="bi bi-arrow-right-circle-fill"></i>
                                    <span><?php echo bk_e($nextCheck); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
            </div>

            <div class="cv2-flow">
            <?php if ($legalCanSeeReadiness): ?>
                <section class="cv2-section is-primary" id="cv2-legal-readiness">
                    <div class="cv2-head">Legal Review Readiness</div>
                    <div class="cv2-readiness">
                        <div class="cv2-readiness-grid">
                            <article class="cv2-readiness-card <?php echo $criticalFlagCount > 0 ? 'is-danger' : ($warningFlagCount > 0 ? 'is-warning' : 'is-ok'); ?>">
                                <div class="cv2-readiness-kicker"><i class="bi bi-shield-check"></i> Review Signals</div>
                                <div class="cv2-readiness-value"><?php echo number_format($criticalFlagCount); ?> critical / <?php echo number_format($warningFlagCount); ?> warning</div>
                                <div class="cv2-readiness-note">Critical blockers must be corrected before Finance routing.</div>
                            </article>
                            <article class="cv2-readiness-card <?php echo $ocrFailedCount > 0 ? 'is-danger' : ($ocrPendingCount > 0 ? 'is-warning' : 'is-ok'); ?>">
                                <div class="cv2-readiness-kicker"><i class="bi bi-file-earmark-check"></i> OCR Gate</div>
                                <div class="cv2-readiness-value"><?php echo number_format($ocrPassedCount); ?> passed / <?php echo number_format($ocrFailedCount); ?> failed</div>
                                <div class="cv2-readiness-note"><?php echo number_format($documentCount); ?> document<?php echo $documentCount === 1 ? '' : 's'; ?> linked to the claim.</div>
                            </article>
                            <article class="cv2-readiness-card <?php echo !empty($peopleSummary['possible_missing_heirs']) ? 'is-warning' : 'is-ok'; ?>">
                                <div class="cv2-readiness-kicker"><i class="bi bi-people"></i> Heir Disclosure</div>
                                <div class="cv2-readiness-value"><?php echo number_format($coHeirCount); ?> co-heir<?php echo $coHeirCount === 1 ? '' : 's'; ?> / <?php echo number_format($childCount); ?> child record<?php echo $childCount === 1 ? '' : 's'; ?></div>
                                <div class="cv2-readiness-note"><?php echo !empty($peopleSummary['possible_missing_heirs']) ? 'Family path needs careful Legal review.' : 'No automatic missing-heir signal.'; ?></div>
                            </article>
                            <article class="cv2-readiness-card <?php echo ($authorityRequired && !$authorityPresent) || !$destinationComplete ? 'is-warning' : 'is-ok'; ?>">
                                <div class="cv2-readiness-kicker"><i class="bi bi-send-check"></i> Authority & Payout</div>
                                <div class="cv2-readiness-value"><?php echo $authorityRequired ? ($authorityPresent ? 'Authority present' : 'Authority missing') : 'Authority not required'; ?></div>
                                <div class="cv2-readiness-note"><?php echo $destinationComplete ? 'Payout destination details are captured.' : 'Payout destination needs review or correction.'; ?></div>
                            </article>
                        </div>

                        <div class="cv2-flag-stack">
                            <?php if (!empty($reviewFlags)): ?>
                                <?php foreach ($reviewFlags as $flag): ?>
                                    <?php
                                    $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
                                    $flagClass = $severity === 'danger' ? 'is-danger' : ($severity === 'warning' ? 'is-warning' : 'is-ok');
                                    $flagIcon = $flagClass === 'is-danger' ? 'bi-x-octagon-fill' : ($flagClass === 'is-warning' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
                                    ?>
                                    <div class="cv2-flag <?php echo bk_e($flagClass); ?>">
                                        <i class="bi <?php echo bk_e($flagIcon); ?>"></i>
                                        <span><?php echo bk_e((string) ($flag['label'] ?? 'Review flag')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="cv2-flag is-ok">
                                    <i class="bi bi-check-circle-fill"></i>
                                    <span>No automatic Legal blockers were detected. Legal still reviews the evidence before approval.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($financeCanSeeReadiness): ?>
                <section class="cv2-section is-primary" id="cv2-finance-readiness">
                    <div class="cv2-head">Finance Settlement Readiness</div>
                    <div class="cv2-readiness">
                        <div class="cv2-readiness-grid">
                            <article class="cv2-readiness-card <?php echo ($assetHoldCount > 0 || $assetMissingCount > 0) ? 'is-danger' : ($assetManualCount > 0 || $assetReviewedCount < $assetCount ? 'is-warning' : 'is-ok'); ?>">
                                <div class="cv2-readiness-kicker"><i class="bi bi-bank"></i> BK Asset Verification</div>
                                <div class="cv2-readiness-value"><?php echo number_format($assetConfirmedCount); ?> confirmed / <?php echo number_format($assetCount); ?> claimed</div>
                                <div class="cv2-readiness-note"><?php echo number_format($assetReviewedCount); ?> reviewed, <?php echo number_format($assetHoldCount); ?> hold(s), <?php echo number_format($assetMissingCount); ?> unmatched.</div>
                            </article>
                            <article class="cv2-readiness-card <?php echo $destinationComplete ? 'is-ok' : 'is-warning'; ?>">
                                <div class="cv2-readiness-kicker"><i class="bi bi-send-check"></i> Settlement Destination</div>
                                <div class="cv2-readiness-value"><?php echo bk_e(udcs_claim_v2_payout_label($distributionMethod)); ?></div>
                                <div class="cv2-readiness-note"><?php echo $destinationComplete ? 'Destination details are captured for finance validation.' : 'Destination details must be corrected or confirmed before closure.'; ?></div>
                            </article>
                            <article class="cv2-readiness-card <?php echo $assetVerifiedTotal !== null ? 'is-ok' : 'is-warning'; ?>">
                                <div class="cv2-readiness-kicker"><i class="bi bi-cash-coin"></i> Verified Value</div>
                                <div class="cv2-readiness-value"><?php echo bk_e($assetVerifiedLabel); ?></div>
                                <div class="cv2-readiness-note">Use the final value field below to record the settlement value sent to the claimant.</div>
                            </article>
                            <article class="cv2-readiness-card <?php echo $financeRecordLogged ? 'is-ok' : 'is-warning'; ?>">
                                <div class="cv2-readiness-kicker"><i class="bi bi-journal-check"></i> Manual Record</div>
                                <div class="cv2-readiness-value"><?php echo $financeRecordLogged ? 'Already logged' : 'Required before closure'; ?></div>
                                <div class="cv2-readiness-note">Finance closure must represent manual execution in bank operations.</div>
                            </article>
                        </div>

                        <div class="cv2-flag-stack">
                            <?php
                            $financeFlags = [];
                            foreach ($reviewFlags as $flag) {
                                $key = (string) ($flag['key'] ?? '');
                                $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
                                if ($severity === 'danger' || in_array($key, [
                                    'asset_holds',
                                    'asset_manual_follow_up',
                                    'asset_not_found',
                                    'payout_destination_incomplete',
                                    'no_assets_declared',
                                ], true)) {
                                    $financeFlags[] = $flag;
                                }
                            }
                            ?>
                            <?php if (!empty($financeFlags)): ?>
                                <?php foreach ($financeFlags as $flag): ?>
                                    <?php
                                    $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
                                    $flagClass = $severity === 'danger' ? 'is-danger' : ($severity === 'warning' ? 'is-warning' : 'is-ok');
                                    $flagIcon = $flagClass === 'is-danger' ? 'bi-x-octagon-fill' : ($flagClass === 'is-warning' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
                                    ?>
                                    <div class="cv2-flag <?php echo bk_e($flagClass); ?>">
                                        <i class="bi <?php echo bk_e($flagIcon); ?>"></i>
                                        <span><?php echo bk_e((string) ($flag['label'] ?? 'Finance settlement signal')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="cv2-flag is-ok">
                                    <i class="bi bi-check-circle-fill"></i>
                                    <span>No automatic Finance settlement blockers are currently detected. Finance still confirms bank-side records before closure.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <details class="cv2-fold is-primary" id="cv2-routing">
                <summary class="cv2-fold-summary">
                    <div class="cv2-fold-heading">
                        <span class="cv2-fold-icon"><i class="bi bi-diagram-3"></i></span>
                        <div class="cv2-fold-copy">
                            <span class="cv2-fold-title">Review Routing Context</span>
                            <span class="cv2-fold-text">Read the legal path signals that explain why this claim is in its current route.</span>
                        </div>
                    </div>
                    <div class="cv2-fold-meta">
                        <span class="cv2-fold-pill"><?php echo ((int) ($claim['will_exists'] ?? 0) === 1) ? 'Will on record' : 'No will recorded'; ?></span>
                        <span class="cv2-fold-pill"><?php echo ((int) ($claim['acting_on_behalf'] ?? 0) === 1) ? 'Representative path' : 'Self path'; ?></span>
                        <i class="bi bi-chevron-down cv2-fold-chevron" aria-hidden="true"></i>
                    </div>
                </summary>
                <div class="cv2-fold-body">
                <div class="cv2-grid">
                    <article class="cv2-item"><div class="cv2-label">Will Exists</div><div class="cv2-value"><?php echo ((int) ($claim['will_exists'] ?? 0) === 1) ? 'Yes' : 'No'; ?></div></article>
                    <article class="cv2-item"><div class="cv2-label">Marital Status</div><div class="cv2-value"><?php echo bk_e(ucwords(strtolower((string) ($claim['marital_status'] ?? 'Not recorded')))); ?></div></article>
                    <article class="cv2-item"><div class="cv2-label">Spouse Status</div><div class="cv2-value"><?php echo bk_e(ucwords(strtolower(str_replace('_', ' ', (string) ($claim['spouse_status'] ?? 'Not recorded'))))); ?></div></article>
                    <article class="cv2-item"><div class="cv2-label">Children Status</div><div class="cv2-value"><?php echo bk_e(ucwords(strtolower(str_replace('_', ' ', (string) ($claim['children_status'] ?? 'Not recorded'))))); ?></div></article>
                    <article class="cv2-item"><div class="cv2-label">Acting on Behalf</div><div class="cv2-value"><?php echo ((int) ($claim['acting_on_behalf'] ?? 0) === 1) ? 'Yes' : 'No'; ?></div></article>
                    <article class="cv2-item"><div class="cv2-label">Finance Return Reason</div><div class="cv2-value"><?php echo bk_e(trim((string) ($claim['finance_return_reason'] ?? '')) !== '' ? (string) $claim['finance_return_reason'] : 'Not recorded'); ?></div></article>
                </div>
                <?php if ($status === 'More Information Required' && ($reopenScopeSummary !== '' || $reopenNote !== '')): ?>
                    <div class="cv2-stack">
                        <?php if ($reopenScopeSummary !== ''): ?>
                            <div class="cv2-note"><strong>Legal reopened these sections:</strong> <?php echo bk_e($reopenScopeSummary); ?></div>
                        <?php endif; ?>
                        <?php if ($reopenNote !== ''): ?>
                            <div class="cv2-note"><strong>Legal note:</strong> <?php echo bk_e($reopenNote); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                </div>
            </details>

            <details class="cv2-fold is-primary" id="cv2-documents">
                <summary class="cv2-fold-summary">
                    <div class="cv2-fold-heading">
                        <span class="cv2-fold-icon"><i class="bi bi-paperclip"></i></span>
                        <div class="cv2-fold-copy">
                            <span class="cv2-fold-title">Uploaded Documents</span>
                            <span class="cv2-fold-text">Open the originals and confirm OCR intake against legal review status.</span>
                        </div>
                    </div>
                    <div class="cv2-fold-meta">
                        <span class="cv2-fold-pill"><?php echo number_format($documentCount); ?> file<?php echo $documentCount === 1 ? '' : 's'; ?></span>
                        <span class="cv2-fold-pill"><?php echo number_format($ocrPassedCount); ?> OCR passed</span>
                        <i class="bi bi-chevron-down cv2-fold-chevron" aria-hidden="true"></i>
                    </div>
                </summary>
                <div class="cv2-fold-body">
                <?php if (!empty($documents)): ?>
                    <div class="cv2-rows">
                        <?php foreach ($documents as $document): ?>
                            <?php $documentUrl = '../document_access.php?id=' . (int) ($document['id'] ?? 0); ?>
                            <article class="cv2-row">
                                <div class="cv2-row-top">
                                    <div class="cv2-row-title"><?php echo bk_e(udcs_claim_document_label((string) ($document['document_type'] ?? ''))); ?></div>
                                    <div class="cv2-row-meta">
                                        <span class="cv2-mini"><?php echo bk_e((string) ($document['owner_person_name'] ?? '') !== '' ? (string) $document['owner_person_name'] : 'Claim linked document'); ?></span>
                                        <?php if (trim((string) ($document['owner_claim_role'] ?? '')) !== ''): ?>
                                            <span class="cv2-mini"><?php echo bk_e(udcs_claim_v2_role_label((string) ($document['owner_claim_role'] ?? ''))); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="cv2-subgrid">
                                    <div class="cv2-item"><div class="cv2-label">OCR Status</div><div class="cv2-value"><?php echo bk_e(trim((string) ($document['ocr_status'] ?? '')) !== '' ? ucwords((string) $document['ocr_status']) : 'Not recorded'); ?></div></div>
                                    <div class="cv2-item">
                                        <div class="cv2-label">Legal Review Status</div>
                                        <div class="cv2-doc-status-row">
                                            <div class="cv2-value"><?php echo bk_e(trim((string) ($document['legal_review_status'] ?? '')) !== '' ? (string) $document['legal_review_status'] : 'Not reviewed'); ?></div>
                                            <a class="cv2-doc-link" href="<?php echo bk_e($documentUrl); ?>" target="_blank" rel="noopener" aria-label="Open document">
                                                <span>Open</span>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="cv2-item"><div class="cv2-label">Uploaded</div><div class="cv2-value"><?php echo bk_e(!empty($document['uploaded_at']) ? date('d M Y H:i', strtotime((string) $document['uploaded_at'])) : 'Not recorded'); ?></div></div>
                                </div>
                                <?php if (trim((string) ($document['rejection_reason'] ?? '')) !== ''): ?>
                                    <div class="cv2-note"><?php echo bk_e((string) ($document['rejection_reason'] ?? '')); ?></div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="cv2-empty">No structured documents are linked to this claim.</div>
                <?php endif; ?>
                </div>
            </details>

            <?php if ($distributionMethod !== '' || !empty($distributionRows)): ?>
                <details class="cv2-fold is-primary" id="cv2-settlement">
                    <summary class="cv2-fold-summary">
                        <div class="cv2-fold-heading">
                            <span class="cv2-fold-icon"><i class="bi bi-send-check"></i></span>
                            <div class="cv2-fold-copy">
                                <span class="cv2-fold-title">Settlement Preference and Destination</span>
                                <span class="cv2-fold-text">Review the claimant's selected payout path and the destination details captured with the claim.</span>
                            </div>
                        </div>
                        <div class="cv2-fold-meta">
                            <span class="cv2-fold-pill"><?php echo bk_e(udcs_claim_v2_payout_label($distributionMethod)); ?></span>
                            <span class="cv2-fold-pill"><?php echo $destinationComplete ? 'Destination captured' : 'Destination incomplete'; ?></span>
                            <i class="bi bi-chevron-down cv2-fold-chevron" aria-hidden="true"></i>
                        </div>
                    </summary>
                    <div class="cv2-fold-body">
                    <div class="cv2-grid">
                        <article class="cv2-item">
                            <div class="cv2-label">Selected Method</div>
                            <div class="cv2-value"><?php echo bk_e(udcs_claim_v2_payout_label($distributionMethod)); ?></div>
                        </article>
                        <?php if (!empty($distributionRows)): ?>
                            <?php foreach ($distributionRows as $row): ?>
                                <article class="cv2-item">
                                    <div class="cv2-label"><?php echo bk_e((string) ($row['label'] ?? 'Detail')); ?></div>
                                    <div class="cv2-value"><?php echo bk_e((string) ($row['value'] ?? 'Not provided')); ?></div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <article class="cv2-item">
                                <div class="cv2-label">Destination Details</div>
                                <div class="cv2-value subtle">No extra destination details were recorded for this method.</div>
                            </article>
                        <?php endif; ?>
                    </div>
                    </div>
                </details>
            <?php endif; ?>

            <details class="cv2-fold is-secondary" id="cv2-people">
                <summary class="cv2-fold-summary">
                    <div class="cv2-fold-heading">
                        <span class="cv2-fold-icon"><i class="bi bi-people"></i></span>
                        <div class="cv2-fold-copy">
                            <span class="cv2-fold-title">People Linked To This Claim</span>
                            <span class="cv2-fold-text">Open the full family and representative structure only when you need to inspect people-level detail.</span>
                        </div>
                    </div>
                    <div class="cv2-fold-meta">
                        <span class="cv2-fold-pill"><?php echo number_format(count($people)); ?> linked</span>
                        <span class="cv2-fold-pill"><?php echo number_format($coHeirCount); ?> co-heir<?php echo $coHeirCount === 1 ? '' : 's'; ?></span>
                        <i class="bi bi-chevron-down cv2-fold-chevron" aria-hidden="true"></i>
                    </div>
                </summary>
                <div class="cv2-fold-body">
                <div class="cv2-rows">
                    <?php foreach ($people as $person): ?>
                        <?php
                        $personRoleLabel = udcs_claim_v2_role_label((string) ($person['role'] ?? ''), (string) ($person['relationship_type'] ?? ''));
                        $personRelationshipLabel = udcs_claim_relationship_label((string) ($person['relationship_type'] ?? ''));
                        $showRelationshipChip = trim((string) ($person['relationship_type'] ?? '')) !== ''
                            && strtoupper(trim((string) ($person['role'] ?? ''))) !== 'CLAIMANT'
                            && !udcs_claim_v2_same_label($personRoleLabel, $personRelationshipLabel);
                        ?>
                        <article class="cv2-row">
                            <div class="cv2-row-top">
                                <div>
                                    <div class="cv2-row-title"><?php echo bk_e((string) ($person['full_name'] ?? 'Unnamed person')); ?></div>
                                    <div class="cv2-row-meta">
                                        <span class="cv2-mini"><?php echo bk_e($personRoleLabel); ?></span>
                                        <?php if ($showRelationshipChip): ?>
                                            <span class="cv2-mini"><?php echo bk_e($personRelationshipLabel); ?></span>
                                        <?php endif; ?>
                                        <span class="cv2-mini"><?php echo bk_e(udcs_claim_v2_alive_label((string) ($person['alive_status'] ?? 'UNKNOWN'))); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="cv2-subgrid">
                                <div class="cv2-item"><div class="cv2-label">ID Number</div><div class="cv2-value"><?php echo bk_e((string) ($person['id_number'] ?? '') !== '' ? (string) $person['id_number'] : 'Not provided'); ?></div></div>
                                <div class="cv2-item"><div class="cv2-label">Phone</div><div class="cv2-value"><?php echo bk_e((string) ($person['contact_phone'] ?? '') !== '' ? (string) $person['contact_phone'] : 'Not provided'); ?></div></div>
                                <div class="cv2-item"><div class="cv2-label">Email</div><div class="cv2-value"><?php echo bk_e((string) ($person['contact_email'] ?? '') !== '' ? (string) $person['contact_email'] : 'Not provided'); ?></div></div>
                                <?php if ((int) ($person['represented_by_person_id'] ?? 0) > 0): ?>
                                    <div class="cv2-item"><div class="cv2-label">Represents Descendants Of</div><div class="cv2-value"><?php echo bk_e($personNameMap[(int) ($person['represented_by_person_id'] ?? 0)] ?? 'Linked deceased child'); ?></div></div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                </div>
            </details>

            <details class="cv2-fold is-primary" id="cv2-assets">
                <summary class="cv2-fold-summary">
                    <div class="cv2-fold-heading">
                        <span class="cv2-fold-icon"><i class="bi bi-bank"></i></span>
                        <div class="cv2-fold-copy">
                            <span class="cv2-fold-title">BK-Held Assets Under This Claim</span>
                            <span class="cv2-fold-text">Review the asset rows that drive bank-side verification, confirmed values, and settlement routing.</span>
                        </div>
                    </div>
                    <div class="cv2-fold-meta">
                        <span class="cv2-fold-pill"><?php echo number_format($assetCount); ?> asset<?php echo $assetCount === 1 ? '' : 's'; ?></span>
                        <span class="cv2-fold-pill"><?php echo bk_e($assetVerifiedLabel); ?></span>
                        <i class="bi bi-chevron-down cv2-fold-chevron" aria-hidden="true"></i>
                    </div>
                </summary>
                <div class="cv2-fold-body">
                <?php if (!empty($assets)): ?>
                    <div class="cv2-rows">
                        <?php foreach ($assets as $asset): ?>
                            <?php $assetCurrency = bk_asset_currency_code((string) ($asset['asset_class'] ?? ''), (string) ($asset['currency_code'] ?? 'RWF')); ?>
                            <article class="cv2-row">
                                <div class="cv2-row-top">
                                    <div class="cv2-row-title"><?php echo bk_e(udcs_claim_asset_label((string) ($asset['asset_class'] ?? ''))); ?></div>
                                    <div class="cv2-row-meta">
                                        <span class="cv2-mini"><?php echo bk_e($assetCurrency); ?></span>
                                        <?php if (trim((string) ($asset['finance_status'] ?? '')) !== ''): ?>
                                            <span class="cv2-mini"><?php echo bk_e((string) ($asset['finance_status'] ?? '')); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="cv2-subgrid">
                                    <div class="cv2-item"><div class="cv2-label">BK Reference Confirmed By Finance</div><div class="cv2-value"><?php echo bk_e((string) ($asset['account_reference'] ?? '') !== '' ? (string) ($asset['account_reference'] ?? '') : 'Pending finance confirmation'); ?></div></div>
                                    <div class="cv2-item"><div class="cv2-label">Claimant Estimated Value</div><div class="cv2-value"><?php echo bk_e(bk_claim_amount_display($asset['estimated_value'] ?? null, $assetCurrency, 'Not declared')); ?></div></div>
                                    <div class="cv2-item"><div class="cv2-label">Finance Confirmed Value</div><div class="cv2-value"><?php echo bk_e(bk_claim_amount_display($asset['verified_value'] ?? null, $assetCurrency, 'Not verified')); ?></div></div>
                                    <div class="cv2-item"><div class="cv2-label">Asset-Level Settlement Override</div><div class="cv2-value"><?php echo bk_e(udcs_claim_v2_payout_label((string) ($asset['payout_preference_override'] ?? ''))); ?></div></div>
                                </div>
                                <?php if ($context === 'finance'): ?>
                                    <div class="cv2-subgrid">
                                        <div class="cv2-item">
                                            <div class="cv2-label">Asset Confirmation Result</div>
                                            <select class="cv2-select" data-finance-review="status" name="asset_reviews[<?php echo (int) ($asset['claim_asset_id'] ?? 0); ?>][finance_status]">
                                                <option value="">Select status</option>
                                                <option value="Confirmed in BK records" <?php echo (string) ($asset['finance_status'] ?? '') === 'Confirmed in BK records' ? 'selected' : ''; ?>>Asset confirmed in BK records</option>
                                                <option value="Restriction or hold found" <?php echo (string) ($asset['finance_status'] ?? '') === 'Restriction or hold found' ? 'selected' : ''; ?>>Asset found with restriction or hold</option>
                                                <option value="Manual follow-up required" <?php echo (string) ($asset['finance_status'] ?? '') === 'Manual follow-up required' ? 'selected' : ''; ?>>Needs manual follow-up</option>
                                                <option value="No matching BK asset found" <?php echo (string) ($asset['finance_status'] ?? '') === 'No matching BK asset found' ? 'selected' : ''; ?>>No matching BK asset located</option>
                                            </select>
                                            <div class="cv2-field-help">Choose the result after checking Bank of Kigali records and operational controls for this specific asset.</div>
                                        </div>
                                        <div class="cv2-item">
                                            <div class="cv2-label">Confirmed BK Product Reference</div>
                                            <input
                                                class="cv2-input"
                                                data-finance-review="reference"
                                                type="text"
                                                name="asset_reviews[<?php echo (int) ($asset['claim_asset_id'] ?? 0); ?>][account_reference]"
                                                value="<?php echo bk_e((string) ($asset['account_reference'] ?? '')); ?>"
                                                placeholder="Enter the confirmed BK account or product reference"
                                            >
                                            <div class="cv2-field-help">Record the exact BK account number, deposit reference, security reference, or investment reference only when the asset is confirmed or located.</div>
                                        </div>
                                        <div class="cv2-item">
                                            <div class="cv2-label">Confirmed Value In BK Records (<?php echo bk_e($assetCurrency); ?>)</div>
                                            <input
                                                type="hidden"
                                                data-finance-review="currency"
                                                name="asset_reviews[<?php echo (int) ($asset['claim_asset_id'] ?? 0); ?>][currency_code]"
                                                value="<?php echo bk_e($assetCurrency); ?>"
                                            >
                                            <input
                                                class="cv2-input"
                                                data-finance-review="value"
                                                type="text"
                                                name="asset_reviews[<?php echo (int) ($asset['claim_asset_id'] ?? 0); ?>][verified_value]"
                                                inputmode="decimal"
                                                value="<?php echo bk_e((string) ($asset['verified_value'] ?? '')); ?>"
                                                placeholder="Enter confirmed value"
                                            >
                                            <div class="cv2-field-help">Enter the value confirmed from BK records only when Finance has positively located and validated the asset for settlement.</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="cv2-empty">No structured BK asset rows are stored for this claim.</div>
                <?php endif; ?>
                </div>
            </details>

            <?php if ($notes !== '' || $comment !== ''): ?>
                <details class="cv2-fold is-secondary" id="cv2-notes">
                    <summary class="cv2-fold-summary">
                        <div class="cv2-fold-heading">
                            <span class="cv2-fold-icon"><i class="bi bi-journal-text"></i></span>
                            <div class="cv2-fold-copy">
                                <span class="cv2-fold-title">Claim Notes and Review Notes</span>
                                <span class="cv2-fold-text">Reference claimant narrative and the latest review commentary without keeping it in the main decision lane.</span>
                            </div>
                        </div>
                        <div class="cv2-fold-meta">
                            <span class="cv2-fold-pill"><?php echo number_format($notesCount); ?> note<?php echo $notesCount === 1 ? '' : 's'; ?></span>
                            <i class="bi bi-chevron-down cv2-fold-chevron" aria-hidden="true"></i>
                        </div>
                    </summary>
                    <div class="cv2-fold-body">
                    <div class="cv2-stack">
                        <?php if ($notes !== ''): ?>
                            <div class="cv2-note"><?php echo bk_e($notes); ?></div>
                        <?php endif; ?>
                        <?php if ($comment !== ''): ?>
                            <div class="cv2-note"><?php echo bk_e($comment); ?></div>
                        <?php endif; ?>
                    </div>
                    </div>
                </details>
            <?php endif; ?>

            <details class="cv2-fold is-secondary" id="cv2-timeline">
                <summary class="cv2-fold-summary">
                    <div class="cv2-fold-heading">
                        <span class="cv2-fold-icon"><i class="bi bi-clock-history"></i></span>
                        <div class="cv2-fold-copy">
                            <span class="cv2-fold-title">Status Timeline</span>
                            <span class="cv2-fold-text">Open the audit trail when you need to inspect who moved the claim and why.</span>
                        </div>
                    </div>
                    <div class="cv2-fold-meta">
                        <span class="cv2-fold-pill"><?php echo number_format($timelineCount); ?> event<?php echo $timelineCount === 1 ? '' : 's'; ?></span>
                        <i class="bi bi-chevron-down cv2-fold-chevron" aria-hidden="true"></i>
                    </div>
                </summary>
                <div class="cv2-fold-body">
                <?php if (!empty($history)): ?>
                    <div class="cv2-rows">
                        <?php foreach ($history as $entry): ?>
                            <article class="cv2-row">
                                <div class="cv2-row-top">
                                    <div class="cv2-row-title"><?php echo bk_e((string) ($entry['status_label'] ?? 'Activity')); ?></div>
                                    <div class="cv2-row-meta">
                                        <span class="cv2-mini"><?php echo bk_e((string) ($entry['actor_role'] ?? 'system')); ?></span>
                                        <span class="cv2-mini"><?php echo bk_e(!empty($entry['created_at']) ? date('d M Y H:i', strtotime((string) $entry['created_at'])) : ''); ?></span>
                                    </div>
                                </div>
                                <div class="cv2-note"><?php echo bk_e((string) ($entry['message'] ?? '')); ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="cv2-empty">No timeline records are available yet.</div>
                <?php endif; ?>
                </div>
            </details>
            </div>
        </div>
        <?php
    }
}
