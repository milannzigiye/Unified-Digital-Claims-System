<?php
declare(strict_types=1);

require_once __DIR__ . '/../security.php';

if (!function_exists('udcs_claim_documents_protect_directory')) {
    function udcs_claim_documents_protect_directory(string $directory): void
    {
        $trimmed = trim($directory);
        if ($trimmed === '') {
            return;
        }

        if (!is_dir($trimmed)) {
            @mkdir($trimmed, 0775, true);
        }
        if (!is_dir($trimmed)) {
            return;
        }

        $htaccessPath = rtrim($trimmed, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccessPath)) {
            $rules = <<<HTACCESS
Options -Indexes
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>

HTACCESS;
            @file_put_contents($htaccessPath, $rules, LOCK_EX);
        }

        $indexPath = rtrim($trimmed, '/\\') . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($indexPath)) {
            @file_put_contents($indexPath, '', LOCK_EX);
        }
    }
}

if (!function_exists('udcs_claim_document_profiles')) {
    function udcs_claim_document_profiles(): array
    {
        $imageOnlyOcr = [
            'ocr_required' => true,
            'allowed_mime_types' => ['image/jpeg', 'image/png'],
            'allowed_extensions' => ['jpg', 'jpeg', 'png'],
            'max_size' => 10 * 1024 * 1024,
        ];

        $imageOrPdf = [
            'ocr_required' => false,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
            'max_size' => 10 * 1024 * 1024,
        ];

        return [
            'deceased_death_certificate' => $imageOnlyOcr,
            'claimant_id' => $imageOnlyOcr,
            'relationship_proof' => $imageOnlyOcr,
            'single_status_evidence' => $imageOnlyOcr,
            'single_status_fallback_evidence' => $imageOnlyOcr,
            'marriage_certificate' => $imageOnlyOcr,
            'spouse_id' => $imageOnlyOcr,
            'spouse_death_certificate' => $imageOnlyOcr,
            'spouse_secondary_death_evidence' => $imageOnlyOcr,
            'child_birth_certificate' => $imageOnlyOcr,
            'child_id' => $imageOnlyOcr,
            'representative_authority' => $imageOnlyOcr,
            'representative_descendant_linkage' => $imageOnlyOcr,
            'represented_heir_id' => $imageOnlyOcr,
            'will_copy' => $imageOnlyOcr,
            'local_authority_attestation' => $imageOnlyOcr,
            'family_resolution' => $imageOnlyOcr,
            'secondary_relationship_evidence' => $imageOnlyOcr,
            'additional_support' => $imageOnlyOcr,
            'death_certificate' => $imageOnlyOcr,
            'id_document' => $imageOnlyOcr,
            'birth_certificate' => $imageOnlyOcr,
            'child_family_resolution' => $imageOnlyOcr,
            'parent_family_resolution' => $imageOnlyOcr,
            'sibling_family_resolution' => $imageOnlyOcr,
            'grandparent_family_resolution' => $imageOnlyOcr,
            'uncle_family_resolution' => $imageOnlyOcr,
            'aunt_family_resolution' => $imageOnlyOcr,
            'full_identity_certificate' => $imageOnlyOcr,
            'legacy_support_document' => $imageOrPdf,
        ];
    }
}

if (!function_exists('udcs_claim_document_profile')) {
    function udcs_claim_document_profile(string $documentType): array
    {
        $key = strtolower(trim($documentType));
        $profiles = udcs_claim_document_profiles();
        return $profiles[$key] ?? [
            'ocr_required' => false,
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
            'max_size' => 10 * 1024 * 1024,
        ];
    }
}

if (!function_exists('udcs_claim_document_requires_ocr')) {
    function udcs_claim_document_requires_ocr(string $documentType): bool
    {
        return !empty(udcs_claim_document_profile($documentType)['ocr_required']);
    }
}

if (!function_exists('udcs_claim_document_detect_mime')) {
    function udcs_claim_document_detect_mime(string $tmpPath): string
    {
        $path = trim($tmpPath);
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }

        $mime = (string) finfo_file($finfo, $path);
        finfo_close($finfo);
        return strtolower(trim($mime));
    }
}

if (!function_exists('udcs_claim_document_upload_error_message')) {
    function udcs_claim_document_upload_error_message(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large. Maximum allowed size is 10 MB.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Upload failed because the server could not store the file.',
            default => 'Upload failed. Please try again.',
        };
    }
}

if (!function_exists('udcs_claim_validate_uploaded_file')) {
    function udcs_claim_validate_uploaded_file(array $file, string $documentType, ?string &$errorMessage = null): ?array
    {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            $errorMessage = udcs_claim_document_upload_error_message($errorCode);
            return null;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $errorMessage = 'Upload source could not be verified.';
            return null;
        }

        $profile = udcs_claim_document_profile($documentType);
        $size = (int) ($file['size'] ?? 0);
        $maxSize = (int) ($profile['max_size'] ?? (10 * 1024 * 1024));
        if ($size <= 0 || $size > $maxSize) {
            $errorMessage = 'File too large. Maximum allowed size is 10 MB.';
            return null;
        }

        $mimeType = udcs_claim_document_detect_mime($tmpName);
        $allowedMimeTypes = array_map('strtolower', (array) ($profile['allowed_mime_types'] ?? []));
        if ($mimeType === '' || !in_array($mimeType, $allowedMimeTypes, true)) {
            $errorMessage = !empty($profile['ocr_required'])
                ? 'This document requires OCR verification. Please upload a clear JPG or PNG image.'
                : 'Invalid file type. Please upload a PDF, JPG, or PNG file.';
            return null;
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ];
        if ($extension === '') {
            $extension = $mimeToExt[$mimeType] ?? '';
        }
        $allowedExtensions = array_map('strtolower', (array) ($profile['allowed_extensions'] ?? []));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            $errorMessage = 'File extension does not match the allowed document format.';
            return null;
        }

        return [
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $size,
            'ocr_required' => !empty($profile['ocr_required']),
        ];
    }
}

if (!function_exists('udcs_claim_documents_temp_directory')) {
    function udcs_claim_documents_temp_directory(): string
    {
        $root = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'udcs_private' . DIRECTORY_SEPARATOR . 'claim_upload_staging';
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        return $root;
    }
}

if (!function_exists('udcs_claim_stage_uploaded_file')) {
    function udcs_claim_stage_uploaded_file(array $file, string $documentType, string $prefix, ?string &$errorMessage = null): ?array
    {
        $validation = udcs_claim_validate_uploaded_file($file, $documentType, $errorMessage);
        if ($validation === null) {
            return null;
        }

        $stageDir = udcs_claim_documents_temp_directory();
        if (!is_dir($stageDir)) {
            $errorMessage = 'Upload staging directory is not available.';
            return null;
        }

        $safePrefix = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower(trim($prefix))) ?: 'document';
        try {
            $token = bin2hex(random_bytes(8));
        } catch (Throwable $exception) {
            $token = uniqid('', true);
        }
        $stageName = $safePrefix . '_' . $token . '.' . $validation['extension'];
        $stagePath = rtrim($stageDir, '/\\') . DIRECTORY_SEPARATOR . $stageName;

        if (!@move_uploaded_file((string) ($file['tmp_name'] ?? ''), $stagePath)) {
            $errorMessage = 'Upload failed. Please try again.';
            return null;
        }

        return [
            'stage_path' => $stagePath,
            'extension' => (string) $validation['extension'],
            'mime_type' => (string) $validation['mime_type'],
            'size' => (int) $validation['size'],
            'ocr_required' => (bool) $validation['ocr_required'],
            'original_name' => (string) ($file['name'] ?? ''),
        ];
    }
}

if (!function_exists('udcs_claim_finalize_staged_upload')) {
    function udcs_claim_finalize_staged_upload(array $stagedUpload, string $directory, string $prefix, ?string &$errorMessage = null): ?string
    {
        $stagePath = trim((string) ($stagedUpload['stage_path'] ?? ''));
        $extension = strtolower(trim((string) ($stagedUpload['extension'] ?? '')));
        if ($stagePath === '' || $extension === '' || !is_file($stagePath)) {
            $errorMessage = 'Staged file is missing.';
            return null;
        }

        udcs_claim_documents_protect_directory($directory);
        if (!is_dir($directory)) {
            $errorMessage = 'Upload directory is not available.';
            return null;
        }

        $safePrefix = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower(trim($prefix))) ?: 'document';
        $targetName = $safePrefix . '_' . uniqid('', true) . '.' . $extension;
        $targetPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $targetName;

        if (!@rename($stagePath, $targetPath)) {
            if (!@copy($stagePath, $targetPath)) {
                $errorMessage = 'Uploaded file could not be finalized.';
                return null;
            }
            @unlink($stagePath);
        }

        return $targetPath;
    }
}

if (!function_exists('udcs_claim_discard_staged_upload')) {
    function udcs_claim_discard_staged_upload(array|string|null $stagedUpload): void
    {
        $stagePath = is_array($stagedUpload)
            ? trim((string) ($stagedUpload['stage_path'] ?? ''))
            : trim((string) $stagedUpload);
        if ($stagePath !== '' && is_file($stagePath)) {
            @unlink($stagePath);
        }
    }
}

if (!function_exists('udcs_claim_document_relative_storage_path')) {
    function udcs_claim_document_relative_storage_path(string $absolutePath): string
    {
        $resolvedPath = realpath($absolutePath);
        $projectRoot = realpath(dirname(__DIR__));
        if ($resolvedPath === false || $projectRoot === false) {
            return trim($absolutePath);
        }

        $normalizedProject = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $resolvedPath);
        if (str_starts_with($normalizedPath, $normalizedProject)) {
            return ltrim(substr($normalizedPath, strlen($normalizedProject)), '/');
        }

        return trim($absolutePath);
    }
}

if (!function_exists('udcs_claim_document_fetch_with_claim')) {
    function udcs_claim_document_fetch_with_claim(mysqli $conn, int $documentId): ?array
    {
        $documentId = (int) $documentId;
        if ($documentId <= 0) {
            return null;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                d.id,
                d.claim_id,
                d.document_type,
                d.file_path,
                c.claimant_id,
                c.claimant_user_id,
                c.assigned_legal_id,
                c.assigned_finance_id
             FROM documents d
             INNER JOIN claims c ON c.id = d.claim_id
             WHERE d.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, 'i', $documentId);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return null;
        }

        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        if (!$result || mysqli_num_rows($result) !== 1) {
            return null;
        }

        $row = mysqli_fetch_assoc($result);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('udcs_claim_document_user_can_access')) {
    function udcs_claim_document_user_can_access(array $documentRow, string $role, int $userId): bool
    {
        $roleKey = strtolower(trim($role));
        $userId = (int) $userId;
        if ($roleKey === '' || $userId <= 0) {
            return false;
        }

        return match ($roleKey) {
            'admin' => true,
            'claimant' => (int) ($documentRow['claimant_user_id'] ?? $documentRow['claimant_id'] ?? 0) === $userId,
            'legal' => (int) ($documentRow['assigned_legal_id'] ?? 0) === $userId,
            'finance' => (int) ($documentRow['assigned_finance_id'] ?? 0) === $userId,
            default => false,
        };
    }
}

if (!function_exists('udcs_claim_document_resolve_path')) {
    function udcs_claim_document_resolve_path(string $storedPath): ?string
    {
        $raw = trim($storedPath);
        if ($raw === '') {
            return null;
        }

        $projectRoot = realpath(dirname(__DIR__));
        $uploadsRoot = $projectRoot ? realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads') : false;
        if (!$projectRoot || !$uploadsRoot) {
            return null;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $raw);
        $strippedRelative = preg_replace('#^(?:\.\.[/\\\\]+)+#', '', $raw);
        $strippedRelative = is_string($strippedRelative) ? $strippedRelative : $raw;
        $strippedRelative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $strippedRelative), '\\/');

        $candidates = [];
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $raw) === 1 || str_starts_with($raw, '/')) {
            $candidates[] = $raw;
        }
        $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . ltrim($normalized, '\\/');
        if ($strippedRelative !== '') {
            $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . $strippedRelative;
        }

        $uploadsPrefix = rtrim(strtolower($uploadsRoot), '\\/') . DIRECTORY_SEPARATOR;
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if (!$resolved || !is_file($resolved)) {
                continue;
            }

            $resolvedLower = strtolower($resolved);
            if ($resolvedLower === strtolower($uploadsRoot) || str_starts_with($resolvedLower, $uploadsPrefix)) {
                return $resolved;
            }
        }

        return null;
    }
}
