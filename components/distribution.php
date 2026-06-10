<?php
// Tags: [DIST]
// [DIST] Disbursement method + field validation rules.

if (!function_exists('bk_distribution_method_labels')) {
    function bk_distribution_method_labels(): array
    {
        return [
            'bk_account_transfer' => 'BK Account Transfer',
            'other_bank_transfer' => 'Other Bank Transfer',
            'mobile_money' => 'Mobile Money',
            'cheque' => 'Cheque / Banker\'s Instrument',
            'hold_pending_instruction' => 'Hold Pending Final Instruction',
            'cash_pickup' => 'Cash pickup at bank branch',
            'transfer_to_claimant_account' => 'Transfer to claimant bank account',
            'transfer_to_other_bank' => 'Transfer to another bank account',
            'mobile_money_wallet' => 'Mobile money wallet transfer',
            'bank_draft' => 'Bank draft / certified cheque',
            'split_payout_accounts' => 'Split payout across two bank accounts',
            'staged_installments' => 'Staged installments to bank account',
            'sell_shares_cash' => 'Sell shares and transfer cash',
            'transfer_shares_claimant' => 'Transfer shares to claimant account',
            'partial_sale_partial_transfer' => 'Part sale to cash, part shares transfer',
            'transfer_shares_nominee' => 'Transfer shares to nominated beneficiary',
            'inspection_access' => 'Request inspection and access',
            'transfer_ownership' => 'Transfer ownership to claimant',
            'liquidate_assets' => 'Liquidate assets and transfer cash',
            'consultation_required' => 'Consultation required',
            'mixed_distribution' => 'Mixed distribution (multiple methods)',
            // [DIST] Legacy values for older saved claims.
            'transfer_to_deceased_account' => 'Transfer to deceased account',
            'hold_shares' => 'Hold shares',
        ];
    }
}

if (!function_exists('bk_distribution_method_label')) {
    function bk_distribution_method_label(?string $method): string
    {
        $key = strtolower(trim((string) $method));
        $labels = bk_distribution_method_labels();
        return $labels[$key] ?? 'Not specified';
    }
}

if (!function_exists('bk_distribution_allowed_methods_by_claim_type')) {
    // [DIST] Claim type -> allowed methods matrix.
    function bk_distribution_allowed_methods_by_claim_type(): array
    {
        return [
            'bank_account' => ['cash_pickup', 'transfer_to_claimant_account', 'transfer_to_other_bank', 'mobile_money_wallet', 'bank_draft', 'split_payout_accounts', 'staged_installments'],
            'savings' => ['cash_pickup', 'transfer_to_claimant_account', 'transfer_to_other_bank', 'mobile_money_wallet', 'bank_draft', 'split_payout_accounts', 'staged_installments'],
            'fixed_deposit' => ['cash_pickup', 'transfer_to_claimant_account', 'transfer_to_other_bank', 'mobile_money_wallet', 'bank_draft', 'split_payout_accounts', 'staged_installments'],
            'investment' => ['cash_pickup', 'transfer_to_claimant_account', 'transfer_to_other_bank', 'mobile_money_wallet', 'bank_draft', 'split_payout_accounts', 'staged_installments'],
            'shares' => ['sell_shares_cash', 'transfer_shares_claimant', 'partial_sale_partial_transfer', 'transfer_shares_nominee'],
            'safe_deposit' => ['inspection_access', 'transfer_ownership', 'liquidate_assets'],
            'multiple' => ['consultation_required', 'mixed_distribution'],
            'other' => ['consultation_required', 'mixed_distribution'],
        ];
    }
}

if (!function_exists('bk_distribution_allowed_methods_for_claim_type')) {
    function bk_distribution_allowed_methods_for_claim_type(string $claimType): array
    {
        $map = bk_distribution_allowed_methods_by_claim_type();
        $normalized = strtolower(trim($claimType));
        return $map[$normalized] ?? [];
    }
}

if (!function_exists('bk_distribution_required_fields_by_method')) {
    // [DIST] Required fields per method.
    function bk_distribution_required_fields_by_method(): array
    {
        return [
            'bk_account_transfer' => ['account_name', 'account_number'],
            'other_bank_transfer' => ['destination_bank', 'destination_account_name', 'destination_account_number'],
            'mobile_money' => ['mobile_network', 'wallet_registered_name', 'mobile_wallet_number'],
            'cheque' => ['payee_name', 'collection_branch'],
            'hold_pending_instruction' => [],
            'cash_pickup' => ['pickup_branch', 'pickup_person_name', 'pickup_phone'],
            'transfer_to_claimant_account' => ['bank_name', 'account_name', 'account_number'],
            'transfer_to_other_bank' => ['destination_bank', 'destination_account_name', 'destination_account_number'],
            'mobile_money_wallet' => ['mobile_network', 'wallet_registered_name', 'mobile_wallet_number'],
            'bank_draft' => ['payee_name', 'collection_branch'],
            'split_payout_accounts' => ['split_primary_bank_name', 'split_primary_account_name', 'split_primary_account_number', 'split_primary_percentage', 'split_secondary_bank_name', 'split_secondary_account_name', 'split_secondary_account_number', 'split_secondary_percentage'],
            'staged_installments' => ['installment_bank_name', 'installment_account_name', 'installment_account_number', 'installment_count', 'installment_frequency', 'first_installment_date'],
            'sell_shares_cash' => ['destination_bank', 'destination_account_name', 'destination_account_number'],
            'transfer_shares_claimant' => ['shares_account_name', 'shares_account_number', 'broker_or_cds_reference'],
            'partial_sale_partial_transfer' => ['partial_sale_percentage', 'partial_cash_destination_bank', 'partial_cash_destination_account_name', 'partial_cash_destination_account_number', 'partial_shares_account_name', 'partial_shares_account_number', 'partial_broker_or_cds_reference'],
            'transfer_shares_nominee' => ['nominee_full_name', 'nominee_national_id', 'nominee_shares_account_name', 'nominee_shares_account_number', 'nominee_broker_or_cds_reference'],
            'liquidate_assets' => ['destination_bank', 'destination_account_name', 'destination_account_number'],
            'mixed_distribution' => ['split_instructions'],
        ];
    }
}

if (!function_exists('bk_distribution_fields_by_method')) {
    // [DIST] Full field set per method (required + optional).
    function bk_distribution_fields_by_method(): array
    {
        return [
            'bk_account_transfer' => ['account_name', 'account_number', 'branch_name'],
            'other_bank_transfer' => ['destination_bank', 'destination_account_name', 'destination_account_number', 'destination_branch'],
            'mobile_money' => ['mobile_network', 'wallet_registered_name', 'mobile_wallet_number', 'wallet_reference_note'],
            'cheque' => ['payee_name', 'collection_branch', 'contact_phone'],
            'hold_pending_instruction' => ['notes'],
            'cash_pickup' => ['pickup_branch', 'pickup_person_name', 'pickup_phone', 'pickup_id_number'],
            'transfer_to_claimant_account' => ['bank_name', 'account_name', 'account_number', 'branch_name'],
            'transfer_to_other_bank' => ['destination_bank', 'destination_account_name', 'destination_account_number', 'destination_branch', 'swift_bic'],
            'mobile_money_wallet' => ['mobile_network', 'wallet_registered_name', 'mobile_wallet_number', 'wallet_reference_note'],
            'bank_draft' => ['payee_name', 'collection_branch', 'contact_phone'],
            'split_payout_accounts' => ['split_primary_bank_name', 'split_primary_account_name', 'split_primary_account_number', 'split_primary_percentage', 'split_secondary_bank_name', 'split_secondary_account_name', 'split_secondary_account_number', 'split_secondary_percentage', 'split_notes'],
            'staged_installments' => ['installment_bank_name', 'installment_account_name', 'installment_account_number', 'installment_count', 'installment_frequency', 'first_installment_date', 'installment_notes'],
            'sell_shares_cash' => ['destination_bank', 'destination_account_name', 'destination_account_number'],
            'transfer_shares_claimant' => ['shares_account_name', 'shares_account_number', 'broker_or_cds_reference'],
            'partial_sale_partial_transfer' => ['partial_sale_percentage', 'partial_cash_destination_bank', 'partial_cash_destination_account_name', 'partial_cash_destination_account_number', 'partial_shares_account_name', 'partial_shares_account_number', 'partial_broker_or_cds_reference', 'partial_notes'],
            'transfer_shares_nominee' => ['nominee_full_name', 'nominee_national_id', 'nominee_shares_account_name', 'nominee_shares_account_number', 'nominee_broker_or_cds_reference', 'nominee_phone'],
            'liquidate_assets' => ['destination_bank', 'destination_account_name', 'destination_account_number', 'liquidation_notes'],
            'mixed_distribution' => ['split_instructions'],
        ];
    }
}

if (!function_exists('bk_distribution_field_labels')) {
    function bk_distribution_field_labels(): array
    {
        return [
            'pickup_branch' => 'Pickup branch',
            'pickup_person_name' => 'Recipient full name',
            'pickup_phone' => 'Recipient phone number',
            'pickup_id_number' => 'Recipient ID number',
            'bank_name' => 'Bank name',
            'account_name' => 'Account holder name',
            'account_number' => 'Account number',
            'branch_name' => 'Branch',
            'destination_branch' => 'Destination branch',
            'swift_bic' => 'SWIFT / BIC (if available)',
            'payee_name' => 'Payee full name',
            'collection_branch' => 'Collection branch',
            'contact_phone' => 'Contact phone',
            'destination_bank' => 'Destination bank',
            'destination_account_name' => 'Destination account name',
            'destination_account_number' => 'Destination account number',
            'mobile_network' => 'Mobile network',
            'wallet_registered_name' => 'Wallet registered name',
            'mobile_wallet_number' => 'Mobile wallet number',
            'wallet_reference_note' => 'Wallet transfer note',
            'split_primary_bank_name' => 'Primary payout bank',
            'split_primary_account_name' => 'Primary account name',
            'split_primary_account_number' => 'Primary account number',
            'split_primary_percentage' => 'Primary payout percentage',
            'split_secondary_bank_name' => 'Secondary payout bank',
            'split_secondary_account_name' => 'Secondary account name',
            'split_secondary_account_number' => 'Secondary account number',
            'split_secondary_percentage' => 'Secondary payout percentage',
            'split_notes' => 'Split payout notes',
            'installment_bank_name' => 'Installment bank',
            'installment_account_name' => 'Installment account name',
            'installment_account_number' => 'Installment account number',
            'installment_count' => 'Number of installments',
            'installment_frequency' => 'Installment frequency',
            'first_installment_date' => 'First installment date',
            'installment_notes' => 'Installment notes',
            'shares_account_name' => 'Securities account name',
            'shares_account_number' => 'Securities account number',
            'broker_or_cds_reference' => 'Broker / CDS reference',
            'partial_sale_percentage' => 'Percentage to sell for cash',
            'partial_cash_destination_bank' => 'Cash destination bank',
            'partial_cash_destination_account_name' => 'Cash destination account name',
            'partial_cash_destination_account_number' => 'Cash destination account number',
            'partial_shares_account_name' => 'Shares destination account name',
            'partial_shares_account_number' => 'Shares destination account number',
            'partial_broker_or_cds_reference' => 'Shares broker / CDS reference',
            'partial_notes' => 'Partial settlement notes',
            'nominee_full_name' => 'Nominee full name',
            'nominee_national_id' => 'Nominee national ID / passport',
            'nominee_shares_account_name' => 'Nominee securities account name',
            'nominee_shares_account_number' => 'Nominee securities account number',
            'nominee_broker_or_cds_reference' => 'Nominee broker / CDS reference',
            'nominee_phone' => 'Nominee phone number',
            'liquidation_notes' => 'Liquidation notes',
            'split_instructions' => 'Distribution plan',
            'notes' => 'Notes',
        ];
    }
}

if (!function_exists('bk_distribution_mobile_network_options')) {
    function bk_distribution_mobile_network_options(): array
    {
        return ['MTN MoMo', 'Airtel Money'];
    }
}

if (!function_exists('bk_distribution_rwanda_bank_options')) {
    function bk_distribution_rwanda_bank_options(): array
    {
        return [
            'Bank of Kigali',
            'BPR Bank Rwanda Plc',
            'Equity Bank Rwanda Plc',
            'I&M Bank Rwanda Plc',
            'Ecobank Rwanda Plc',
            'KCB Bank Rwanda Plc',
            'Access Bank Rwanda Plc',
            'GTBank Rwanda',
            'NCBA Bank Rwanda Plc',
            'Cogebanque Plc',
            'Urwego Bank Plc',
            'Other bank in Rwanda (subject to BK verification)',
        ];
    }
}

if (!function_exists('bk_distribution_bk_branch_options')) {
    function bk_distribution_bk_branch_options(): array
    {
        return [
            'Kigali Main Branch',
            'Kacyiru Branch',
            'Remera Branch',
            'Kimironko Branch',
            'Nyabugogo Branch',
            'Nyamirambo Branch',
            'Gisozi Branch',
            'Kicukiro Branch',
            'Huye Branch',
            'Musanze Branch',
            'Rubavu Branch',
            'Rusizi Branch',
            'Rwamagana Branch',
            'Muhanga Branch',
            'Nyagatare Branch',
            'Other BK branch (confirm with staff)',
        ];
    }
}

if (!function_exists('bk_distribution_select_options_by_field')) {
    function bk_distribution_select_options_by_field(): array
    {
        $bankOptions = bk_distribution_rwanda_bank_options();
        return [
            'mobile_network' => bk_distribution_mobile_network_options(),
            'bank_name' => $bankOptions,
            'destination_bank' => $bankOptions,
            'split_primary_bank_name' => $bankOptions,
            'split_secondary_bank_name' => $bankOptions,
            'installment_bank_name' => $bankOptions,
            'partial_cash_destination_bank' => $bankOptions,
            'branch_name' => bk_distribution_bk_branch_options(),
            'pickup_branch' => bk_distribution_bk_branch_options(),
            'collection_branch' => bk_distribution_bk_branch_options(),
            'installment_frequency' => ['Monthly', 'Bi-Monthly', 'Quarterly'],
        ];
    }
}

if (!function_exists('bk_distribution_field_allowed_options')) {
    function bk_distribution_field_allowed_options(string $fieldKey): array
    {
        $map = bk_distribution_select_options_by_field();
        return $map[$fieldKey] ?? [];
    }
}

if (!function_exists('bk_distribution_strlen')) {
    function bk_distribution_strlen(string $value): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value) : strlen($value);
    }
}

if (!function_exists('bk_distribution_parse_details_payload')) {
    // [DIST] Parse and normalize details payload JSON.
    function bk_distribution_parse_details_payload(string $raw, ?string &$errorMessage = null): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            $errorMessage = 'Please complete settlement details using the required fields.';
            return null;
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $cleanKey = strtolower(trim((string) $key));
            if ($cleanKey === '') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $cleanValue = trim((string) $value);
            if ($cleanValue !== '') {
                $normalized[$cleanKey] = $cleanValue;
            }
        }

        return $normalized;
    }
}

if (!function_exists('bk_distribution_validate_field_value')) {
    // [DIST] Validate one distribution field value.
    function bk_distribution_validate_field_value(string $fieldKey, string $value, bool $required, ?string &$errorMessage = null): bool
    {
        $trimmed = trim($value);
        $labels = bk_distribution_field_labels();
        $label = $labels[$fieldKey] ?? ucwords(str_replace('_', ' ', $fieldKey));

        if ($required && $trimmed === '') {
            $errorMessage = 'Please provide ' . strtolower($label) . '.';
            return false;
        }

        if ($trimmed === '') {
            return true;
        }

        $allowedOptions = bk_distribution_field_allowed_options($fieldKey);
        $strictSelectFields = ['mobile_network', 'installment_frequency'];
        if (
            in_array($fieldKey, $strictSelectFields, true)
            && !empty($allowedOptions)
            && !in_array($trimmed, $allowedOptions, true)
        ) {
            $errorMessage = 'Please choose a valid ' . strtolower($label) . '.';
            return false;
        }

        $len = bk_distribution_strlen($trimmed);
        $maxLengthByField = [
            'split_instructions' => 1200,
            'liquidation_notes' => 500,
            'wallet_reference_note' => 500,
            'split_notes' => 500,
            'installment_notes' => 500,
            'partial_notes' => 500,
        ];
        $maxLength = $maxLengthByField[$fieldKey] ?? 255;
        if ($len > $maxLength) {
            $errorMessage = $label . ' is too long.';
            return false;
        }

        switch ($fieldKey) {
            case 'pickup_phone':
            case 'contact_phone':
            case 'mobile_wallet_number':
            case 'nominee_phone':
                $compact = preg_replace('/[\s\-]/', '', $trimmed);
                if (!preg_match('/^\+?[0-9]{9,15}$/', (string) $compact)) {
                    $errorMessage = $label . ' must be a valid phone number (9-15 digits).';
                    return false;
                }
                break;

            case 'account_number':
            case 'destination_account_number':
            case 'shares_account_number':
            case 'split_primary_account_number':
            case 'split_secondary_account_number':
            case 'installment_account_number':
            case 'partial_cash_destination_account_number':
            case 'partial_shares_account_number':
            case 'nominee_shares_account_number':
                if (!preg_match('/^[A-Za-z0-9\-]{6,34}$/', $trimmed)) {
                    $errorMessage = $label . ' must be 6-34 characters using letters, numbers, or hyphen.';
                    return false;
                }
                break;

            case 'pickup_person_name':
            case 'account_name':
            case 'payee_name':
            case 'destination_account_name':
            case 'shares_account_name':
            case 'wallet_registered_name':
            case 'split_primary_account_name':
            case 'split_secondary_account_name':
            case 'installment_account_name':
            case 'partial_cash_destination_account_name':
            case 'partial_shares_account_name':
            case 'nominee_full_name':
            case 'nominee_shares_account_name':
                if ($len < 3 || !preg_match('/^[A-Za-z][A-Za-z .\'-]{2,119}$/', $trimmed)) {
                    $errorMessage = $label . ' must be a valid name.';
                    return false;
                }
                break;

            case 'bank_name':
            case 'destination_bank':
            case 'branch_name':
            case 'destination_branch':
            case 'split_primary_bank_name':
            case 'split_secondary_bank_name':
            case 'installment_bank_name':
            case 'partial_cash_destination_bank':
                if ($len < 2) {
                    $errorMessage = $label . ' is too short.';
                    return false;
                }
                break;

            case 'pickup_branch':
            case 'collection_branch':
                if ($len < 2) {
                    $errorMessage = $label . ' is too short.';
                    return false;
                }
                break;

            case 'mobile_network':
                if (!in_array($trimmed, bk_distribution_mobile_network_options(), true)) {
                    $errorMessage = 'Please choose a valid mobile network.';
                    return false;
                }
                break;

            case 'pickup_id_number':
                if (!preg_match('/^[A-Za-z0-9\-\/]{4,30}$/', $trimmed)) {
                    $errorMessage = $label . ' format is invalid.';
                    return false;
                }
                break;

            case 'broker_or_cds_reference':
            case 'partial_broker_or_cds_reference':
            case 'nominee_broker_or_cds_reference':
                if ($len < 3 || $len > 80) {
                    $errorMessage = $label . ' must be 3-80 characters.';
                    return false;
                }
                break;

            case 'swift_bic':
                if (!preg_match('/^[A-Za-z0-9]{8}([A-Za-z0-9]{3})?$/', $trimmed)) {
                    $errorMessage = $label . ' must be 8 or 11 alphanumeric characters.';
                    return false;
                }
                break;

            case 'split_primary_percentage':
            case 'split_secondary_percentage':
            case 'partial_sale_percentage':
                if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $trimmed)) {
                    $errorMessage = $label . ' must be a valid percentage.';
                    return false;
                }
                $number = (float) $trimmed;
                if ($number <= 0 || $number >= 100) {
                    $errorMessage = $label . ' must be between 0 and 100.';
                    return false;
                }
                break;

            case 'installment_count':
                if (!preg_match('/^\d{1,2}$/', $trimmed)) {
                    $errorMessage = $label . ' must be a number between 2 and 12.';
                    return false;
                }
                $count = (int) $trimmed;
                if ($count < 2 || $count > 12) {
                    $errorMessage = $label . ' must be between 2 and 12.';
                    return false;
                }
                break;

            case 'installment_frequency':
                if (!preg_match('/^(monthly|bi[\s\-]?monthly|quarterly)$/i', $trimmed)) {
                    $errorMessage = $label . ' must be Monthly, Bi-Monthly, or Quarterly.';
                    return false;
                }
                break;

            case 'first_installment_date':
                if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $trimmed) || strtotime($trimmed) === false) {
                    $errorMessage = $label . ' must be a valid date.';
                    return false;
                }
                break;

            case 'nominee_national_id':
                $compactId = preg_replace('/[\s\-]/', '', $trimmed);
                $isRwandaId = preg_match('/^\d{16}$/', (string) $compactId) === 1;
                $isPassport = preg_match('/^[A-Za-z]{1,2}\d{6,8}$/', $trimmed) === 1;
                if (!$isRwandaId && !$isPassport) {
                    $errorMessage = $label . ' must be a valid Rwanda national ID or passport number.';
                    return false;
                }
                break;

            case 'split_instructions':
                if ($len < 15) {
                    $errorMessage = 'Distribution plan must be at least 15 characters.';
                    return false;
                }
                if ($len > 1200) {
                    $errorMessage = 'Distribution plan is too long.';
                    return false;
                }
                break;

            case 'wallet_reference_note':
            case 'split_notes':
            case 'installment_notes':
            case 'partial_notes':
            case 'liquidation_notes':
                if ($len > 500) {
                    $errorMessage = $label . ' is too long.';
                    return false;
                }
                break;
        }

        return true;
    }
}

if (!function_exists('bk_distribution_validate_selection')) {
    // [DIST] Validate method + required fields + field formats.
    function bk_distribution_validate_selection(string $claimType, string $method, array $details, ?string &$errorMessage = null): ?array
    {
        $claimTypeKey = strtolower(trim($claimType));
        $methodKey = strtolower(trim($method));
        $allowedByType = bk_distribution_allowed_methods_by_claim_type();

        if (!isset($allowedByType[$claimTypeKey])) {
            $errorMessage = 'Invalid claim type selected.';
            return null;
        }

        if ($methodKey === '') {
            $errorMessage = 'Please choose how you want to receive the claimed assets.';
            return null;
        }

        if (!in_array($methodKey, $allowedByType[$claimTypeKey], true)) {
            $errorMessage = 'The selected settlement method does not match the chosen asset type.';
            return null;
        }

        $requiredByMethod = bk_distribution_required_fields_by_method();
        $fieldsByMethod = bk_distribution_fields_by_method();
        $methodFields = $fieldsByMethod[$methodKey] ?? [];
        $requiredFields = $requiredByMethod[$methodKey] ?? [];

        if (!empty($requiredFields) && empty($details)) {
            $errorMessage = 'Please provide the required settlement details for the selected method.';
            return null;
        }

        $normalized = [];
        foreach ($methodFields as $fieldKey) {
            $value = trim((string) ($details[$fieldKey] ?? ''));
            $isRequired = in_array($fieldKey, $requiredFields, true);
            if (!bk_distribution_validate_field_value($fieldKey, $value, $isRequired, $errorMessage)) {
                return null;
            }
            if ($value !== '') {
                $normalized[$fieldKey] = $value;
            }
        }

        if ($methodKey === 'split_payout_accounts') {
            $primary = (float) ($normalized['split_primary_percentage'] ?? '0');
            $secondary = (float) ($normalized['split_secondary_percentage'] ?? '0');
            if (abs(($primary + $secondary) - 100.0) > 0.01) {
                $errorMessage = 'Primary and secondary payout percentages must total 100%.';
                return null;
            }
        }

        return $normalized;
    }
}

if (!function_exists('bk_distribution_detail_rows')) {
    // [DIST] Convert stored details JSON for UI display.
    function bk_distribution_detail_rows(?string $rawDetails): array
    {
        $raw = trim((string) $rawDetails);
        if ($raw === '') {
            return [];
        }

        $labels = bk_distribution_field_labels();
        $rows = [];
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [
                ['label' => 'Details', 'value' => $raw],
            ];
        }

        foreach ($decoded as $key => $value) {
            $cleanKey = strtolower(trim((string) $key));
            if ($cleanKey === '' || $cleanKey === 'method') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $cleanValue = trim((string) $value);
            if ($cleanValue === '') {
                continue;
            }

            $rows[] = [
                'label' => $labels[$cleanKey] ?? ucwords(str_replace('_', ' ', $cleanKey)),
                'value' => $cleanValue,
            ];
        }

        return $rows;
    }
}
