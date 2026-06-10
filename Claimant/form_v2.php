<?php
require_once '../security.php';
secure_session_start();
include '../connect.php';

require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/alert.php';
require_once dirname(__DIR__) . '/components/distribution.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claim_documents.php';
require_once dirname(__DIR__) . '/components/claim_email_helper.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'claimant') {
    header('Location: ../claimant-access.php');
    exit();
}

$sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $sessionEmail, 'claimant');
if (!$user_data) {
    header('Location: ../claimant-access.php');
    exit();
}

udcs_claims_v2_ensure_schema($conn);
bk_claims_ensure_workflow_schema($conn);

$claimantId = (int) ($user_data['id'] ?? 0);
$claimant_name = (string) ($user_data['full_name'] ?? 'Claimant');
$photo = !empty($user_data['photo']) ? '../uploads/' . ltrim((string) $user_data['photo'], '/\\') : '../Images/logo.png';
$claimantPhone = trim((string) ($user_data['phone'] ?? ''));
$csrfToken = udcs_csrf_get('claim_v2_submit');
$deathDateMax = date('Y-m-d');

$assetOptions = [
    'current_account' => 'Current / Transaction Account Balances',
    'savings_account' => 'Savings Account Balances',
    'fixed_deposit' => 'Fixed / Term Deposits',
    'shares_securities' => 'Shares / Securities',
    'investment_account' => 'Investment Accounts (Funds / Bonds)',
];
$currencyOptions = bk_supported_currency_options();
$relationshipOptions = udcs_claim_relationship_labels();
$payoutOptions = [
    'bk_account_transfer' => 'BK Account Transfer',
    'other_bank_transfer' => 'Other Bank Transfer',
    'mobile_money' => 'Mobile Money',
    'cheque' => 'Cheque / Banker\'s Instrument',
    'hold_pending_instruction' => 'Hold Pending Final Instruction',
];

define('TESSERACT_V2_PATH', '"C:\\Program Files\\Tesseract-OCR\\tesseract.exe"');

function claimant_form_uploaded_index(string $field, int|string $index): ?array
{
    if (!isset($_FILES[$field])) {
        return null;
    }
    $bag = $_FILES[$field];
    if (!is_array($bag['name'] ?? null) || !array_key_exists($index, $bag['name'])) {
        return null;
    }

    return [
        'name' => (string) ($bag['name'][$index] ?? ''),
        'type' => (string) ($bag['type'][$index] ?? ''),
        'tmp_name' => (string) ($bag['tmp_name'][$index] ?? ''),
        'error' => (int) ($bag['error'][$index] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($bag['size'][$index] ?? 0),
    ];
}

function claimant_form_extract_text(string $imagePath, string $tempDir): string
{
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }

    $outputBase = $tempDir . DIRECTORY_SEPARATOR . uniqid('ocr_v2_', true);
    $profiles = ['eng+fra', 'eng'];
    $text = '';
    foreach ($profiles as $profile) {
        $cmd = TESSERACT_V2_PATH . ' ' . escapeshellarg($imagePath) . ' ' . escapeshellarg($outputBase) . ' -l ' . $profile . ' --psm 6';
        $output = [];
        $returnVar = 0;
        @exec($cmd . ' 2>&1', $output, $returnVar);
        $txtFile = $outputBase . '.txt';
        if (file_exists($txtFile)) {
            $text = (string) file_get_contents($txtFile);
            @unlink($txtFile);
            if (trim($text) !== '') {
                break;
            }
        }
    }

    return strtolower(trim($text));
}

function claimant_form_normalize_text(string $text): string
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\\s]/', ' ', $text);
    $text = preg_replace('/\\s+/', ' ', (string) $text);
    return trim((string) $text);
}

function claimant_form_name_match(string $fullName, string $text, int $minimum = 1): bool
{
    $name = claimant_form_normalize_text($fullName);
    $text = claimant_form_normalize_text($text);
    if ($name === '' || $text === '') {
        return false;
    }
    $tokens = array_values(array_filter(explode(' ', $name), static fn ($part) => strlen($part) >= 3));
    if (empty($tokens)) {
        return false;
    }
    $matches = 0;
    foreach ($tokens as $token) {
        if (strpos($text, $token) !== false) {
            $matches++;
        }
    }
    return $matches >= $minimum;
}

function claimant_form_keyword_match(string $text, array $keywords, int $minimum = 1): bool
{
    $text = claimant_form_normalize_text($text);
    if ($text === '') {
        return false;
    }
    $count = 0;
    foreach ($keywords as $keyword) {
        $needle = claimant_form_normalize_text((string) $keyword);
        if ($needle !== '' && strpos($text, $needle) !== false) {
            $count++;
        }
    }
    return $count >= $minimum;
}

function claimant_form_date_match(string $rawDate, string $text): bool
{
    $rawDate = trim($rawDate);
    if ($rawDate === '') {
        return true;
    }
    $timestamp = strtotime($rawDate);
    if ($timestamp === false) {
        return false;
    }
    $variants = [
        date('Y-m-d', $timestamp),
        date('d/m/Y', $timestamp),
        date('d-m-Y', $timestamp),
        date('d.m.Y', $timestamp),
    ];
    $text = claimant_form_normalize_text($text);
    foreach ($variants as $variant) {
        if (strpos($text, claimant_form_normalize_text($variant)) !== false) {
            return true;
        }
    }
    return false;
}

function claimant_form_id_pattern_match(string $text): bool
{
    return (bool) preg_match('/\\b[0-9]{8,16}[a-z0-9]*\\b/i', $text);
}

function claimant_form_validate_ocr(string $documentType, string $filePath, array $context): array
{
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        return [
            'ok' => false,
            'message' => 'Only JPG and PNG files are supported for OCR intake validation.',
            'ocr_status' => 'failed',
            'text' => '',
        ];
    }

    $text = claimant_form_extract_text($filePath, __DIR__ . DIRECTORY_SEPARATOR . 'temp_ocr');
    if ($text === '') {
        return [
            'ok' => false,
            'message' => 'The uploaded document could not be read clearly by OCR.',
            'ocr_status' => 'failed',
            'text' => '',
        ];
    }

    $deceasedName = (string) ($context['deceased_name'] ?? '');
    $claimantName = (string) ($context['claimant_name'] ?? '');
    $spouseName = (string) ($context['spouse_name'] ?? '');
    $dateOfDeath = (string) ($context['date_of_death'] ?? '');

    $passed = false;
    $message = 'OCR validation passed.';

    switch ($documentType) {
        case 'deceased_death_certificate':
            $passed = claimant_form_keyword_match($text, ['death certificate', 'certificate of death', 'deces', 'urupfu', 'date of death'], 2)
                && claimant_form_name_match($deceasedName, $text)
                && claimant_form_date_match($dateOfDeath, $text);
            $message = $passed
                ? 'Death certificate was read and matched the deceased details.'
                : 'Death certificate failed OCR validation. Check clarity, deceased name, and date of death.';
            break;

        case 'claimant_id':
        case 'spouse_id':
        case 'child_id':
            $expectedName = $documentType === 'spouse_id' ? $spouseName : ($context['person_name'] ?? $claimantName);
            $passed = claimant_form_keyword_match($text, ['national id', 'identity card', 'passport', 'indangamuntu'], 1)
                && claimant_form_name_match((string) $expectedName, $text)
                && claimant_form_id_pattern_match($text);
            $message = $passed
                ? 'Identity document was read and matched expected identity markers.'
                : 'Identity document failed OCR validation. Check clarity, name, and ID pattern.';
            break;

        case 'relationship_proof':
        case 'secondary_relationship_evidence':
        case 'family_resolution':
        case 'local_authority_attestation':
            $passed = claimant_form_keyword_match($text, ['family', 'resolution', 'succession', 'attestation', 'executive secretary', 'relationship'], 1)
                && (claimant_form_name_match($claimantName, $text) || claimant_form_name_match($deceasedName, $text));
            $message = $passed
                ? 'Relationship support document was readable and linked to the claim parties.'
                : 'Relationship support document failed OCR validation. Make sure it is readable and names are visible.';
            break;

        case 'single_status_evidence':
            $personName = (string) ($context['person_name'] ?? $deceasedName);
            $passed = claimant_form_keyword_match($text, ['certificate of being single', 'single', 'unmarried', 'civil status', 'no marriage'], 1)
                && ($personName === '' || claimant_form_name_match($personName, $text));
            $message = $passed
                ? 'Single-status evidence was readable and linked to the declared single path.'
                : 'Single-status evidence failed OCR validation. Check clarity, civil-status wording, and the visible deceased name.';
            break;

        case 'single_status_fallback_evidence':
            $personName = (string) ($context['person_name'] ?? $deceasedName);
            $passed = claimant_form_keyword_match($text, ['single', 'unmarried', 'attestation', 'executive secretary', 'civil status', 'no spouse'], 1)
                && ($personName === '' || claimant_form_name_match($personName, $text));
            $message = $passed
                ? 'Fallback single-status attestation was readable and linked to the declared single path.'
                : 'Fallback single-status attestation failed OCR validation. Check clarity, attestation wording, and the visible deceased name.';
            break;

        case 'marriage_certificate':
            $passed = claimant_form_keyword_match($text, ['marriage certificate', 'acte de mariage', 'marriage'], 1)
                && claimant_form_name_match($claimantName, $text)
                && ($spouseName === '' || claimant_form_name_match($spouseName, $text) || claimant_form_name_match($deceasedName, $text));
            $message = $passed
                ? 'Marriage certificate was read and linked to the spouse path.'
                : 'Marriage certificate failed OCR validation. Check clarity and names.';
            break;

        case 'spouse_death_certificate':
            $passed = claimant_form_keyword_match($text, ['death certificate', 'certificate of death', 'deces', 'urupfu'], 2)
                && ($spouseName === '' || claimant_form_name_match($spouseName, $text));
            $message = $passed
                ? 'Spouse death certificate was read and matched the spouse path.'
                : 'Spouse death certificate failed OCR validation. Check clarity and spouse details.';
            break;

        case 'child_birth_certificate':
            $personName = (string) ($context['person_name'] ?? '');
            $passed = claimant_form_keyword_match($text, ['birth certificate', 'certificate of birth', 'naissance', 'birth'], 1)
                && ($personName === '' || claimant_form_name_match($personName, $text))
                && claimant_form_name_match($deceasedName, $text);
            $message = $passed
                ? 'Child proof document was read and linked to the deceased path.'
                : 'Child proof document failed OCR validation. Check clarity and visible names.';
            break;

        case 'representative_authority':
            $passed = claimant_form_keyword_match($text, ['mandate', 'authorization', 'court order', 'succession', 'attestation'], 1)
                && claimant_form_name_match($claimantName, $text);
            $message = $passed
                ? 'Representative authority document was readable and linked to the claimant.'
                : 'Representative authority document failed OCR validation. Check clarity and authority wording.';
            break;

        case 'will_copy':
            $passed = claimant_form_keyword_match($text, ['will', 'testament', 'last will'], 1)
                && claimant_form_name_match($deceasedName, $text);
            $message = $passed
                ? 'Will copy was readable and linked to the deceased.'
                : 'Will copy failed OCR validation. Check clarity and deceased name.';
            break;

        default:
            $passed = trim($text) !== '';
            $message = $passed
                ? 'Support document was readable enough for intake.'
                : 'Support document could not be read clearly.';
            break;
    }

    return [
        'ok' => $passed,
        'message' => $message,
        'ocr_status' => $passed ? 'passed' : 'failed',
        'text' => $text,
    ];
}

function claimant_form_stage_upload(array $file, string $documentType, string $prefix, ?string &$errorMessage = null): ?array
{
    return udcs_claim_stage_uploaded_file($file, $documentType, $prefix, $errorMessage);
}

function claimant_form_finalize_upload(array $stagedUpload, string $directory, string $prefix, ?string &$errorMessage = null): ?string
{
    return udcs_claim_finalize_staged_upload($stagedUpload, $directory, $prefix, $errorMessage);
}

function claimant_form_create_draft(mysqli $conn, int $claimantId): int
{
    $status = 'Draft';
    $modelVersion = 'v2';
    $legacyFlag = 0;
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO claims (
            claimant_id,
            claimant_user_id,
            claim_status,
            status,
            model_version,
            legacy_read_only,
            submitted_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'iisssi', $claimantId, $claimantId, $status, $status, $modelVersion, $legacyFlag);
    $success = mysqli_stmt_execute($stmt);
    $claimId = $success ? (int) mysqli_insert_id($conn) : 0;
    mysqli_stmt_close($stmt);
    if ($claimId > 0) {
        udcs_claim_history_log($conn, $claimId, 'claimant', 'Draft', 'Draft claim created.');
    }
    return $claimId;
}

function claimant_form_editable_statuses(): array
{
    return ['Draft', 'OCR Validation Failed', 'More Information Required', 'Returned by Finance'];
}

function claimant_form_is_correction_mode(?array $claim): bool
{
    if (!is_array($claim)) {
        return false;
    }

    $status = udcs_claim_effective_status($claim);
    if ($status === 'More Information Required') {
        return true;
    }

    return $status === 'Returned by Finance'
        && strtolower(trim((string) ($claim['finance_return_route'] ?? 'claimant'))) === 'claimant';
}

function claimant_form_claim_is_editable(?array $claim): bool
{
    if (!is_array($claim) || udcs_claim_legacy_flag($claim)) {
        return false;
    }

    return in_array(udcs_claim_effective_status($claim), claimant_form_editable_statuses(), true);
}

function claimant_form_reopened_sections(?array $claim): array
{
    if (!is_array($claim) || !claimant_form_is_correction_mode($claim)) {
        return [];
    }

    $sections = udcs_claim_reopen_scope_decode((string) ($claim['legal_reopen_scope'] ?? ''));
    if (empty($sections) && udcs_claim_effective_status($claim) === 'Returned by Finance') {
        $sections = ['assets_payout', 'supporting_documents'];
    }
    if (empty($sections)) {
        $sections = array_keys(udcs_claim_reopen_section_labels());
    }

    return $sections;
}

function claimant_form_restore_locked_sections(array &$form, array $baseline, array $allowedSections): void
{
    if (!in_array('deceased_entry', $allowedSections, true)) {
        foreach ([
            'deceased_full_name',
            'deceased_id_number',
            'date_of_death',
            'will_exists',
            'marital_status',
            'spouse_status',
            'children_status',
            'claimant_relationship',
            'acting_on_behalf',
        ] as $key) {
            $form[$key] = $baseline[$key] ?? ($form[$key] ?? '');
        }
    }

    if (!in_array('spouse_details', $allowedSections, true)) {
        foreach (['spouse_full_name', 'spouse_id_number'] as $key) {
            $form[$key] = $baseline[$key] ?? ($form[$key] ?? '');
        }
    }

    if (!in_array('children', $allowedSections, true)) {
        $form['children'] = $baseline['children'] ?? [];
    }

    if (!in_array('other_heirs', $allowedSections, true)) {
        $form['other_heirs'] = $baseline['other_heirs'] ?? [];
    }

    if (!in_array('assets_payout', $allowedSections, true)) {
        foreach (['preferred_payout_method', 'distribution_details'] as $key) {
            $form[$key] = $baseline[$key] ?? ($form[$key] ?? '');
        }
        $form['assets'] = $baseline['assets'] ?? [];
    }
}

function claimant_form_take_existing_slot(array &$slots, string $bucket): ?array
{
    $items = $slots[$bucket] ?? [];
    if (!is_array($items) || empty($items)) {
        return null;
    }

    $slot = array_shift($items);
    $slots[$bucket] = array_values($items);
    return is_array($slot) ? $slot : null;
}

function claimant_form_sync_claim_person(
    mysqli $conn,
    int $claimId,
    ?array $existingSlot,
    array $personData,
    array $linkData
): array {
    $existingPersonId = (int) ($existingSlot['person_id'] ?? 0);
    $existingClaimPersonId = (int) ($existingSlot['claim_person_id'] ?? 0);
    $personId = udcs_claim_save_person($conn, $personData, $existingPersonId);
    if ($personId <= 0) {
        return [
            'person_id' => 0,
            'claim_person_id' => 0,
            'orphan_person_ids' => [],
        ];
    }

    $payload = array_merge($linkData, [
        'claim_id' => $claimId,
        'person_id' => $personId,
    ]);

    if ($existingClaimPersonId > 0) {
        $updated = udcs_claim_update_person_link($conn, $existingClaimPersonId, $payload);
        if ($updated) {
            udcs_claim_sync_documents_for_claim_person($conn, $existingClaimPersonId, $personId);
        }
        $claimPersonId = $updated ? $existingClaimPersonId : 0;
    } else {
        $claimPersonId = 0;
    }

    if ($claimPersonId <= 0) {
        $claimPersonId = udcs_claim_link_person($conn, $payload);
    }

    $orphanPersonIds = [];
    if ($existingPersonId > 0 && $existingPersonId !== $personId) {
        $orphanPersonIds[] = $existingPersonId;
    }

    return [
        'person_id' => $personId,
        'claim_person_id' => $claimPersonId,
        'orphan_person_ids' => array_values(array_unique($orphanPersonIds)),
    ];
}

function claimant_form_remove_claim_person_slots(mysqli $conn, int $claimId, array $slots): array
{
    $claimId = (int) $claimId;
    if ($claimId <= 0 || empty($slots)) {
        return [];
    }

    $claimPersonIds = [];
    $personIds = [];
    foreach ($slots as $slot) {
        $claimPersonId = (int) ($slot['claim_person_id'] ?? 0);
        $personId = (int) ($slot['person_id'] ?? 0);
        if ($claimPersonId > 0) {
            $claimPersonIds[] = $claimPersonId;
        }
        if ($personId > 0) {
            $personIds[] = $personId;
        }
    }

    $filePaths = [];
    if (!empty($claimPersonIds)) {
        $documentCleanup = udcs_claim_delete_documents_for_claim_people($conn, $claimId, $claimPersonIds);
        $filePaths = (array) ($documentCleanup['file_paths'] ?? []);
        $personIds = array_merge($personIds, (array) ($documentCleanup['owner_person_ids'] ?? []));
    }

    foreach ($claimPersonIds as $claimPersonId) {
        $stmt = mysqli_prepare($conn, 'DELETE FROM claim_people WHERE claim_person_id = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $claimPersonId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    udcs_claim_prune_people($conn, $personIds);
    return array_values(array_unique(array_filter($filePaths)));
}

function claimant_form_payout_detail_configs(): array
{
    return [
        'bk_account_transfer' => [
            'label' => 'BK account transfer details',
            'hint' => 'Provide the Bank of Kigali account that should receive the approved payout.',
            'fields' => [
                ['key' => 'account_name', 'required' => true, 'placeholder' => 'Name on BK account'],
                ['key' => 'account_number', 'required' => true, 'placeholder' => 'BK account number'],
                ['key' => 'branch_name', 'required' => false, 'placeholder' => 'Preferred BK branch'],
            ],
            'defaults' => ['bank_name' => 'Bank of Kigali'],
        ],
        'other_bank_transfer' => [
            'label' => 'Other bank transfer details',
            'hint' => 'Use a Rwanda bank account that can receive the final approved payout.',
            'fields' => [
                ['key' => 'destination_bank', 'required' => true, 'placeholder' => 'Select destination bank'],
                ['key' => 'destination_account_name', 'required' => true, 'placeholder' => 'Account holder name'],
                ['key' => 'destination_account_number', 'required' => true, 'placeholder' => 'Destination account number'],
                ['key' => 'destination_branch', 'required' => false, 'placeholder' => 'Destination branch'],
            ],
        ],
        'mobile_money' => [
            'label' => 'Mobile money details',
            'hint' => 'Use a mobile wallet registered in the claimant identity. Rwanda networks only.',
            'fields' => [
                ['key' => 'mobile_network', 'required' => true, 'placeholder' => 'Select mobile network'],
                ['key' => 'wallet_registered_name', 'required' => true, 'placeholder' => 'Registered wallet name'],
                ['key' => 'mobile_wallet_number', 'required' => true, 'placeholder' => '07XXXXXXXX', 'type' => 'tel'],
                ['key' => 'wallet_reference_note', 'required' => false, 'placeholder' => 'Optional wallet note', 'type' => 'textarea'],
            ],
        ],
        'cheque' => [
            'label' => 'Cheque collection details',
            'hint' => 'Tell the bank exactly who should appear on the cheque and where it should be collected.',
            'fields' => [
                ['key' => 'payee_name', 'required' => true, 'placeholder' => 'Name to appear on cheque'],
                ['key' => 'collection_branch', 'required' => true, 'placeholder' => 'Select BK branch'],
                ['key' => 'contact_phone', 'required' => false, 'placeholder' => 'Phone for collection updates', 'type' => 'tel'],
            ],
        ],
        'hold_pending_instruction' => [
            'label' => 'Hold pending final instruction',
            'hint' => 'Use this when the claimant is not yet ready to give final destination details. Legal and Finance will still review before settlement.',
            'fields' => [
                ['key' => 'notes', 'required' => false, 'placeholder' => 'Explain what still needs to be confirmed', 'type' => 'textarea'],
            ],
        ],
    ];
}

function claimant_form_validate_payout_details(string $method, array $details, ?string &$errorMessage = null): ?array
{
    $methodKey = strtolower(trim($method));
    $configs = claimant_form_payout_detail_configs();
    if ($methodKey === '' || !isset($configs[$methodKey])) {
        $errorMessage = 'Choose a valid settlement instruction.';
        return null;
    }

    $config = $configs[$methodKey];
    $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
    $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
    $normalized = [];

    foreach ($defaults as $fieldKey => $fieldValue) {
        $cleanKey = strtolower(trim((string) $fieldKey));
        $cleanValue = trim((string) $fieldValue);
        if ($cleanKey !== '' && $cleanValue !== '') {
            $normalized[$cleanKey] = $cleanValue;
        }
    }

    foreach ($fields as $field) {
        $fieldKey = strtolower(trim((string) ($field['key'] ?? '')));
        if ($fieldKey === '') {
            continue;
        }
        $value = trim((string) ($details[$fieldKey] ?? ''));
        $required = !empty($field['required']);
        if (!bk_distribution_validate_field_value($fieldKey, $value, $required, $errorMessage)) {
            return null;
        }
        if ($value !== '') {
            $normalized[$fieldKey] = $value;
        }
    }

    return $normalized;
}

function claimant_form_document_type_counts(array $documents): array
{
    $counts = [];
    foreach ($documents as $document) {
        $type = strtolower(trim((string) ($document['document_type'] ?? '')));
        if ($type === '') {
            continue;
        }
        $counts[$type] = (int) ($counts[$type] ?? 0) + 1;
    }
    return $counts;
}

function claimant_form_document_owner_meta(array $document): array
{
    $type = strtolower(trim((string) ($document['document_type'] ?? '')));
    $role = strtoupper(trim((string) ($document['owner_claim_role'] ?? '')));

    return match ($type) {
        'deceased_death_certificate', 'will_copy', 'single_status_evidence', 'single_status_fallback_evidence' => ['label' => 'Deceased / Estate', 'class' => 'is-deceased'],
        'claimant_id', 'relationship_proof', 'representative_authority' => ['label' => 'Claimant / Representative', 'class' => 'is-claimant'],
        'marriage_certificate', 'spouse_id', 'spouse_death_certificate', 'spouse_secondary_death_evidence' => ['label' => 'Spouse / Family Path', 'class' => 'is-spouse'],
        'child_birth_certificate', 'child_id' => ['label' => 'Child', 'class' => 'is-child'],
        'representative_descendant_linkage', 'represented_heir_id', 'local_authority_attestation', 'family_resolution', 'secondary_relationship_evidence', 'additional_support' => ['label' => 'Family / Co-Heir', 'class' => 'is-family'],
        default => match ($role) {
            'CLAIMANT' => ['label' => 'Claimant / Representative', 'class' => 'is-claimant'],
            'DECEASED' => ['label' => 'Deceased / Estate', 'class' => 'is-deceased'],
            'SPOUSE' => ['label' => 'Spouse / Family Path', 'class' => 'is-spouse'],
            'CHILD' => ['label' => 'Child', 'class' => 'is-child'],
            default => ['label' => 'Family / Co-Heir', 'class' => 'is-family'],
        },
    };
}

function claimant_form_relationship_requires_supporting_certificate(string $relationship): bool
{
    $relationship = strtoupper(trim($relationship));
    return !in_array($relationship, ['CHILD', 'SPOUSE'], true);
}

function claimant_form_single_status_requirement_label(): string
{
    return 'Proof of Single Status or Fallback Single-Status Attestation';
}

function claimant_form_spouse_death_requirement_label(): string
{
    return 'Spouse Death Certificate or Fallback Spouse-Death Evidence';
}

$currentClaimId = isset($_GET['claim_id']) ? (int) $_GET['claim_id'] : 0;
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$claimRow = null;
if ($currentClaimId > 0) {
    $claimRow = udcs_claim_fetch_single($conn, $currentClaimId);
    if (!$claimRow || (int) ($claimRow['claimant_user_ref'] ?? 0) !== $claimantId || !claimant_form_claim_is_editable($claimRow)) {
        $claimRow = null;
        $currentClaimId = 0;
    }
}

if ($currentClaimId <= 0 && $requestMethod !== 'POST') {
    if (isset($_GET['claim_id'])) {
        $_SESSION['error'] = 'Only draft, OCR-failed, or legal follow-up claims can be edited here.';
        header('Location: claims.php');
        exit();
    }
    $draftId = claimant_form_create_draft($conn, $claimantId);
    if ($draftId > 0) {
        header('Location: form_v2.php?claim_id=' . $draftId);
        exit();
    }
}

$existingPeople = $currentClaimId > 0 ? udcs_claim_fetch_people_by_claim($conn, $currentClaimId) : [];
$existingAssets = $currentClaimId > 0 ? udcs_claim_fetch_assets($conn, $currentClaimId) : [];
$existingDocuments = $currentClaimId > 0 ? udcs_claim_fetch_documents($conn, $currentClaimId) : [];
$existingDocumentCounts = claimant_form_document_type_counts($existingDocuments);
$existingDocumentDisplay = array_map(static function (array $row): array {
    $ownerMeta = claimant_form_document_owner_meta($row);
    return [
        'label' => udcs_claim_document_label((string) ($row['document_type'] ?? 'document')),
        'owner_label' => (string) ($ownerMeta['label'] ?? 'Family / Co-Heir'),
        'owner_class' => (string) ($ownerMeta['class'] ?? 'is-family'),
    ];
}, $existingDocuments);
$claimStatus = $claimRow ? udcs_claim_effective_status($claimRow) : 'Draft';
$isCorrectionMode = claimant_form_is_correction_mode($claimRow);
$reopenedSections = claimant_form_reopened_sections($claimRow);
$reopenedSectionSummary = udcs_claim_reopen_scope_summary((string) ($claimRow['legal_reopen_scope'] ?? ''));
$legalReopenNote = trim((string) ($claimRow['legal_reopen_note'] ?? ''));

$defaultAssets = !empty($existingAssets) ? array_map(static function ($row) {
    return [
        'asset_class' => (string) ($row['asset_class'] ?? ''),
        'currency_code' => bk_asset_currency_code((string) ($row['asset_class'] ?? ''), (string) ($row['currency_code'] ?? 'RWF')),
        'account_reference' => (string) ($row['account_reference'] ?? ''),
        'estimated_value' => (string) ($row['estimated_value'] ?? ''),
        'payout_preference_override' => (string) ($row['payout_preference_override'] ?? ''),
    ];
}, $existingAssets) : [['asset_class' => '', 'currency_code' => 'RWF', 'account_reference' => '', 'estimated_value' => '', 'payout_preference_override' => '']];

$form = [
    'deceased_full_name' => (string) ($claimRow['deceased_full_name'] ?? $claimRow['deceased_name'] ?? ''),
    'deceased_id_number' => (string) ($claimRow['deceased_id_number'] ?? $claimRow['deceased_national_id'] ?? ''),
    'date_of_death' => (string) ($claimRow['date_of_death'] ?? $claimRow['deceased_date'] ?? ''),
    'will_exists' => (string) (($claimRow['will_exists'] ?? '') === '' ? 'NO' : ((int) ($claimRow['will_exists'] ?? 0) === 1 ? 'YES' : 'NO')),
    'marital_status' => (string) ($claimRow['marital_status'] ?? ''),
    'spouse_status' => (string) ($claimRow['spouse_status'] ?? ''),
    'children_status' => (string) ($claimRow['children_status'] ?? ''),
    'claimant_relationship' => strtoupper(trim((string) ($claimRow['relationship'] ?? ''))),
    'acting_on_behalf' => (string) (((int) ($claimRow['acting_on_behalf'] ?? 0) === 1) ? 'YES' : 'NO'),
    'preferred_payout_method' => (string) (($claimRow['preferred_payout_method'] ?? '') !== '' ? $claimRow['preferred_payout_method'] : (string) ($claimRow['distribution_method'] ?? '')),
    'distribution_details' => bk_claim_distribution_details_array((string) ($claimRow['distribution_details'] ?? '')),
    'spouse_full_name' => '',
    'spouse_id_number' => '',
    'children' => [],
    'other_heirs' => [],
    'assets' => $defaultAssets,
];

foreach ($existingPeople as $personRow) {
    $role = strtoupper(trim((string) ($personRow['role'] ?? '')));
    if ($role === 'SPOUSE' && (int) ($personRow['is_claimant'] ?? 0) !== 1) {
        $form['spouse_full_name'] = (string) ($personRow['full_name'] ?? '');
        $form['spouse_id_number'] = (string) ($personRow['id_number'] ?? '');
    } elseif ($role === 'CHILD') {
        $form['children'][] = [
            'full_name' => (string) ($personRow['full_name'] ?? ''),
            'date_of_birth' => (string) ($personRow['date_of_birth'] ?? ''),
            'alive_status' => (string) ($personRow['alive_status'] ?? 'YES'),
            'id_number' => (string) ($personRow['id_number'] ?? ''),
            'has_descendants' => '',
        ];
    } elseif (!in_array($role, ['DECEASED', 'CLAIMANT', 'SPOUSE'], true)) {
        $form['other_heirs'][] = [
            'full_name' => (string) ($personRow['full_name'] ?? ''),
            'relationship_type' => (string) ($personRow['relationship_type'] ?? ''),
            'alive_status' => (string) ($personRow['alive_status'] ?? 'YES'),
            'id_number' => (string) ($personRow['id_number'] ?? ''),
            'contact_phone' => (string) ($personRow['contact_phone'] ?? ''),
            'contact_email' => (string) ($personRow['contact_email'] ?? ''),
            'represented_child_index' => '',
        ];
    }
}

if (empty($form['children'])) {
    $form['children'][] = ['full_name' => '', 'date_of_birth' => '', 'alive_status' => 'YES', 'id_number' => '', 'has_descendants' => 'NO'];
}
if (empty($form['other_heirs'])) {
    $form['other_heirs'][] = ['full_name' => '', 'relationship_type' => '', 'alive_status' => 'YES', 'id_number' => '', 'contact_phone' => '', 'contact_email' => '', 'represented_child_index' => ''];
}

$baselineForm = $form;

$errors = [];
$successMessage = '';

if ($requestMethod === 'POST') {
    if (!udcs_csrf_validate((string) ($_POST['csrf_token'] ?? ''), 'claim_v2_submit')) {
        $errors[] = 'Your session security token is invalid. Refresh and try again.';
    } else {
        $currentClaimId = (int) ($_POST['claim_id'] ?? 0);
        $claimRow = $currentClaimId > 0 ? udcs_claim_fetch_single($conn, $currentClaimId) : null;
        if (!$claimRow || (int) ($claimRow['claimant_user_ref'] ?? 0) !== $claimantId || !claimant_form_claim_is_editable($claimRow)) {
            $errors[] = 'This draft claim is not available for editing.';
        }
    }

    $claimStatus = $claimRow ? udcs_claim_effective_status($claimRow) : 'Draft';
    $isCorrectionMode = claimant_form_is_correction_mode($claimRow);
    $reopenedSections = claimant_form_reopened_sections($claimRow);
    $reopenedSectionSummary = udcs_claim_reopen_scope_summary((string) ($claimRow['legal_reopen_scope'] ?? ''));
    $legalReopenNote = trim((string) ($claimRow['legal_reopen_note'] ?? ''));

    $form['deceased_full_name'] = trim((string) ($_POST['deceased_full_name'] ?? ''));
    $form['deceased_id_number'] = trim((string) ($_POST['deceased_id_number'] ?? ''));
    $form['date_of_death'] = trim((string) ($_POST['date_of_death'] ?? ''));
    $form['will_exists'] = strtoupper(trim((string) ($_POST['will_exists'] ?? 'NO')));
    $form['marital_status'] = strtoupper(trim((string) ($_POST['marital_status'] ?? '')));
    $form['spouse_status'] = strtoupper(trim((string) ($_POST['spouse_status'] ?? 'NOT_APPLICABLE')));
    $form['children_status'] = strtoupper(trim((string) ($_POST['children_status'] ?? 'NONE')));
    $form['claimant_relationship'] = strtoupper(trim((string) ($_POST['claimant_relationship'] ?? '')));
    $form['acting_on_behalf'] = strtoupper(trim((string) ($_POST['acting_on_behalf'] ?? 'NO')));
    $form['preferred_payout_method'] = strtolower(trim((string) ($_POST['preferred_payout_method'] ?? '')));
    $distributionDetailsRaw = trim((string) ($_POST['distribution_details'] ?? ''));
    $distributionParseError = null;
    $parsedDistributionDetails = bk_distribution_parse_details_payload($distributionDetailsRaw, $distributionParseError);
    $form['distribution_details'] = is_array($parsedDistributionDetails) ? $parsedDistributionDetails : [];
    $form['spouse_full_name'] = trim((string) ($_POST['spouse_full_name'] ?? ''));
    $form['spouse_id_number'] = trim((string) ($_POST['spouse_id_number'] ?? ''));
    $form['children'] = array_values(array_filter((array) ($_POST['children'] ?? []), static function ($row) {
        return trim((string) ($row['full_name'] ?? '')) !== '' || trim((string) ($row['date_of_birth'] ?? '')) !== '' || trim((string) ($row['id_number'] ?? '')) !== '';
    }));
    $form['other_heirs'] = array_values(array_filter((array) ($_POST['other_heirs'] ?? []), static function ($row) {
        return trim((string) ($row['full_name'] ?? '')) !== '' || trim((string) ($row['relationship_type'] ?? '')) !== '';
    }));
    $form['assets'] = array_values(array_filter((array) ($_POST['assets'] ?? []), static function ($row) {
        return trim((string) ($row['asset_class'] ?? '')) !== '';
    }));
    foreach ($form['assets'] as $index => $assetRow) {
        $assetClass = strtolower(trim((string) ($assetRow['asset_class'] ?? '')));
        $currencyCode = bk_currency_code((string) ($assetRow['currency_code'] ?? 'RWF'));
        if (!bk_asset_currency_supported($assetClass, $currencyCode)) {
            $allowed = implode(', ', bk_asset_supported_currency_codes($assetClass));
            $errors[] = 'Currency ' . $currencyCode . ' is not compatible with ' . udcs_claim_asset_label($assetClass) . '. Allowed: ' . $allowed . '.';
            $currencyCode = bk_asset_currency_code($assetClass, $currencyCode);
        }
        $estimateRaw = str_replace([',', ' '], '', trim((string) ($assetRow['estimated_value'] ?? '')));
        if ($estimateRaw !== '') {
            $decimals = bk_currency_decimals($currencyCode);
            $amountPattern = $decimals === 0
                ? '/^\d+$/'
                : '/^\d+(?:\.\d{1,' . $decimals . '})?$/';
            if (!preg_match($amountPattern, $estimateRaw)) {
                $errors[] = udcs_claim_asset_label($assetClass) . ' estimate must match the selected currency. ' . $currencyCode . ' allows ' . ($decimals === 0 ? 'whole numbers only' : 'up to ' . $decimals . ' decimal places') . '.';
            }
        }
        $form['assets'][$index]['asset_class'] = $assetClass;
        $form['assets'][$index]['currency_code'] = $currencyCode;
    }

    if ($isCorrectionMode) {
        claimant_form_restore_locked_sections($form, $baselineForm, $reopenedSections);
    }

    if ($form['marital_status'] === 'WIDOWED') {
        $form['spouse_status'] = 'DECEASED';
    }
    if ($form['marital_status'] === 'SINGLE') {
        $form['spouse_status'] = 'NOT_APPLICABLE';
    }
    if ($form['marital_status'] === 'SINGLE' && $form['claimant_relationship'] === 'SPOUSE') {
        $errors[] = 'Single claims cannot use spouse as claimant relationship.';
    }
    if ($form['marital_status'] === 'WIDOWED' && $form['claimant_relationship'] === 'SPOUSE') {
        $errors[] = 'Widowed claims cannot use spouse as claimant relationship.';
    }

    if ($form['deceased_full_name'] === '') {
        $errors[] = 'Deceased full name is required.';
    }
    if ($form['deceased_id_number'] === '') {
        $errors[] = 'Deceased ID number is required.';
    }
    if ($form['date_of_death'] === '') {
        $errors[] = 'Date of death is required.';
    } else {
        $deathDateParsed = DateTimeImmutable::createFromFormat('!Y-m-d', $form['date_of_death']);
        if (!$deathDateParsed || $deathDateParsed->format('Y-m-d') !== $form['date_of_death']) {
            $errors[] = 'Date of death must be a valid calendar date.';
        } elseif ($form['date_of_death'] > $deathDateMax) {
            $errors[] = 'Date of death cannot be in the future.';
        }
    }
    if ($form['marital_status'] === '') {
        $errors[] = 'Marital status at death is required.';
    }
    if ($form['children_status'] === '') {
        $errors[] = 'Children status is required.';
    }
    if ($form['claimant_relationship'] === '') {
        $errors[] = 'Claimant relationship is required.';
    }
    if ($form['preferred_payout_method'] === '' || !isset($payoutOptions[$form['preferred_payout_method']])) {
        $errors[] = 'Default payout preference is required.';
    }
    if ($parsedDistributionDetails === null) {
        $errors[] = $distributionParseError ?: 'Please complete the required disbursement details.';
    }
    if (empty($form['assets'])) {
        $errors[] = 'At least one BK-held asset class must be selected.';
    }
    if ($form['marital_status'] === 'MARRIED' && !in_array($form['spouse_status'], ['ALIVE', 'DECEASED'], true)) {
        $errors[] = 'Married claims must declare spouse status as alive or deceased.';
    }
    if ($form['spouse_status'] === 'ALIVE' && $form['claimant_relationship'] !== 'SPOUSE' && $form['spouse_full_name'] === '') {
        $errors[] = 'Spouse full name is required when the spouse is alive and is not the claimant.';
    }
    if ($form['spouse_status'] === 'DECEASED' && $form['spouse_full_name'] === '') {
        $errors[] = 'Spouse full name is required when spouse status is declared as deceased.';
    }
    if ($form['spouse_status'] === 'DECEASED' && $form['claimant_relationship'] === 'SPOUSE') {
        $errors[] = 'A surviving spouse cannot be selected as claimant when spouse status is declared as deceased.';
    }
    if ($form['children_status'] === 'HAS_CHILDREN' && empty($form['children'])) {
        $errors[] = 'Add at least one child entry or change children status.';
    }
    if ($form['claimant_relationship'] === 'CHILD' && $form['children_status'] !== 'HAS_CHILDREN') {
        $errors[] = 'A child claimant path must declare children and upload child proof instead of relying on a general relationship certificate.';
    }
    if ($form['claimant_relationship'] === 'SPOUSE' && $form['spouse_status'] !== 'ALIVE') {
        $errors[] = 'A spouse claimant path must identify the claimant as the surviving spouse.';
    }
    if ($form['acting_on_behalf'] === 'YES' && count($form['children']) + count($form['other_heirs']) === 0 && $form['claimant_relationship'] !== 'SPOUSE') {
        $errors[] = 'If acting on behalf of multiple heirs, disclose the represented heirs.';
    }

    foreach ($form['children'] as $index => $childRow) {
        $aliveStatus = strtoupper(trim((string) ($childRow['alive_status'] ?? 'YES')));
        $hasDescendants = strtoupper(trim((string) ($childRow['has_descendants'] ?? 'NO')));
        if ($aliveStatus === 'NO' && !in_array($hasDescendants, ['YES', 'NO'], true)) {
            $errors[] = 'For each deceased child, specify whether descendants exist.';
        }
    }

    foreach ($form['other_heirs'] as $index => $heirRow) {
        $relationshipType = strtoupper(trim((string) ($heirRow['relationship_type'] ?? '')));
        $representedChildIndex = trim((string) ($heirRow['represented_child_index'] ?? ''));
        if ($relationshipType === 'REPRESENTATIVE_DESCENDANT' && $representedChildIndex === '') {
            $errors[] = 'Each representative descendant must be linked to a deceased child who left descendants.';
            continue;
        }
        if ($relationshipType === 'REPRESENTATIVE_DESCENDANT' && $representedChildIndex !== '') {
            $childRow = $form['children'][(int) $representedChildIndex] ?? null;
            $childAlive = strtoupper(trim((string) ($childRow['alive_status'] ?? '')));
            if (!$childRow || $childAlive !== 'NO') {
                $errors[] = 'Representative descendants can only be linked to a child who is marked as deceased.';
            }
        }
    }

    if (empty($errors)) {
        $distributionValidationError = null;
        $normalizedDistributionDetails = claimant_form_validate_payout_details(
            $form['preferred_payout_method'],
            is_array($form['distribution_details']) ? $form['distribution_details'] : [],
            $distributionValidationError
        );
        if ($normalizedDistributionDetails === null) {
            $errors[] = $distributionValidationError ?: 'Please complete the required disbursement details.';
        } else {
            $form['distribution_details'] = $normalizedDistributionDetails;
        }
    }

    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        try {
            $filesToDeleteAfterCommit = [];
            $rollbackUploadPaths = [];
            $orphanPersonIds = [];
            $pendingDocumentRows = [];
            $existingPeopleState = udcs_claim_fetch_people_by_claim($conn, $currentClaimId);
            $existingGroupedPeople = udcs_claim_people_grouped($existingPeopleState);
            $existingSlots = [
                'claimant' => !empty($existingGroupedPeople['claimant']) ? [$existingGroupedPeople['claimant']] : [],
                'deceased' => !empty($existingGroupedPeople['deceased']) ? [$existingGroupedPeople['deceased']] : [],
                'spouse' => !empty($existingGroupedPeople['spouse']) ? [$existingGroupedPeople['spouse']] : [],
                'children' => array_values($existingGroupedPeople['children'] ?? []),
                'other_heirs' => array_values($existingGroupedPeople['other_heirs'] ?? []),
            ];

            if (!$isCorrectionMode) {
                $existingDocContext = udcs_claim_collect_cleanup_context($conn, $currentClaimId);
                $filesToDeleteAfterCommit = array_merge($filesToDeleteAfterCommit, (array) ($existingDocContext['file_paths'] ?? []));
                $deleteDocumentsStmt = mysqli_prepare($conn, 'DELETE FROM documents WHERE claim_id = ?');
                if (!$deleteDocumentsStmt) {
                    throw new RuntimeException('Document reset failed.');
                }
                mysqli_stmt_bind_param($deleteDocumentsStmt, 'i', $currentClaimId);
                if (!mysqli_stmt_execute($deleteDocumentsStmt)) {
                    mysqli_stmt_close($deleteDocumentsStmt);
                    throw new RuntimeException('Document reset execution failed.');
                }
                mysqli_stmt_close($deleteDocumentsStmt);
            }

            $deleteAssetsStmt = mysqli_prepare($conn, 'DELETE FROM claim_assets WHERE claim_id = ?');
            if (!$deleteAssetsStmt) {
                throw new RuntimeException('Asset reset failed.');
            }
            mysqli_stmt_bind_param($deleteAssetsStmt, 'i', $currentClaimId);
            if (!mysqli_stmt_execute($deleteAssetsStmt)) {
                mysqli_stmt_close($deleteAssetsStmt);
                throw new RuntimeException('Asset reset execution failed.');
            }
            mysqli_stmt_close($deleteAssetsStmt);

            $claimantSync = claimant_form_sync_claim_person(
                $conn,
                $currentClaimId,
                claimant_form_take_existing_slot($existingSlots, 'claimant'),
                [
                    'full_name' => $claimant_name,
                    'contact_phone' => $claimantPhone,
                    'contact_email' => $sessionEmail,
                    'alive_status' => 'YES',
                ],
                [
                    'role' => 'CLAIMANT',
                    'relationship_type' => $form['claimant_relationship'],
                    'is_claimant' => 1,
                    'is_co_heir' => 1,
                ]
            );
            $claimantPersonId = (int) ($claimantSync['person_id'] ?? 0);
            $claimantClaimPersonId = (int) ($claimantSync['claim_person_id'] ?? 0);
            $orphanPersonIds = array_merge($orphanPersonIds, (array) ($claimantSync['orphan_person_ids'] ?? []));
            if ($claimantPersonId <= 0 || $claimantClaimPersonId <= 0) {
                throw new RuntimeException('Claimant link sync failed.');
            }

            $deceasedSync = claimant_form_sync_claim_person(
                $conn,
                $currentClaimId,
                claimant_form_take_existing_slot($existingSlots, 'deceased'),
                [
                    'full_name' => $form['deceased_full_name'],
                    'id_number' => $form['deceased_id_number'],
                    'alive_status' => 'NO',
                ],
                [
                    'role' => 'DECEASED',
                    'relationship_type' => '',
                    'is_claimant' => 0,
                    'is_co_heir' => 0,
                ]
            );
            $deceasedPersonId = (int) ($deceasedSync['person_id'] ?? 0);
            $deceasedClaimPersonId = (int) ($deceasedSync['claim_person_id'] ?? 0);
            $orphanPersonIds = array_merge($orphanPersonIds, (array) ($deceasedSync['orphan_person_ids'] ?? []));
            if ($deceasedPersonId <= 0 || $deceasedClaimPersonId <= 0) {
                throw new RuntimeException('Deceased link sync failed.');
            }

            $spousePersonId = 0;
            $spouseClaimPersonId = 0;
            $spouseExistingSlot = claimant_form_take_existing_slot($existingSlots, 'spouse');
            if (in_array($form['spouse_status'], ['ALIVE', 'DECEASED'], true)) {
                if ($form['claimant_relationship'] === 'SPOUSE' && $form['spouse_status'] === 'ALIVE') {
                    $spousePersonId = $claimantPersonId;
                    $spouseLinkPayload = [
                        'claim_id' => $currentClaimId,
                        'person_id' => $claimantPersonId,
                        'role' => 'SPOUSE',
                        'relationship_type' => 'SPOUSE',
                        'is_claimant' => 1,
                        'is_co_heir' => 1,
                    ];
                    $existingSpouseClaimPersonId = (int) ($spouseExistingSlot['claim_person_id'] ?? 0);
                    $existingSpousePersonId = (int) ($spouseExistingSlot['person_id'] ?? 0);
                    if ($existingSpouseClaimPersonId > 0) {
                        if (!udcs_claim_update_person_link($conn, $existingSpouseClaimPersonId, $spouseLinkPayload)) {
                            throw new RuntimeException('Spouse link update failed.');
                        }
                        udcs_claim_sync_documents_for_claim_person($conn, $existingSpouseClaimPersonId, $claimantPersonId);
                        $spouseClaimPersonId = $existingSpouseClaimPersonId;
                        if ($existingSpousePersonId > 0 && $existingSpousePersonId !== $claimantPersonId) {
                            $orphanPersonIds[] = $existingSpousePersonId;
                        }
                    } else {
                        $spouseClaimPersonId = udcs_claim_link_person($conn, $spouseLinkPayload);
                    }
                } else {
                    $spouseSync = claimant_form_sync_claim_person(
                        $conn,
                        $currentClaimId,
                        $spouseExistingSlot,
                        [
                            'full_name' => $form['spouse_full_name'] !== '' ? $form['spouse_full_name'] : 'Declared spouse',
                            'id_number' => $form['spouse_id_number'],
                            'alive_status' => $form['spouse_status'] === 'DECEASED' ? 'NO' : 'YES',
                        ],
                        [
                            'role' => 'SPOUSE',
                            'relationship_type' => 'SPOUSE',
                            'is_claimant' => 0,
                            'is_co_heir' => 1,
                        ]
                    );
                    $spousePersonId = (int) ($spouseSync['person_id'] ?? 0);
                    $spouseClaimPersonId = (int) ($spouseSync['claim_person_id'] ?? 0);
                    $orphanPersonIds = array_merge($orphanPersonIds, (array) ($spouseSync['orphan_person_ids'] ?? []));
                }
            }

            $childClaimPeople = [];
            foreach ($form['children'] as $index => $childRow) {
                $childSync = claimant_form_sync_claim_person(
                    $conn,
                    $currentClaimId,
                    claimant_form_take_existing_slot($existingSlots, 'children'),
                    [
                        'full_name' => trim((string) ($childRow['full_name'] ?? '')),
                        'date_of_birth' => trim((string) ($childRow['date_of_birth'] ?? '')),
                        'id_number' => trim((string) ($childRow['id_number'] ?? '')),
                        'alive_status' => strtoupper(trim((string) ($childRow['alive_status'] ?? 'YES'))),
                    ],
                    [
                        'role' => 'CHILD',
                        'relationship_type' => 'CHILD',
                        'is_claimant' => 0,
                        'is_co_heir' => 1,
                    ]
                );
                $personId = (int) ($childSync['person_id'] ?? 0);
                $claimPersonId = (int) ($childSync['claim_person_id'] ?? 0);
                $orphanPersonIds = array_merge($orphanPersonIds, (array) ($childSync['orphan_person_ids'] ?? []));
                if ($personId > 0 && $claimPersonId > 0) {
                    $childClaimPeople[$index] = [
                        'person_id' => $personId,
                        'claim_person_id' => $claimPersonId,
                    ];
                }
            }

            $otherHeirClaimPeople = [];
            foreach ($form['other_heirs'] as $index => $heirRow) {
                $relationshipType = strtoupper(trim((string) ($heirRow['relationship_type'] ?? '')));
                $representedByPersonId = null;
                if ($relationshipType === 'REPRESENTATIVE_DESCENDANT') {
                    $representedChildIndex = trim((string) ($heirRow['represented_child_index'] ?? ''));
                    if ($representedChildIndex !== '' && isset($childClaimPeople[(int) $representedChildIndex]['person_id'])) {
                        $representedByPersonId = (int) $childClaimPeople[(int) $representedChildIndex]['person_id'];
                    }
                }
                $otherHeirSync = claimant_form_sync_claim_person(
                    $conn,
                    $currentClaimId,
                    claimant_form_take_existing_slot($existingSlots, 'other_heirs'),
                    [
                        'full_name' => trim((string) ($heirRow['full_name'] ?? '')),
                        'id_number' => trim((string) ($heirRow['id_number'] ?? '')),
                        'contact_phone' => trim((string) ($heirRow['contact_phone'] ?? '')),
                        'contact_email' => trim((string) ($heirRow['contact_email'] ?? '')),
                        'alive_status' => strtoupper(trim((string) ($heirRow['alive_status'] ?? 'YES'))),
                    ],
                    [
                        'role' => $relationshipType !== '' ? $relationshipType : 'OTHER_REPRESENTATIVE',
                        'relationship_type' => $relationshipType,
                        'is_claimant' => 0,
                        'is_co_heir' => 1,
                        'represented_by_person_id' => $representedByPersonId,
                    ]
                );
                $personId = (int) ($otherHeirSync['person_id'] ?? 0);
                $claimPersonId = (int) ($otherHeirSync['claim_person_id'] ?? 0);
                $orphanPersonIds = array_merge($orphanPersonIds, (array) ($otherHeirSync['orphan_person_ids'] ?? []));
                if ($personId > 0 && $claimPersonId > 0) {
                    $otherHeirClaimPeople[$index] = [
                        'person_id' => $personId,
                        'claim_person_id' => $claimPersonId,
                    ];
                }
            }

            $filesToDeleteAfterCommit = array_merge(
                $filesToDeleteAfterCommit,
                claimant_form_remove_claim_person_slots(
                    $conn,
                    $currentClaimId,
                    array_merge(
                        $existingSlots['spouse'] ?? [],
                        $existingSlots['children'] ?? [],
                        $existingSlots['other_heirs'] ?? []
                    )
                )
            );
            udcs_claim_prune_people($conn, $orphanPersonIds);

            $assetClasses = [];
            $estimatedTotalsByCurrency = [];
            foreach ($form['assets'] as $assetRow) {
                $assetClass = (string) ($assetRow['asset_class'] ?? '');
                $currencyCode = bk_asset_currency_code($assetClass, (string) ($assetRow['currency_code'] ?? 'RWF'));
                $estimatedValue = bk_claim_amount_numeric($assetRow['estimated_value'] ?? null);
                if ($estimatedValue !== null) {
                    $estimatedTotalsByCurrency[$currencyCode] = (float) ($estimatedTotalsByCurrency[$currencyCode] ?? 0) + $estimatedValue;
                }
                $assetClasses[] = $assetClass;
                udcs_claim_insert_asset($conn, [
                    'claim_id' => $currentClaimId,
                    'asset_class' => $assetClass,
                    'currency_code' => $currencyCode,
                    'account_reference' => (string) ($assetRow['account_reference'] ?? ''),
                    'estimated_value' => $estimatedValue,
                    'payout_preference_override' => (string) ($assetRow['payout_preference_override'] ?? ''),
                ]);
            }

            $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $currentClaimId;
            udcs_claim_documents_protect_directory($uploadDir);

            $ocrContext = [
                'deceased_name' => $form['deceased_full_name'],
                'claimant_name' => $claimant_name,
                'spouse_name' => $form['spouse_full_name'],
                'date_of_death' => $form['date_of_death'],
            ];
            $documentsSectionOpen = !$isCorrectionMode || in_array('supporting_documents', $reopenedSections, true);

            $hardMissing = [];
            $manualFlags = [
                'will_exists' => $form['will_exists'] === 'YES',
                'children_status' => $form['children_status'],
                'marital_status' => $form['marital_status'],
                'single_status_fallback_evidence' => false,
                'missing_coheir_docs' => false,
                'possible_missing_heirs' => false,
                'spouse_priority_review' => false,
                'contradictory_spouse_status' => false,
                'widowed_fallback_evidence' => false,
                'representative_descendant_disclosure' => false,
            ];
            foreach ($form['other_heirs'] as $heirRow) {
                if (strtoupper(trim((string) ($heirRow['relationship_type'] ?? ''))) === 'REPRESENTATIVE_DESCENDANT') {
                    $manualFlags['representative_descendant_disclosure'] = true;
                    break;
                }
            }

            $relationshipProofRequired = claimant_form_relationship_requires_supporting_certificate($form['claimant_relationship']);
            $hardDocs = [
                ['field' => 'deceased_death_certificate', 'type' => 'deceased_death_certificate', 'owner' => $deceasedPersonId, 'claim_person' => $deceasedClaimPersonId],
                ['field' => 'claimant_id_document', 'type' => 'claimant_id', 'owner' => $claimantPersonId, 'claim_person' => $claimantClaimPersonId],
            ];
            if ($relationshipProofRequired) {
                $hardDocs[] = ['field' => 'relationship_proof_document', 'type' => 'relationship_proof', 'owner' => $claimantPersonId, 'claim_person' => $claimantClaimPersonId];
            }

            if ($form['marital_status'] === 'SINGLE') {
                $hardDocs[] = ['field' => 'single_status_evidence', 'type' => 'single_status_evidence', 'owner' => $deceasedPersonId, 'claim_person' => $deceasedClaimPersonId];
            }

            if (in_array($form['spouse_status'], ['ALIVE', 'DECEASED'], true) || $form['claimant_relationship'] === 'SPOUSE') {
                $hardDocs[] = ['field' => 'marriage_certificate', 'type' => 'marriage_certificate', 'owner' => $claimantPersonId, 'claim_person' => $claimantClaimPersonId];
            }
            if ($form['spouse_status'] === 'ALIVE' && $form['claimant_relationship'] !== 'SPOUSE') {
                $hardDocs[] = ['field' => 'spouse_id_document', 'type' => 'spouse_id', 'owner' => $spousePersonId, 'claim_person' => $spouseClaimPersonId];
            }
            if ($form['will_exists'] === 'YES') {
                $hardDocs[] = ['field' => 'will_copy_document', 'type' => 'will_copy', 'owner' => $deceasedPersonId, 'claim_person' => $deceasedClaimPersonId];
            }
            if ($form['acting_on_behalf'] === 'YES') {
                $hardDocs[] = ['field' => 'representative_authority_document', 'type' => 'representative_authority', 'owner' => $claimantPersonId, 'claim_person' => $claimantClaimPersonId];
            }

            foreach ($hardDocs as $docMeta) {
                $hasUpload = isset($_FILES[$docMeta['field']]) && (int) ($_FILES[$docMeta['field']]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                $singleStatusFallbackProvided = $docMeta['type'] === 'single_status_evidence'
                    && isset($_FILES['single_status_fallback_evidence'])
                    && (int) ($_FILES['single_status_fallback_evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                $singleStatusExistingFallback = $docMeta['type'] === 'single_status_evidence'
                    && ($existingDocumentCounts['single_status_fallback_evidence'] ?? 0) > 0;
                if (!$documentsSectionOpen) {
                    if (($existingDocumentCounts[$docMeta['type']] ?? 0) <= 0 && !$singleStatusExistingFallback) {
                        $hardMissing[] = $docMeta['type'] === 'single_status_evidence'
                            ? claimant_form_single_status_requirement_label()
                            : udcs_claim_document_label($docMeta['type']);
                    } elseif ($docMeta['type'] === 'single_status_evidence' && $singleStatusExistingFallback && ($existingDocumentCounts[$docMeta['type']] ?? 0) <= 0) {
                        $manualFlags['single_status_fallback_evidence'] = true;
                    }
                    continue;
                }
                if (!$hasUpload && !$singleStatusFallbackProvided) {
                    if ($isCorrectionMode && ($existingDocumentCounts[$docMeta['type']] ?? 0) > 0) {
                        continue;
                    }
                    if ($singleStatusExistingFallback) {
                        $manualFlags['single_status_fallback_evidence'] = true;
                        continue;
                    }
                    $hardMissing[] = $docMeta['type'] === 'single_status_evidence'
                        ? claimant_form_single_status_requirement_label()
                        : udcs_claim_document_label($docMeta['type']);
                    continue;
                }
                if (!$hasUpload && $singleStatusFallbackProvided) {
                    continue;
                }
                $uploadError = null;
                $stagedUpload = claimant_form_stage_upload($_FILES[$docMeta['field']], $docMeta['type'], $docMeta['type'], $uploadError);
                if ($stagedUpload === null) {
                    $errors[] = $uploadError ?: (udcs_claim_document_label($docMeta['type']) . ' could not be uploaded.');
                    continue;
                }
                $rollbackUploadPaths[] = (string) ($stagedUpload['stage_path'] ?? '');
                $ocr = claimant_form_validate_ocr($docMeta['type'], (string) ($stagedUpload['stage_path'] ?? ''), $ocrContext);
                if (!$ocr['ok']) {
                    $errors[] = $ocr['message'];
                }
                $pendingDocumentRows[] = [
                    'staged_upload' => $stagedUpload,
                    'prefix' => $docMeta['type'],
                    'row' => [
                        'claim_id' => $currentClaimId,
                        'owner_person_id' => (int) $docMeta['owner'],
                        'related_claim_person_id' => (int) $docMeta['claim_person'],
                        'document_type' => $docMeta['type'],
                        'ocr_status' => (string) $ocr['ocr_status'],
                    ],
                ];
            }

            if (!$relationshipProofRequired && $documentsSectionOpen) {
                $relationshipUpload = $_FILES['relationship_proof_document'] ?? null;
                $hasRelationshipUpload = is_array($relationshipUpload) && (int) ($relationshipUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                if ($hasRelationshipUpload) {
                    $uploadError = null;
                    $stagedUpload = claimant_form_stage_upload($relationshipUpload, 'relationship_proof', 'relationship_proof', $uploadError);
                    if ($stagedUpload === null) {
                        $errors[] = $uploadError ?: 'Supporting relationship certificate could not be uploaded.';
                    } else {
                        $rollbackUploadPaths[] = (string) ($stagedUpload['stage_path'] ?? '');
                        $ocr = claimant_form_validate_ocr('relationship_proof', (string) ($stagedUpload['stage_path'] ?? ''), $ocrContext);
                        $pendingDocumentRows[] = [
                            'staged_upload' => $stagedUpload,
                            'prefix' => 'relationship_proof',
                            'row' => [
                                'claim_id' => $currentClaimId,
                                'owner_person_id' => (int) $claimantPersonId,
                                'related_claim_person_id' => (int) $claimantClaimPersonId,
                                'document_type' => 'relationship_proof',
                                'ocr_status' => (string) $ocr['ocr_status'],
                            ],
                        ];
                    }
                }
            }

            if ($form['marital_status'] === 'SINGLE') {
                $singleFallbackUpload = $_FILES['single_status_fallback_evidence'] ?? null;
                $hasSingleFallback = is_array($singleFallbackUpload) && (int) ($singleFallbackUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                $hasSingleFormalUpload = isset($_FILES['single_status_evidence']) && (int) ($_FILES['single_status_evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                $hasExistingSingleFormal = ($existingDocumentCounts['single_status_evidence'] ?? 0) > 0;

                if (!$documentsSectionOpen) {
                    if (($existingDocumentCounts['single_status_fallback_evidence'] ?? 0) > 0 && ($existingDocumentCounts['single_status_evidence'] ?? 0) <= 0) {
                        $manualFlags['single_status_fallback_evidence'] = true;
                    }
                } elseif ($hasSingleFallback) {
                    $uploadError = null;
                    $stagedUpload = claimant_form_stage_upload($singleFallbackUpload, 'single_status_fallback_evidence', 'single_status_fallback_evidence', $uploadError);
                    if ($stagedUpload === null) {
                        $errors[] = $uploadError ?: 'Fallback single-status attestation could not be uploaded.';
                    } else {
                        $rollbackUploadPaths[] = (string) ($stagedUpload['stage_path'] ?? '');
                        $ocr = claimant_form_validate_ocr('single_status_fallback_evidence', (string) ($stagedUpload['stage_path'] ?? ''), $ocrContext + [
                            'person_name' => $form['deceased_full_name'],
                        ]);
                        $pendingDocumentRows[] = [
                            'staged_upload' => $stagedUpload,
                            'prefix' => 'single_status_fallback_evidence',
                            'row' => [
                                'claim_id' => $currentClaimId,
                                'owner_person_id' => (int) $deceasedPersonId,
                                'related_claim_person_id' => (int) $deceasedClaimPersonId,
                                'document_type' => 'single_status_fallback_evidence',
                                'ocr_status' => (string) $ocr['ocr_status'],
                            ],
                        ];
                        if (!$ocr['ok']) {
                            $errors[] = $ocr['message'];
                        }
                        if (!$hasSingleFormalUpload && !$hasExistingSingleFormal) {
                            $manualFlags['single_status_fallback_evidence'] = true;
                        }
                    }
                }
            }

            if ($form['spouse_status'] === 'DECEASED') {
                $spouseDeathUpload = $_FILES['spouse_death_certificate'] ?? null;
                $spouseFallbackUpload = $_FILES['spouse_secondary_death_evidence'] ?? null;
                $hasStandardSpouseDeath = is_array($spouseDeathUpload) && (int) ($spouseDeathUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                $hasFallbackSpouseDeath = is_array($spouseFallbackUpload) && (int) ($spouseFallbackUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

                if (!$documentsSectionOpen) {
                    if (($existingDocumentCounts['spouse_death_certificate'] ?? 0) <= 0 && ($existingDocumentCounts['spouse_secondary_death_evidence'] ?? 0) <= 0) {
                        $hardMissing[] = claimant_form_spouse_death_requirement_label();
                    } elseif (($existingDocumentCounts['spouse_secondary_death_evidence'] ?? 0) > 0 && ($existingDocumentCounts['spouse_death_certificate'] ?? 0) <= 0) {
                        $manualFlags['widowed_fallback_evidence'] = true;
                    }
                } elseif ($hasStandardSpouseDeath) {
                    $uploadError = null;
                    $stagedUpload = claimant_form_stage_upload($spouseDeathUpload, 'spouse_death_certificate', 'spouse_death_certificate', $uploadError);
                    if ($stagedUpload === null) {
                        $errors[] = $uploadError ?: 'Spouse death certificate could not be uploaded.';
                    } else {
                        $rollbackUploadPaths[] = (string) ($stagedUpload['stage_path'] ?? '');
                        $ocr = claimant_form_validate_ocr('spouse_death_certificate', (string) ($stagedUpload['stage_path'] ?? ''), $ocrContext);
                        if (!$ocr['ok']) {
                            $errors[] = $ocr['message'];
                        }
                        $pendingDocumentRows[] = [
                            'staged_upload' => $stagedUpload,
                            'prefix' => 'spouse_death_certificate',
                            'row' => [
                                'claim_id' => $currentClaimId,
                                'owner_person_id' => (int) $spousePersonId,
                                'related_claim_person_id' => (int) $spouseClaimPersonId,
                                'document_type' => 'spouse_death_certificate',
                                'ocr_status' => (string) $ocr['ocr_status'],
                            ],
                        ];
                    }
                } elseif ($hasFallbackSpouseDeath) {
                    $uploadError = null;
                    $stagedUpload = claimant_form_stage_upload($spouseFallbackUpload, 'spouse_secondary_death_evidence', 'spouse_secondary_death_evidence', $uploadError);
                    if ($stagedUpload === null) {
                        $errors[] = $uploadError ?: 'Fallback spouse-death evidence could not be uploaded.';
                    } else {
                        $rollbackUploadPaths[] = (string) ($stagedUpload['stage_path'] ?? '');
                        $ocr = claimant_form_validate_ocr('secondary_relationship_evidence', (string) ($stagedUpload['stage_path'] ?? ''), $ocrContext + [
                            'person_name' => $form['spouse_full_name'],
                        ]);
                        $pendingDocumentRows[] = [
                            'staged_upload' => $stagedUpload,
                            'prefix' => 'spouse_secondary_death_evidence',
                            'row' => [
                                'claim_id' => $currentClaimId,
                                'owner_person_id' => (int) $spousePersonId,
                                'related_claim_person_id' => (int) $spouseClaimPersonId,
                                'document_type' => 'spouse_secondary_death_evidence',
                                'ocr_status' => (string) $ocr['ocr_status'],
                            ],
                        ];
                        $manualFlags['widowed_fallback_evidence'] = true;
                    }
                } elseif ($isCorrectionMode && ($existingDocumentCounts['spouse_death_certificate'] ?? 0) > 0) {
                    // Keep the previously stored standard spouse death evidence.
                } elseif ($isCorrectionMode && ($existingDocumentCounts['spouse_secondary_death_evidence'] ?? 0) > 0) {
                    $manualFlags['widowed_fallback_evidence'] = true;
                } else {
                    $hardMissing[] = claimant_form_spouse_death_requirement_label();
                }
            }

            $existingChildProofs = (int) ($existingDocumentCounts['child_birth_certificate'] ?? 0);
            foreach ($childClaimPeople as $index => $childMeta) {
                $birthUpload = claimant_form_uploaded_index('child_birth_certificate', $index);
                $hasChildUpload = $birthUpload && (int) ($birthUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                if (!$documentsSectionOpen) {
                    if ($existingChildProofs > 0) {
                        $existingChildProofs--;
                        continue;
                    }
                    $hardMissing[] = 'Child proof document for child #' . ($index + 1);
                    continue;
                }
                if (!$hasChildUpload) {
                    if ($isCorrectionMode && $existingChildProofs > 0) {
                        $existingChildProofs--;
                        continue;
                    }
                    $hardMissing[] = 'Child proof document for child #' . ($index + 1);
                    continue;
                }
                $uploadError = null;
                $stagedUpload = claimant_form_stage_upload($birthUpload, 'child_birth_certificate', 'child_birth_certificate_' . $index, $uploadError);
                if ($stagedUpload === null) {
                    $errors[] = $uploadError ?: ('Child proof document #' . ($index + 1) . ' could not be uploaded.');
                    continue;
                }
                $rollbackUploadPaths[] = (string) ($stagedUpload['stage_path'] ?? '');
                $ocr = claimant_form_validate_ocr('child_birth_certificate', (string) ($stagedUpload['stage_path'] ?? ''), $ocrContext + [
                    'person_name' => (string) ($form['children'][$index]['full_name'] ?? ''),
                ]);
                if (!$ocr['ok']) {
                    $errors[] = $ocr['message'];
                }
                $pendingDocumentRows[] = [
                    'staged_upload' => $stagedUpload,
                    'prefix' => 'child_birth_certificate_' . $index,
                    'row' => [
                        'claim_id' => $currentClaimId,
                        'owner_person_id' => (int) $childMeta['person_id'],
                        'related_claim_person_id' => (int) $childMeta['claim_person_id'],
                        'document_type' => 'child_birth_certificate',
                        'ocr_status' => (string) $ocr['ocr_status'],
                    ],
                ];
            }

            $existingOtherHeirDocs = [
                'secondary_relationship_evidence' => (int) ($existingDocumentCounts['secondary_relationship_evidence'] ?? 0),
                'representative_descendant_linkage' => (int) ($existingDocumentCounts['representative_descendant_linkage'] ?? 0),
            ];
            foreach ($otherHeirClaimPeople as $index => $heirMeta) {
                $relationshipType = strtoupper(trim((string) ($form['other_heirs'][$index]['relationship_type'] ?? '')));
                $expectedDocType = $relationshipType === 'REPRESENTATIVE_DESCENDANT'
                    ? 'representative_descendant_linkage'
                    : 'secondary_relationship_evidence';
                $supportUpload = claimant_form_uploaded_index('other_heir_support', $index);
                $hasSupportUpload = $supportUpload && (int) ($supportUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                if (!$documentsSectionOpen) {
                    if (($existingOtherHeirDocs[$expectedDocType] ?? 0) > 0) {
                        $existingOtherHeirDocs[$expectedDocType]--;
                        continue;
                    }
                    $manualFlags['missing_coheir_docs'] = true;
                    continue;
                }
                if (!$hasSupportUpload) {
                    if ($isCorrectionMode && ($existingOtherHeirDocs[$expectedDocType] ?? 0) > 0) {
                        $existingOtherHeirDocs[$expectedDocType]--;
                        continue;
                    }
                    $manualFlags['missing_coheir_docs'] = true;
                    continue;
                }
                $uploadError = null;
                $stagedUpload = claimant_form_stage_upload($supportUpload, $expectedDocType, 'other_heir_support_' . $index, $uploadError);
                if ($stagedUpload === null) {
                    $manualFlags['missing_coheir_docs'] = true;
                    continue;
                }
                $rollbackUploadPaths[] = (string) ($stagedUpload['stage_path'] ?? '');
                $ocr = claimant_form_validate_ocr('secondary_relationship_evidence', (string) ($stagedUpload['stage_path'] ?? ''), $ocrContext + [
                    'person_name' => (string) ($form['other_heirs'][$index]['full_name'] ?? ''),
                ]);
                $pendingDocumentRows[] = [
                    'staged_upload' => $stagedUpload,
                    'prefix' => 'other_heir_support_' . $index,
                    'row' => [
                        'claim_id' => $currentClaimId,
                        'owner_person_id' => (int) $heirMeta['person_id'],
                        'related_claim_person_id' => (int) $heirMeta['claim_person_id'],
                        'document_type' => $expectedDocType,
                        'ocr_status' => (string) $ocr['ocr_status'],
                    ],
                ];
                if (!$ocr['ok']) {
                    $manualFlags['missing_coheir_docs'] = true;
                }
            }

            if (!empty($hardMissing)) {
                $errors[] = 'Missing required documents: ' . implode(', ', array_unique($hardMissing)) . '.';
            }

            if ($form['children_status'] === 'HAS_CHILDREN' && empty($childClaimPeople)) {
                $manualFlags['possible_missing_heirs'] = true;
            }
            if ($form['marital_status'] === 'MARRIED' && $form['spouse_status'] === 'ALIVE' && $spousePersonId <= 0 && $form['claimant_relationship'] !== 'SPOUSE') {
                $manualFlags['possible_missing_heirs'] = true;
            }
            if ($form['spouse_status'] === 'ALIVE' && $form['claimant_relationship'] !== 'SPOUSE') {
                $manualFlags['spouse_priority_review'] = true;
            }
            if (in_array($form['marital_status'], ['SINGLE', 'WIDOWED'], true) && $form['children_status'] === 'NONE' && empty($otherHeirClaimPeople)) {
                $manualFlags['possible_missing_heirs'] = true;
            }
            if ($form['spouse_status'] === 'DECEASED' && $form['children_status'] === 'NONE' && empty($otherHeirClaimPeople)) {
                $manualFlags['possible_missing_heirs'] = true;
            }
            foreach ($form['children'] as $index => $childRow) {
                $aliveStatus = strtoupper(trim((string) ($childRow['alive_status'] ?? 'YES')));
                $hasDescendants = strtoupper(trim((string) ($childRow['has_descendants'] ?? 'NO')));
                if ($aliveStatus === 'NO' && $hasDescendants === 'YES') {
                    $matchingRepresentative = false;
                    foreach ($form['other_heirs'] as $heirRow) {
                        if (
                            strtoupper(trim((string) ($heirRow['relationship_type'] ?? ''))) === 'REPRESENTATIVE_DESCENDANT'
                            && trim((string) ($heirRow['represented_child_index'] ?? '')) === (string) $index
                        ) {
                            $matchingRepresentative = true;
                            break;
                        }
                    }
                    if (!$matchingRepresentative) {
                        $manualFlags['possible_missing_heirs'] = true;
                    }
                }
            }

            if (!empty($errors)) {
                mysqli_rollback($conn);
                foreach ($rollbackUploadPaths as $rollbackPath) {
                    udcs_claim_delete_upload_file((string) $rollbackPath);
                    udcs_claim_discard_staged_upload((string) $rollbackPath);
                }
                $ocrFailureReasons = implode(' ', array_values(array_unique(array_filter(array_map('trim', $errors)))));
                $ocrFailureMessage = $isCorrectionMode
                    ? 'Requested corrections failed intake validation.'
                    : 'Submission blocked at OCR intake.';
                if ($ocrFailureReasons !== '') {
                    $ocrFailureMessage .= ' ' . $ocrFailureReasons;
                }
                if ($isCorrectionMode) {
                    udcs_claim_set_status($conn, $currentClaimId, 'More Information Required', $claimantId, 'claimant', $ocrFailureMessage, [
                        'legal_reopen_scope' => (string) ($claimRow['legal_reopen_scope'] ?? ''),
                        'legal_reopen_note' => (string) ($claimRow['legal_reopen_note'] ?? ''),
                        'legal_reopen_requested_at' => trim((string) ($claimRow['legal_reopen_requested_at'] ?? '')) !== ''
                            ? (string) $claimRow['legal_reopen_requested_at']
                            : null,
                    ]);
                } else {
                    udcs_claim_set_status($conn, $currentClaimId, 'OCR Validation Failed', $claimantId, 'claimant', $ocrFailureMessage);
                }
            } else {
                foreach ($pendingDocumentRows as $pendingDocument) {
                    $finalizeError = null;
                    $finalPath = claimant_form_finalize_upload(
                        (array) ($pendingDocument['staged_upload'] ?? []),
                        $uploadDir,
                        (string) ($pendingDocument['prefix'] ?? 'document'),
                        $finalizeError
                    );
                    if ($finalPath === null) {
                        throw new RuntimeException($finalizeError ?: 'Document finalization failed.');
                    }

                    $rollbackUploadPaths[] = $finalPath;
                    $rowPayload = (array) ($pendingDocument['row'] ?? []);
                    $rowPayload['file_path'] = udcs_claim_document_relative_storage_path($finalPath);
                    if (udcs_claim_insert_document_row($conn, $rowPayload) <= 0) {
                        throw new RuntimeException('Document record save failed.');
                    }
                }

                $manualReasons = udcs_claim_manual_review_reasons($manualFlags);
                $manualReasonText = udcs_claim_join_manual_review_reasons($manualReasons);
                $liveStatus = udcs_claim_live_status_after_submission($manualReasons);
                $primaryAsset = count(array_unique(array_filter($assetClasses))) > 1 ? 'multiple' : ((string) reset($assetClasses));
                $legacyClaimType = match ($primaryAsset) {
                    'current_account' => 'bank_account',
                    'savings_account' => 'savings',
                    'shares_securities' => 'shares',
                    'investment_account' => 'investment',
                    default => $primaryAsset,
                };
                $claimCurrencyCode = count($estimatedTotalsByCurrency) === 1
                    ? (string) array_key_first($estimatedTotalsByCurrency)
                    : 'RWF';
                $claimAmount = count($estimatedTotalsByCurrency) === 1
                    ? (float) reset($estimatedTotalsByCurrency)
                    : null;
                $relationshipLegacy = strtolower($form['claimant_relationship']);
                $distributionDetailsJson = '';
                if (!empty($form['distribution_details']) && is_array($form['distribution_details'])) {
                    $normalizedDistributionPayload = $form['distribution_details'];
                    $normalizedDistributionPayload['method'] = $form['preferred_payout_method'];
                    $encodedDistributionPayload = json_encode($normalizedDistributionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $distributionDetailsJson = is_string($encodedDistributionPayload) ? $encodedDistributionPayload : '';
                }
                $updateStmt = mysqli_prepare(
                    $conn,
                    "UPDATE claims
                     SET claimant_id = ?,
                         claimant_user_id = ?,
                         deceased_name = ?,
                         deceased_full_name = ?,
                         deceased_national_id = ?,
                         deceased_id_number = ?,
                         deceased_date = ?,
                         date_of_death = ?,
                         relationship = ?,
                         claim_type = ?,
                         claim_amount = ?,
                         claim_currency_code = ?,
                         claim_description = NULL,
                         marital_status = ?,
                         spouse_status = ?,
                         children_status = ?,
                         will_exists = ?,
                         acting_on_behalf = ?,
                         preferred_payout_method = ?,
                         distribution_method = ?,
                         distribution_details = NULLIF(?, ''),
                         manual_review_flag = ?,
                         manual_review_reason = NULLIF(?, ''),
                         model_version = 'v2',
                         legacy_read_only = 0,
                         submitted_at = " . ($isCorrectionMode ? "COALESCE(submitted_at, NOW())" : "NOW()") . ",
                         legal_reopen_scope = NULL,
                         legal_reopen_note = NULL,
                         legal_reopen_requested_at = NULL,
                         status = ?,
                         claim_status = ?,
                         alt_email = NULL,
                         alt_phone = NULL,
                         updated_at = NOW()
                     WHERE id = ?
                     LIMIT 1"
                );
                if (!$updateStmt) {
                    throw new RuntimeException('Claim update failed.');
                }
                $willValue = $form['will_exists'] === 'YES' ? 1 : 0;
                $actingValue = $form['acting_on_behalf'] === 'YES' ? 1 : 0;
                $manualValue = !empty($manualReasons) ? 1 : 0;
                $typedUpdateParams = [
                    ['i', $claimantId],
                    ['i', $claimantId],
                    ['s', $form['deceased_full_name']],
                    ['s', $form['deceased_full_name']],
                    ['s', $form['deceased_id_number']],
                    ['s', $form['deceased_id_number']],
                    ['s', $form['date_of_death']],
                    ['s', $form['date_of_death']],
                    ['s', $relationshipLegacy],
                    ['s', $legacyClaimType],
                    ['d', $claimAmount],
                    ['s', $claimCurrencyCode],
                    ['s', $form['marital_status']],
                    ['s', $form['spouse_status']],
                    ['s', $form['children_status']],
                    ['i', $willValue],
                    ['i', $actingValue],
                    ['s', $form['preferred_payout_method']],
                    ['s', $form['preferred_payout_method']],
                    ['s', $distributionDetailsJson],
                    ['i', $manualValue],
                    ['s', $manualReasonText],
                    ['s', $liveStatus],
                    ['s', $liveStatus],
                    ['i', $currentClaimId],
                ];
                $updateTypes = implode('', array_column($typedUpdateParams, 0));
                $updateParams = array_map(static fn ($row) => $row[1], $typedUpdateParams);
                if (!udcs_db_stmt_bind($updateStmt, $updateTypes, $updateParams) || !mysqli_stmt_execute($updateStmt)) {
                    throw new RuntimeException('Claim update execution failed.');
                }
                mysqli_stmt_close($updateStmt);

                udcs_claim_history_log(
                    $conn,
                    $currentClaimId,
                    'claimant',
                    $isCorrectionMode ? 'Ready for Resubmission' : 'Ready for Submission',
                    $isCorrectionMode ? 'Claim corrections passed intake completeness checks.' : 'Claim passed intake completeness checks.'
                );
                udcs_claim_history_log(
                    $conn,
                    $currentClaimId,
                    'claimant',
                    $isCorrectionMode ? 'Correction Submitted' : 'Submitted',
                    $isCorrectionMode ? 'Claimant submitted requested corrections.' : 'Claim submitted by claimant.'
                );
                $liveStatusMessage = 'Live status set to ' . $liveStatus . '.';
                if ($manualReasonText !== '') {
                    $liveStatusMessage .= ' Manual review reason: ' . str_replace(',', ', ', $manualReasonText) . '.';
                }
                udcs_claim_history_log($conn, $currentClaimId, 'system', $liveStatus, $liveStatusMessage);
                bk_activity_log($conn, [
                    'actor_id' => $claimantId,
                    'actor_role' => 'claimant',
                    'claim_id' => $currentClaimId,
                    'action_key' => $isCorrectionMode ? 'claim_correction_submitted_v2' : 'claim_submitted_v2',
                    'action_label' => $isCorrectionMode ? 'Claim Correction Submitted' : 'Claim Submitted',
                    'details' => $isCorrectionMode
                        ? 'Claimant submitted requested legal corrections.'
                        : 'Redesigned deceased-assets claim submitted.',
                    'meta' => [
                        'status' => $liveStatus,
                        'manual_review_reason' => $manualReasonText,
                        'asset_classes' => array_values(array_filter($assetClasses)),
                    ],
                ]);
                $assignedLegalId = (int) ($claimRow['assigned_legal_id'] ?? 0);
                if ($assignedLegalId <= 0) {
                    $assignedLegalId = (int) (bk_assign_claim_to_legal($conn, $currentClaimId) ?? 0);
                }

                $claimCode = 'CL-' . str_pad((string) $currentClaimId, 6, '0', STR_PAD_LEFT);
                $claimantSubmissionNotification = $isCorrectionMode
                    ? 'Your updates for claim ' . $claimCode . ' were submitted successfully and returned to the ' . $liveStatus . ' stage.'
                    : 'Your claim ' . $claimCode . ' was submitted successfully and is now in the ' . $liveStatus . ' stage.';
                udcs_db_insert_notification($conn, (string) $claimantId, (string) $claimantId, $claimantSubmissionNotification);

                if ($assignedLegalId > 0) {
                    $legalQueueMessage = $isCorrectionMode
                        ? 'Claim ' . $claimCode . ' has been updated by the claimant after your information request.'
                        : 'A new claim ' . $claimCode . ' has been assigned to your legal queue.';
                    if ($manualReasonText !== '') {
                        $legalQueueMessage .= ' Manual review flag: ' . str_replace(',', ', ', $manualReasonText) . '.';
                    }
                    udcs_db_insert_notification($conn, (string) $assignedLegalId, (string) $claimantId, $legalQueueMessage);
                } else {
                    $adminUsersStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'admin'");
                    $adminUsers = false;
                    if ($adminUsersStmt && mysqli_stmt_execute($adminUsersStmt)) {
                        $adminUsers = mysqli_stmt_get_result($adminUsersStmt);
                    }
                    if ($adminUsers) {
                        while ($admin = mysqli_fetch_assoc($adminUsers)) {
                            $receiverId = (int) ($admin['id'] ?? 0);
                            if ($receiverId <= 0) {
                                continue;
                            }
                            $adminQueueMessage = 'Claim ' . $claimCode . ' was submitted but no approved legal officer is currently available for assignment.';
                            udcs_db_insert_notification($conn, (string) $receiverId, (string) $claimantId, $adminQueueMessage);
                        }
                    }
                    if ($adminUsersStmt) {
                        mysqli_stmt_close($adminUsersStmt);
                    }
                }

                mysqli_commit($conn);
                if ($assignedLegalId > 0) {
                    udcs_send_staff_workflow_email(
                        $conn,
                        $isCorrectionMode ? 'legal_updated' : 'legal_assigned',
                        $currentClaimId,
                        [$assignedLegalId],
                        [
                            'actor_name' => $claimant_name,
                            'note' => $legalQueueMessage ?? '',
                        ]
                    );
                }
                foreach (array_values(array_unique(array_filter($filesToDeleteAfterCommit))) as $stalePath) {
                    udcs_claim_delete_upload_file((string) $stalePath);
                }
                $_SESSION['success'] = $assignedLegalId > 0
                    ? ($isCorrectionMode
                        ? 'Requested corrections submitted successfully. The claim is back in Legal review.'
                        : 'Claim submitted successfully. Legal review routing has started.')
                    : ($isCorrectionMode
                        ? 'Requested corrections submitted successfully. No approved legal officer is available right now, so administrators were notified.'
                        : 'Claim submitted successfully. No approved legal officer is available right now, so administrators were notified.');
                header('Location: claims.php');
                exit();
            }
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            if (isset($rollbackUploadPaths) && is_array($rollbackUploadPaths)) {
                foreach ($rollbackUploadPaths as $rollbackPath) {
                    udcs_claim_delete_upload_file((string) $rollbackPath);
                    udcs_claim_discard_staged_upload((string) $rollbackPath);
                }
            }
            $errors[] = 'We could not save the redesigned claim right now.';
        }
    }
}

$childrenJson = json_encode($form['children'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$heirsJson = json_encode($form['other_heirs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$assetsJson = json_encode($form['assets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$existingDocumentCountsJson = json_encode($existingDocumentCounts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$payoutDetailConfigsJson = json_encode(claimant_form_payout_detail_configs(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$payoutSelectOptionsJson = json_encode(bk_distribution_select_options_by_field(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$distributionDetailsSeedJson = json_encode($form['distribution_details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$distributionDetailsFieldValue = '';
if (!empty($form['distribution_details']) && is_array($form['distribution_details'])) {
    $encodedDistributionDetails = json_encode($form['distribution_details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $distributionDetailsFieldValue = is_string($encodedDistributionDetails) ? $encodedDistributionDetails : '';
}
$assetRows = is_array($form['assets'] ?? null) ? $form['assets'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Submit Claim v2 | UNIFIED DIGITAL CLAIMS SYSTEM', '..'); ?>
    <style>
        .claim-v2-shell { max-width: 1160px; margin: 0 auto; }
        .claim-v2-card {
            border: 1px solid rgba(var(--bk-border-rgb), 0.95);
            border-radius: 1.15rem;
            background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 0.98), rgba(var(--bk-surface-rgb), 0.96));
            box-shadow: var(--shadow-soft);
        }
        .claim-v2-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(250px, 290px);
            gap: 1rem;
            align-items: stretch;
            padding: 1.1rem 1.15rem;
            background:
                linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.14), rgba(var(--bk-primary-rgb), 0.035) 58%, rgba(var(--bk-white-rgb), 0.98));
        }
        .claim-v2-hero-main { display: grid; gap: 0.45rem; align-content: center; }
        .claim-v2-hero h1 { margin: 0; font-size: clamp(1.55rem, 1.9vw, 2rem); line-height: 1.05; color: rgb(var(--bk-text-rgb)); font-weight: 800; }
        .claim-v2-note { font-size: 0.88rem; color: rgb(var(--bk-muted-rgb)); line-height: 1.58; }
        .claim-v2-ref-card {
            align-self: stretch;
            border: 1px solid rgba(var(--bk-border-rgb), 0.92);
            border-radius: 1rem;
            background: rgba(var(--bk-white-rgb), 0.88);
            padding: 0.9rem 1rem;
            display: grid;
            gap: 0.28rem;
        }
        .claim-v2-ref-label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.08em; color: rgb(var(--bk-primary-rgb)); font-weight: 800; }
        .claim-v2-ref-value { font-size: 1.12rem; line-height: 1.1; color: rgb(var(--bk-text-rgb)); font-weight: 800; }
        .claim-v2-ref-note { font-size: 0.8rem; color: rgb(var(--bk-muted-rgb)); line-height: 1.45; }
        .claim-v2-journey {
            padding: 0.95rem 1rem;
            display: grid;
            gap: 0.8rem;
            background: linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.08), rgba(var(--bk-white-rgb), 0.98));
        }
        .claim-v2-journey-head { display: grid; gap: 0.2rem; }
        .claim-v2-journey-head h2 { margin: 0; font-size: 1rem; font-weight: 800; color: rgb(var(--bk-text-rgb)); }
        .claim-v2-journey-head p { margin: 0; font-size: 0.82rem; line-height: 1.5; color: rgb(var(--bk-muted-rgb)); max-width: 54rem; }
        .claim-v2-journey-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0.72rem; }
        .claim-v2-journey-step {
            border: 1px solid rgba(var(--bk-primary-rgb), 0.16);
            border-radius: 0.96rem;
            background: rgba(var(--bk-white-rgb), 0.9);
            padding: 0.82rem 0.86rem;
            display: grid;
            gap: 0.28rem;
            min-width: 0;
        }
        .claim-v2-journey-kicker {
            font-size: 0.68rem;
            line-height: 1;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 800;
            color: rgb(var(--bk-primary-rgb));
        }
        .claim-v2-journey-title { margin: 0; font-size: 0.88rem; font-weight: 800; color: rgb(var(--bk-text-rgb)); }
        .claim-v2-journey-copy { margin: 0; font-size: 0.76rem; line-height: 1.45; color: rgb(var(--bk-muted-rgb)); }
        .claim-v2-grid { display: grid; gap: 0.95rem; padding: 0.95rem 0 0; }
        .claim-v2-section {
            border: 1px solid rgba(var(--bk-border-rgb), 0.94);
            border-radius: 1rem;
            background: rgba(var(--bk-white-rgb), 0.96);
            overflow: hidden;
        }
        .claim-v2-section-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            padding: 0.95rem 1rem 0.9rem;
            border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.9);
            background: linear-gradient(135deg, rgba(var(--bk-primary-rgb), 0.085), rgba(var(--bk-white-rgb), 0.98));
        }
        .claim-v2-section-intro { display: grid; gap: 0.26rem; min-width: 0; }
        .claim-v2-section h2 { margin: 0; font-size: 1rem; line-height: 1.15; color: rgb(var(--bk-text-rgb)); font-weight: 800; }
        .claim-v2-section p { margin: 0; color: rgb(var(--bk-muted-rgb)); font-size: 0.82rem; line-height: 1.5; max-width: 48rem; }
        .claim-v2-section-body { padding: 1rem 1rem 1.08rem; display: grid; gap: 1rem; }
        .claim-v2-form-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1.08rem 1.2rem; align-items: start; }
        .claim-v2-field { display: grid; gap: 0.48rem; grid-column: span 3; min-width: 0; align-content: start; }
        .claim-v2-field label {
            font-weight: 900;
            font-size: 0.84rem;
            line-height: 1.34;
            color: rgb(var(--bk-text-rgb));
            letter-spacing: 0.005em;
            overflow-wrap: anywhere;
        }
        .claim-v2-field input,
        .claim-v2-field select,
        .claim-v2-field textarea {
            width: 100%;
            min-height: 2.78rem;
            border: 1.8px solid rgba(var(--bk-primary-rgb), 0.34);
            border-radius: 0.82rem;
            padding: 0.66rem 0.8rem;
            background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-surface-rgb), 0.98));
            color: rgb(var(--bk-text-rgb));
            font-size: 0.94rem;
            line-height: 1.4;
            box-shadow: inset 0 1px 0 rgba(var(--bk-white-rgb), 0.92), 0 3px 8px rgba(var(--bk-primary-rgb), 0.08);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .claim-v2-field input::placeholder,
        .claim-v2-field textarea::placeholder { color: rgb(var(--bk-muted-rgb) / 0.94); opacity: 1; }
        .claim-v2-field input:focus,
        .claim-v2-field select:focus,
        .claim-v2-field textarea:focus {
            outline: none;
            border-color: rgb(var(--bk-primary-rgb) / 0.96);
            box-shadow: 0 0 0 4px rgb(var(--bk-primary-rgb) / 0.16);
        }
        .claim-v2-field textarea { min-height: 92px; resize: vertical; }
        .claim-v2-field input[type="file"],
        .claim-v2-doc-item input[type="file"] { min-height: 2.35rem; padding: 0.5rem 0.58rem; font-size: 0.81rem; line-height: 1.25; }
        .claim-v2-field-note { font-size: 0.78rem; line-height: 1.5; color: rgb(var(--bk-text-rgb)); opacity: 0.72; overflow-wrap: anywhere; }
        .claim-v2-field input:disabled,
        .claim-v2-field select:disabled,
        .claim-v2-field textarea:disabled,
        .claim-v2-field input[readonly],
        .claim-v2-field textarea[readonly],
        .claim-v2-doc-item input[type="file"]:disabled {
            opacity: 1 !important;
            color: rgb(var(--bk-text-rgb)) !important;
            -webkit-text-fill-color: rgb(var(--bk-text-rgb));
            background: linear-gradient(180deg, rgba(var(--bk-bg-rgb), 0.66), rgba(var(--bk-white-rgb), 0.98)) !important;
            border-color: rgba(var(--bk-primary-rgb), 0.24) !important;
            box-shadow: inset 0 1px 0 rgba(var(--bk-white-rgb), 0.72) !important;
            cursor: not-allowed;
        }
        .claim-v2-span-2 { grid-column: span 2; }
        .claim-v2-span-3 { grid-column: span 3; }
        .claim-v2-span-4 { grid-column: span 4; }
        .claim-v2-span-5 { grid-column: span 5; }
        .claim-v2-span-6 { grid-column: span 6; }
        .claim-v2-span-8 { grid-column: span 8; }
        .claim-v2-span-12,
        .claim-v2-span-full { grid-column: 1 / -1; }
        .claim-v2-field.is-invalid label,
        .claim-v2-field.is-invalid .required-star,
        .claim-v2-doc-item.is-invalid strong { color: rgb(var(--bk-danger-rgb) / 1) !important; }
        .claim-v2-field.is-invalid input,
        .claim-v2-field.is-invalid select,
        .claim-v2-field.is-invalid textarea,
        .claim-v2-doc-item.is-invalid {
            border-color: rgb(var(--bk-danger-rgb) / 0.72) !important;
            background: linear-gradient(180deg, rgb(var(--bk-danger-rgb) / 0.08), rgb(var(--bk-white-rgb) / 0.96)) !important;
        }
        .claim-v2-field.is-invalid input,
        .claim-v2-field.is-invalid select,
        .claim-v2-field.is-invalid textarea {
            border-color: rgb(var(--bk-danger-rgb) / 0.72) !important;
            background: linear-gradient(180deg, rgb(var(--bk-danger-rgb) / 0.045), rgb(var(--bk-white-rgb) / 0.98)) !important;
            box-shadow: 0 0 0 1px rgb(var(--bk-danger-rgb) / 0.16) !important;
        }
        .claim-v2-field.is-invalid input:focus,
        .claim-v2-field.is-invalid select:focus,
        .claim-v2-field.is-invalid textarea:focus,
        .claim-v2-field.is-invalid input:focus-visible,
        .claim-v2-field.is-invalid select:focus-visible,
        .claim-v2-field.is-invalid textarea:focus-visible,
        .claim-v2-doc-item.is-invalid:focus-within {
            outline: none !important;
            border-color: rgb(var(--bk-danger-rgb) / 0.92) !important;
            box-shadow: 0 0 0 4px rgb(var(--bk-danger-rgb) / 0.16) !important;
        }
        .claim-v2-field-error,
        .claim-v2-doc-error { display: none; font-size: 0.76rem; color: rgb(var(--bk-danger-rgb) / 1); font-weight: 700; overflow-wrap: anywhere; }
        .claim-v2-field.is-invalid .claim-v2-field-error,
        .claim-v2-doc-item.is-invalid .claim-v2-doc-error { display: block; }
        .required-star { color: rgb(var(--bk-danger-rgb) / 1); margin-left: 0.15rem; font-weight: 800; }
        .claim-v2-validation-summary {
            border: 1px solid rgb(var(--bk-danger-rgb) / 0.32);
            border-radius: 0.95rem;
            background: linear-gradient(180deg, rgb(var(--bk-danger-rgb) / 0.1), rgb(var(--bk-white-rgb) / 0.98));
            padding: 0.92rem 1rem;
            color: rgb(var(--bk-danger-rgb) / 1);
            font-size: 0.88rem;
            font-weight: 800;
            box-shadow: 0 12px 28px rgb(var(--bk-danger-rgb) / 0.1);
        }
        #childrenRows,
        #heirRows,
        #assetRows { display: grid; gap: 0.95rem; }
        .claim-v2-row-card {
            border: 1.4px solid rgba(var(--bk-primary-rgb), 0.2);
            border-radius: 0.95rem;
            padding: 1rem;
            background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 0.99), rgba(var(--bk-surface-rgb), 0.92));
            box-shadow: 0 8px 18px rgba(var(--bk-primary-rgb), 0.06);
        }
        .claim-v2-asset-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.95rem 1.05rem;
            align-items: start;
        }
        .claim-v2-asset-grid .claim-v2-asset-primary {
            grid-column: 1 / -1;
        }
        .claim-v2-asset-grid .claim-v2-asset-secondary {
            grid-column: 1 / -1;
        }
        .claim-v2-actions { display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
        .claim-v2-button {
            border: 1px solid transparent;
            border-radius: 999px;
            padding: 0.76rem 1.08rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.16s ease, border-color 0.16s ease, color 0.16s ease, transform 0.16s ease;
            white-space: nowrap;
        }
        .claim-v2-button.primary { background: rgb(var(--bk-primary-rgb)); color: white; box-shadow: 0 10px 22px rgba(3, 78, 162, 0.16); }
        .claim-v2-button.primary:hover { transform: translateY(-1px); }
        .claim-v2-button.secondary { background: rgba(var(--bk-primary-rgb), 0.08); color: rgb(var(--bk-primary-rgb)); border-color: rgba(var(--bk-primary-rgb), 0.18); }
        .claim-v2-button.secondary:hover { background: rgba(var(--bk-primary-rgb), 0.12); }
        .claim-v2-doc-groups {
            display: grid;
            gap: 0.72rem;
        }
        .claim-v2-doc-group {
            border: 1px solid rgba(var(--bk-border-rgb), 0.9);
            border-radius: 1.12rem;
            background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-primary-rgb), 0.025));
            padding: 0.72rem;
            display: grid;
            gap: 0.56rem;
        }
        .claim-v2-doc-group-head {
            display: grid;
            gap: 0.18rem;
        }
        .claim-v2-doc-group-title {
            margin: 0;
            font-size: 0.92rem;
            font-weight: 800;
            color: rgb(var(--bk-text-rgb));
            letter-spacing: 0.01em;
        }
        .claim-v2-doc-group-copy {
            margin: 0;
            font-size: 0.78rem;
            line-height: 1.45;
            color: rgb(var(--bk-muted-rgb));
        }
        .claim-v2-docs { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 0.55rem 0.62rem; }
        .claim-v2-doc-choice-note {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.72rem;
            border: 1px solid rgba(var(--bk-primary-rgb), 0.22);
            border-radius: 0.82rem;
            background: linear-gradient(90deg, rgba(var(--bk-primary-rgb), 0.09), rgba(var(--bk-white-rgb), 0.96));
            padding: 0.58rem 0.68rem;
            color: rgb(var(--bk-text-rgb));
            font-size: 0.78rem;
            line-height: 1.35;
        }
        .claim-v2-doc-choice-note strong {
            color: rgb(var(--bk-text-rgb));
            font-size: 0.82rem;
            font-weight: 900;
            white-space: nowrap;
        }
        .claim-v2-doc-choice-note span:last-child {
            color: rgb(var(--bk-muted-rgb));
            text-align: right;
        }
        .claim-v2-doc-item {
            grid-column: span 4;
            border: 1px dashed rgba(var(--bk-primary-rgb), 0.26);
            border-radius: 0.95rem;
            padding: 0.64rem 0.68rem;
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.05), rgba(var(--bk-white-rgb), 0.98));
            min-width: 0;
            display: grid;
            align-content: start;
            gap: 0.26rem;
            min-height: 6.15rem;
        }
        .claim-v2-doc-owner-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            max-width: 100%;
            border-radius: 999px;
            padding: 0.22rem 0.58rem;
            font-size: 0.69rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            line-height: 1.2;
            border: 1px solid transparent;
        }
        .claim-v2-doc-owner-pill.is-deceased {
            background: rgba(var(--bk-danger-rgb), 0.1);
            color: rgb(var(--bk-danger-rgb));
            border-color: rgba(var(--bk-danger-rgb), 0.18);
        }
        .claim-v2-doc-owner-pill.is-claimant {
            background: rgba(var(--bk-primary-rgb), 0.11);
            color: rgb(var(--bk-primary-rgb));
            border-color: rgba(var(--bk-primary-rgb), 0.16);
        }
        .claim-v2-doc-owner-pill.is-spouse {
            background: rgba(var(--bk-success-rgb), 0.12);
            color: rgb(var(--bk-success-rgb));
            border-color: rgba(var(--bk-success-rgb), 0.2);
        }
        .claim-v2-doc-owner-pill.is-child {
            background: rgba(var(--bk-warning-rgb), 0.14);
            color: rgb(var(--bk-warning-rgb));
            border-color: rgba(var(--bk-warning-rgb), 0.22);
        }
        .claim-v2-doc-owner-pill.is-family {
            background: rgba(var(--bk-text-rgb), 0.07);
            color: rgb(var(--bk-text-rgb));
            border-color: rgba(var(--bk-text-rgb), 0.12);
        }
        .claim-v2-doc-item strong { display: block; margin-bottom: 0.12rem; color: rgb(var(--bk-text-rgb)); font-size: 0.82rem; line-height: 1.3; overflow-wrap: anywhere; }
        .claim-v2-existing { font-size: 0.72rem; color: rgb(var(--bk-muted-rgb)); margin-top: 0.12rem; line-height: 1.32; }
        .claim-v2-existing-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 0.7rem 0.82rem;
        }
        .claim-v2-existing-item {
            grid-column: span 4;
            border: 1px solid rgba(var(--bk-border-rgb), 0.92);
            border-radius: 0.95rem;
            background: rgba(var(--bk-surface-rgb), 1);
            padding: 0.76rem 0.82rem;
            display: grid;
            gap: 0.34rem;
        }
        .claim-v2-existing-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: rgb(var(--bk-text-rgb));
            line-height: 1.35;
        }
        .claim-v2-doc-item.claim-v2-doc-wide { grid-column: span 6; }
        .claim-v2-doc-item.claim-v2-doc-full { grid-column: 1 / -1; }
        .claim-v2-badge { display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 999px; padding: 0.32rem 0.66rem; background: rgba(var(--bk-primary-rgb), 0.12); color: rgb(var(--bk-primary-rgb)); font-size: 0.78rem; font-weight: 700; }
        .claim-v2-badge.warning { background: rgba(var(--bk-warning-rgb), 0.16); color: rgb(var(--bk-warning-rgb)); }
        .claim-v2-subpanel {
            border: 1px solid rgba(var(--bk-primary-rgb), 0.16);
            border-radius: 1rem;
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.045), rgba(var(--bk-white-rgb), 0.98));
            padding: 0.9rem 0.95rem;
            display: grid;
            gap: 0.72rem;
        }
        .claim-v2-subpanel-head { display: grid; gap: 0.22rem; }
        .claim-v2-subpanel-title { margin: 0; color: rgb(var(--bk-text-rgb)); font-size: 0.94rem; font-weight: 800; }
        .claim-v2-subpanel-copy { margin: 0; color: rgb(var(--bk-muted-rgb)); font-size: 0.8rem; line-height: 1.5; max-width: 46rem; }
        .claim-v2-payout-details {
            border-color: rgba(var(--bk-primary-rgb), 0.18);
            background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.06), rgba(var(--bk-white-rgb), 0.985));
        }
        .claim-v2-followup-card { border: 1px solid rgba(var(--bk-warning-rgb), 0.28); border-radius: 1rem; background: linear-gradient(180deg, rgba(var(--bk-warning-rgb), 0.12), rgba(var(--bk-white-rgb), 0.98)); padding: 0.95rem 1rem; display: grid; gap: 0.55rem; }
        .claim-v2-followup-list { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .claim-v2-followup-pill { display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 999px; padding: 0.3rem 0.7rem; background: rgba(var(--bk-surface-rgb), 0.95); border: 1px solid rgba(var(--bk-warning-rgb), 0.22); color: rgb(var(--bk-text-rgb)); font-size: 0.78rem; font-weight: 700; }
        .claim-v2-section.is-locked { border-color: rgba(var(--bk-warning-rgb), 0.22); }
        .claim-v2-section.is-locked .claim-v2-section-head { background: linear-gradient(135deg, rgba(var(--bk-warning-rgb), 0.12), rgba(var(--bk-white-rgb), 0.98)); }
        .claim-v2-section.is-locked .claim-v2-section-body { background: rgba(var(--bk-bg-rgb), 0.36); }
        .claim-v2-section.is-locked .claim-v2-row-card { background: rgba(var(--bk-bg-rgb), 0.68); }
        .claim-v2-section.is-locked .claim-v2-field label,
        .claim-v2-section.is-locked .claim-v2-doc-item strong,
        .claim-v2-section.is-locked .claim-v2-existing,
        .claim-v2-section.is-locked .claim-v2-field-error,
        .claim-v2-section.is-locked .claim-v2-doc-error {
            opacity: 1 !important;
            color: rgb(var(--bk-text-rgb)) !important;
        }
        .claim-v2-section.is-locked .claim-v2-field input,
        .claim-v2-section.is-locked .claim-v2-field select,
        .claim-v2-section.is-locked .claim-v2-field textarea,
        .claim-v2-section.is-locked .claim-v2-doc-item input[type="file"] {
            opacity: 1 !important;
            color: rgb(var(--bk-text-rgb)) !important;
            -webkit-text-fill-color: rgb(var(--bk-text-rgb));
        }
        .claim-v2-lock-note { display: none; font-size: 0.78rem; color: rgb(var(--bk-warning-rgb)); font-weight: 700; }
        .claim-v2-section.is-locked .claim-v2-lock-note { display: block; }
        .claim-v2-submit-card { padding: 0.95rem 1rem; display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
        .claim-v2-submit-copy { max-width: 44rem; font-size: 0.84rem; line-height: 1.55; color: rgb(var(--bk-muted-rgb)); }
        .claim-v2-hide { display: none !important; }
        @media (max-width: 980px) {
            .claim-v2-hero { grid-template-columns: 1fr; }
            .claim-v2-ref-card { width: 100%; }
            .claim-v2-journey-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .claim-v2-section-head,
            .claim-v2-submit-card { flex-direction: column; align-items: stretch; }
        }
        @media (max-width: 1120px) {
            .claim-v2-form-grid,
            .claim-v2-docs { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .claim-v2-existing-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .claim-v2-asset-grid { grid-template-columns: 1fr 1fr; }
            .claim-v2-field,
            .claim-v2-doc-item { grid-column: span 3; }
            .claim-v2-existing-item { grid-column: span 3; }
            .claim-v2-span-2 { grid-column: span 2; }
            .claim-v2-span-3,
            .claim-v2-span-4 { grid-column: span 3; }
            .claim-v2-span-5,
            .claim-v2-span-6,
            .claim-v2-span-8,
            .claim-v2-doc-item.claim-v2-doc-wide { grid-column: span 6; }
            .claim-v2-span-12,
            .claim-v2-span-full,
            .claim-v2-doc-item.claim-v2-doc-full { grid-column: 1 / -1; }
        }
        @media (max-width: 860px) {
            .claim-v2-form-grid,
            .claim-v2-docs,
            .claim-v2-existing-grid,
            .claim-v2-asset-grid { grid-template-columns: 1fr; }
            .claim-v2-journey-grid { grid-template-columns: 1fr; }
            .claim-v2-field,
            .claim-v2-doc-item,
            .claim-v2-existing-item,
            .claim-v2-span-2,
            .claim-v2-span-3,
            .claim-v2-span-4,
            .claim-v2-span-5,
            .claim-v2-span-6,
            .claim-v2-span-8,
            .claim-v2-span-12,
            .claim-v2-span-full,
            .claim-v2-doc-item.claim-v2-doc-wide,
            .claim-v2-doc-item.claim-v2-doc-full { grid-column: 1 / -1; }
            .claim-v2-doc-choice-note {
                align-items: flex-start;
                flex-direction: column;
            }
            .claim-v2-doc-choice-note span:last-child {
                text-align: left;
            }
            .claim-v2-actions { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<style>
    .claim-v2-grid {
        counter-reset: claim-v2-sections;
    }

    .claim-v2-grid > .claim-v2-section {
        counter-increment: claim-v2-sections;
    }

    .claim-v2-shell .claim-v2-section-head {
        background: linear-gradient(180deg, rgba(var(--bk-primary-rgb), 0.08), rgba(var(--bk-white-rgb), 0.98)) !important;
        border-bottom: 1px solid rgba(var(--bk-border-rgb), 0.9) !important;
        box-shadow: none !important;
    }

    .claim-v2-shell .claim-v2-section-title,
    .claim-v2-shell .claim-v2-section h2 {
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.08rem !important;
        width: fit-content !important;
        max-width: max-content !important;
        padding: 0.42rem 0.72rem !important;
        border-radius: 0.76rem !important;
        background: rgb(var(--bk-primary-rgb)) !important;
        color: rgba(var(--bk-white-rgb), 1) !important;
        -webkit-text-fill-color: rgba(var(--bk-white-rgb), 1) !important;
        box-shadow: 0 10px 22px rgba(3, 78, 162, 0.16) !important;
        line-height: 1.05 !important;
    }

    .claim-v2-shell .claim-v2-section-title,
    .claim-v2-shell .claim-v2-section-title * {
        color: rgba(var(--bk-white-rgb), 1) !important;
        -webkit-text-fill-color: rgba(var(--bk-white-rgb), 1) !important;
    }

    .claim-v2-shell .claim-v2-section-head p,
    .claim-v2-shell .claim-v2-lock-note {
        color: rgb(var(--bk-text-rgb)) !important;
        opacity: 0.82 !important;
    }

    .claim-v2-shell .claim-v2-section-title::before {
        content: counter(claim-v2-sections) ". ";
        display: inline-block !important;
        min-width: auto !important;
        margin-right: 0.1rem !important;
        color: rgba(var(--bk-white-rgb), 1) !important;
        -webkit-text-fill-color: rgba(var(--bk-white-rgb), 1) !important;
        font-weight: 900 !important;
    }

    .claim-v2-shell .claim-v2-section-head .claim-v2-button.secondary {
        background: rgba(var(--bk-white-rgb), 0.98) !important;
        color: rgb(var(--bk-primary-rgb)) !important;
        border-color: rgba(var(--bk-white-rgb), 0.82) !important;
        box-shadow: 0 8px 18px rgba(var(--bk-primary-rgb), 0.18) !important;
    }

    .claim-v2-shell .claim-v2-section-head .claim-v2-button.secondary:hover {
        background: rgba(var(--bk-white-rgb), 0.92) !important;
    }

    .claim-v2-shell .claim-v2-section-body {
        background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 0.99), rgba(var(--bk-surface-rgb), 0.92)) !important;
    }

    .claim-v2-shell .claim-v2-field {
        gap: 0.5rem !important;
        align-content: start !important;
    }

    .claim-v2-shell .claim-v2-field label {
        color: rgb(var(--bk-text-rgb)) !important;
        font-size: 0.92rem !important;
        font-weight: 900 !important;
        line-height: 1.34 !important;
        letter-spacing: 0 !important;
        opacity: 1 !important;
    }

    .claim-v2-shell .claim-v2-field input,
    .claim-v2-shell .claim-v2-field select,
    .claim-v2-shell .claim-v2-field textarea {
        min-height: 2.95rem !important;
        border: 2px solid rgba(var(--bk-primary-rgb), 0.42) !important;
        background: #ffffff !important;
        color: rgb(var(--bk-text-rgb)) !important;
        font-size: 0.98rem !important;
        line-height: 1.42 !important;
        padding: 0.72rem 0.85rem !important;
        box-shadow: inset 0 1px 0 rgba(var(--bk-white-rgb), 0.96), 0 4px 12px rgba(var(--bk-primary-rgb), 0.08) !important;
        -webkit-text-fill-color: rgb(var(--bk-text-rgb)) !important;
    }

    .claim-v2-shell #deceased_full_name,
    .claim-v2-shell #deceased_id_number,
    .claim-v2-shell #spouse_full_name,
    .claim-v2-shell #spouse_id_number,
    .claim-v2-shell input[name^="children["][name$="[full_name]"],
    .claim-v2-shell input[name^="children["][name$="[date_of_birth]"],
    .claim-v2-shell input[name^="children["][name$="[id_number]"],
    .claim-v2-shell input[name^="other_heirs["][name$="[full_name]"],
    .claim-v2-shell input[name^="other_heirs["][name$="[id_number]"],
    .claim-v2-shell input[name^="other_heirs["][name$="[contact_phone]"],
    .claim-v2-shell input[name^="other_heirs["][name$="[contact_email]"] {
        border: 2.6px solid rgb(var(--bk-primary-rgb)) !important;
        box-shadow: inset 0 1px 0 rgba(var(--bk-white-rgb), 0.96), 0 0 0 1px rgba(var(--bk-primary-rgb), 0.18), 0 6px 14px rgba(var(--bk-primary-rgb), 0.12) !important;
    }

    .claim-v2-shell input[name^="assets["][name$="[estimated_value]"] {
        border: 2.6px solid rgb(var(--bk-primary-rgb)) !important;
        box-shadow: inset 0 1px 0 rgba(var(--bk-white-rgb), 0.96), 0 0 0 1px rgba(var(--bk-primary-rgb), 0.18), 0 6px 14px rgba(var(--bk-primary-rgb), 0.12) !important;
    }

    .claim-v2-shell .claim-v2-field-deceased-name,
    .claim-v2-shell .claim-v2-field-claimant-relationship,
    .claim-v2-shell .claim-v2-field-acting-heirs,
    .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-primary,
    .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-currency,
    .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-estimate,
    .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-override {
        justify-self: start !important;
        width: 100% !important;
    }

    .claim-v2-shell .claim-v2-field-deceased-name {
        grid-column: span 5 !important;
        max-width: 34rem !important;
    }

    .claim-v2-shell .claim-v2-field-claimant-relationship {
        grid-column: span 4 !important;
        max-width: 27rem !important;
    }

    .claim-v2-shell .claim-v2-field-acting-heirs {
        grid-column: span 4 !important;
        max-width: 24rem !important;
    }

    .claim-v2-shell .claim-v2-field input::placeholder,
    .claim-v2-shell .claim-v2-field textarea::placeholder {
        color: rgb(var(--bk-muted-rgb)) !important;
        opacity: 1 !important;
    }

    .claim-v2-shell .claim-v2-field input:focus,
    .claim-v2-shell .claim-v2-field select:focus,
    .claim-v2-shell .claim-v2-field textarea:focus,
    .claim-v2-shell .claim-v2-field input:focus-visible,
    .claim-v2-shell .claim-v2-field select:focus-visible,
    .claim-v2-shell .claim-v2-field textarea:focus-visible {
        border-color: rgb(var(--bk-primary-rgb)) !important;
        box-shadow: 0 0 0 4px rgba(var(--bk-primary-rgb), 0.16) !important;
        outline: none !important;
    }

    .claim-v2-shell .claim-v2-field-note {
        color: rgb(var(--bk-text-rgb)) !important;
        font-size: 0.82rem !important;
        line-height: 1.52 !important;
        opacity: 0.78 !important;
    }

        .claim-v2-shell .claim-v2-row-card {
            border: 1.6px solid rgba(var(--bk-primary-rgb), 0.24) !important;
            background: linear-gradient(180deg, rgba(var(--bk-white-rgb), 1), rgba(var(--bk-surface-rgb), 0.94)) !important;
            box-shadow: 0 10px 22px rgba(var(--bk-primary-rgb), 0.07) !important;
        }
    .claim-v2-shell .claim-v2-asset-grid {
        grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
        gap: 1rem 1.08rem !important;
    }

    .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-primary {
        grid-column: span 4 !important;
        max-width: 30rem !important;
    }

    .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-currency {
        grid-column: span 3 !important;
        max-width: 18rem !important;
    }

    .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-estimate {
        grid-column: span 3 !important;
        max-width: 18rem !important;
    }

    .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-override {
        grid-column: span 4 !important;
        max-width: 28rem !important;
    }

    @media (max-width: 1120px) {
        .claim-v2-shell .claim-v2-asset-grid {
            grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
        }

        .claim-v2-shell .claim-v2-field-deceased-name,
        .claim-v2-shell .claim-v2-field-claimant-relationship,
        .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-primary {
            grid-column: span 4 !important;
        }

        .claim-v2-shell .claim-v2-field-acting-heirs,
        .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-estimate {
            grid-column: span 3 !important;
        }

        .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-override {
            grid-column: span 4 !important;
        }
    }

    @media (max-width: 860px) {
        .claim-v2-shell .claim-v2-field-deceased-name,
        .claim-v2-shell .claim-v2-field-claimant-relationship,
        .claim-v2-shell .claim-v2-field-acting-heirs,
        .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-primary,
        .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-estimate,
        .claim-v2-shell .claim-v2-asset-grid .claim-v2-asset-override {
            grid-column: 1 / -1 !important;
            justify-self: stretch !important;
            max-width: none !important;
        }
    }
</style>
<main class="main-content px-4 pb-8 pt-4 sm:px-6 lg:px-8">
    <div class="claim-v2-shell">
        <section class="claim-v2-card claim-v2-hero">
            <div class="claim-v2-hero-main">
                <?php if ($isCorrectionMode): ?>
                    <span class="claim-v2-badge warning">Legal Follow-Up</span>
                <?php endif; ?>
                <h1><?php echo $isCorrectionMode ? 'Update Claim' : 'Submit Claim'; ?></h1>
                <p class="claim-v2-note">Enter the deceased customer, the claimant path, BK assets, and only the documents this case needs.</p>
            </div>
        </section>

        <div class="mt-4">
            <?php if (!empty($errors)): ?>
                <?php render_alert(implode(' ', $errors), ['type' => 'danger', 'dismissible' => true, 'class' => 'mb-4']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['success'])): ?>
                <?php render_alert((string) $_SESSION['success'], ['type' => 'success', 'dismissible' => true, 'class' => 'mb-4']); unset($_SESSION['success']); ?>
            <?php endif; ?>
            <?php if ($isCorrectionMode): ?>
                <section class="claim-v2-followup-card mb-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="claim-v2-badge warning">Legal Follow-up</span>
                        <strong>Update only the sections Legal reopened for this claim.</strong>
                    </div>
                    <div class="claim-v2-followup-list">
                        <?php foreach ($reopenedSections as $sectionKey): ?>
                            <span class="claim-v2-followup-pill"><?php echo bk_e(udcs_claim_reopen_section_label($sectionKey)); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($legalReopenNote !== ''): ?>
                        <div class="claim-v2-note"><?php echo bk_e($legalReopenNote); ?></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" class="claim-v2-grid" id="claimV2Form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo bk_e($csrfToken); ?>">
            <input type="hidden" name="claim_id" value="<?php echo (int) $currentClaimId; ?>">
            <div id="claimV2ValidationSummary" class="claim-v2-validation-summary claim-v2-hide" role="alert">
                Complete the highlighted required fields before submitting this claim.
            </div>

            <section class="claim-v2-section" data-claim-section="deceased_entry">
                <div class="claim-v2-section-head">
                    <div class="claim-v2-section-intro">
                        <h2 class="claim-v2-section-title" style="display:inline-flex;align-items:center;gap:0.08rem;width:fit-content;max-width:max-content;padding:0.42rem 0.72rem;border-radius:0.76rem;background:rgb(var(--bk-primary-rgb));color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;box-shadow:0 10px 22px rgba(3, 78, 162, 0.16);line-height:1.05;">About the Deceased</h2>
                        <p>Begin with the deceased person's core identity. The rest of the claim will build from this record.</p>
                        <p class="claim-v2-lock-note">Locked until Legal reopens this section.</p>
                    </div>
                </div>
                <div class="claim-v2-section-body">
                <div class="claim-v2-form-grid">
                    <div class="claim-v2-field claim-v2-span-6 claim-v2-field-deceased-name"><label for="deceased_full_name">Deceased Full Name<span class="required-star">*</span></label><input id="deceased_full_name" name="deceased_full_name" value="<?php echo bk_e($form['deceased_full_name']); ?>" required><span class="claim-v2-field-error">Enter the deceased person's full name.</span></div>
                    <div class="claim-v2-field claim-v2-span-3"><label for="deceased_id_number">Deceased ID / Passport<span class="required-star">*</span></label><input id="deceased_id_number" name="deceased_id_number" value="<?php echo bk_e($form['deceased_id_number']); ?>" required><span class="claim-v2-field-error">Enter the deceased person's ID or passport number.</span></div>
                    <div class="claim-v2-field claim-v2-span-3"><label for="date_of_death">Date of Death<span class="required-star">*</span></label><input id="date_of_death" type="date" name="date_of_death" value="<?php echo bk_e($form['date_of_death']); ?>" max="<?php echo bk_e($deathDateMax); ?>" required><span class="claim-v2-field-error">Enter a valid date of death. Future dates are not allowed.</span></div>
                </div>
                </div>
            </section>

            <section class="claim-v2-section" data-claim-section="deceased_entry">
                <div class="claim-v2-section-head">
                    <div class="claim-v2-section-intro">
                        <h2 class="claim-v2-section-title" style="display:inline-flex;align-items:center;gap:0.08rem;width:fit-content;max-width:max-content;padding:0.42rem 0.72rem;border-radius:0.76rem;background:rgb(var(--bk-primary-rgb));color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;box-shadow:0 10px 22px rgba(3, 78, 162, 0.16);line-height:1.05;">Family and Claimant Path</h2>
                        <p>These answers decide which people, documents, and review rules the claim must follow.</p>
                        <p class="claim-v2-lock-note">Locked until Legal reopens this section.</p>
                    </div>
                </div>
                <div class="claim-v2-section-body">
                <div class="claim-v2-form-grid">
                    <div class="claim-v2-field claim-v2-span-4"><label for="will_exists">Will on Record?<span class="required-star">*</span></label><select id="will_exists" name="will_exists"><option value="NO" <?php echo $form['will_exists'] === 'NO' ? 'selected' : ''; ?>>No</option><option value="YES" <?php echo $form['will_exists'] === 'YES' ? 'selected' : ''; ?>>Yes</option></select><span class="claim-v2-field-error">Choose whether a will exists.</span></div>
                    <div class="claim-v2-field claim-v2-span-4"><label for="marital_status">Marital Status at Death<span class="required-star">*</span></label><select id="marital_status" name="marital_status" required><option value="">Select</option><option value="SINGLE" <?php echo $form['marital_status'] === 'SINGLE' ? 'selected' : ''; ?>>Single</option><option value="MARRIED" <?php echo $form['marital_status'] === 'MARRIED' ? 'selected' : ''; ?>>Married</option><option value="DIVORCED" <?php echo $form['marital_status'] === 'DIVORCED' ? 'selected' : ''; ?>>Divorced</option><option value="WIDOWED" <?php echo $form['marital_status'] === 'WIDOWED' ? 'selected' : ''; ?>>Widowed</option><option value="SEPARATED" <?php echo $form['marital_status'] === 'SEPARATED' ? 'selected' : ''; ?>>Separated</option></select><span class="claim-v2-field-error">Select the marital status at death.</span></div>
                    <div class="claim-v2-field claim-v2-span-4"><label for="spouse_status">Spouse Status</label><select id="spouse_status" name="spouse_status"><option value="NOT_APPLICABLE" <?php echo $form['spouse_status'] === 'NOT_APPLICABLE' ? 'selected' : ''; ?>>Not applicable</option><option value="ALIVE" <?php echo $form['spouse_status'] === 'ALIVE' ? 'selected' : ''; ?>>Alive</option><option value="DECEASED" <?php echo $form['spouse_status'] === 'DECEASED' ? 'selected' : ''; ?>>Deceased</option></select><span class="claim-v2-field-error">Choose the spouse status for a married claim.</span></div>
                    <div class="claim-v2-field claim-v2-span-4"><label for="children_status">Children<span class="required-star">*</span></label><select id="children_status" name="children_status" required><option value="NONE" <?php echo $form['children_status'] === 'NONE' ? 'selected' : ''; ?>>None</option><option value="HAS_CHILDREN" <?php echo $form['children_status'] === 'HAS_CHILDREN' ? 'selected' : ''; ?>>Has children</option><option value="UNKNOWN" <?php echo $form['children_status'] === 'UNKNOWN' ? 'selected' : ''; ?>>Unknown</option></select><span class="claim-v2-field-error">Select the children status.</span></div>
                    <div class="claim-v2-field claim-v2-span-4 claim-v2-field-claimant-relationship"><label for="claimant_relationship">Claimant Relationship<span class="required-star">*</span></label><select id="claimant_relationship" name="claimant_relationship" required><option value="">Select</option><?php foreach ($relationshipOptions as $value => $label): ?><option value="<?php echo bk_e($value); ?>" <?php echo $form['claimant_relationship'] === $value ? 'selected' : ''; ?>><?php echo bk_e($label); ?></option><?php endforeach; ?></select><span class="claim-v2-field-error">Select the claimant's relationship to the deceased.</span><span class="claim-v2-field-note">This identifies how the claimant is connected to the deceased, not whether they are the only beneficiary.</span></div>
                    <div class="claim-v2-field claim-v2-span-4 claim-v2-field-acting-heirs"><label for="acting_on_behalf">Acting for Other Heirs?<span class="required-star">*</span></label><select id="acting_on_behalf" name="acting_on_behalf"><option value="NO" <?php echo $form['acting_on_behalf'] === 'NO' ? 'selected' : ''; ?>>No</option><option value="YES" <?php echo $form['acting_on_behalf'] === 'YES' ? 'selected' : ''; ?>>Yes</option></select><span class="claim-v2-field-error">Choose whether this claim is being filed for multiple heirs.</span><span class="claim-v2-field-note">Choose yes only if this claimant is presenting documents or authority for other heirs as well.</span></div>
                </div>
                </div>
            </section>

            <section class="claim-v2-section" id="spouseBlock" data-claim-section="spouse_details">
                <div class="claim-v2-section-head">
                    <div class="claim-v2-section-intro">
                        <h2 class="claim-v2-section-title" style="display:inline-flex;align-items:center;gap:0.08rem;width:fit-content;max-width:max-content;padding:0.42rem 0.72rem;border-radius:0.76rem;background:rgb(var(--bk-primary-rgb));color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;box-shadow:0 10px 22px rgba(3, 78, 162, 0.16);line-height:1.05;">Spouse Details</h2>
                        <p>Shown only when spouse information is relevant to the claim.</p>
                        <p class="claim-v2-lock-note">Locked until Legal reopens this section.</p>
                    </div>
                </div>
                <div class="claim-v2-section-body">
                <div class="claim-v2-form-grid">
                    <div class="claim-v2-field claim-v2-span-6"><label for="spouse_full_name">Spouse Name</label><input id="spouse_full_name" name="spouse_full_name" value="<?php echo bk_e($form['spouse_full_name']); ?>"><span class="claim-v2-field-error">Enter the spouse full name for this path.</span></div>
                    <div class="claim-v2-field claim-v2-span-6"><label for="spouse_id_number">Spouse ID</label><input id="spouse_id_number" name="spouse_id_number" value="<?php echo bk_e($form['spouse_id_number']); ?>"></div>
                </div>
                </div>
            </section>

            <section class="claim-v2-section" data-claim-section="children">
                <div class="claim-v2-section-head">
                    <div class="claim-v2-section-intro">
                        <h2 class="claim-v2-section-title" style="display:inline-flex;align-items:center;gap:0.08rem;width:fit-content;max-width:max-content;padding:0.42rem 0.72rem;border-radius:0.76rem;background:rgb(var(--bk-primary-rgb));color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;box-shadow:0 10px 22px rgba(3, 78, 162, 0.16);line-height:1.05;">Children</h2>
                        <p>Add each declared child. Descendant representation appears only when needed.</p>
                        <p class="claim-v2-lock-note">Locked until Legal reopens this section.</p>
                    </div>
                    <button type="button" class="claim-v2-button secondary" id="addChildBtn">Add Child</button>
                </div>
                <div class="claim-v2-section-body">
                    <div id="childrenRows"></div>
                </div>
            </section>

            <section class="claim-v2-section" id="otherHeirsSection" data-claim-section="other_heirs">
                <div class="claim-v2-section-head">
                    <div class="claim-v2-section-intro">
                        <h2 class="claim-v2-section-title" style="display:inline-flex;align-items:center;gap:0.08rem;width:fit-content;max-width:max-content;padding:0.42rem 0.72rem;border-radius:0.76rem;background:rgb(var(--bk-primary-rgb));color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;box-shadow:0 10px 22px rgba(3, 78, 162, 0.16);line-height:1.05;">Other Heirs / Representatives</h2>
                        <p>This section appears only when the family path requires extra co-heirs, representative descendants, or authorized representatives.</p>
                        <p class="claim-v2-lock-note">Locked until Legal reopens this section.</p>
                    </div>
                    <button type="button" class="claim-v2-button secondary" id="addHeirBtn">Add Entry</button>
                </div>
                <div class="claim-v2-section-body">
                    <div id="heirRows"></div>
                </div>
            </section>

            <section class="claim-v2-section" data-claim-section="assets_payout">
                <div class="claim-v2-section-head">
                    <div class="claim-v2-section-intro">
                        <h2 class="claim-v2-section-title" style="display:inline-flex;align-items:center;gap:0.08rem;width:fit-content;max-width:max-content;padding:0.42rem 0.72rem;border-radius:0.76rem;background:rgb(var(--bk-primary-rgb));color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;box-shadow:0 10px 22px rgba(3, 78, 162, 0.16);line-height:1.05;">BK Assets Being Claimed</h2>
                        <p>First identify each Bank of Kigali product involved in this claim. Settlement instructions come after the asset list is clear, and the final method is still confirmed during Legal and Finance review.</p>
                        <p class="claim-v2-lock-note">Locked until Legal reopens this section.</p>
                    </div>
                    <button type="button" class="claim-v2-button secondary" id="addAssetBtn">Add Asset</button>
                </div>
                <div class="claim-v2-section-body">
                    <div id="assetRows"></div>
                    <?php $hasInitialAssetSelection = !empty(array_filter(array_map(static fn ($asset) => trim((string) ($asset['asset_class'] ?? '')), $assetRows))); ?>
                    <div class="claim-v2-subpanel<?php echo $hasInitialAssetSelection ? '' : ' claim-v2-hide'; ?>" id="payoutPreferencePanel">
                        <div class="claim-v2-subpanel-head">
                            <h3 class="claim-v2-subpanel-title">Preferred Settlement Path</h3>
                            <p class="claim-v2-subpanel-copy">After the BK asset list is clear, record the claimant's preferred settlement path. This is a preference only. Legal and Finance still confirm the final settlement method.</p>
                        </div>
                        <div class="claim-v2-form-grid">
                            <div class="claim-v2-field claim-v2-span-6">
                                <label for="preferred_payout_method">Claim-Level Settlement Preference<span class="required-star">*</span></label>
                                <select id="preferred_payout_method" name="preferred_payout_method" required <?php echo $hasInitialAssetSelection ? '' : 'disabled'; ?>>
                                    <option value="">Select</option>
                                    <?php foreach ($payoutOptions as $value => $label): ?>
                                        <option value="<?php echo bk_e($value); ?>" <?php echo $form['preferred_payout_method'] === $value ? 'selected' : ''; ?>><?php echo bk_e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="claim-v2-field-error">Choose the claimant's preferred settlement path.</span>
                                <span class="claim-v2-field-note">Use this as the main settlement choice for the whole claim. Only use an asset-level override when one BK product needs a different path.</span>
                            </div>
                        </div>
                        <input type="hidden" id="distribution_details" name="distribution_details" value="<?php echo bk_e($distributionDetailsFieldValue); ?>">
                        <div class="claim-v2-subpanel claim-v2-payout-details<?php echo $form['preferred_payout_method'] !== '' ? '' : ' claim-v2-hide'; ?>" id="payoutDetailsPanel">
                            <div class="claim-v2-subpanel-head">
                                <h3 class="claim-v2-subpanel-title" id="payoutDetailsTitle">Settlement Destination Details</h3>
                                <p class="claim-v2-subpanel-copy" id="payoutDetailsHint">Fill in the destination details required for the chosen settlement instruction.</p>
                            </div>
                            <div class="claim-v2-form-grid" id="payoutDetailsFields"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="claim-v2-section" data-claim-section="supporting_documents">
                <div class="claim-v2-section-head">
                    <div class="claim-v2-section-intro">
                        <h2 class="claim-v2-section-title" style="display:inline-flex;align-items:center;gap:0.08rem;width:fit-content;max-width:max-content;padding:0.42rem 0.72rem;border-radius:0.76rem;background:rgb(var(--bk-primary-rgb));color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;box-shadow:0 10px 22px rgba(3, 78, 162, 0.16);line-height:1.05;">Supporting Documents</h2>
                        <p class="claim-v2-lock-note">Locked until Legal reopens this section.</p>
                    </div>
                </div>
                <div class="claim-v2-section-body">
                <div class="claim-v2-doc-groups">
                    <section class="claim-v2-doc-group">
                        <div class="claim-v2-doc-group-head">
                            <h3 class="claim-v2-doc-group-title">Deceased / Estate Documents</h3>
                        </div>
                        <div class="claim-v2-docs">
                            <div class="claim-v2-doc-item">
                                <span class="claim-v2-doc-owner-pill is-deceased">Deceased / Estate</span>
                                <strong>Death Certificate<span class="required-star">*</span></strong>
                                <input type="file" name="deceased_death_certificate" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload the deceased death certificate.</span>
                            </div>
                            <div class="claim-v2-doc-choice-note single-status-doc claim-v2-hide">
                                <strong>Single Status Evidence<span class="required-star">*</span></strong>
                                <span>Choose one: formal proof or fallback attestation.</span>
                            </div>
                            <div class="claim-v2-doc-item single-status-doc">
                                <span class="claim-v2-doc-owner-pill is-deceased">Deceased / Estate</span>
                                <strong>Proof of Single Status</strong>
                                <input type="file" name="single_status_evidence" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload formal single-status evidence or use the fallback attestation below.</span>
                                <div class="claim-v2-existing single-status-doc-note">Preferred formal certificate for a single deceased person.</div>
                            </div>
                            <div class="claim-v2-doc-item single-status-doc claim-v2-doc-wide">
                                <span class="claim-v2-doc-owner-pill is-deceased">Deceased / Estate</span>
                                <strong>Fallback Single-Status Attestation</strong>
                                <input type="file" name="single_status_fallback_evidence" accept=".jpg,.jpeg,.png">
                                <div class="claim-v2-existing single-status-doc-note">Use only when the formal certificate is unavailable. Legal review is required if used.</div>
                            </div>
                            <div class="claim-v2-doc-item will-doc">
                                <span class="claim-v2-doc-owner-pill is-deceased">Deceased / Estate</span>
                                <strong>Copy of Will<span class="required-star">*</span></strong>
                                <input type="file" name="will_copy_document" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload the will copy for this path.</span>
                            </div>
                        </div>
                    </section>

                    <section class="claim-v2-doc-group">
                        <div class="claim-v2-doc-group-head">
                            <h3 class="claim-v2-doc-group-title">Claimant / Representative Documents</h3>
                        </div>
                        <div class="claim-v2-docs">
                            <div class="claim-v2-doc-item">
                                <span class="claim-v2-doc-owner-pill is-claimant">Claimant / Representative</span>
                                <strong>Claimant ID<span class="required-star">*</span></strong>
                                <input type="file" name="claimant_id_document" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload the claimant ID.</span>
                            </div>
                            <div class="claim-v2-doc-item relationship-proof-doc">
                                <span class="claim-v2-doc-owner-pill is-claimant">Claimant / Representative</span>
                                <strong>Supporting Relationship Certificate<span class="relationship-required-star required-star claim-v2-hide">*</span></strong>
                                <input type="file" name="relationship_proof_document" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload supporting relationship proof for this path.</span>
                                <div class="claim-v2-existing relationship-proof-note">Required only if no direct proof already exists.</div>
                            </div>
                            <div class="claim-v2-doc-item representative-doc">
                                <span class="claim-v2-doc-owner-pill is-claimant">Claimant / Representative</span>
                                <strong>Authority Document<span class="required-star">*</span></strong>
                                <input type="file" name="representative_authority_document" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload the authority document for a representative claim.</span>
                            </div>
                        </div>
                    </section>

                    <section class="claim-v2-doc-group">
                        <div class="claim-v2-doc-group-head">
                            <h3 class="claim-v2-doc-group-title">Spouse / Family Path Documents</h3>
                        </div>
                        <div class="claim-v2-docs">
                            <div class="claim-v2-doc-item spouse-doc">
                                <span class="claim-v2-doc-owner-pill is-spouse">Spouse / Family Path</span>
                                <strong>Marriage Certificate<span class="required-star">*</span></strong>
                                <input type="file" name="marriage_certificate" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload the marriage certificate.</span>
                            </div>
                            <div class="claim-v2-doc-item spouse-alive-doc">
                                <span class="claim-v2-doc-owner-pill is-spouse">Spouse / Family Path</span>
                                <strong>Spouse ID<span class="required-star">*</span></strong>
                                <input type="file" name="spouse_id_document" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload the spouse ID.</span>
                            </div>
                            <div class="claim-v2-doc-choice-note spouse-deceased-doc claim-v2-hide">
                                <strong>Spouse Death Evidence<span class="required-star">*</span></strong>
                                <span>Choose one: spouse death certificate or fallback evidence.</span>
                            </div>
                            <div class="claim-v2-doc-item spouse-deceased-doc claim-v2-doc-wide">
                                <span class="claim-v2-doc-owner-pill is-spouse">Spouse / Family Path</span>
                                <strong>Spouse Death Certificate</strong>
                                <input type="file" name="spouse_death_certificate" accept=".jpg,.jpeg,.png">
                                <span class="claim-v2-doc-error">Upload the spouse death certificate or use the fallback evidence below.</span>
                            </div>
                            <div class="claim-v2-doc-item spouse-deceased-doc claim-v2-doc-wide">
                                <span class="claim-v2-doc-owner-pill is-spouse">Spouse / Family Path</span>
                                <strong>Fallback Spouse-Death Evidence</strong>
                                <input type="file" name="spouse_secondary_death_evidence" accept=".jpg,.jpeg,.png">
                                <div class="claim-v2-existing">Use only when the spouse death certificate is unavailable. Legal review is required if used.</div>
                            </div>
                        </div>
                    </section>

                    <section class="claim-v2-doc-group">
                        <div class="claim-v2-doc-group-head">
                            <h3 class="claim-v2-doc-group-title">Files Already Stored For This Draft</h3>
                        </div>
                        <?php if (!empty($existingDocumentDisplay)): ?>
                            <div class="claim-v2-existing-grid">
                                <?php foreach ($existingDocumentDisplay as $documentCard): ?>
                                    <div class="claim-v2-existing-item">
                                        <span class="claim-v2-doc-owner-pill <?php echo bk_e((string) ($documentCard['owner_class'] ?? 'is-family')); ?>"><?php echo bk_e((string) ($documentCard['owner_label'] ?? 'Family / Co-Heir')); ?></span>
                                        <div class="claim-v2-existing-label"><?php echo bk_e((string) ($documentCard['label'] ?? 'Document')); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="claim-v2-existing">No files stored on this draft yet.</div>
                        <?php endif; ?>
                    </section>
                </div>
                </div>
            </section>

            <section class="claim-v2-card claim-v2-submit-card">
                <div class="claim-v2-submit-copy">Required fields and triggered documents must pass intake checks before the claim can move into Legal review.</div>
                <div class="claim-v2-actions">
                    <button type="submit" class="claim-v2-button primary"><?php echo $isCorrectionMode ? 'Submit Requested Updates' : 'Submit Claim'; ?></button>
                </div>
            </section>
        </form>
    </div>
</main>

<script>
const relationshipOptions = <?php echo json_encode($relationshipOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const payoutOptions = <?php echo json_encode($payoutOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const payoutDetailConfigs = <?php echo $payoutDetailConfigsJson ?: '{}'; ?>;
const payoutDetailSelectOptions = <?php echo $payoutSelectOptionsJson ?: '{}'; ?>;
const payoutFieldLabels = <?php echo json_encode(bk_distribution_field_labels(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const assetOptions = <?php echo json_encode($assetOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const currencyOptions = <?php echo json_encode($currencyOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const currencyDecimals = <?php echo json_encode(array_map(static fn($meta): int => (int) ($meta['decimals'] ?? 2), bk_supported_currencies()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const childSeed = <?php echo $childrenJson ?: '[]'; ?>;
const heirSeed = <?php echo $heirsJson ?: '[]'; ?>;
const assetSeed = <?php echo $assetsJson ?: '[]'; ?>;
const payoutDetailsSeed = <?php echo $distributionDetailsSeedJson ?: '{}'; ?>;
const existingDocumentCounts = <?php echo $existingDocumentCountsJson ?: '{}'; ?>;
const correctionMode = <?php echo $isCorrectionMode ? 'true' : 'false'; ?>;
const reopenedSections = <?php echo json_encode(array_values($reopenedSections), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const reopenedSectionSet = new Set(Array.isArray(reopenedSections) ? reopenedSections : []);

function buildSelectOptions(map, selected = '') {
    return Object.entries(map).map(([value, label]) => `<option value="${value}" ${selected === value ? 'selected' : ''}>${label}</option>`).join('');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function amountMatchesCurrency(rawValue, currencyCode) {
    const normalized = String(rawValue || '').replace(/[,\s]/g, '').trim();
    if (normalized === '') {
        return true;
    }
    const decimals = Number(currencyDecimals[String(currencyCode || 'RWF').toUpperCase()] ?? 2);
    const pattern = decimals === 0
        ? /^\d+$/
        : new RegExp(`^\\d+(?:\\.\\d{1,${decimals}})?$`);
    return pattern.test(normalized);
}

function getPayoutConfig(method) {
    return payoutDetailConfigs?.[method] || { label: 'Settlement Destination Details', hint: 'Fill in the destination details required for the chosen settlement instruction.', fields: [] };
}

function getPayoutFieldOptions(fieldKey) {
    const options = payoutDetailSelectOptions?.[fieldKey];
    return Array.isArray(options) ? options : [];
}

function normalizePayoutFieldValue(fieldKey, value) {
    if (value == null) {
        return '';
    }
    const raw = String(value);
    const trimmed = raw.trim();
    if (['mobile_wallet_number', 'contact_phone'].includes(fieldKey)) {
        return trimmed.replace(/\s+/g, '');
    }
    return trimmed;
}

function buildPayoutFieldMarkup(field, fieldValue = '') {
    const fieldId = `payoutDetail_${field.key}`;
    const labelText = String(field.label || payoutFieldLabels?.[field.key] || field.key || 'Detail');
    const requiredMark = field.required ? '<span class="required-star">*</span>' : '';
    const placeholder = escapeHtml(field.placeholder || '');
    const normalizedValue = normalizePayoutFieldValue(field.key, fieldValue);
    const safeValue = escapeHtml(normalizedValue);
    const note = field.note ? `<span class="claim-v2-field-note">${escapeHtml(field.note)}</span>` : '';
    const selectOptions = getPayoutFieldOptions(field.key);

    if ((field.type || '') === 'textarea') {
        return `
            <div class="claim-v2-field claim-v2-span-12">
                <label for="${fieldId}">${escapeHtml(labelText)}${requiredMark}</label>
                <textarea id="${fieldId}" class="payout-detail-input" data-payout-key="${escapeHtml(field.key)}" data-payout-required="${field.required ? '1' : '0'}" placeholder="${placeholder}">${safeValue}</textarea>
                <span class="claim-v2-field-error">Provide ${escapeHtml(labelText.toLowerCase())}.</span>
                ${note}
            </div>
        `;
    }

    if (selectOptions.length) {
        const optionsMarkup = selectOptions.map((option) => {
            const optionValue = String(option || '');
            const selected = normalizedValue === optionValue ? 'selected' : '';
            return `<option value="${escapeHtml(optionValue)}" ${selected}>${escapeHtml(optionValue)}</option>`;
        }).join('');
        return `
            <div class="claim-v2-field claim-v2-span-4">
                <label for="${fieldId}">${escapeHtml(labelText)}${requiredMark}</label>
                <select id="${fieldId}" class="payout-detail-input" data-payout-key="${escapeHtml(field.key)}" data-payout-required="${field.required ? '1' : '0'}">
                    <option value="">Select</option>
                    ${optionsMarkup}
                </select>
                <span class="claim-v2-field-error">Provide ${escapeHtml(labelText.toLowerCase())}.</span>
                ${note}
            </div>
        `;
    }

    const inputType = field.type || 'text';
    const fieldSpanClass = inputType === 'date' ? 'claim-v2-span-4' : 'claim-v2-span-4';
    return `
        <div class="claim-v2-field ${fieldSpanClass}">
            <label for="${fieldId}">${escapeHtml(labelText)}${requiredMark}</label>
            <input id="${fieldId}" type="${escapeHtml(inputType)}" class="payout-detail-input" data-payout-key="${escapeHtml(field.key)}" data-payout-required="${field.required ? '1' : '0'}" value="${safeValue}" placeholder="${placeholder}">
            <span class="claim-v2-field-error">Provide ${escapeHtml(labelText.toLowerCase())}.</span>
            ${note}
        </div>
    `;
}

function collectPayoutDetailsPayload() {
    const payload = {};
    const detailFields = Array.from(document.querySelectorAll('#payoutDetailsFields .payout-detail-input'));
    detailFields.forEach((field) => {
        const key = String(field.getAttribute('data-payout-key') || '').trim();
        if (!key) {
            return;
        }
        const value = normalizePayoutFieldValue(key, field.value || '');
        if (value !== '') {
            payload[key] = value;
        }
    });
    return payload;
}

function syncPayoutDetailsPayload() {
    const method = String(document.getElementById('preferred_payout_method')?.value || '').trim();
    const hiddenInput = document.getElementById('distribution_details');
    if (!hiddenInput) {
        return;
    }
    if (method === '') {
        hiddenInput.value = '';
        return;
    }

    const payload = collectPayoutDetailsPayload();
    if (method === 'bk_account_transfer' && !payload.bank_name) {
        payload.bank_name = 'Bank of Kigali';
    }
    if (Object.keys(payload).length > 0) {
        payload.method = method;
        hiddenInput.value = JSON.stringify(payload);
    } else {
        hiddenInput.value = '';
    }
}

function renderPayoutDetailsFields() {
    const method = String(document.getElementById('preferred_payout_method')?.value || '').trim();
    const panel = document.getElementById('payoutDetailsPanel');
    const title = document.getElementById('payoutDetailsTitle');
    const hint = document.getElementById('payoutDetailsHint');
    const fieldsRoot = document.getElementById('payoutDetailsFields');

    if (!panel || !title || !hint || !fieldsRoot) {
        return;
    }

    if (method === '') {
        panel.classList.add('claim-v2-hide');
        fieldsRoot.innerHTML = '';
        syncPayoutDetailsPayload();
        return;
    }

    const config = getPayoutConfig(method);
    const fields = Array.isArray(config.fields) ? config.fields : [];
    const seed = window.payoutDetailsData && typeof window.payoutDetailsData === 'object' ? window.payoutDetailsData : {};

    title.textContent = config.label || 'Settlement Destination Details';
    hint.textContent = config.hint || 'Fill in the destination details required for the chosen settlement instruction.';

    if (!fields.length) {
        fieldsRoot.innerHTML = '<div class="claim-v2-field claim-v2-span-12"><span class="claim-v2-field-note">No additional destination details are required for this settlement instruction.</span></div>';
    } else {
        fieldsRoot.innerHTML = fields.map((field) => buildPayoutFieldMarkup(field, seed[field.key] || '')).join('');
    }

    panel.classList.remove('claim-v2-hide');
    applyCorrectionLocks();
    syncPayoutDetailsPayload();
}

function setFieldLocked(field, locked) {
    if (!field || field.type === 'hidden') {
        return;
    }
    if (field.tagName === 'SELECT' || field.type === 'file' || field.tagName === 'BUTTON') {
        field.disabled = locked;
    } else {
        field.readOnly = locked;
    }
    if (locked) {
        field.setAttribute('tabindex', '-1');
    } else {
        field.removeAttribute('tabindex');
    }
}

function captureChildRowsData() {
    const cards = Array.from(document.querySelectorAll('#childrenRows .claim-v2-row-card'));
    if (!cards.length) {
        return;
    }
    window.childRowsData = cards.map((card, index) => ({
        full_name: card.querySelector(`input[name="children[${index}][full_name]"]`)?.value || '',
        date_of_birth: card.querySelector(`input[name="children[${index}][date_of_birth]"]`)?.value || '',
        alive_status: card.querySelector(`select[name="children[${index}][alive_status]"]`)?.value || 'YES',
        id_number: card.querySelector(`input[name="children[${index}][id_number]"]`)?.value || '',
        has_descendants: card.querySelector(`select[name="children[${index}][has_descendants]"]`)?.value || 'NO',
    }));
}

function captureHeirRowsData() {
    const cards = Array.from(document.querySelectorAll('#heirRows .claim-v2-row-card'));
    if (!cards.length) {
        return;
    }
    window.heirRowsData = cards.map((card, index) => ({
        full_name: card.querySelector(`input[name="other_heirs[${index}][full_name]"]`)?.value || '',
        relationship_type: card.querySelector(`select[name="other_heirs[${index}][relationship_type]"]`)?.value || '',
        alive_status: card.querySelector(`select[name="other_heirs[${index}][alive_status]"]`)?.value || 'YES',
        id_number: card.querySelector(`input[name="other_heirs[${index}][id_number]"]`)?.value || '',
        contact_phone: card.querySelector(`input[name="other_heirs[${index}][contact_phone]"]`)?.value || '',
        contact_email: card.querySelector(`input[name="other_heirs[${index}][contact_email]"]`)?.value || '',
        represented_child_index: card.querySelector(`select[name="other_heirs[${index}][represented_child_index]"]`)?.value || '',
    }));
}

function captureAssetRowsData() {
    const cards = Array.from(document.querySelectorAll('#assetRows .claim-v2-row-card'));
    if (!cards.length) {
        return;
    }
    const previousRows = Array.isArray(window.assetRowsData) ? window.assetRowsData : [];
    window.assetRowsData = cards.map((card, index) => ({
        asset_class: card.querySelector(`select[name="assets[${index}][asset_class]"]`)?.value || '',
        currency_code: card.querySelector(`select[name="assets[${index}][currency_code]"]`)?.value || 'RWF',
        account_reference: previousRows[index]?.account_reference || '',
        estimated_value: card.querySelector(`input[name="assets[${index}][estimated_value]"]`)?.value || '',
        payout_preference_override: card.querySelector(`select[name="assets[${index}][payout_preference_override]"]`)?.value || '',
    }));
}

function applyCorrectionLocks() {
    if (!correctionMode) {
        return;
    }

    document.querySelectorAll('[data-claim-section]').forEach((section) => {
        const sectionKey = section.dataset.claimSection || '';
        const locked = !reopenedSectionSet.has(sectionKey);
        section.classList.toggle('is-locked', locked);
        section.querySelectorAll('input, select, textarea, button').forEach((field) => {
            setFieldLocked(field, locked);
        });
    });
}

function renderChildRows() {
    const root = document.getElementById('childrenRows');
    const rows = window.childRowsData;
    root.innerHTML = rows.map((row, index) => `
        <div class="claim-v2-row-card">
            <div class="claim-v2-form-grid">
                <div class="claim-v2-field claim-v2-span-6"><label>Full Name<span class="required-star">*</span></label><input name="children[${index}][full_name]" value="${escapeHtml(row.full_name || '')}"><span class="claim-v2-field-error">Enter the child's full name.</span></div>
                <div class="claim-v2-field claim-v2-span-3"><label>Date of Birth</label><input type="date" name="children[${index}][date_of_birth]" value="${escapeHtml(row.date_of_birth || '')}"></div>
                <div class="claim-v2-field claim-v2-span-3"><label>Alive Status</label><select name="children[${index}][alive_status]"><option value="YES" ${(row.alive_status || 'YES') === 'YES' ? 'selected' : ''}>Alive</option><option value="NO" ${row.alive_status === 'NO' ? 'selected' : ''}>Deceased</option></select></div>
                <div class="claim-v2-field claim-v2-span-6"><label>ID Number</label><input name="children[${index}][id_number]" value="${escapeHtml(row.id_number || '')}"></div>
                <div class="claim-v2-field claim-v2-span-6"><label>Left Descendants?</label><select name="children[${index}][has_descendants]"><option value="NO" ${(row.has_descendants || 'NO') === 'NO' ? 'selected' : ''}>No</option><option value="YES" ${row.has_descendants === 'YES' ? 'selected' : ''}>Yes</option></select></div>
                <div class="claim-v2-field claim-v2-span-12"><label>Child Proof<span class="required-star">*</span></label><input type="file" name="child_birth_certificate[${index}]" accept=".jpg,.jpeg,.png"><span class="claim-v2-field-error">Upload child proof for this entry.</span></div>
            </div>
        </div>
    `).join('');
}

function buildDeceasedChildOptions(selected = '') {
    const childCards = Array.from(document.querySelectorAll('#childrenRows .claim-v2-row-card'));
    return childCards
        .map((card, index) => ({
            index,
            fullName: card.querySelector(`input[name="children[${index}][full_name]"]`)?.value || '',
            aliveStatus: card.querySelector(`select[name="children[${index}][alive_status]"]`)?.value || 'YES',
        }))
        .filter(({aliveStatus}) => aliveStatus === 'NO')
        .map(({fullName, index}) => {
            const label = fullName && fullName.trim() !== '' ? fullName.trim() : `Child #${index + 1}`;
            return `<option value="${index}" ${String(selected) === String(index) ? 'selected' : ''}>${escapeHtml(label)}</option>`;
        })
        .join('');
}

function getHeirSupportMeta(relationshipType) {
    const key = String(relationshipType || '').trim().toUpperCase();
    if (key === 'REPRESENTATIVE_DESCENDANT') {
        return {
            label: 'Descendant Linkage Evidence',
            note: 'Upload the linkage proof connecting this representative descendant to the deceased child they represent.'
        };
    }
    if (['PARENT', 'FULL_SIBLING', 'HALF_SIBLING', 'GRANDPARENT', 'UNCLE_AUNT', 'OTHER_REPRESENTATIVE'].includes(key)) {
        return {
            label: 'Local Authority / Relationship Evidence',
            note: 'Upload the civil record, local authority attestation, family resolution, succession council decision, or similar support for this relationship path.'
        };
    }
    return {
        label: 'Support Document',
        note: 'Upload the document that supports this extra heir or representative entry.'
    };
}

function renderHeirRows() {
    const root = document.getElementById('heirRows');
    const rows = window.heirRowsData;
    root.innerHTML = rows.map((row, index) => `
        <div class="claim-v2-row-card">
            <div class="claim-v2-form-grid">
                ${(() => {
                    const supportMeta = getHeirSupportMeta(row.relationship_type || '');
                    return `
                <div class="claim-v2-field claim-v2-span-6"><label>Full Name</label><input name="other_heirs[${index}][full_name]" value="${escapeHtml(row.full_name || '')}"></div>
                <div class="claim-v2-field claim-v2-span-3"><label>Relationship</label><select name="other_heirs[${index}][relationship_type]"><option value="">Select</option>${buildSelectOptions(relationshipOptions, row.relationship_type || '')}</select></div>
                <div class="claim-v2-field claim-v2-span-3"><label>Alive Status</label><select name="other_heirs[${index}][alive_status]"><option value="YES" ${(row.alive_status || 'YES') === 'YES' ? 'selected' : ''}>Alive</option><option value="NO" ${row.alive_status === 'NO' ? 'selected' : ''}>Deceased</option></select></div>
                <div class="claim-v2-field claim-v2-span-4"><label>ID Number</label><input name="other_heirs[${index}][id_number]" value="${escapeHtml(row.id_number || '')}"></div>
                <div class="claim-v2-field claim-v2-span-4"><label>Phone</label><input name="other_heirs[${index}][contact_phone]" value="${escapeHtml(row.contact_phone || '')}"></div>
                <div class="claim-v2-field claim-v2-span-4"><label>Email</label><input type="email" name="other_heirs[${index}][contact_email]" value="${escapeHtml(row.contact_email || '')}"></div>
                <div class="claim-v2-field claim-v2-span-6 rep-descendant-link"><label>Represents Child</label><select name="other_heirs[${index}][represented_child_index]"><option value="">Select only for representative descendant</option>${buildDeceasedChildOptions(row.represented_child_index || '')}</select></div>
                <div class="claim-v2-field claim-v2-span-6"><label>${escapeHtml(supportMeta.label)}</label><input type="file" name="other_heir_support[${index}]" accept=".jpg,.jpeg,.png"><span class="claim-v2-field-note">${escapeHtml(supportMeta.note)}</span></div>
                `;
                })()}
            </div>
        </div>
    `).join('');

    root.querySelectorAll('.claim-v2-row-card').forEach((card) => {
        const relationshipSelect = card.querySelector('select[name*="[relationship_type]"]');
        const repLinkField = card.querySelector('.rep-descendant-link');
        if (!relationshipSelect || !repLinkField) {
            return;
        }
        repLinkField.classList.toggle('claim-v2-hide', relationshipSelect.value !== 'REPRESENTATIVE_DESCENDANT');
    });
}

function renderAssetRows() {
    const root = document.getElementById('assetRows');
    const rows = window.assetRowsData;
    const defaultPayoutMethod = String(document.getElementById('preferred_payout_method')?.value || '').trim();
    const showOverrideField = rows.length > 1 && defaultPayoutMethod !== '';
    root.innerHTML = rows.map((row, index) => `
        <div class="claim-v2-row-card">
            <div class="claim-v2-asset-grid">
                <div class="claim-v2-field claim-v2-asset-primary"><label>BK Asset Class<span class="required-star">*</span></label><select name="assets[${index}][asset_class]"><option value="">Select</option>${buildSelectOptions(assetOptions, row.asset_class || '')}</select><span class="claim-v2-field-error">Select a BK-held asset class.</span></div>
                <div class="claim-v2-field claim-v2-asset-secondary claim-v2-asset-currency"><label>Currency<span class="required-star">*</span></label><select name="assets[${index}][currency_code]">${buildSelectOptions(currencyOptions, row.currency_code || 'RWF')}</select><span class="claim-v2-field-error">Select the asset currency.</span><span class="claim-v2-field-note">Use the currency in which BK holds this specific asset.</span></div>
                <div class="claim-v2-field claim-v2-asset-secondary claim-v2-asset-estimate"><label>Claimant Estimate</label><input name="assets[${index}][estimated_value]" value="${escapeHtml(row.estimated_value || '')}"><span class="claim-v2-field-error">Enter a valid number for the selected currency.</span><span class="claim-v2-field-note">Optional estimate only. Finance confirms the actual BK value later.</span></div>
                ${showOverrideField ? `<div class="claim-v2-field claim-v2-asset-secondary claim-v2-asset-override"><label>Asset-Level Override</label><select name="assets[${index}][payout_preference_override]"><option value="">Use claim-level preference</option>${buildSelectOptions(payoutOptions, row.payout_preference_override || '')}</select><span class="claim-v2-field-note">Use this only when this specific BK asset needs a different settlement path.</span></div>` : ''}
            </div>
        </div>
    `).join('');
}

function clearValidationState() {
    document.querySelectorAll('.claim-v2-field.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
    document.querySelectorAll('.claim-v2-doc-item.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
    document.querySelectorAll('#claimV2Form [aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
    const summary = document.getElementById('claimV2ValidationSummary');
    if (summary) {
        summary.classList.add('claim-v2-hide');
    }
}

function isElementInteractive(element) {
    if (!element || element.disabled) {
        return false;
    }
    return element.offsetParent !== null;
}

function relationshipRequiresSupportingCertificate(relationship) {
    return !['CHILD', 'SPOUSE'].includes(String(relationship || '').trim().toUpperCase());
}

function markInvalidField(input) {
    const field = input ? input.closest('.claim-v2-field') : null;
    if (field) {
        field.classList.add('is-invalid');
    }
    if (input) {
        input.setAttribute('aria-invalid', 'true');
    }
}

function markInvalidDoc(input) {
    const card = input ? input.closest('.claim-v2-doc-item') : null;
    if (card) {
        card.classList.add('is-invalid');
    }
    if (input) {
        input.setAttribute('aria-invalid', 'true');
    }
}

function hasRepresentativeChildRequirement() {
    return Array.from(document.querySelectorAll('select[name*="[has_descendants]"]')).some((select) => {
        if (!isElementInteractive(select)) {
            return false;
        }
        const aliveInput = select.closest('.claim-v2-row-card')?.querySelector('select[name*="[alive_status]"]');
        return aliveInput && aliveInput.value === 'NO' && select.value === 'YES';
    });
}

function hasSelectedAssetClass() {
    return Array.from(document.querySelectorAll('select[name^="assets["][name$="[asset_class]"]')).some((select) => {
        if (!isElementInteractive(select)) {
            return false;
        }
        return String(select.value || '').trim() !== '';
    });
}

function syncConditionalSections() {
    const marital = document.getElementById('marital_status').value;
    const spouse = document.getElementById('spouse_status');
    const spouseBlock = document.getElementById('spouseBlock');
    const otherHeirsSection = document.getElementById('otherHeirsSection');
    const childrenStatus = document.getElementById('children_status').value;
    const acting = document.getElementById('acting_on_behalf').value;
    const claimantRelationship = document.getElementById('claimant_relationship').value;
    const spouseDocs = document.querySelectorAll('.spouse-doc');
    const spouseAliveDocs = document.querySelectorAll('.spouse-alive-doc');
    const spouseDeceasedDocs = document.querySelectorAll('.spouse-deceased-doc');
    const singleStatusDocs = document.querySelectorAll('.single-status-doc');
    const willDocs = document.querySelectorAll('.will-doc');
    const repDocs = document.querySelectorAll('.representative-doc');
    const relationshipProofDocs = document.querySelectorAll('.relationship-proof-doc');
    const willExists = document.getElementById('will_exists').value;
    const spouseRelationshipPath = claimantRelationship === 'SPOUSE';
    const relationshipProofRequired = relationshipRequiresSupportingCertificate(claimantRelationship);
    const childDescendantPath = hasRepresentativeChildRequirement();
    const payoutPanel = document.getElementById('payoutPreferencePanel');
    const payoutSelect = document.getElementById('preferred_payout_method');
    const hasAssetsSelected = hasSelectedAssetClass();
    const relationshipNeedsHeirSection = ['PARENT', 'FULL_SIBLING', 'HALF_SIBLING', 'GRANDPARENT', 'UNCLE_AUNT', 'OTHER_REPRESENTATIVE', 'REPRESENTATIVE_DESCENDANT'].includes(claimantRelationship);
    const shouldShowOtherHeirs = acting === 'YES' || childDescendantPath || relationshipNeedsHeirSection;

    if (marital === 'WIDOWED') {
        spouse.value = 'DECEASED';
        spouse.setAttribute('readonly', 'readonly');
    } else if (marital === 'SINGLE') {
        spouse.value = 'NOT_APPLICABLE';
        spouse.setAttribute('readonly', 'readonly');
    } else if (spouseRelationshipPath) {
        spouse.value = 'ALIVE';
        spouse.setAttribute('readonly', 'readonly');
    } else {
        spouse.removeAttribute('readonly');
    }

    const spouseClaimReuse = spouse.value === 'ALIVE' && claimantRelationship === 'SPOUSE';
    spouseBlock.classList.toggle('claim-v2-hide', spouse.value === 'NOT_APPLICABLE' || spouseClaimReuse);
    document.getElementById('childrenRows').parentElement.classList.toggle('claim-v2-hide', childrenStatus !== 'HAS_CHILDREN');
    otherHeirsSection.classList.toggle('claim-v2-hide', !shouldShowOtherHeirs);
    singleStatusDocs.forEach(node => node.classList.toggle('claim-v2-hide', marital !== 'SINGLE'));
    spouseDocs.forEach(node => node.classList.toggle('claim-v2-hide', spouse.value === 'NOT_APPLICABLE' && !spouseRelationshipPath));
    spouseAliveDocs.forEach(node => node.classList.toggle('claim-v2-hide', spouse.value !== 'ALIVE' || spouseClaimReuse));
    spouseDeceasedDocs.forEach(node => node.classList.toggle('claim-v2-hide', spouse.value !== 'DECEASED'));
    willDocs.forEach(node => node.classList.toggle('claim-v2-hide', willExists !== 'YES'));
    repDocs.forEach(node => node.classList.toggle('claim-v2-hide', acting !== 'YES'));
    relationshipProofDocs.forEach((node) => {
        node.querySelector('.relationship-required-star')?.classList.toggle('claim-v2-hide', !relationshipProofRequired);
        const note = node.querySelector('.relationship-proof-note');
        if (note) {
            note.textContent = relationshipProofRequired
                ? 'Required for this relationship path because no birth/filiation or marriage certificate directly proves the claimant standing.'
                : 'Optional supporting evidence. The required relationship proof for this path is handled by the birth/filiation or marriage certificate.';
        }
    });
    applyCorrectionLocks();
    if (payoutPanel) {
        payoutPanel.classList.toggle('claim-v2-hide', !hasAssetsSelected);
    }
    if (payoutSelect) {
        payoutSelect.disabled = !hasAssetsSelected || (correctionMode && !reopenedSectionSet.has('assets_payout'));
    }
    captureAssetRowsData();
    renderAssetRows();
    renderPayoutDetailsFields();
    syncPayoutDetailsPayload();
}

window.childRowsData = Array.isArray(childSeed) && childSeed.length ? childSeed : [{full_name:'', date_of_birth:'', alive_status:'YES', id_number:'', has_descendants:'NO'}];
window.heirRowsData = Array.isArray(heirSeed) && heirSeed.length ? heirSeed : [{full_name:'', relationship_type:'', alive_status:'YES', id_number:'', contact_phone:'', contact_email:'', represented_child_index:''}];
window.assetRowsData = Array.isArray(assetSeed) && assetSeed.length ? assetSeed : [{asset_class:'', currency_code:'RWF', account_reference:'', estimated_value:'', payout_preference_override:''}];
window.payoutDetailsData = payoutDetailsSeed && typeof payoutDetailsSeed === 'object' ? { ...payoutDetailsSeed } : {};

document.getElementById('addChildBtn').addEventListener('click', () => { captureChildRowsData(); window.childRowsData.push({full_name:'', date_of_birth:'', alive_status:'YES', id_number:'', has_descendants:'NO'}); renderChildRows(); captureHeirRowsData(); renderHeirRows(); syncConditionalSections(); });
document.getElementById('addHeirBtn').addEventListener('click', () => { captureHeirRowsData(); window.heirRowsData.push({full_name:'', relationship_type:'', alive_status:'YES', id_number:'', contact_phone:'', contact_email:'', represented_child_index:''}); renderHeirRows(); syncConditionalSections(); });
document.getElementById('addAssetBtn').addEventListener('click', () => { captureAssetRowsData(); window.assetRowsData.push({asset_class:'', currency_code:'RWF', account_reference:'', estimated_value:'', payout_preference_override:''}); renderAssetRows(); syncConditionalSections(); });
document.getElementById('childrenRows').addEventListener('change', (event) => {
    if (event.target.matches('select[name*="[alive_status]"], select[name*="[has_descendants]"]')) {
        captureChildRowsData();
        captureHeirRowsData();
        renderHeirRows();
        syncConditionalSections();
    }
});
document.getElementById('heirRows').addEventListener('change', (event) => {
    if (event.target.matches('select[name*="[relationship_type]"]')) {
        const card = event.target.closest('.claim-v2-row-card');
        const repLinkField = card ? card.querySelector('.rep-descendant-link') : null;
        if (repLinkField) {
            repLinkField.classList.toggle('claim-v2-hide', event.target.value !== 'REPRESENTATIVE_DESCENDANT');
        }
        syncConditionalSections();
    }
});
document.getElementById('assetRows').addEventListener('change', (event) => {
    if (event.target.matches('select[name^="assets["], input[name^="assets["]')) {
        captureAssetRowsData();
        syncConditionalSections();
    }
});

['marital_status', 'spouse_status', 'children_status', 'acting_on_behalf', 'will_exists', 'claimant_relationship'].forEach(id => {
    const node = document.getElementById(id);
    if (node) {
        node.addEventListener('change', syncConditionalSections);
    }
});
const payoutMethodNode = document.getElementById('preferred_payout_method');
if (payoutMethodNode) {
    payoutMethodNode.addEventListener('change', () => {
        window.payoutDetailsData = {};
        captureAssetRowsData();
        syncConditionalSections();
        clearValidationState();
    });
}

renderChildRows();
renderHeirRows();
renderAssetRows();
syncConditionalSections();
applyCorrectionLocks();

const payoutDetailsRoot = document.getElementById('payoutDetailsFields');
if (payoutDetailsRoot) {
    payoutDetailsRoot.addEventListener('input', (event) => {
        const target = event.target;
        if (!target || !target.classList.contains('payout-detail-input')) {
            return;
        }
        const field = target.closest('.claim-v2-field');
        if (field && String(target.value || '').trim() !== '') {
            field.classList.remove('is-invalid');
            target.removeAttribute('aria-invalid');
        }
        window.payoutDetailsData = collectPayoutDetailsPayload();
        syncPayoutDetailsPayload();
    });
    payoutDetailsRoot.addEventListener('change', (event) => {
        const target = event.target;
        if (!target || !target.classList.contains('payout-detail-input')) {
            return;
        }
        window.payoutDetailsData = collectPayoutDetailsPayload();
        syncPayoutDetailsPayload();
    });
}

const claimV2Form = document.getElementById('claimV2Form');
const validationSummary = document.getElementById('claimV2ValidationSummary');

if (claimV2Form) {
    claimV2Form.addEventListener('input', clearValidationState);
    claimV2Form.addEventListener('change', clearValidationState);
    claimV2Form.addEventListener('submit', (event) => {
        clearValidationState();
        let firstInvalid = null;

        function invalidate(input, type = 'field') {
            if (!firstInvalid) {
                firstInvalid = input;
            }
            if (type === 'doc') {
                markInvalidDoc(input);
            } else {
                markInvalidField(input);
            }
        }

        function requireValue(selector, extraCheck = null) {
            const input = document.querySelector(selector);
            if (!isElementInteractive(input)) {
                return;
            }
            const valid = typeof extraCheck === 'function'
                ? extraCheck(input)
                : String(input.value || '').trim() !== '';
            if (!valid) {
                invalidate(input);
            }
        }

        requireValue('#deceased_full_name');
        requireValue('#deceased_id_number');
        requireValue('#date_of_death', (input) => {
            const value = String(input.value || '').trim();
            const max = String(input.getAttribute('max') || '').trim();
            return value !== '' && (max === '' || value <= max);
        });
        requireValue('#marital_status');
        requireValue('#children_status');
        requireValue('#claimant_relationship');
        requireValue('#acting_on_behalf');

        const maritalStatus = document.getElementById('marital_status')?.value || '';
        const spouseStatus = document.getElementById('spouse_status')?.value || '';
        const claimantRelationship = document.getElementById('claimant_relationship')?.value || '';
        if (maritalStatus === 'MARRIED') {
            requireValue('#spouse_status', (input) => ['ALIVE', 'DECEASED'].includes(String(input.value || '')));
        }
        if (claimantRelationship === 'SPOUSE') {
            requireValue('#spouse_status', (input) => String(input.value || '') === 'ALIVE');
        }
        if (isElementInteractive(document.getElementById('spouse_full_name')) && spouseStatus !== 'NOT_APPLICABLE') {
            requireValue('#spouse_full_name');
        }

        const visibleAssetInputs = Array.from(document.querySelectorAll('select[name^="assets["][name$="[asset_class]"]')).filter(isElementInteractive);
        const visibleCurrencyInputs = Array.from(document.querySelectorAll('select[name^="assets["][name$="[currency_code]"]')).filter(isElementInteractive);
        const hasSelectedAssets = visibleAssetInputs.some((input) => String(input.value || '').trim() !== '');
        if (!hasSelectedAssets) {
            visibleAssetInputs.forEach((input) => invalidate(input));
        } else {
            visibleCurrencyInputs.forEach((input) => {
                if (String(input.value || '').trim() === '') {
                    invalidate(input);
                }
            });
            Array.from(document.querySelectorAll('#assetRows .claim-v2-row-card')).forEach((card) => {
                const currencyInput = card.querySelector('select[name^="assets["][name$="[currency_code]"]');
                const estimateInput = card.querySelector('input[name^="assets["][name$="[estimated_value]"]');
                if (!isElementInteractive(estimateInput)) {
                    return;
                }
                if (!amountMatchesCurrency(estimateInput.value, currencyInput?.value || 'RWF')) {
                    invalidate(estimateInput);
                }
            });
            requireValue('#preferred_payout_method');
            const payoutMethod = String(document.getElementById('preferred_payout_method')?.value || '').trim();
            if (payoutMethod !== '') {
                const detailFields = Array.from(document.querySelectorAll('#payoutDetailsFields .payout-detail-input')).filter(isElementInteractive);
                detailFields.forEach((input) => {
                    if (input.getAttribute('data-payout-required') === '1' && String(input.value || '').trim() === '') {
                        invalidate(input);
                    }
                });
                syncPayoutDetailsPayload();
            }
        }

        const childStatus = document.getElementById('children_status')?.value || '';
        if (claimantRelationship === 'CHILD' && childStatus !== 'HAS_CHILDREN') {
            requireValue('#children_status', (input) => String(input.value || '') === 'HAS_CHILDREN');
        }
        if (childStatus === 'HAS_CHILDREN') {
            const childNameInputs = Array.from(document.querySelectorAll('input[name^="children["][name$="[full_name]"]')).filter(isElementInteractive);
            if (!childNameInputs.some((input) => String(input.value || '').trim() !== '')) {
                if (childNameInputs[0]) {
                    invalidate(childNameInputs[0]);
                }
            }
        }

        const requiredDocSelectors = [
            ['input[name="deceased_death_certificate"]', 'deceased_death_certificate'],
            ['input[name="claimant_id_document"]', 'claimant_id'],
        ];
        if (relationshipRequiresSupportingCertificate(claimantRelationship)) {
            requiredDocSelectors.push(['input[name="relationship_proof_document"]', 'relationship_proof']);
        }
        if (document.querySelector('.spouse-doc input') && document.querySelector('.spouse-doc input').offsetParent !== null) {
            requiredDocSelectors.push(['input[name="marriage_certificate"]', 'marriage_certificate']);
        }
        if (document.querySelector('.spouse-alive-doc input') && document.querySelector('.spouse-alive-doc input').offsetParent !== null) {
            requiredDocSelectors.push(['input[name="spouse_id_document"]', 'spouse_id']);
        }
        if (document.querySelector('.will-doc input') && document.querySelector('.will-doc input').offsetParent !== null) {
            requiredDocSelectors.push(['input[name="will_copy_document"]', 'will_copy']);
        }
        if (document.querySelector('.representative-doc input') && document.querySelector('.representative-doc input').offsetParent !== null) {
            requiredDocSelectors.push(['input[name="representative_authority_document"]', 'representative_authority']);
        }

        requiredDocSelectors.forEach(([selector, docType]) => {
            const input = document.querySelector(selector);
            if (!isElementInteractive(input)) {
                return;
            }
            if (Number(existingDocumentCounts?.[docType] || 0) > 0 && correctionMode) {
                return;
            }
            if (!input.files || input.files.length === 0) {
                invalidate(input, 'doc');
            }
        });

        const singleStatusInput = document.querySelector('input[name="single_status_evidence"]');
        const singleStatusFallbackInput = document.querySelector('input[name="single_status_fallback_evidence"]');
        if (maritalStatus === 'SINGLE' && singleStatusInput && isElementInteractive(singleStatusInput)) {
            const hasFormal = singleStatusInput.files && singleStatusInput.files.length > 0;
            const hasFallback = singleStatusFallbackInput && singleStatusFallbackInput.files && singleStatusFallbackInput.files.length > 0;
            const hasExistingFormal = Number(existingDocumentCounts?.single_status_evidence || 0) > 0 && correctionMode;
            const hasExistingFallback = Number(existingDocumentCounts?.single_status_fallback_evidence || 0) > 0 && correctionMode;
            if (!hasFormal && !hasFallback && !hasExistingFormal && !hasExistingFallback) {
                invalidate(singleStatusInput, 'doc');
            }
        }

        const spouseDeathInput = document.querySelector('input[name="spouse_death_certificate"]');
        const spouseFallbackInput = document.querySelector('input[name="spouse_secondary_death_evidence"]');
        if (spouseDeathInput && isElementInteractive(spouseDeathInput)) {
            const hasStandard = spouseDeathInput.files && spouseDeathInput.files.length > 0;
            const hasFallback = spouseFallbackInput && spouseFallbackInput.files && spouseFallbackInput.files.length > 0;
            const hasExistingStandard = Number(existingDocumentCounts?.spouse_death_certificate || 0) > 0 && correctionMode;
            const hasExistingFallback = Number(existingDocumentCounts?.spouse_secondary_death_evidence || 0) > 0 && correctionMode;
            if (!hasStandard && !hasFallback && !hasExistingStandard && !hasExistingFallback) {
                invalidate(spouseDeathInput, 'doc');
            }
        }

        if (childStatus === 'HAS_CHILDREN') {
            let remainingChildDocs = Number(existingDocumentCounts?.child_birth_certificate || 0);
            Array.from(document.querySelectorAll('input[name^="child_birth_certificate["]')).filter(isElementInteractive).forEach((input) => {
                if (correctionMode && remainingChildDocs > 0) {
                    remainingChildDocs--;
                    return;
                }
                if (!input.files || input.files.length === 0) {
                    invalidate(input);
                }
            });
        }

        if (firstInvalid) {
            event.preventDefault();
            if (validationSummary) {
                validationSummary.classList.remove('claim-v2-hide');
            }
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.focus({ preventScroll: true });
        }
    });
}
</script>
</body>
</html>

