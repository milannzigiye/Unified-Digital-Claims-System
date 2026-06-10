<?php
// Tags: [CLAIMS_V2] [SCHEMA] [PEOPLE] [ASSETS] [STATUS] [LEGACY]
// [CLAIMS_V2] Shared schema + helpers for the redesigned deceased-assets workflow.
require_once __DIR__ . '/currency.php';

if (!function_exists('udcs_schema_identifier')) {
    function udcs_schema_identifier(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: '';
    }
}

if (!function_exists('udcs_schema_has_table')) {
    function udcs_schema_has_table(mysqli $conn, string $table): bool
    {
        $tableName = udcs_schema_identifier($table);
        if ($tableName === '') {
            return false;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 's', $tableName);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        return $result !== false && mysqli_num_rows($result) > 0;
    }
}

if (!function_exists('udcs_schema_has_column')) {
    function udcs_schema_has_column(mysqli $conn, string $table, string $column): bool
    {
        $tableName = udcs_schema_identifier($table);
        $columnName = udcs_schema_identifier($column);
        if ($tableName === '' || $columnName === '') {
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
        mysqli_stmt_bind_param($stmt, 'ss', $tableName, $columnName);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        return $result !== false && mysqli_num_rows($result) > 0;
    }
}

if (!function_exists('udcs_schema_has_index')) {
    function udcs_schema_has_index(mysqli $conn, string $table, string $index): bool
    {
        $tableName = udcs_schema_identifier($table);
        $indexName = udcs_schema_identifier($index);
        if ($tableName === '' || $indexName === '') {
            return false;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $tableName, $indexName);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return false;
        }
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        return $result !== false && mysqli_num_rows($result) > 0;
    }
}

if (!function_exists('udcs_schema_column_type')) {
    function udcs_schema_column_type(mysqli $conn, string $table, string $column): string
    {
        $tableName = udcs_schema_identifier($table);
        $columnName = udcs_schema_identifier($column);
        if ($tableName === '' || $columnName === '') {
            return '';
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return '';
        }
        mysqli_stmt_bind_param($stmt, 'ss', $tableName, $columnName);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return '';
        }
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        return strtolower(trim((string) ($row['COLUMN_TYPE'] ?? '')));
    }
}

if (!function_exists('udcs_claim_model_versions')) {
    function udcs_claim_model_versions(): array
    {
        return [
            'legacy' => 'Legacy',
            'v2' => 'Current',
        ];
    }
}

if (!function_exists('udcs_claim_status_labels')) {
    function udcs_claim_status_labels(): array
    {
        return [
            'Draft' => 'Draft',
            'OCR Validation Failed' => 'OCR Validation Failed',
            'Ready for Submission' => 'Ready for Submission',
            'Submitted' => 'Submitted',
            'Pending Legal Review' => 'Pending Legal Review',
            'More Information Required' => 'More Information Required',
            'Manual Legal Review Required' => 'Manual Legal Review Required',
            'Rejected by Legal' => 'Rejected by Legal',
            'Approved by Legal' => 'Approved by Legal',
            'Pending Finance Review' => 'Pending Finance Review',
            'Returned by Finance' => 'Returned by Finance',
            'Approved for Disbursement' => 'Approved for Disbursement',
            'Disbursed' => 'Disbursed',
            'Closed' => 'Closed',
            'pending' => 'Legacy Pending',
            'transferred to finance' => 'Legacy Finance Review',
            'approved by finance' => 'Legacy Approved by Finance',
            'rejected by legal' => 'Legacy Rejected by Legal',
            'rejected by finance' => 'Legacy Returned by Finance',
        ];
    }
}

if (!function_exists('udcs_claim_status_label')) {
    function udcs_claim_status_label(?string $status): string
    {
        $value = trim((string) $status);
        if ($value === '') {
            return 'Unknown';
        }

        $labels = udcs_claim_status_labels();
        return $labels[$value] ?? ucwords(str_replace('_', ' ', $value));
    }
}

if (!function_exists('udcs_claim_status_key')) {
    function udcs_claim_status_key(?string $status): string
    {
        $value = strtolower(trim((string) $status));
        $value = str_replace('_', ' ', $value);
        return preg_replace('/\s+/', ' ', $value) ?? '';
    }
}

if (!function_exists('udcs_claim_status_class')) {
    function udcs_claim_status_class(?string $status): string
    {
        $value = strtolower(trim((string) $status));
        return match ($value) {
            'draft' => 'status-neutral',
            'ocr validation failed' => 'status-rejected',
            'ready for submission' => 'status-review',
            'submitted' => 'status-review',
            'pending legal review' => 'status-pending',
            'more information required' => 'status-warning',
            'requires info', 'requires_info' => 'status-warning',
            'manual legal review required' => 'status-review',
            'rejected by legal' => 'status-rejected',
            'approved by legal' => 'status-approved',
            'pending finance review' => 'status-pending',
            'returned by finance' => 'status-warning',
            'approved for disbursement' => 'status-approved',
            'disbursed', 'closed' => 'status-approved',
            'pending' => 'status-pending',
            'under review', 'under_review', 'transferred to finance' => 'status-review',
            'approved by finance' => 'status-approved',
            'rejected by finance', 'rejected by legal' => 'status-rejected',
            default => 'status-neutral',
        };
    }
}

if (!function_exists('udcs_claim_asset_labels')) {
    function udcs_claim_asset_labels(): array
    {
        return [
            'current_account' => 'Current / Transaction Account Balances',
            'savings_account' => 'Savings Account Balances',
            'fixed_deposit' => 'Fixed / Term Deposits',
            'shares_securities' => 'Shares / Securities',
            'investment_account' => 'Investment Accounts (Funds / Bonds)',
            'bank_account' => 'Current / Transaction Account Balances',
            'savings' => 'Savings Account Balances',
            'shares' => 'Shares / Securities',
            'investment' => 'Investment Accounts (Funds / Bonds)',
        ];
    }
}

if (!function_exists('udcs_claim_asset_label')) {
    function udcs_claim_asset_label(?string $assetClass): string
    {
        $key = strtolower(trim((string) $assetClass));
        if ($key === '') {
            return 'Unknown asset';
        }
        $labels = udcs_claim_asset_labels();
        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('udcs_claim_asset_summary_label')) {
    function udcs_claim_asset_summary_label(?string $serializedAssetClasses, ?string $fallbackClaimType = null): string
    {
        $raw = trim((string) $serializedAssetClasses);
        if ($raw !== '') {
            $parts = array_values(array_filter(array_map('trim', explode('||', $raw))));
            $labels = [];
            foreach ($parts as $part) {
                $labels[] = udcs_claim_asset_label($part);
            }
            $labels = array_values(array_unique(array_filter($labels)));
            if (!empty($labels)) {
                if (count($labels) === 1) {
                    return $labels[0];
                }
                if (count($labels) === 2) {
                    return $labels[0] . ' and ' . $labels[1];
                }
                return $labels[0] . ' +' . (count($labels) - 1) . ' more asset classes';
            }
        }

        $fallback = trim((string) $fallbackClaimType);
        if ($fallback !== '') {
            return bk_claim_type_label($fallback);
        }

        return 'BK asset classes not recorded';
    }
}

if (!function_exists('udcs_claim_relationship_labels')) {
    function udcs_claim_relationship_labels(): array
    {
        return [
            'SPOUSE' => 'Spouse',
            'CHILD' => 'Child',
            'REPRESENTATIVE_DESCENDANT' => 'Representative Descendant',
            'PARENT' => 'Parent',
            'FULL_SIBLING' => 'Full Sibling',
            'HALF_SIBLING' => 'Half Sibling',
            'GRANDPARENT' => 'Grandparent',
            'UNCLE_AUNT' => 'Uncle / Aunt',
            'OTHER_REPRESENTATIVE' => 'Other Representative',
        ];
    }
}

if (!function_exists('udcs_claim_relationship_label')) {
    function udcs_claim_relationship_label(?string $relationship): string
    {
        $value = strtoupper(trim((string) $relationship));
        if ($value === '') {
            return 'Not specified';
        }
        $labels = udcs_claim_relationship_labels();
        return $labels[$value] ?? ucwords(str_replace('_', ' ', strtolower($value)));
    }
}

if (!function_exists('udcs_claim_manual_reason_labels')) {
    function udcs_claim_manual_reason_labels(): array
    {
        return [
            'WILL_EXISTS' => 'Will exists',
            'UNKNOWN_CHILDREN' => 'Children status is unknown',
            'DIVORCED' => 'Marital status is divorced',
            'SEPARATED' => 'Marital status is separated',
            'SINGLE_STATUS_FALLBACK' => 'Single-status path uses fallback evidence',
            'MISSING_COHEIR_SUPPORTING_DOCUMENTS' => 'Core co-heir documents are missing',
            'POSSIBLE_MISSING_HEIRS' => 'Possible missing heirs',
            'SPOUSE_PRIORITY_REVIEW' => 'Living spouse priority must be reviewed',
            'CONTRADICTORY_SPOUSE_STATUS' => 'Contradictory spouse details',
            'WIDOWED_FALLBACK_EVIDENCE' => 'Widowed path uses fallback spouse-death evidence',
            'REPRESENTATIVE_DESCENDANT_DISCLOSURE' => 'Representative descendant path requires legal follow-up',
        ];
    }
}

if (!function_exists('udcs_claim_manual_reason_label')) {
    function udcs_claim_manual_reason_label(?string $reason): string
    {
        $value = strtoupper(trim((string) $reason));
        if ($value === '') {
            return 'No manual review reason recorded';
        }
        $labels = udcs_claim_manual_reason_labels();
        if (isset($labels[$value])) {
            return $labels[$value];
        }

        $parts = array_filter(array_map('trim', explode(',', $value)));
        if (count($parts) > 1) {
            $out = [];
            foreach ($parts as $part) {
                $out[] = udcs_claim_manual_reason_label($part);
            }
            return implode('; ', array_unique($out));
        }

        return ucwords(str_replace('_', ' ', strtolower($value)));
    }
}

if (!function_exists('udcs_claim_reopen_section_labels')) {
    function udcs_claim_reopen_section_labels(): array
    {
        return [
            'deceased_entry' => 'Deceased and Entry Logic',
            'spouse_details' => 'Spouse Details',
            'children' => 'Children',
            'other_heirs' => 'Other Potential Heirs',
            'assets_payout' => 'BK Assets and Payout Preference',
            'supporting_documents' => 'Supporting Documents',
        ];
    }
}

if (!function_exists('udcs_claim_reopen_section_label')) {
    function udcs_claim_reopen_section_label(?string $section): string
    {
        $key = strtolower(trim((string) $section));
        $labels = udcs_claim_reopen_section_labels();
        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key ?: 'claim section'));
    }
}

if (!function_exists('udcs_claim_reopen_scope_encode')) {
    function udcs_claim_reopen_scope_encode(array $sections): string
    {
        $allowed = array_keys(udcs_claim_reopen_section_labels());
        $clean = [];
        foreach ($sections as $section) {
            $value = strtolower(trim((string) $section));
            if ($value !== '' && in_array($value, $allowed, true)) {
                $clean[] = $value;
            }
        }

        $clean = array_values(array_unique($clean));
        if (empty($clean)) {
            return '';
        }

        $encoded = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '';
    }
}

if (!function_exists('udcs_claim_reopen_scope_decode')) {
    function udcs_claim_reopen_scope_decode(?string $raw): array
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return [];
        }

        $allowed = array_keys(udcs_claim_reopen_section_labels());
        $decoded = json_decode($value, true);
        $parts = is_array($decoded) ? $decoded : explode(',', $value);
        $clean = [];
        foreach ($parts as $part) {
            $section = strtolower(trim((string) $part));
            if ($section !== '' && in_array($section, $allowed, true)) {
                $clean[] = $section;
            }
        }

        return array_values(array_unique($clean));
    }
}

if (!function_exists('udcs_claim_reopen_scope_summary')) {
    function udcs_claim_reopen_scope_summary(?string $raw): string
    {
        $sections = udcs_claim_reopen_scope_decode($raw);
        if (empty($sections)) {
            return '';
        }

        $labels = [];
        foreach ($sections as $section) {
            $labels[] = udcs_claim_reopen_section_label($section);
        }

        return implode(', ', array_values(array_unique($labels)));
    }
}

if (!function_exists('udcs_claim_document_labels')) {
    function udcs_claim_document_labels(): array
    {
        return [
            'deceased_death_certificate' => 'Deceased Death Certificate',
            'claimant_id' => 'Claimant ID',
            'relationship_proof' => 'Supporting Relationship Certificate',
            'single_status_evidence' => 'Proof of Single Status',
            'single_status_fallback_evidence' => 'Fallback Single-Status Attestation',
            'marriage_certificate' => 'Marriage Certificate',
            'spouse_id' => 'Spouse ID',
            'spouse_death_certificate' => 'Spouse Death Certificate',
            'spouse_secondary_death_evidence' => 'Fallback Spouse Death Evidence',
            'child_birth_certificate' => 'Child Birth Certificate / Proof of Filiation',
            'child_id' => 'Child ID',
            'representative_authority' => 'Representative Authority Document',
            'representative_descendant_linkage' => 'Representative Descendant Linkage Evidence',
            'represented_heir_id' => 'Represented Heir ID',
            'will_copy' => 'Copy of Will',
            'local_authority_attestation' => 'Local Authority Attestation',
            'family_resolution' => 'Family Resolution / Succession Decision',
            'secondary_relationship_evidence' => 'Secondary Relationship Evidence',
            'additional_support' => 'Additional Support Document',
        ];
    }
}

if (!function_exists('udcs_claim_document_label')) {
    function udcs_claim_document_label(?string $documentType): string
    {
        $key = strtolower(trim((string) $documentType));
        if ($key === '') {
            return 'Document';
        }
        $labels = udcs_claim_document_labels();
        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('udcs_claim_legacy_flag')) {
    function udcs_claim_legacy_flag(array $claim): bool
    {
        $model = strtolower(trim((string) ($claim['model_version'] ?? '')));
        if ($model !== '') {
            return $model !== 'v2';
        }
        return !isset($claim['claimant_user_id']) || (int) ($claim['claimant_user_id'] ?? 0) <= 0;
    }
}

if (!function_exists('udcs_claim_effective_status')) {
    function udcs_claim_effective_status(array $claim): string
    {
        $preferred = trim((string) ($claim['status'] ?? ''));
        if ($preferred !== '') {
            return $preferred;
        }
        return trim((string) ($claim['claim_status'] ?? ''));
    }
}

if (!function_exists('udcs_claim_account_reference_sql')) {
    function udcs_claim_account_reference_sql(?string $tableAlias = 'c'): string
    {
        $prefix = trim((string) $tableAlias);
        if ($prefix !== '') {
            $prefix = rtrim($prefix, '.') . '.';
        }

        return "COALESCE(NULLIF({$prefix}account_number, ''), {$prefix}accout_number)";
    }
}

if (!function_exists('udcs_claims_v2_ensure_schema')) {
    function udcs_claims_v2_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        @mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS people (
                person_id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(190) NOT NULL,
                date_of_birth DATE NULL,
                id_number VARCHAR(120) NULL,
                contact_phone VARCHAR(60) NULL,
                contact_email VARCHAR(160) NULL,
                alive_status ENUM('YES','NO','UNKNOWN') NOT NULL DEFAULT 'YES',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                INDEX idx_people_id_number (id_number),
                INDEX idx_people_name (full_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        @mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS claim_people (
                claim_person_id INT AUTO_INCREMENT PRIMARY KEY,
                claim_id INT NOT NULL,
                person_id INT NOT NULL,
                role VARCHAR(50) NOT NULL,
                relationship_type VARCHAR(60) NULL,
                is_claimant TINYINT(1) NOT NULL DEFAULT 0,
                is_co_heir TINYINT(1) NOT NULL DEFAULT 0,
                represented_by_person_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                INDEX idx_claim_people_claim (claim_id),
                INDEX idx_claim_people_person (person_id),
                INDEX idx_claim_people_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        @mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS claim_assets (
                claim_asset_id INT AUTO_INCREMENT PRIMARY KEY,
                claim_id INT NOT NULL,
                asset_class VARCHAR(80) NOT NULL,
                currency_code VARCHAR(3) NOT NULL DEFAULT 'RWF',
                account_reference VARCHAR(160) NULL,
                estimated_value DECIMAL(15,2) NULL,
                verified_value DECIMAL(15,2) NULL,
                finance_status VARCHAR(80) NULL,
                payout_preference_override VARCHAR(80) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                INDEX idx_claim_assets_claim (claim_id),
                INDEX idx_claim_assets_class (asset_class),
                INDEX idx_claim_assets_currency (currency_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $assetAlterations = [
            'currency_code' => "ALTER TABLE claim_assets ADD COLUMN currency_code VARCHAR(3) NOT NULL DEFAULT 'RWF' AFTER asset_class",
        ];
        foreach ($assetAlterations as $column => $sql) {
            if (!udcs_schema_has_column($conn, 'claim_assets', $column)) {
                @mysqli_query($conn, $sql);
            }
        }

        $claimsAlterations = [
            'claimant_user_id' => "ALTER TABLE claims ADD COLUMN claimant_user_id INT NULL",
            'deceased_full_name' => "ALTER TABLE claims ADD COLUMN deceased_full_name VARCHAR(190) NULL",
            'deceased_id_number' => "ALTER TABLE claims ADD COLUMN deceased_id_number VARCHAR(120) NULL",
            'date_of_death' => "ALTER TABLE claims ADD COLUMN date_of_death DATE NULL",
            'account_number' => "ALTER TABLE claims ADD COLUMN account_number VARCHAR(160) NULL",
            'marital_status' => "ALTER TABLE claims ADD COLUMN marital_status VARCHAR(40) NULL",
            'spouse_status' => "ALTER TABLE claims ADD COLUMN spouse_status VARCHAR(40) NULL",
            'children_status' => "ALTER TABLE claims ADD COLUMN children_status VARCHAR(40) NULL",
            'will_exists' => "ALTER TABLE claims ADD COLUMN will_exists TINYINT(1) NULL DEFAULT NULL",
            'acting_on_behalf' => "ALTER TABLE claims ADD COLUMN acting_on_behalf TINYINT(1) NULL DEFAULT NULL",
            'preferred_payout_method' => "ALTER TABLE claims ADD COLUMN preferred_payout_method VARCHAR(80) NULL",
            'distribution_method' => "ALTER TABLE claims ADD COLUMN distribution_method VARCHAR(100) NULL",
            'distribution_details' => "ALTER TABLE claims ADD COLUMN distribution_details TEXT NULL",
            'claim_currency_code' => "ALTER TABLE claims ADD COLUMN claim_currency_code VARCHAR(3) NOT NULL DEFAULT 'RWF'",
            'finance_assessed_currency_code' => "ALTER TABLE claims ADD COLUMN finance_assessed_currency_code VARCHAR(3) NOT NULL DEFAULT 'RWF'",
            'manual_review_flag' => "ALTER TABLE claims ADD COLUMN manual_review_flag TINYINT(1) NOT NULL DEFAULT 0",
            'manual_review_reason' => "ALTER TABLE claims ADD COLUMN manual_review_reason VARCHAR(255) NULL",
            'status' => "ALTER TABLE claims ADD COLUMN status VARCHAR(80) NULL",
            'model_version' => "ALTER TABLE claims ADD COLUMN model_version VARCHAR(16) NOT NULL DEFAULT 'legacy'",
            'legacy_read_only' => "ALTER TABLE claims ADD COLUMN legacy_read_only TINYINT(1) NOT NULL DEFAULT 0",
            'legal_reopen_scope' => "ALTER TABLE claims ADD COLUMN legal_reopen_scope TEXT NULL",
            'legal_reopen_note' => "ALTER TABLE claims ADD COLUMN legal_reopen_note TEXT NULL",
            'legal_reopen_requested_at' => "ALTER TABLE claims ADD COLUMN legal_reopen_requested_at DATETIME NULL",
            'finance_return_reason' => "ALTER TABLE claims ADD COLUMN finance_return_reason VARCHAR(255) NULL",
            'finance_return_route' => "ALTER TABLE claims ADD COLUMN finance_return_route VARCHAR(40) NULL",
            'closed_at' => "ALTER TABLE claims ADD COLUMN closed_at DATETIME NULL",
        ];
        foreach ($claimsAlterations as $column => $sql) {
            if (!udcs_schema_has_column($conn, 'claims', $column)) {
                @mysqli_query($conn, $sql);
            }
        }
        $claimAmountType = udcs_schema_column_type($conn, 'claims', 'claim_amount');
        if ($claimAmountType !== '' && !str_starts_with($claimAmountType, 'decimal')) {
            @mysqli_query($conn, "ALTER TABLE claims MODIFY claim_amount DECIMAL(15,2) NULL");
        }

        $documentsAlterations = [
            'owner_person_id' => "ALTER TABLE documents ADD COLUMN owner_person_id INT NULL",
            'related_claim_person_id' => "ALTER TABLE documents ADD COLUMN related_claim_person_id INT NULL",
            'ocr_status' => "ALTER TABLE documents ADD COLUMN ocr_status VARCHAR(40) NULL",
            'ocr_extracted_name' => "ALTER TABLE documents ADD COLUMN ocr_extracted_name VARCHAR(190) NULL",
            'ocr_extracted_id' => "ALTER TABLE documents ADD COLUMN ocr_extracted_id VARCHAR(120) NULL",
            'ocr_extracted_date' => "ALTER TABLE documents ADD COLUMN ocr_extracted_date VARCHAR(80) NULL",
            'legal_review_status' => "ALTER TABLE documents ADD COLUMN legal_review_status VARCHAR(40) NULL",
            'rejection_reason' => "ALTER TABLE documents ADD COLUMN rejection_reason TEXT NULL",
            'created_at' => "ALTER TABLE documents ADD COLUMN created_at DATETIME NULL",
            'updated_at' => "ALTER TABLE documents ADD COLUMN updated_at DATETIME NULL",
        ];
        foreach ($documentsAlterations as $column => $sql) {
            if (!udcs_schema_has_column($conn, 'documents', $column)) {
                @mysqli_query($conn, $sql);
            }
        }

        if (!udcs_schema_has_table($conn, 'claim_history')) {
            @mysqli_query(
                $conn,
                "CREATE TABLE IF NOT EXISTS claim_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    claim_id INT NOT NULL,
                    actor_role VARCHAR(32) NULL,
                    status_label VARCHAR(120) NULL,
                    message TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_claim_history_claim (claim_id),
                    INDEX idx_claim_history_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        if (!udcs_schema_has_index($conn, 'claims', 'idx_claims_status_v2')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD INDEX idx_claims_status_v2 (status)");
        }
        if (!udcs_schema_has_index($conn, 'claims', 'idx_claims_model_version')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD INDEX idx_claims_model_version (model_version)");
        }
        if (!udcs_schema_has_index($conn, 'claims', 'idx_claims_claimant_user')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD INDEX idx_claims_claimant_user (claimant_user_id)");
        }
        if (!udcs_schema_has_index($conn, 'claim_assets', 'idx_claim_assets_currency')) {
            @mysqli_query($conn, "ALTER TABLE claim_assets ADD INDEX idx_claim_assets_currency (currency_code)");
        }

        $claimsSyncSql = "UPDATE claims
             SET
                claimant_user_id = COALESCE(claimant_user_id, claimant_id),
                deceased_full_name = COALESCE(NULLIF(deceased_full_name, ''), deceased_name),
                deceased_id_number = COALESCE(NULLIF(deceased_id_number, ''), deceased_national_id),
                date_of_death = COALESCE(date_of_death, deceased_date),
                claim_currency_code = COALESCE(NULLIF(claim_currency_code, ''), 'RWF'),
                finance_assessed_currency_code = COALESCE(NULLIF(finance_assessed_currency_code, ''), claim_currency_code, 'RWF'),
                preferred_payout_method = COALESCE(NULLIF(preferred_payout_method, ''), distribution_method),
                status = COALESCE(NULLIF(status, ''), claim_status),
                claim_status = COALESCE(NULLIF(claim_status, ''), status),
                model_version = CASE
                    WHEN COALESCE(NULLIF(model_version, ''), 'legacy') = 'v2' THEN 'v2'
                    ELSE 'legacy'
                END,
                legacy_read_only = CASE
                    WHEN COALESCE(NULLIF(model_version, ''), 'legacy') = 'v2' THEN 0
                    ELSE 1
                END";
        if (udcs_schema_has_column($conn, 'claims', 'accout_number') && udcs_schema_has_column($conn, 'claims', 'account_number')) {
            $claimsSyncSql .= ",
                account_number = COALESCE(NULLIF(account_number, ''), accout_number),
                accout_number = COALESCE(NULLIF(accout_number, ''), account_number)";
        }
        @mysqli_query($conn, $claimsSyncSql);
        @mysqli_query($conn, "UPDATE claim_assets SET currency_code = 'RWF' WHERE currency_code IS NULL OR currency_code = ''");

        @mysqli_query(
            $conn,
            "UPDATE documents
             SET created_at = COALESCE(created_at, uploaded_at),
                 updated_at = COALESCE(updated_at, uploaded_at)"
        );
    }
}

if (!function_exists('udcs_claim_history_log')) {
    function udcs_claim_history_log(mysqli $conn, int $claimId, string $actorRole, string $statusLabel, string $message): void
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO claim_history (claim_id, actor_role, status_label, message, created_at)
             VALUES (?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NOW())"
        );
        if (!$stmt) {
            return;
        }

        mysqli_stmt_bind_param($stmt, 'isss', $claimId, $actorRole, $statusLabel, $message);
        @mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('udcs_claim_set_status')) {
    function udcs_claim_set_status(
        mysqli $conn,
        int $claimId,
        string $status,
        int $actorId = 0,
        string $actorRole = 'system',
        string $historyMessage = '',
        array $extraFields = []
    ): bool {
        udcs_claims_v2_ensure_schema($conn);

        $claimId = (int) $claimId;
        $status = trim($status);
        if ($claimId <= 0 || $status === '') {
            return false;
        }

        $allowedExtra = [
            'manual_review_flag',
            'manual_review_reason',
            'assigned_legal_id',
            'assigned_finance_id',
            'assigned_to',
            'legal_reopen_scope',
            'legal_reopen_note',
            'legal_reopen_requested_at',
            'finance_assessed_amount',
            'finance_assessed_currency_code',
            'finance_return_reason',
            'finance_return_route',
            'closed_at',
            'comment',
            'updated_at',
        ];

        $setParts = ['status = ?', 'claim_status = ?'];
        $types = 'ss';
        $values = [$status, $status];

        foreach ($extraFields as $key => $value) {
            if (!in_array($key, $allowedExtra, true)) {
                continue;
            }

            if ($key === 'updated_at') {
                $setParts[] = 'updated_at = NOW()';
                continue;
            }
            if ($key === 'closed_at') {
                if ($value === null || $value === '') {
                    $setParts[] = 'closed_at = NULL';
                } elseif (strtoupper((string) $value) === 'NOW()') {
                    $setParts[] = 'closed_at = NOW()';
                } else {
                    $setParts[] = 'closed_at = ?';
                    $types .= 's';
                    $values[] = (string) $value;
                }
                continue;
            }
            if ($value === null) {
                $setParts[] = $key . ' = NULL';
                continue;
            }

            $setParts[] = $key . ' = ?';
            if (is_int($value)) {
                $types .= 'i';
                $values[] = $value;
            } elseif (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
            } else {
                $types .= 's';
                $values[] = (string) $value;
            }
        }

        $sql = 'UPDATE claims SET ' . implode(', ', $setParts) . ' WHERE id = ? LIMIT 1';
        $types .= 'i';
        $values[] = $claimId;

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt || !udcs_db_stmt_bind($stmt, $types, $values)) {
            return false;
        }
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$success) {
            return false;
        }

        if ($historyMessage !== '') {
            udcs_claim_history_log($conn, $claimId, $actorRole, $status, $historyMessage);
        }
        if (function_exists('bk_activity_log')) {
            bk_activity_log($conn, [
                'actor_id' => $actorId,
                'actor_role' => $actorRole,
                'claim_id' => $claimId,
                'action_key' => 'claim_status_updated_v2',
                'action_label' => 'Claim Status Updated',
                'details' => $historyMessage !== '' ? $historyMessage : ('Claim status updated to ' . $status . '.'),
                'meta' => [
                    'status' => $status,
                ],
            ]);
        }

        return true;
    }
}

if (!function_exists('udcs_claim_manual_review_reasons')) {
    function udcs_claim_manual_review_reasons(array $claimData): array
    {
        $reasons = [];

        if (!empty($claimData['will_exists'])) {
            $reasons[] = 'WILL_EXISTS';
        }

        $childrenStatus = strtoupper(trim((string) ($claimData['children_status'] ?? '')));
        if ($childrenStatus === 'UNKNOWN') {
            $reasons[] = 'UNKNOWN_CHILDREN';
        }

        $maritalStatus = strtoupper(trim((string) ($claimData['marital_status'] ?? '')));
        if ($maritalStatus === 'DIVORCED') {
            $reasons[] = 'DIVORCED';
        }
        if ($maritalStatus === 'SEPARATED') {
            $reasons[] = 'SEPARATED';
        }
        if (!empty($claimData['single_status_fallback_evidence'])) {
            $reasons[] = 'SINGLE_STATUS_FALLBACK';
        }

        if (!empty($claimData['missing_coheir_docs'])) {
            $reasons[] = 'MISSING_COHEIR_SUPPORTING_DOCUMENTS';
        }

        if (!empty($claimData['possible_missing_heirs'])) {
            $reasons[] = 'POSSIBLE_MISSING_HEIRS';
        }

        if (!empty($claimData['spouse_priority_review'])) {
            $reasons[] = 'SPOUSE_PRIORITY_REVIEW';
        }

        if (!empty($claimData['contradictory_spouse_status'])) {
            $reasons[] = 'CONTRADICTORY_SPOUSE_STATUS';
        }
        if (!empty($claimData['widowed_fallback_evidence'])) {
            $reasons[] = 'WIDOWED_FALLBACK_EVIDENCE';
        }
        if (!empty($claimData['representative_descendant_disclosure'])) {
            $reasons[] = 'REPRESENTATIVE_DESCENDANT_DISCLOSURE';
        }

        return array_values(array_unique($reasons));
    }
}

if (!function_exists('udcs_claim_join_manual_review_reasons')) {
    function udcs_claim_join_manual_review_reasons(array $reasons): string
    {
        $clean = [];
        foreach ($reasons as $reason) {
            $value = strtoupper(trim((string) $reason));
            if ($value !== '') {
                $clean[] = $value;
            }
        }
        return implode(', ', array_values(array_unique($clean)));
    }
}

if (!function_exists('udcs_claim_live_status_after_submission')) {
    function udcs_claim_live_status_after_submission(array $manualReviewReasons): string
    {
        return !empty($manualReviewReasons)
            ? 'Manual Legal Review Required'
            : 'Pending Legal Review';
    }
}

if (!function_exists('udcs_claim_insert_person')) {
    function udcs_claim_insert_person(mysqli $conn, array $data): int
    {
        udcs_claims_v2_ensure_schema($conn);

        $fullName = trim((string) ($data['full_name'] ?? ''));
        if ($fullName === '') {
            return 0;
        }

        $dateOfBirth = trim((string) ($data['date_of_birth'] ?? ''));
        if ($dateOfBirth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
            $dateOfBirth = '';
        }
        $idNumber = trim((string) ($data['id_number'] ?? ''));
        $contactPhone = trim((string) ($data['contact_phone'] ?? ''));
        $contactEmail = trim((string) ($data['contact_email'] ?? ''));
        $aliveStatus = strtoupper(trim((string) ($data['alive_status'] ?? 'YES')));
        if (!in_array($aliveStatus, ['YES', 'NO', 'UNKNOWN'], true)) {
            $aliveStatus = 'UNKNOWN';
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO people (
                full_name,
                date_of_birth,
                id_number,
                contact_phone,
                contact_email,
                alive_status,
                created_at,
                updated_at
            ) VALUES (
                ?,
                NULLIF(?, ''),
                NULLIF(?, ''),
                NULLIF(?, ''),
                NULLIF(?, ''),
                ?,
                NOW(),
                NOW()
            )"
        );
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'ssssss',
            $fullName,
            $dateOfBirth,
            $idNumber,
            $contactPhone,
            $contactEmail,
            $aliveStatus
        );
        $success = mysqli_stmt_execute($stmt);
        $personId = $success ? (int) mysqli_insert_id($conn) : 0;
        mysqli_stmt_close($stmt);
        return $personId;
    }
}

if (!function_exists('udcs_claim_save_person')) {
    function udcs_claim_save_person(mysqli $conn, array $data, int $existingPersonId = 0): int
    {
        udcs_claims_v2_ensure_schema($conn);

        $existingPersonId = (int) $existingPersonId;
        if ($existingPersonId <= 0) {
            return udcs_claim_insert_person($conn, $data);
        }

        $fullName = trim((string) ($data['full_name'] ?? ''));
        if ($fullName === '') {
            return 0;
        }

        $dateOfBirth = trim((string) ($data['date_of_birth'] ?? ''));
        if ($dateOfBirth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
            $dateOfBirth = '';
        }
        $idNumber = trim((string) ($data['id_number'] ?? ''));
        $contactPhone = trim((string) ($data['contact_phone'] ?? ''));
        $contactEmail = trim((string) ($data['contact_email'] ?? ''));
        $aliveStatus = strtoupper(trim((string) ($data['alive_status'] ?? 'YES')));
        if (!in_array($aliveStatus, ['YES', 'NO', 'UNKNOWN'], true)) {
            $aliveStatus = 'UNKNOWN';
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE people
             SET
                full_name = ?,
                date_of_birth = NULLIF(?, ''),
                id_number = NULLIF(?, ''),
                contact_phone = NULLIF(?, ''),
                contact_email = NULLIF(?, ''),
                alive_status = ?,
                updated_at = NOW()
             WHERE person_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'ssssssi',
            $fullName,
            $dateOfBirth,
            $idNumber,
            $contactPhone,
            $contactEmail,
            $aliveStatus,
            $existingPersonId
        );
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $success ? $existingPersonId : 0;
    }
}

if (!function_exists('udcs_claim_link_person')) {
    function udcs_claim_link_person(mysqli $conn, array $data): int
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimId = (int) ($data['claim_id'] ?? 0);
        $personId = (int) ($data['person_id'] ?? 0);
        $role = strtoupper(trim((string) ($data['role'] ?? '')));
        if ($claimId <= 0 || $personId <= 0 || $role === '') {
            return 0;
        }

        $relationshipType = trim((string) ($data['relationship_type'] ?? ''));
        $isClaimant = !empty($data['is_claimant']) ? 1 : 0;
        $isCoHeir = !empty($data['is_co_heir']) ? 1 : 0;
        $representedBy = (int) ($data['represented_by_person_id'] ?? 0);
        $representedBy = $representedBy > 0 ? $representedBy : null;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO claim_people (
                claim_id,
                person_id,
                role,
                relationship_type,
                is_claimant,
                is_co_heir,
                represented_by_person_id,
                created_at,
                updated_at
            ) VALUES (
                ?,
                ?,
                ?,
                NULLIF(?, ''),
                ?,
                ?,
                ?,
                NOW(),
                NOW()
            )"
        );
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'iissiii',
            $claimId,
            $personId,
            $role,
            $relationshipType,
            $isClaimant,
            $isCoHeir,
            $representedBy
        );
        $success = mysqli_stmt_execute($stmt);
        $linkId = $success ? (int) mysqli_insert_id($conn) : 0;
        mysqli_stmt_close($stmt);
        return $linkId;
    }
}

if (!function_exists('udcs_claim_update_person_link')) {
    function udcs_claim_update_person_link(mysqli $conn, int $claimPersonId, array $data): bool
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimPersonId = (int) $claimPersonId;
        $claimId = (int) ($data['claim_id'] ?? 0);
        $personId = (int) ($data['person_id'] ?? 0);
        $role = strtoupper(trim((string) ($data['role'] ?? '')));
        if ($claimPersonId <= 0 || $claimId <= 0 || $personId <= 0 || $role === '') {
            return false;
        }

        $relationshipType = trim((string) ($data['relationship_type'] ?? ''));
        $isClaimant = !empty($data['is_claimant']) ? 1 : 0;
        $isCoHeir = !empty($data['is_co_heir']) ? 1 : 0;
        $representedBy = (int) ($data['represented_by_person_id'] ?? 0);
        $representedBy = $representedBy > 0 ? $representedBy : null;

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE claim_people
             SET
                claim_id = ?,
                person_id = ?,
                role = ?,
                relationship_type = NULLIF(?, ''),
                is_claimant = ?,
                is_co_heir = ?,
                represented_by_person_id = ?,
                updated_at = NOW()
             WHERE claim_person_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'iissiiii',
            $claimId,
            $personId,
            $role,
            $relationshipType,
            $isClaimant,
            $isCoHeir,
            $representedBy,
            $claimPersonId
        );
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $success;
    }
}

if (!function_exists('udcs_claim_sync_documents_for_claim_person')) {
    function udcs_claim_sync_documents_for_claim_person(mysqli $conn, int $claimPersonId, int $ownerPersonId): bool
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimPersonId = (int) $claimPersonId;
        $ownerPersonId = (int) $ownerPersonId;
        if ($claimPersonId <= 0 || $ownerPersonId <= 0) {
            return false;
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE documents
             SET
                owner_person_id = ?,
                related_claim_person_id = ?,
                updated_at = NOW()
             WHERE related_claim_person_id = ?"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'iii', $ownerPersonId, $claimPersonId, $claimPersonId);
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $success;
    }
}

if (!function_exists('udcs_claim_collect_cleanup_context')) {
    function udcs_claim_collect_cleanup_context(mysqli $conn, int $claimId): array
    {
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return [
                'claim_id' => 0,
                'person_ids' => [],
                'claim_person_ids' => [],
                'file_paths' => [],
            ];
        }

        $people = udcs_claim_fetch_people_by_claim($conn, $claimId);
        $documents = udcs_claim_fetch_documents($conn, $claimId);

        $personIds = [];
        $claimPersonIds = [];
        foreach ($people as $person) {
            $personId = (int) ($person['person_id'] ?? 0);
            $claimPersonId = (int) ($person['claim_person_id'] ?? 0);
            if ($personId > 0) {
                $personIds[] = $personId;
            }
            if ($claimPersonId > 0) {
                $claimPersonIds[] = $claimPersonId;
            }
        }

        $filePaths = [];
        foreach ($documents as $document) {
            $ownerPersonId = (int) ($document['owner_person_id'] ?? 0);
            $path = trim((string) ($document['file_path'] ?? ''));
            if ($ownerPersonId > 0) {
                $personIds[] = $ownerPersonId;
            }
            if ($path !== '') {
                $filePaths[] = $path;
            }
        }

        return [
            'claim_id' => $claimId,
            'person_ids' => array_values(array_unique(array_filter(array_map('intval', $personIds), static fn ($value) => $value > 0))),
            'claim_person_ids' => array_values(array_unique(array_filter(array_map('intval', $claimPersonIds), static fn ($value) => $value > 0))),
            'file_paths' => array_values(array_unique(array_filter($filePaths))),
        ];
    }
}

if (!function_exists('udcs_claim_delete_upload_file')) {
    function udcs_claim_delete_upload_file(string $path): void
    {
        $rawPath = trim($path);
        if ($rawPath === '') {
            return;
        }

        $uploadsRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
        if ($uploadsRoot === false) {
            return;
        }

        $candidatePath = $rawPath;
        if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidatePath) && !str_starts_with($candidatePath, DIRECTORY_SEPARATOR)) {
            $candidatePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidatePath), DIRECTORY_SEPARATOR);
        }

        $resolvedPath = realpath($candidatePath);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            return;
        }

        $uploadsPrefix = rtrim(strtolower(str_replace('\\', '/', $uploadsRoot)), '/') . '/';
        $resolvedPrefix = strtolower(str_replace('\\', '/', $resolvedPath));
        if (!str_starts_with($resolvedPrefix, $uploadsPrefix)) {
            return;
        }

        @unlink($resolvedPath);
    }
}

if (!function_exists('udcs_claim_delete_upload_directory')) {
    function udcs_claim_delete_upload_directory(int $claimId): void
    {
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return;
        }

        $uploadsRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
        if ($uploadsRoot === false) {
            return;
        }

        $directory = $uploadsRoot . DIRECTORY_SEPARATOR . $claimId;
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($entryPath)) {
                continue;
            }

            @unlink($entryPath);
        }

        @rmdir($directory);
    }
}

if (!function_exists('udcs_claim_prune_people')) {
    function udcs_claim_prune_people(mysqli $conn, array $personIds): void
    {
        udcs_claims_v2_ensure_schema($conn);

        $personIds = array_values(array_unique(array_filter(array_map('intval', $personIds), static fn ($value) => $value > 0)));
        if (empty($personIds)) {
            return;
        }

        $claimPeopleCheckStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM claim_people WHERE person_id = ?');
        $documentCheckStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM documents WHERE owner_person_id = ?');
        $deleteStmt = mysqli_prepare($conn, 'DELETE FROM people WHERE person_id = ? LIMIT 1');
        if (!$claimPeopleCheckStmt || !$documentCheckStmt || !$deleteStmt) {
            if ($claimPeopleCheckStmt) {
                mysqli_stmt_close($claimPeopleCheckStmt);
            }
            if ($documentCheckStmt) {
                mysqli_stmt_close($documentCheckStmt);
            }
            if ($deleteStmt) {
                mysqli_stmt_close($deleteStmt);
            }
            return;
        }

        foreach ($personIds as $personId) {
            mysqli_stmt_bind_param($claimPeopleCheckStmt, 'i', $personId);
            $claimLinkCount = 1;
            if (mysqli_stmt_execute($claimPeopleCheckStmt)) {
                $result = mysqli_stmt_get_result($claimPeopleCheckStmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                $claimLinkCount = (int) ($row['total'] ?? 0);
            }
            if ($claimLinkCount > 0) {
                continue;
            }

            mysqli_stmt_bind_param($documentCheckStmt, 'i', $personId);
            $documentCount = 1;
            if (mysqli_stmt_execute($documentCheckStmt)) {
                $result = mysqli_stmt_get_result($documentCheckStmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                $documentCount = (int) ($row['total'] ?? 0);
            }
            if ($documentCount > 0) {
                continue;
            }

            mysqli_stmt_bind_param($deleteStmt, 'i', $personId);
            @mysqli_stmt_execute($deleteStmt);
        }

        mysqli_stmt_close($claimPeopleCheckStmt);
        mysqli_stmt_close($documentCheckStmt);
        mysqli_stmt_close($deleteStmt);
    }
}

if (!function_exists('udcs_claim_delete_documents_for_claim_people')) {
    function udcs_claim_delete_documents_for_claim_people(mysqli $conn, int $claimId, array $claimPersonIds): array
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimId = (int) $claimId;
        $claimPersonIds = array_values(array_unique(array_filter(array_map('intval', $claimPersonIds), static fn ($value) => $value > 0)));
        if ($claimId <= 0 || empty($claimPersonIds)) {
            return [
                'file_paths' => [],
                'owner_person_ids' => [],
            ];
        }

        $documents = udcs_claim_fetch_documents($conn, $claimId);
        $paths = [];
        $ownerPersonIds = [];
        foreach ($documents as $document) {
            $relatedClaimPersonId = (int) ($document['related_claim_person_id'] ?? 0);
            if (!in_array($relatedClaimPersonId, $claimPersonIds, true)) {
                continue;
            }

            $path = trim((string) ($document['file_path'] ?? ''));
            $ownerPersonId = (int) ($document['owner_person_id'] ?? 0);
            if ($path !== '') {
                $paths[] = $path;
            }
            if ($ownerPersonId > 0) {
                $ownerPersonIds[] = $ownerPersonId;
            }
        }

        $placeholders = implode(',', array_fill(0, count($claimPersonIds), '?'));
        $types = 'i' . str_repeat('i', count($claimPersonIds));
        $values = array_merge([$claimId], $claimPersonIds);
        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM documents
             WHERE claim_id = ?
               AND related_claim_person_id IN ($placeholders)"
        );
        if ($stmt && udcs_db_stmt_bind($stmt, $types, $values)) {
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } elseif ($stmt) {
            mysqli_stmt_close($stmt);
        }

        return [
            'file_paths' => array_values(array_unique(array_filter($paths))),
            'owner_person_ids' => array_values(array_unique(array_filter(array_map('intval', $ownerPersonIds), static fn ($value) => $value > 0))),
        ];
    }
}

if (!function_exists('udcs_claim_delete_rows')) {
    function udcs_claim_delete_rows(mysqli $conn, int $claimId, ?array $context = null): bool
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return false;
        }

        $context = is_array($context) ? $context : udcs_claim_collect_cleanup_context($conn, $claimId);
        $queries = [
            'DELETE FROM documents WHERE claim_id = ?',
            'DELETE FROM claim_assets WHERE claim_id = ?',
            'DELETE FROM claim_people WHERE claim_id = ?',
            'DELETE FROM claim_history WHERE claim_id = ?',
            'DELETE FROM activity_logs WHERE claim_id = ?',
            'DELETE FROM claims WHERE id = ? LIMIT 1',
        ];

        foreach ($queries as $sql) {
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) {
                return false;
            }
            mysqli_stmt_bind_param($stmt, 'i', $claimId);
            $success = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if (!$success) {
                return false;
            }
        }

        udcs_claim_prune_people($conn, (array) ($context['person_ids'] ?? []));
        return true;
    }
}

if (!function_exists('udcs_claim_delete_single')) {
    function udcs_claim_delete_single(mysqli $conn, int $claimId): bool
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return false;
        }

        $context = udcs_claim_collect_cleanup_context($conn, $claimId);
        mysqli_begin_transaction($conn);

        try {
            if (!udcs_claim_delete_rows($conn, $claimId, $context)) {
                throw new RuntimeException('Claim cleanup failed.');
            }
            mysqli_commit($conn);
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            return false;
        }

        foreach ((array) ($context['file_paths'] ?? []) as $filePath) {
            udcs_claim_delete_upload_file((string) $filePath);
        }
        udcs_claim_delete_upload_directory($claimId);

        return true;
    }
}

if (!function_exists('udcs_claim_insert_asset')) {
    function udcs_claim_insert_asset(mysqli $conn, array $data): int
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimId = (int) ($data['claim_id'] ?? 0);
        $assetClass = strtolower(trim((string) ($data['asset_class'] ?? '')));
        if ($claimId <= 0 || $assetClass === '') {
            return 0;
        }

        $accountReference = trim((string) ($data['account_reference'] ?? ''));
        $currencyCode = bk_asset_currency_code($assetClass, (string) ($data['currency_code'] ?? 'RWF'));
        $estimatedValue = $data['estimated_value'] ?? null;
        $verifiedValue = $data['verified_value'] ?? null;
        $financeStatus = trim((string) ($data['finance_status'] ?? ''));
        $override = trim((string) ($data['payout_preference_override'] ?? ''));

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO claim_assets (
                claim_id,
                asset_class,
                currency_code,
                account_reference,
                estimated_value,
                verified_value,
                finance_status,
                payout_preference_override,
                created_at,
                updated_at
            ) VALUES (
                ?,
                ?,
                ?,
                NULLIF(?, ''),
                ?,
                ?,
                NULLIF(?, ''),
                NULLIF(?, ''),
                NOW(),
                NOW()
            )"
        );
        if (!$stmt) {
            return 0;
        }

        $estimated = $estimatedValue !== null && $estimatedValue !== '' ? (float) $estimatedValue : null;
        $verified = $verifiedValue !== null && $verifiedValue !== '' ? (float) $verifiedValue : null;
        mysqli_stmt_bind_param(
            $stmt,
            'isssddss',
            $claimId,
            $assetClass,
            $currencyCode,
            $accountReference,
            $estimated,
            $verified,
            $financeStatus,
            $override
        );
        $success = mysqli_stmt_execute($stmt);
        $assetId = $success ? (int) mysqli_insert_id($conn) : 0;
        mysqli_stmt_close($stmt);
        return $assetId;
    }
}

if (!function_exists('udcs_claim_insert_document_row')) {
    function udcs_claim_insert_document_row(mysqli $conn, array $data): int
    {
        udcs_claims_v2_ensure_schema($conn);

        $claimId = (int) ($data['claim_id'] ?? 0);
        $documentType = trim((string) ($data['document_type'] ?? ''));
        $filePath = trim((string) ($data['file_path'] ?? ''));
        if ($claimId <= 0 || $documentType === '' || $filePath === '') {
            return 0;
        }

        $ownerPersonId = (int) ($data['owner_person_id'] ?? 0);
        $relatedClaimPersonId = (int) ($data['related_claim_person_id'] ?? 0);
        $ocrStatus = trim((string) ($data['ocr_status'] ?? ''));
        $ocrName = trim((string) ($data['ocr_extracted_name'] ?? ''));
        $ocrId = trim((string) ($data['ocr_extracted_id'] ?? ''));
        $ocrDate = trim((string) ($data['ocr_extracted_date'] ?? ''));
        $legalStatus = trim((string) ($data['legal_review_status'] ?? ''));
        $rejectionReason = trim((string) ($data['rejection_reason'] ?? ''));

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO documents (
                claim_id,
                owner_person_id,
                related_claim_person_id,
                document_type,
                file_path,
                uploaded_at,
                ocr_status,
                ocr_extracted_name,
                ocr_extracted_id,
                ocr_extracted_date,
                legal_review_status,
                rejection_reason,
                created_at,
                updated_at
            ) VALUES (
                ?,
                NULLIF(?, 0),
                NULLIF(?, 0),
                ?,
                ?,
                NOW(),
                NULLIF(?, ''),
                NULLIF(?, ''),
                NULLIF(?, ''),
                NULLIF(?, ''),
                NULLIF(?, ''),
                NULLIF(?, ''),
                NOW(),
                NOW()
            )"
        );
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'iiissssssss',
            $claimId,
            $ownerPersonId,
            $relatedClaimPersonId,
            $documentType,
            $filePath,
            $ocrStatus,
            $ocrName,
            $ocrId,
            $ocrDate,
            $legalStatus,
            $rejectionReason
        );
        $success = mysqli_stmt_execute($stmt);
        $documentId = $success ? (int) mysqli_insert_id($conn) : 0;
        mysqli_stmt_close($stmt);
        return $documentId;
    }
}

if (!function_exists('udcs_claim_fetch_people_by_claim')) {
    function udcs_claim_fetch_people_by_claim(mysqli $conn, int $claimId): array
    {
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return [];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                cp.claim_person_id,
                cp.claim_id,
                cp.person_id,
                cp.role,
                cp.relationship_type,
                cp.is_claimant,
                cp.is_co_heir,
                cp.represented_by_person_id,
                p.full_name,
                p.date_of_birth,
                p.id_number,
                p.contact_phone,
                p.contact_email,
                p.alive_status
             FROM claim_people cp
             INNER JOIN people p ON p.person_id = cp.person_id
             WHERE cp.claim_id = ?
             ORDER BY
                CASE cp.role
                    WHEN 'DECEASED' THEN 1
                    WHEN 'CLAIMANT' THEN 2
                    WHEN 'SPOUSE' THEN 3
                    WHEN 'CHILD' THEN 4
                    ELSE 5
                END,
                cp.claim_person_id ASC"
        );
        $rows = [];
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $claimId);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $rows[] = $row;
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
        return $rows;
    }
}

if (!function_exists('udcs_claim_fetch_assets')) {
    function udcs_claim_fetch_assets(mysqli $conn, int $claimId): array
    {
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return [];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT claim_asset_id, claim_id, asset_class, currency_code, account_reference, estimated_value, verified_value, finance_status, payout_preference_override
             FROM claim_assets
             WHERE claim_id = ?
             ORDER BY claim_asset_id ASC"
        );
        $rows = [];
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $claimId);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $rows[] = $row;
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
        return $rows;
    }
}

if (!function_exists('udcs_claim_fetch_documents')) {
    function udcs_claim_fetch_documents(mysqli $conn, int $claimId): array
    {
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return [];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                d.*,
                p.full_name AS owner_person_name,
                cp.role AS owner_claim_role
             FROM documents d
             LEFT JOIN people p ON p.person_id = d.owner_person_id
             LEFT JOIN claim_people cp ON cp.claim_person_id = d.related_claim_person_id
             WHERE d.claim_id = ?
             ORDER BY COALESCE(d.created_at, d.uploaded_at) DESC, d.id DESC"
        );
        $rows = [];
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $claimId);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $rows[] = $row;
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
        return $rows;
    }
}

if (!function_exists('udcs_claim_fetch_history')) {
    function udcs_claim_fetch_history(mysqli $conn, int $claimId): array
    {
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return [];
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, claim_id, actor_role, status_label, message, created_at
             FROM claim_history
             WHERE claim_id = ?
             ORDER BY created_at DESC, id DESC"
        );
        $rows = [];
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $claimId);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $rows[] = $row;
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }

        return $rows;
    }
}

if (!function_exists('udcs_claim_people_grouped')) {
    function udcs_claim_people_grouped(array $people): array
    {
        $grouped = [
            'claimant' => null,
            'deceased' => null,
            'spouse' => null,
            'children' => [],
            'other_heirs' => [],
        ];

        foreach ($people as $person) {
            $role = strtoupper(trim((string) ($person['role'] ?? '')));
            if ($role === 'CLAIMANT') {
                $grouped['claimant'] = $person;
                continue;
            }
            if ($role === 'DECEASED') {
                $grouped['deceased'] = $person;
                continue;
            }
            if ($role === 'SPOUSE') {
                $grouped['spouse'] = $person;
                continue;
            }
            if ($role === 'CHILD') {
                $grouped['children'][] = $person;
                continue;
            }
            $grouped['other_heirs'][] = $person;
        }

        return $grouped;
    }
}

if (!function_exists('udcs_claim_fetch_single')) {
    function udcs_claim_fetch_single(mysqli $conn, int $claimId): ?array
    {
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return null;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                c.*,
                COALESCE(c.claimant_user_id, c.claimant_id) AS claimant_user_ref,
                u.full_name AS claimant_name,
                u.email AS claimant_email,
                u.phone AS claimant_phone,
                CASE
                    WHEN COALESCE(NULLIF(c.status, ''), '') <> '' THEN c.status
                    ELSE c.claim_status
                END AS effective_status
             FROM claims c
             LEFT JOIN users u ON u.id = COALESCE(c.claimant_user_id, c.claimant_id)
             WHERE c.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, 'i', $claimId);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return null;
        }

        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        if (!$result || mysqli_num_rows($result) === 0) {
            return null;
        }

        return mysqli_fetch_assoc($result) ?: null;
    }
}

if (!function_exists('udcs_claim_payout_method_label')) {
    function udcs_claim_payout_method_label(?string $method): string
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

if (!function_exists('udcs_claim_distribution_payload')) {
    function udcs_claim_distribution_payload(?string $rawDetails): array
    {
        $raw = trim((string) $rawDetails);
        if ($raw === '') {
            return [];
        }

        if (function_exists('bk_claim_distribution_details_array')) {
            return bk_claim_distribution_details_array($raw);
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('udcs_claim_distribution_detail_rows')) {
    function udcs_claim_distribution_detail_rows(?string $rawDetails): array
    {
        $raw = trim((string) $rawDetails);
        if ($raw === '') {
            return [];
        }

        if (function_exists('bk_distribution_detail_rows')) {
            return bk_distribution_detail_rows($raw);
        }

        $payload = udcs_claim_distribution_payload($raw);
        $rows = [];
        foreach ($payload as $key => $value) {
            $cleanKey = trim((string) $key);
            $cleanValue = trim((string) $value);
            if ($cleanKey === '' || $cleanValue === '') {
                continue;
            }
            if ($cleanKey === 'method') {
                continue;
            }
            $rows[] = [
                'label' => ucwords(str_replace('_', ' ', $cleanKey)),
                'value' => $cleanValue,
            ];
        }

        return $rows;
    }
}

if (!function_exists('udcs_claim_document_review_bucket')) {
    function udcs_claim_document_review_bucket(array $document): string
    {
        $legalStatus = strtolower(trim((string) ($document['legal_review_status'] ?? '')));
        if ($legalStatus !== '') {
            if (str_contains($legalStatus, 'reject')) {
                return 'rejected';
            }
            if (str_contains($legalStatus, 'accept') || str_contains($legalStatus, 'approve') || $legalStatus === 'passed') {
                return 'accepted';
            }
        }

        $ocrStatus = strtolower(trim((string) ($document['ocr_status'] ?? '')));
        if ($ocrStatus === 'passed') {
            return 'passed';
        }
        if ($ocrStatus === 'failed') {
            return 'failed';
        }

        return 'pending';
    }
}

if (!function_exists('udcs_claim_review_stage_key')) {
    function udcs_claim_review_stage_key(?string $status): string
    {
        $statusKey = udcs_claim_status_key($status);

        return match ($statusKey) {
            'draft', 'ocr validation failed', 'ready for submission', 'submitted' => 'claimant_intake',
            'pending legal review', 'manual legal review required', 'more information required', 'rejected by legal', 'rejected by finance' => 'legal_review',
            'pending finance review', 'returned by finance', 'approved for disbursement' => 'finance_review',
            'disbursed', 'closed', 'approved by finance' => 'closed',
            default => 'active',
        };
    }
}

if (!function_exists('udcs_claim_build_people_summary')) {
    function udcs_claim_build_people_summary(array $claim, array $people, array $grouped): array
    {
        $maritalStatus = strtoupper(trim((string) ($claim['marital_status'] ?? '')));
        $spouseStatus = strtoupper(trim((string) ($claim['spouse_status'] ?? '')));
        $childrenStatus = strtoupper(trim((string) ($claim['children_status'] ?? '')));
        $actingOnBehalf = (int) ($claim['acting_on_behalf'] ?? 0) === 1;

        $roleCounts = [];
        $relationshipCounts = [];
        $representativeCount = 0;
        $coHeirCount = 0;

        foreach ($people as $person) {
            $role = strtoupper(trim((string) ($person['role'] ?? '')));
            $relationship = strtoupper(trim((string) ($person['relationship_type'] ?? '')));
            if ($role !== '') {
                $roleCounts[$role] = (int) ($roleCounts[$role] ?? 0) + 1;
            }
            if ($relationship !== '') {
                $relationshipCounts[$relationship] = (int) ($relationshipCounts[$relationship] ?? 0) + 1;
            }
            if (!empty($person['is_co_heir']) || !in_array($role, ['CLAIMANT', 'DECEASED'], true)) {
                if (!in_array($role, ['CLAIMANT', 'DECEASED'], true)) {
                    $coHeirCount++;
                }
            }
            if ($relationship === 'REPRESENTATIVE_DESCENDANT' || $role === 'REPRESENTATIVE_DESCENDANT' || (int) ($person['represented_by_person_id'] ?? 0) > 0) {
                $representativeCount++;
            }
        }

        $spouseRequired = in_array($maritalStatus, ['MARRIED', 'WIDOWED'], true) || in_array($spouseStatus, ['ALIVE', 'DECEASED'], true);
        $spouseDeclared = is_array($grouped['spouse'] ?? null);
        if (!$spouseDeclared && strtoupper(trim((string) ($claim['relationship'] ?? ''))) === 'SPOUSE' && $spouseStatus === 'ALIVE') {
            $spouseDeclared = true;
        }

        $childrenDeclared = $childrenStatus !== 'HAS_CHILDREN' || !empty($grouped['children']);
        $otherHeirsDeclared = !empty($grouped['other_heirs']);
        $possibleMissingHeirs = false;

        if ($spouseRequired && !$spouseDeclared) {
            $possibleMissingHeirs = true;
        }
        if ($childrenStatus === 'HAS_CHILDREN' && !$childrenDeclared) {
            $possibleMissingHeirs = true;
        }
        if ($childrenStatus === 'UNKNOWN') {
            $possibleMissingHeirs = true;
        }

        return [
            'claimant_name' => (string) (($grouped['claimant']['full_name'] ?? '') !== '' ? $grouped['claimant']['full_name'] : ($claim['claimant_name'] ?? '')),
            'deceased_name' => (string) (($grouped['deceased']['full_name'] ?? '') !== '' ? $grouped['deceased']['full_name'] : ($claim['deceased_full_name'] ?? $claim['deceased_name'] ?? '')),
            'spouse_required' => $spouseRequired,
            'spouse_declared' => $spouseDeclared,
            'children_status' => $childrenStatus,
            'children_declared' => $childrenDeclared,
            'child_count' => count((array) ($grouped['children'] ?? [])),
            'other_heir_count' => count((array) ($grouped['other_heirs'] ?? [])),
            'representative_count' => $representativeCount,
            'co_heir_count' => $coHeirCount,
            'acting_on_behalf' => $actingOnBehalf,
            'possible_missing_heirs' => $possibleMissingHeirs,
            'role_counts' => $roleCounts,
            'relationship_counts' => $relationshipCounts,
        ];
    }
}

if (!function_exists('udcs_claim_build_asset_summary')) {
    function udcs_claim_build_asset_summary(array $claim, array $assets): array
    {
        $classes = [];
        $currencies = [];
        $estimatedTotals = [];
        $verifiedTotals = [];
        $hasEstimated = false;
        $hasVerified = false;
        $confirmedCount = 0;
        $holdCount = 0;
        $manualFollowUpCount = 0;
        $missingCount = 0;
        $reviewedCount = 0;
        $overrideCount = 0;

        foreach ($assets as $asset) {
            $assetClass = strtolower(trim((string) ($asset['asset_class'] ?? '')));
            if ($assetClass !== '') {
                $classes[] = $assetClass;
            }
            $currencyCode = bk_asset_currency_code($assetClass, (string) ($asset['currency_code'] ?? 'RWF'));
            $currencies[] = $currencyCode;

            $estimatedValue = $asset['estimated_value'] ?? null;
            if ($estimatedValue !== null && $estimatedValue !== '') {
                $estimatedTotals[$currencyCode] = (float) ($estimatedTotals[$currencyCode] ?? 0) + (float) $estimatedValue;
                $hasEstimated = true;
            }

            $verifiedValue = $asset['verified_value'] ?? null;
            if ($verifiedValue !== null && $verifiedValue !== '') {
                $verifiedTotals[$currencyCode] = (float) ($verifiedTotals[$currencyCode] ?? 0) + (float) $verifiedValue;
                $hasVerified = true;
            }

            $financeStatus = trim((string) ($asset['finance_status'] ?? ''));
            if ($financeStatus !== '') {
                $reviewedCount++;
            }

            switch ($financeStatus) {
                case 'Confirmed in BK records':
                    $confirmedCount++;
                    break;
                case 'Restriction or hold found':
                    $holdCount++;
                    break;
                case 'Manual follow-up required':
                    $manualFollowUpCount++;
                    break;
                case 'No matching BK asset found':
                    $missingCount++;
                    break;
            }

            if (trim((string) ($asset['payout_preference_override'] ?? '')) !== '') {
                $overrideCount++;
            }
        }

        $classes = array_values(array_unique(array_filter($classes)));
        $currencies = array_values(array_unique(array_filter($currencies)));
        $serializedClasses = implode('||', $classes);
        $singleEstimatedCurrency = $hasEstimated && count($estimatedTotals) === 1 ? array_key_first($estimatedTotals) : '';
        $singleVerifiedCurrency = $hasVerified && count($verifiedTotals) === 1 ? array_key_first($verifiedTotals) : '';

        return [
            'count' => count($assets),
            'classes' => $classes,
            'currencies' => $currencies,
            'currency_label' => !empty($currencies) ? implode(', ', $currencies) : 'RWF',
            'label' => udcs_claim_asset_summary_label($serializedClasses, (string) ($claim['claim_type'] ?? '')),
            'estimated_total' => $singleEstimatedCurrency !== '' ? (float) $estimatedTotals[$singleEstimatedCurrency] : null,
            'estimated_currency_code' => $singleEstimatedCurrency,
            'estimated_totals_by_currency' => $estimatedTotals,
            'estimated_total_label' => $hasEstimated ? bk_amount_totals_label($estimatedTotals, 'Not declared') : 'Not declared',
            'verified_total' => $singleVerifiedCurrency !== '' ? (float) $verifiedTotals[$singleVerifiedCurrency] : null,
            'verified_currency_code' => $singleVerifiedCurrency,
            'verified_totals_by_currency' => $verifiedTotals,
            'verified_total_label' => $hasVerified ? bk_amount_totals_label($verifiedTotals, 'Not verified') : 'Not verified',
            'confirmed_count' => $confirmedCount,
            'hold_count' => $holdCount,
            'manual_follow_up_count' => $manualFollowUpCount,
            'missing_count' => $missingCount,
            'reviewed_count' => $reviewedCount,
            'all_reviewed' => !empty($assets) && $reviewedCount === count($assets),
            'override_count' => $overrideCount,
            'has_overrides' => $overrideCount > 0,
        ];
    }
}

if (!function_exists('udcs_claim_build_document_summary')) {
    function udcs_claim_build_document_summary(array $documents): array
    {
        $typeCounts = [];
        $ownerRoleCounts = [];
        $ocrPassedCount = 0;
        $ocrFailedCount = 0;
        $ocrPendingCount = 0;
        $acceptedCount = 0;
        $rejectedCount = 0;
        $authorityDocumentPresent = false;
        $marriageCertificatePresent = false;
        $spouseDeathEvidencePresent = false;
        $singleStatusEvidencePresent = false;
        $singleStatusFallbackEvidencePresent = false;
        $willCopyPresent = false;
        $childProofPresent = false;

        foreach ($documents as $document) {
            $type = strtolower(trim((string) ($document['document_type'] ?? '')));
            $ownerRole = strtoupper(trim((string) ($document['owner_claim_role'] ?? '')));

            if ($type !== '') {
                $typeCounts[$type] = (int) ($typeCounts[$type] ?? 0) + 1;
            }
            if ($ownerRole !== '') {
                $ownerRoleCounts[$ownerRole] = (int) ($ownerRoleCounts[$ownerRole] ?? 0) + 1;
            }

            $ocrStatus = strtolower(trim((string) ($document['ocr_status'] ?? '')));
            if ($ocrStatus === 'passed') {
                $ocrPassedCount++;
            } elseif ($ocrStatus === 'failed') {
                $ocrFailedCount++;
            } else {
                $ocrPendingCount++;
            }

            $legalStatus = strtolower(trim((string) ($document['legal_review_status'] ?? '')));
            if ($legalStatus !== '') {
                if (str_contains($legalStatus, 'reject')) {
                    $rejectedCount++;
                } elseif (str_contains($legalStatus, 'accept') || str_contains($legalStatus, 'approve') || $legalStatus === 'passed') {
                    $acceptedCount++;
                }
            }

            if ($type === 'representative_authority') {
                $authorityDocumentPresent = true;
            }
            if ($type === 'marriage_certificate') {
                $marriageCertificatePresent = true;
            }
            if ($type === 'single_status_evidence') {
                $singleStatusEvidencePresent = true;
            }
            if ($type === 'single_status_fallback_evidence') {
                $singleStatusFallbackEvidencePresent = true;
            }
            if (in_array($type, ['spouse_death_certificate', 'spouse_secondary_death_evidence'], true)) {
                $spouseDeathEvidencePresent = true;
            }
            if ($type === 'will_copy') {
                $willCopyPresent = true;
            }
            if ($type === 'child_birth_certificate') {
                $childProofPresent = true;
            }
        }

        return [
            'count' => count($documents),
            'types' => array_keys($typeCounts),
            'type_counts' => $typeCounts,
            'owner_role_counts' => $ownerRoleCounts,
            'ocr_passed_count' => $ocrPassedCount,
            'ocr_failed_count' => $ocrFailedCount,
            'ocr_pending_count' => $ocrPendingCount,
            'legal_accepted_count' => $acceptedCount,
            'legal_rejected_count' => $rejectedCount,
            'has_any_failures' => $ocrFailedCount > 0 || $rejectedCount > 0,
            'ocr_gate_complete' => !empty($documents) && $ocrFailedCount === 0 && $ocrPendingCount === 0,
            'authority_document_present' => $authorityDocumentPresent,
            'marriage_certificate_present' => $marriageCertificatePresent,
            'spouse_death_evidence_present' => $spouseDeathEvidencePresent,
            'single_status_evidence_present' => $singleStatusEvidencePresent,
            'single_status_fallback_evidence_present' => $singleStatusFallbackEvidencePresent,
            'will_copy_present' => $willCopyPresent,
            'child_proof_present' => $childProofPresent,
        ];
    }
}

if (!function_exists('udcs_claim_build_review_flags')) {
    function udcs_claim_build_review_flags(array $claim, array $peopleSummary, array $assetSummary, array $documentSummary, array $payoutSummary): array
    {
        $flags = [];
        $manualReviewFlag = (int) ($claim['manual_review_flag'] ?? 0) === 1;
        $manualReason = trim((string) ($claim['manual_review_reason'] ?? ''));
        $childrenStatus = strtoupper(trim((string) ($claim['children_status'] ?? '')));
        $willExists = (int) ($claim['will_exists'] ?? 0) === 1;
        $actingOnBehalf = (int) ($claim['acting_on_behalf'] ?? 0) === 1;

        if ($manualReviewFlag) {
            $flags[] = [
                'key' => 'manual_review_required',
                'severity' => 'warning',
                'label' => $manualReason !== '' ? udcs_claim_manual_reason_label($manualReason) : 'Manual legal review required',
            ];
        }
        if ($willExists) {
            $flags[] = [
                'key' => 'will_exists',
                'severity' => 'warning',
                'label' => 'Will provided. Manual legal interpretation is required.',
            ];
        }
        if ($childrenStatus === 'UNKNOWN') {
            $flags[] = [
                'key' => 'unknown_children',
                'severity' => 'warning',
                'label' => 'Children status is unknown and must be reviewed carefully.',
            ];
        }
        if (!empty($peopleSummary['spouse_required']) && empty($peopleSummary['spouse_declared'])) {
            $flags[] = [
                'key' => 'missing_spouse_record',
                'severity' => 'danger',
                'label' => 'Spouse information should exist for this marital path but is not fully declared.',
            ];
        }
        if (strtoupper(trim((string) ($claim['marital_status'] ?? ''))) === 'SINGLE'
            && empty($documentSummary['single_status_evidence_present'])
            && empty($documentSummary['single_status_fallback_evidence_present'])) {
            $flags[] = [
                'key' => 'missing_single_status_evidence',
                'severity' => 'warning',
                'label' => 'Single-status evidence is expected for a claim declared as single.',
            ];
        }
        if (!empty($documentSummary['single_status_fallback_evidence_present']) && empty($documentSummary['single_status_evidence_present'])) {
            $flags[] = [
                'key' => 'single_status_fallback',
                'severity' => 'warning',
                'label' => 'Single-status path relies on fallback attestation and needs legal review.',
            ];
        }
        if ($childrenStatus === 'HAS_CHILDREN' && empty($peopleSummary['children_declared'])) {
            $flags[] = [
                'key' => 'missing_child_entries',
                'severity' => 'danger',
                'label' => 'Children were declared, but no structured child entries are linked to the claim.',
            ];
        }
        if (!empty($peopleSummary['possible_missing_heirs'])) {
            $flags[] = [
                'key' => 'possible_missing_heirs',
                'severity' => 'warning',
                'label' => 'The family path suggests co-heirs may still be missing or incomplete.',
            ];
        }
        if ($actingOnBehalf && empty($documentSummary['authority_document_present'])) {
            $flags[] = [
                'key' => 'missing_authority_document',
                'severity' => 'danger',
                'label' => 'Representative authority is expected but no authority document is linked.',
            ];
        }
        if (strtoupper(trim((string) ($claim['spouse_status'] ?? ''))) === 'DECEASED' && empty($documentSummary['spouse_death_evidence_present'])) {
            $flags[] = [
                'key' => 'missing_spouse_death_evidence',
                'severity' => 'warning',
                'label' => 'Spouse death evidence is expected for this spouse path.',
            ];
        }
        if ($documentSummary['ocr_failed_count'] > 0) {
            $flags[] = [
                'key' => 'ocr_failures',
                'severity' => 'danger',
                'label' => 'One or more uploaded documents failed OCR intake checks.',
            ];
        }
        if ($documentSummary['legal_rejected_count'] > 0) {
            $flags[] = [
                'key' => 'document_rejections',
                'severity' => 'warning',
                'label' => 'One or more documents were rejected during review.',
            ];
        }
        if ($assetSummary['count'] <= 0) {
            $flags[] = [
                'key' => 'no_assets_declared',
                'severity' => 'danger',
                'label' => 'No BK-held asset rows are linked to this claim.',
            ];
        }
        if ($assetSummary['hold_count'] > 0) {
            $flags[] = [
                'key' => 'asset_holds',
                'severity' => 'warning',
                'label' => 'One or more assets show a restriction or operational hold.',
            ];
        }
        if ($assetSummary['manual_follow_up_count'] > 0) {
            $flags[] = [
                'key' => 'asset_manual_follow_up',
                'severity' => 'warning',
                'label' => 'One or more assets still require manual finance follow-up.',
            ];
        }
        if ($assetSummary['missing_count'] > 0) {
            $flags[] = [
                'key' => 'asset_not_found',
                'severity' => 'warning',
                'label' => 'Some claimed BK assets have not yet been matched in bank records.',
            ];
        }
        if (!$payoutSummary['destination_complete']) {
            $flags[] = [
                'key' => 'payout_destination_incomplete',
                'severity' => 'warning',
                'label' => 'Settlement destination details are not complete for final disbursement.',
            ];
        }

        return $flags;
    }
}

if (!function_exists('udcs_claim_fetch_review_contract')) {
    function udcs_claim_fetch_review_contract(mysqli $conn, int $claimId, ?array $claimRow = null): ?array
    {
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return null;
        }

        $claim = is_array($claimRow) ? $claimRow : udcs_claim_fetch_single($conn, $claimId);
        if (!$claim) {
            return null;
        }

        $people = udcs_claim_fetch_people_by_claim($conn, $claimId);
        $assets = udcs_claim_fetch_assets($conn, $claimId);
        $documents = udcs_claim_fetch_documents($conn, $claimId);
        $history = udcs_claim_fetch_history($conn, $claimId);
        $groupedPeople = udcs_claim_people_grouped($people);
        $peopleSummary = udcs_claim_build_people_summary($claim, $people, $groupedPeople);
        $assetSummary = udcs_claim_build_asset_summary($claim, $assets);
        $documentSummary = udcs_claim_build_document_summary($documents);

        $preferredPayoutMethod = trim((string) (($claim['preferred_payout_method'] ?? '') !== '' ? ($claim['preferred_payout_method'] ?? '') : ($claim['distribution_method'] ?? '')));
        $distributionPayload = udcs_claim_distribution_payload((string) ($claim['distribution_details'] ?? ''));
        $distributionRows = udcs_claim_distribution_detail_rows((string) ($claim['distribution_details'] ?? ''));
        $assetOverrides = [];
        foreach ($assets as $asset) {
            $override = trim((string) ($asset['payout_preference_override'] ?? ''));
            if ($override === '') {
                continue;
            }
            $assetOverrides[] = [
                'claim_asset_id' => (int) ($asset['claim_asset_id'] ?? 0),
                'asset_class' => (string) ($asset['asset_class'] ?? ''),
                'asset_label' => udcs_claim_asset_label((string) ($asset['asset_class'] ?? '')),
                'method' => $override,
                'method_label' => udcs_claim_payout_method_label($override),
            ];
        }
        $payoutSummary = [
            'preferred_method' => $preferredPayoutMethod,
            'preferred_label' => udcs_claim_payout_method_label($preferredPayoutMethod),
            'distribution_method' => (string) ($claim['distribution_method'] ?? ''),
            'distribution_label' => udcs_claim_payout_method_label((string) ($claim['distribution_method'] ?? '')),
            'details_raw' => (string) ($claim['distribution_details'] ?? ''),
            'details_payload' => $distributionPayload,
            'detail_rows' => $distributionRows,
            'has_details' => !empty($distributionPayload) || trim((string) ($claim['distribution_details'] ?? '')) !== '',
            'destination_complete' => $preferredPayoutMethod === '' ? false : ($preferredPayoutMethod === 'hold_pending_instruction' || !empty($distributionPayload) || !empty($distributionRows)),
            'asset_override_count' => count($assetOverrides),
            'asset_overrides' => $assetOverrides,
        ];

        $status = udcs_claim_effective_status($claim);
        $manualReviewReason = trim((string) ($claim['manual_review_reason'] ?? ''));
        $flags = udcs_claim_build_review_flags($claim, $peopleSummary, $assetSummary, $documentSummary, $payoutSummary);

        $historyCount = count($history);
        $latestHistory = $history[0] ?? null;
        $claimCurrency = bk_currency_code((string) ($claim['claim_currency_code'] ?? 'RWF'));
        $financeCurrency = bk_currency_code((string) ($claim['finance_assessed_currency_code'] ?? $claimCurrency));
        $review = [
            'manual_review_required' => (int) ($claim['manual_review_flag'] ?? 0) === 1,
            'manual_review_reason_key' => $manualReviewReason,
            'manual_review_reason_label' => udcs_claim_manual_reason_label($manualReviewReason),
            'will_exists' => (int) ($claim['will_exists'] ?? 0) === 1,
            'acting_on_behalf' => (int) ($claim['acting_on_behalf'] ?? 0) === 1,
            'children_unknown' => strtoupper(trim((string) ($claim['children_status'] ?? ''))) === 'UNKNOWN',
            'spouse_required' => (bool) ($peopleSummary['spouse_required'] ?? false),
            'spouse_declared' => (bool) ($peopleSummary['spouse_declared'] ?? false),
            'children_declared' => (bool) ($peopleSummary['children_declared'] ?? false),
            'possible_missing_heirs' => (bool) ($peopleSummary['possible_missing_heirs'] ?? false),
            'ocr_gate_complete' => (bool) ($documentSummary['ocr_gate_complete'] ?? false),
            'document_failures_present' => (bool) ($documentSummary['has_any_failures'] ?? false),
            'payout_destination_complete' => (bool) ($payoutSummary['destination_complete'] ?? false),
            'legal_workspace_ready' => !udcs_claim_legacy_flag($claim) && !empty($assets) && !empty($documents),
            'finance_workspace_ready' => !udcs_claim_legacy_flag($claim) && !empty($assets) && ((int) ($claim['assigned_finance_id'] ?? 0) > 0 || in_array(udcs_claim_status_key($status), ['pending finance review', 'returned by finance', 'approved for disbursement', 'disbursed', 'closed', 'approved by finance'], true)),
            'flags' => $flags,
        ];

        return [
            'claim' => $claim,
            'status' => [
                'key' => udcs_claim_status_key($status),
                'label' => udcs_claim_status_label($status),
                'class' => udcs_claim_status_class($status),
                'stage' => udcs_claim_review_stage_key($status),
                'is_legacy' => udcs_claim_legacy_flag($claim),
                'is_v2' => !udcs_claim_legacy_flag($claim),
            ],
            'people' => [
                'items' => $people,
                'grouped' => $groupedPeople,
                'summary' => $peopleSummary,
            ],
            'assets' => [
                'items' => $assets,
                'summary' => $assetSummary,
            ],
            'documents' => [
                'items' => $documents,
                'summary' => $documentSummary,
            ],
            'history' => [
                'items' => $history,
                'summary' => [
                    'count' => $historyCount,
                    'latest_at' => (string) ($latestHistory['created_at'] ?? ''),
                    'latest_status_label' => (string) ($latestHistory['status_label'] ?? ''),
                ],
            ],
            'payout' => $payoutSummary,
            'routing' => [
                'assigned_legal_id' => (int) ($claim['assigned_legal_id'] ?? 0),
                'assigned_finance_id' => (int) ($claim['assigned_finance_id'] ?? 0),
                'legal_reopen_scope' => (string) ($claim['legal_reopen_scope'] ?? ''),
                'legal_reopen_scope_summary' => udcs_claim_reopen_scope_summary((string) ($claim['legal_reopen_scope'] ?? '')),
                'legal_reopen_note' => (string) ($claim['legal_reopen_note'] ?? ''),
                'legal_reopen_requested_at' => (string) ($claim['legal_reopen_requested_at'] ?? ''),
                'finance_return_reason' => (string) ($claim['finance_return_reason'] ?? ''),
                'finance_return_route' => (string) ($claim['finance_return_route'] ?? ''),
            ],
            'summary' => [
                'claimant_value_label' => function_exists('bk_claim_amount_display')
                    ? bk_claim_amount_display($claim['claim_amount'] ?? null, $claimCurrency, 'Not declared')
                    : ((string) ($claim['claim_amount'] ?? '') !== '' ? $claimCurrency . ' ' . number_format((float) ($claim['claim_amount'] ?? 0), bk_currency_decimals($claimCurrency)) : 'Not declared'),
                'finance_value_label' => function_exists('bk_claim_amount_display')
                    ? bk_claim_amount_display($claim['finance_assessed_amount'] ?? null, $financeCurrency, 'Not assessed yet')
                    : ((string) ($claim['finance_assessed_amount'] ?? '') !== '' ? $financeCurrency . ' ' . number_format((float) ($claim['finance_assessed_amount'] ?? 0), bk_currency_decimals($financeCurrency)) : 'Not assessed yet'),
                'claim_currency_code' => $claimCurrency,
                'finance_assessed_currency_code' => $financeCurrency,
                'asset_classes_label' => (string) ($assetSummary['label'] ?? 'No assets linked'),
            ],
            'review' => $review,
        ];
    }
}

if (!function_exists('udcs_claim_contract_destination_label')) {
    function udcs_claim_contract_destination_label(array $contract): string
    {
        $payout = (array) ($contract['payout'] ?? []);
        $rows = (array) ($payout['detail_rows'] ?? []);
        $parts = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            $parts[] = $label . ': ' . $value;
        }

        if (!empty($parts)) {
            return implode(' | ', $parts);
        }

        $method = strtolower(trim((string) ($payout['preferred_method'] ?? '')));
        if ($method === 'hold_pending_instruction') {
            return 'Hold pending final instruction';
        }

        return 'Destination not fully captured';
    }
}

if (!function_exists('udcs_claim_contract_review_signal_label')) {
    function udcs_claim_contract_review_signal_label(array $contract): string
    {
        $flags = (array) ($contract['review']['flags'] ?? []);
        $critical = 0;
        $warning = 0;

        foreach ($flags as $flag) {
            $severity = strtolower(trim((string) ($flag['severity'] ?? '')));
            if ($severity === 'danger') {
                $critical++;
            } elseif ($severity === 'warning') {
                $warning++;
            }
        }

        if ($critical === 0 && $warning === 0) {
            return 'No automatic blockers detected';
        }

        return $critical . ' critical / ' . $warning . ' warning';
    }
}

if (!function_exists('udcs_claim_contract_document_label')) {
    function udcs_claim_contract_document_label(array $contract): string
    {
        $summary = (array) ($contract['documents']['summary'] ?? []);
        $count = (int) ($summary['count'] ?? 0);
        $passed = (int) ($summary['ocr_passed_count'] ?? 0);
        $failed = (int) ($summary['ocr_failed_count'] ?? 0);
        $pending = (int) ($summary['ocr_pending_count'] ?? 0);

        return $count . ' file(s); OCR ' . $passed . ' passed / ' . $failed . ' failed / ' . $pending . ' pending';
    }
}

if (!function_exists('udcs_claim_contract_value_label')) {
    function udcs_claim_contract_value_label(array $contract, string $kind = 'estimated'): string
    {
        $assetSummary = (array) ($contract['assets']['summary'] ?? []);
        if ($kind === 'verified') {
            $verifiedLabel = trim((string) ($assetSummary['verified_total_label'] ?? ''));
            if ($verifiedLabel !== '' && $verifiedLabel !== 'Not verified') {
                return $verifiedLabel;
            }

            return (string) ($contract['summary']['finance_value_label'] ?? 'Not assessed yet');
        }

        $estimatedLabel = trim((string) ($assetSummary['estimated_total_label'] ?? ''));
        if ($estimatedLabel !== '' && $estimatedLabel !== 'Not declared') {
            return $estimatedLabel;
        }

        return (string) ($contract['summary']['claimant_value_label'] ?? 'Not declared');
    }
}
