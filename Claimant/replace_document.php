<?php
// Tags: [CLAIMANT] [DOCS] [OCR]
include '../connect.php';
require_once '../security.php';
secure_session_start();
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';
require_once dirname(__DIR__) . '/components/claim_documents.php';

header('Content-Type: application/json');

if (!defined('TESSERACT_PATH')) {
    define('TESSERACT_PATH', '"C:\\Program Files\\Tesseract-OCR\\tesseract.exe"');
}

function replace_doc_json(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit();
}

function normalize_ocr_text(string $text): string
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', (string) $text);
    return trim((string) $text);
}

function count_keyword_matches(string $text, array $keywords): int
{
    $normalizedText = normalize_ocr_text($text);
    $matches = [];

    foreach ($keywords as $keyword) {
        $normalizedKeyword = normalize_ocr_text((string) $keyword);
        if ($normalizedKeyword === '') {
            continue;
        }
        if (strpos($normalizedText, $normalizedKeyword) !== false) {
            $matches[$normalizedKeyword] = true;
        }
    }

    return count($matches);
}

function significant_name_parts(string $fullName): array
{
    $normalized = normalize_ocr_text($fullName);
    $parts = explode(' ', $normalized);
    $parts = array_filter($parts, static fn ($part) => strlen((string) $part) >= 3);
    return array_values($parts);
}

function has_any_name_token_match(string $fullName, string $text): bool
{
    $parts = significant_name_parts($fullName);
    if (empty($parts)) {
        return false;
    }

    $normalizedText = normalize_ocr_text($text);
    foreach ($parts as $part) {
        if (strpos($normalizedText, $part) !== false) {
            return true;
        }
    }

    return false;
}

function has_date_match(string $rawText, string $dateInput): bool
{
    $dateInput = trim($dateInput);
    if ($dateInput === '') {
        return true;
    }

    $timestamp = strtotime($dateInput);
    if ($timestamp === false) {
        return false;
    }

    $dateFragments = [
        date('Y-m-d', $timestamp),
        date('d/m/Y', $timestamp),
        date('d-m-Y', $timestamp),
        date('d.m.Y', $timestamp),
        date('j/n/Y', $timestamp),
        date('j-n-Y', $timestamp),
        date('j n Y', $timestamp),
    ];

    $normalizedText = normalize_ocr_text($rawText);
    foreach ($dateFragments as $fragment) {
        $normFragment = normalize_ocr_text($fragment);
        if ($normFragment !== '' && strpos($normalizedText, $normFragment) !== false) {
            return true;
        }
    }

    return false;
}

function has_rwanda_national_id_number(string $rawText): bool
{
    $rawText = strtolower($rawText);
    if (preg_match('/\b\d{16}\b/', $rawText) === 1) {
        return true;
    }
    return preg_match('/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/', $rawText) === 1;
}

function has_rwanda_passport_number(string $rawText): bool
{
    return preg_match('/\b[a-z]{1,2}\d{6,8}\b/', strtolower($rawText)) === 1;
}

function extract_text_from_image(string $imagePath, string $tempDir): string
{
    $outputBase = rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR . uniqid('ocr_', true);
    $extractedText = '';

    foreach (['eng+fra', 'eng'] as $langProfile) {
        $cmd = TESSERACT_PATH . ' ' . escapeshellarg($imagePath) . ' ' . escapeshellarg($outputBase) . " -l {$langProfile} --psm 6";
        $output = [];
        $returnVar = 0;
        exec($cmd . ' 2>&1', $output, $returnVar);

        $txtFile = $outputBase . '.txt';
        if (file_exists($txtFile)) {
            $extractedText = (string) file_get_contents($txtFile);
            unlink($txtFile);
            if (trim($extractedText) !== '') {
                break;
            }
        }
    }

    return strtolower(trim($extractedText));
}

function validate_ocr_document(string $imagePath, string $documentType, array $ctx, string $tempDir): array
{
    $deathKeywords = [
        'death certificate', 'certificate of death', 'acte de deces', 'deces', 'urupfu',
        'civil status', 'etat civil', 'date of death', 'date de deces', 'republic of rwanda',
        'nida', 'registration no', 'certificate no',
    ];

    $relationshipKeywords = [
        'relationship_proof' => ['relationship', 'certificate', 'proof', 'civil status', 'etat civil', 'republic of rwanda'],
        'birth_certificate' => ['birth certificate', 'certificate of birth', 'acte de naissance', 'birth', 'parent', 'child', 'civil status'],
        'child_family_resolution' => ['executive secretary', 'cell', 'meeting', 'family meeting', 'minutes', 'resolution', 'succession', 'heirs', 'child'],
        'parent_family_resolution' => ['executive secretary', 'cell', 'meeting', 'family meeting', 'minutes', 'resolution', 'succession', 'heirs', 'parent'],
        'sibling_family_resolution' => ['executive secretary', 'cell', 'meeting', 'family meeting', 'minutes', 'resolution', 'succession', 'heirs', 'siblings'],
        'grandparent_family_resolution' => ['executive secretary', 'cell', 'meeting', 'family meeting', 'minutes', 'resolution', 'succession', 'heirs', 'grandparent'],
        'uncle_family_resolution' => ['executive secretary', 'cell', 'meeting', 'family meeting', 'minutes', 'resolution', 'succession', 'heirs', 'uncle'],
        'aunt_family_resolution' => ['executive secretary', 'cell', 'meeting', 'family meeting', 'minutes', 'resolution', 'succession', 'heirs', 'aunt'],
    ];

    $idKeywords = [
        'indangamuntu', 'national identity card', 'national id', 'identity card',
        'republic of rwanda', 'passport', 'passeport',
    ];

    $marriageKeywords = [
        'marriage certificate', 'certificate of marriage', 'acte de mariage', 'marriage',
        'civil status', 'etat civil', 'republic of rwanda', 'nida', 'district', 'sector',
        'spouse', 'husband', 'wife', 'epoux', 'epouse',
    ];

    $fullIdentityKeywords = [
        'certificate of full identity', 'full identity certificate', 'attestation d identite complete',
        'attestation d\'identite complete', 'identite complete', 'civil status', 'etat civil',
        'republic of rwanda', 'nida', 'district', 'sector', 'cell', 'village', 'family', 'lineage',
    ];

    $rawText = extract_text_from_image($imagePath, $tempDir);
    if ($rawText === '') {
        return ['valid' => false, 'message' => 'OCR could not read this document clearly. Upload a clearer JPG or PNG image.'];
    }

    if ($documentType === 'death_certificate') {
        $docMatches = count_keyword_matches($rawText, array_merge($deathKeywords, ['deceased']));
        if ($docMatches < 2) {
            return ['valid' => false, 'message' => 'Death-certificate keywords were not detected.'];
        }
        if (!has_any_name_token_match((string) ($ctx['deceased_name'] ?? ''), $rawText)) {
            return ['valid' => false, 'message' => 'Deceased name was not detected in the death certificate.'];
        }
        if (!has_date_match($rawText, (string) ($ctx['deceased_date'] ?? ''))) {
            return ['valid' => false, 'message' => 'Date of death was not detected in the uploaded document.'];
        }
        return ['valid' => true, 'message' => 'Death certificate OCR verification passed.'];
    }

    if (in_array($documentType, [
        'relationship_proof',
        'birth_certificate',
        'child_family_resolution',
        'parent_family_resolution',
        'sibling_family_resolution',
        'grandparent_family_resolution',
        'uncle_family_resolution',
        'aunt_family_resolution',
    ], true)) {
        $keywords = $relationshipKeywords[$documentType] ?? ($relationshipKeywords['relationship_proof'] ?? []);
        if (count_keyword_matches($rawText, $keywords) < 2) {
            return ['valid' => false, 'message' => 'Relationship-document keywords were not detected.'];
        }
        $hasClaimant = has_any_name_token_match((string) ($ctx['claimant_name'] ?? ''), $rawText);
        $hasDeceased = has_any_name_token_match((string) ($ctx['deceased_name'] ?? ''), $rawText);
        if (!$hasClaimant && !$hasDeceased) {
            return ['valid' => false, 'message' => 'Relationship document must include claimant or deceased name details.'];
        }
        return ['valid' => true, 'message' => 'Relationship document OCR verification passed.'];
    }

    if ($documentType === 'id_document') {
        $idMarkerMatches = count_keyword_matches($rawText, array_merge($idKeywords, ['id number', 'document number', 'card number']));
        if ($idMarkerMatches < 1) {
            return ['valid' => false, 'message' => 'ID markers were not detected.'];
        }

        $hasNationalId = has_rwanda_national_id_number($rawText);
        $hasPassportMarker = count_keyword_matches($rawText, ['passport', 'passeport']) > 0;
        $hasPassportNumber = has_rwanda_passport_number($rawText);
        $hasGenericIdNumber = count_keyword_matches($rawText, ['id number', 'document number', 'card number']) > 0;
        if (!$hasNationalId && !($hasPassportMarker && $hasPassportNumber) && !$hasGenericIdNumber) {
            return ['valid' => false, 'message' => 'A valid ID/passport number was not detected.'];
        }

        if (!has_any_name_token_match((string) ($ctx['claimant_name'] ?? ''), $rawText)) {
            return ['valid' => false, 'message' => 'Claimant name was not detected in the uploaded ID document.'];
        }

        return ['valid' => true, 'message' => 'ID document OCR verification passed.'];
    }

    if ($documentType === 'marriage_certificate') {
        if (count_keyword_matches($rawText, array_merge($marriageKeywords, ['epoux', 'epouse'])) < 2) {
            return ['valid' => false, 'message' => 'Marriage-certificate keywords were not detected.'];
        }
        $hasClaimant = has_any_name_token_match((string) ($ctx['claimant_name'] ?? ''), $rawText);
        $hasDeceased = has_any_name_token_match((string) ($ctx['deceased_name'] ?? ''), $rawText);
        if (!$hasClaimant || !$hasDeceased) {
            return ['valid' => false, 'message' => 'Marriage certificate must include claimant and deceased names.'];
        }
        return ['valid' => true, 'message' => 'Marriage certificate OCR verification passed.'];
    }

    if ($documentType === 'full_identity_certificate') {
        if (count_keyword_matches($rawText, $fullIdentityKeywords) < 2) {
            return ['valid' => false, 'message' => 'Certificate-of-full-identity keywords were not detected.'];
        }
        $hasClaimant = has_any_name_token_match((string) ($ctx['claimant_name'] ?? ''), $rawText);
        $hasDeceased = has_any_name_token_match((string) ($ctx['deceased_name'] ?? ''), $rawText);
        if (!$hasClaimant && !$hasDeceased) {
            return ['valid' => false, 'message' => 'Full identity certificate must include claimant or deceased name details.'];
        }
        return ['valid' => true, 'message' => 'Certificate of full identity OCR verification passed.'];
    }

    return ['valid' => true, 'message' => 'Document replacement validated.'];
}

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'claimant') {
    replace_doc_json(false, 'Unauthorized');
}

$user_email = trim((string) ($_SESSION['email'] ?? ''));
$claimant_id = udcs_db_fetch_user_id_by_email_role($conn, $user_email, 'claimant');
if ($claimant_id <= 0) {
    replace_doc_json(false, 'User not found');
}

$claim_id = (int) ($_POST['claim_id'] ?? 0);
$document_id = (int) ($_POST['document_id'] ?? 0);
if ($claim_id <= 0 || $document_id <= 0) {
    replace_doc_json(false, 'Invalid request');
}

$claimStmt = mysqli_prepare(
    $conn,
    'SELECT c.id, COALESCE(NULLIF(c.deceased_full_name, \'\'), c.deceased_name) AS deceased_name, COALESCE(c.date_of_death, c.deceased_date) AS deceased_date, c.relationship, u.full_name AS claimant_name
     FROM claims c
     INNER JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
     WHERE c.id = ? AND COALESCE(c.claimant_user_id, c.claimant_id) = ?
     LIMIT 1'
);
$claimRow = null;
if ($claimStmt) {
    mysqli_stmt_bind_param($claimStmt, 'ii', $claim_id, $claimant_id);
    if (mysqli_stmt_execute($claimStmt)) {
        $claimQuery = mysqli_stmt_get_result($claimStmt);
        $claimRow = $claimQuery ? mysqli_fetch_assoc($claimQuery) : null;
    }
}
if (!$claimRow) {
    replace_doc_json(false, 'Access denied');
}

$docStmt = mysqli_prepare(
    $conn,
    'SELECT id, file_path, document_type
     FROM documents
     WHERE id = ? AND claim_id = ?
     LIMIT 1'
);
$existingDoc = null;
if ($docStmt) {
    mysqli_stmt_bind_param($docStmt, 'ii', $document_id, $claim_id);
    if (mysqli_stmt_execute($docStmt)) {
        $docCheck = mysqli_stmt_get_result($docStmt);
        $existingDoc = $docCheck ? mysqli_fetch_assoc($docCheck) : null;
    }
}
if (!$existingDoc) {
    replace_doc_json(false, 'Document not found');
}
$document_type = strtolower(trim((string) ($existingDoc['document_type'] ?? '')));
if ($document_type === '') {
    replace_doc_json(false, 'Document type could not be resolved');
}

if (!isset($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    replace_doc_json(false, 'No file uploaded');
}
$file = $_FILES['file'];
$requiresOcr = udcs_claim_document_requires_ocr($document_type);
$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $claim_id;
udcs_claim_documents_protect_directory($uploadDir);
if (!is_dir($uploadDir)) {
    replace_doc_json(false, 'Upload directory is not available.');
}
$uploadError = null;
$stagedUpload = udcs_claim_stage_uploaded_file($file, $document_type, $document_type, $uploadError);
if ($stagedUpload === null) {
    replace_doc_json(false, $uploadError ?: 'Upload failed. Please try again.');
}

$ocrMessage = '';
if ($requiresOcr) {
    $tempDir = __DIR__ . '/temp_ocr/';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0775, true);
    }

    $validationContext = [
        'deceased_name' => (string) ($claimRow['deceased_name'] ?? ''),
        'deceased_date' => (string) ($claimRow['deceased_date'] ?? ''),
        'claimant_name' => (string) ($claimRow['claimant_name'] ?? ''),
    ];

    $ocrResult = validate_ocr_document((string) ($stagedUpload['stage_path'] ?? ''), $document_type, $validationContext, $tempDir);
    if (!($ocrResult['valid'] ?? false)) {
        udcs_claim_discard_staged_upload($stagedUpload);
        replace_doc_json(false, (string) ($ocrResult['message'] ?? 'Document failed OCR verification.'));
    }

    $ocrMessage = (string) ($ocrResult['message'] ?? 'OCR verification passed.');
}

$finalizeError = null;
$targetPath = udcs_claim_finalize_staged_upload($stagedUpload, $uploadDir, $document_type, $finalizeError);
if ($targetPath === null) {
    udcs_claim_discard_staged_upload($stagedUpload);
    replace_doc_json(false, $finalizeError ?: 'Uploaded file could not be finalized.');
}
$dbPath = udcs_claim_document_relative_storage_path($targetPath);

mysqli_begin_transaction($conn);
try {
    $updateStmt = mysqli_prepare(
        $conn,
        'UPDATE documents
         SET file_path = ?, uploaded_at = NOW(), updated_at = NOW()
         WHERE id = ? AND claim_id = ?
         LIMIT 1'
    );
    if (!$updateStmt) {
        throw new RuntimeException('Database update failed.');
    }
    mysqli_stmt_bind_param($updateStmt, 'sii', $dbPath, $document_id, $claim_id);
    if (!mysqli_stmt_execute($updateStmt)) {
        mysqli_stmt_close($updateStmt);
        throw new RuntimeException('Database update failed.');
    }
    mysqli_stmt_close($updateStmt);
    mysqli_commit($conn);
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    udcs_claim_delete_upload_file($targetPath);
    udcs_claim_discard_staged_upload($stagedUpload);
    replace_doc_json(false, 'Database update failed. Please try again.');
}

$oldPathRaw = trim((string) ($existingDoc['file_path'] ?? ''));
if ($oldPathRaw !== '') {
    udcs_claim_delete_upload_file($oldPathRaw);
}

$notif_msg = $requiresOcr
    ? 'You replaced a claim document and it passed OCR verification.'
    : 'You replaced a document for your claim.';
udcs_db_insert_notification($conn, (string) $claimant_id, (string) $claimant_id, $notif_msg);

bk_activity_log($conn, [
    'actor_id' => $claimant_id,
    'actor_role' => 'claimant',
    'claim_id' => $claim_id,
    'action_key' => 'claimant_document_replaced',
    'action_label' => 'Claimant Replaced Document',
    'details' => 'Claim document replaced by claimant.',
    'meta' => [
        'document_id' => $document_id,
        'document_type' => $document_type,
        'requires_ocr' => $requiresOcr,
    ],
]);

$successMessage = $requiresOcr
    ? 'Document replaced successfully. OCR verification passed.'
    : 'Document replaced successfully.';
replace_doc_json(true, $successMessage, [
    'new_path' => $dbPath,
    'ocr_checked' => $requiresOcr,
    'ocr_message' => $ocrMessage,
]);

