<?php
// Tags: [COMPONENT] [UI]
require_once __DIR__ . '/currency.php';

if (!function_exists('bk_e')) {
    function bk_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bk_attrs')) {
    function bk_attrs(array $attrs): string
    {
        $parts = [];

        foreach ($attrs as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $parts[] = bk_e($key);
                continue;
            }

            $parts[] = bk_e($key) . '="' . bk_e((string) $value) . '"';
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('bk_class')) {
    function bk_class(...$values): string
    {
        $out = [];

        foreach ($values as $value) {
            if (!$value) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $nested) {
                    if ($nested) {
                        $out[] = trim((string) $nested);
                    }
                }
            } else {
                $out[] = trim((string) $value);
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $out)) ?? '');
    }
}

if (!function_exists('bk_claim_type_labels')) {
    function bk_claim_type_labels(): array
    {
        return [
            'bank_account' => 'Current/Transaction Account Balances',
            'savings' => 'Savings Account Balances',
            'fixed_deposit' => 'Fixed / Term Deposit',
            'shares' => 'Shares / Securities',
            'investment' => 'Investment Account (Funds/Bonds)',
            'safe_deposit' => 'Safe Deposit Box Contents',
            'multiple' => 'Multiple Asset Types (same estate)',
            'other' => 'Other BK-Held Assets (requires review)',
        ];
    }
}

if (!function_exists('bk_claim_type_label')) {
    function bk_claim_type_label(?string $claimType): string
    {
        $key = strtolower(trim((string) $claimType));
        if ($key === '') {
            return 'Unknown';
        }

        $labels = bk_claim_type_labels();
        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('bk_claim_amount_numeric')) {
    function bk_claim_amount_numeric($rawAmount): ?float
    {
        $text = str_replace([',', ' '], '', trim((string) $rawAmount));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        $value = (float) $text;
        return $value > 0 ? $value : null;
    }
}

if (!function_exists('bk_claim_amount_display')) {
    function bk_claim_amount_display($rawAmount, string $currency = 'RWF', string $fallback = 'Not declared'): string
    {
        $value = bk_claim_amount_numeric($rawAmount);
        if ($value === null) {
            return $fallback;
        }

        $prefix = bk_currency_code($currency);
        $formatted = number_format($value, bk_currency_decimals($prefix));
        return $prefix !== '' ? ($prefix . ' ' . $formatted) : $formatted;
    }
}

if (!function_exists('bk_claim_amount_display_for_type')) {
    function bk_claim_amount_display_for_type($rawAmount, ?string $claimType, string $currency = 'RWF'): string
    {
        $type = strtolower(trim((string) $claimType));
        $fallback = in_array($type, ['shares', 'safe_deposit', 'multiple', 'other'], true)
            ? 'Asset value pending finance assessment'
            : 'Value not provided by claimant (finance will assess)';

        return bk_claim_amount_display($rawAmount, $currency, $fallback);
    }
}

if (!function_exists('bk_claim_distribution_details_array')) {
    function bk_claim_distribution_details_array(?string $rawDetails): array
    {
        $raw = trim((string) $rawDetails);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            $cleanKey = strtolower(trim((string) $key));
            if ($cleanKey === '' || is_array($value) || is_object($value)) {
                continue;
            }

            $cleanValue = trim((string) $value);
            if ($cleanValue === '') {
                continue;
            }
            $out[$cleanKey] = $cleanValue;
        }

        return $out;
    }
}

if (!function_exists('bk_claim_destination_summary')) {
    function bk_claim_destination_summary(?string $legacyAccount, ?string $distributionMethod = null, ?string $distributionDetails = null): string
    {
        $legacy = trim((string) $legacyAccount);
        if ($legacy !== '') {
            return 'Account: ' . $legacy;
        }

        $details = bk_claim_distribution_details_array($distributionDetails);
        $priorityFields = [
            'account_number' => 'Account',
            'destination_account_number' => 'Destination account',
            'mobile_wallet_number' => 'Mobile wallet',
            'shares_account_number' => 'Securities account',
            'split_primary_account_number' => 'Primary account',
            'split_secondary_account_number' => 'Secondary account',
            'installment_account_number' => 'Installment account',
            'partial_cash_destination_account_number' => 'Cash destination account',
            'partial_shares_account_number' => 'Shares destination account',
            'nominee_shares_account_number' => 'Nominee securities account',
            'pickup_branch' => 'Pickup branch',
            'collection_branch' => 'Collection branch',
        ];

        foreach ($priorityFields as $key => $label) {
            $value = trim((string) ($details[$key] ?? ''));
            if ($value !== '') {
                return $label . ': ' . $value;
            }
        }

        $method = strtolower(trim((string) $distributionMethod));
        if (in_array($method, ['inspection_access', 'transfer_ownership', 'liquidate_assets'], true)) {
            return 'No bank account required (physical asset process)';
        }
        if (in_array($method, ['consultation_required', 'mixed_distribution'], true)) {
            return 'To be finalized with Bank of Kigali';
        }
        if ($method === 'cash_pickup') {
            return 'Cash pickup (branch confirmation pending)';
        }

        return 'Not provided yet';
    }
}

if (!function_exists('bk_claim_account_reference')) {
    function bk_claim_account_reference(array $claim): string
    {
        $primary = trim((string) ($claim['account_number'] ?? ''));
        if ($primary !== '') {
            return $primary;
        }

        return trim((string) ($claim['accout_number'] ?? ''));
    }
}

if (!function_exists('udcs_exit_public_error')) {
    function udcs_exit_public_error(string $logContext, string $publicMessage = 'The requested report could not be generated right now.', ?mysqli $conn = null): void
    {
        $details = $conn instanceof mysqli ? mysqli_error($conn) : '';
        error_log(trim($logContext . ($details !== '' ? ' | ' . $details : '')));
        exit($publicMessage);
    }
}

