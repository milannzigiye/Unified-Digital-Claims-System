<?php
// Tags: [CURRENCY] [BK] [AMOUNTS]
// [CURRENCY] Central registry for BK-supported claim currencies.

if (!function_exists('bk_supported_currencies')) {
    function bk_supported_currencies(): array
    {
        return [
            'RWF' => ['label' => 'Rwandan Franc', 'decimals' => 0],
            'USD' => ['label' => 'US Dollar', 'decimals' => 2],
            'EUR' => ['label' => 'Euro', 'decimals' => 2],
            'GBP' => ['label' => 'British Pound', 'decimals' => 2],
            'CHF' => ['label' => 'Swiss Franc', 'decimals' => 2],
            'CAD' => ['label' => 'Canadian Dollar', 'decimals' => 2],
            'KES' => ['label' => 'Kenyan Shilling', 'decimals' => 2],
            'UGX' => ['label' => 'Ugandan Shilling', 'decimals' => 0],
            'TZS' => ['label' => 'Tanzanian Shilling', 'decimals' => 0],
            'JPY' => ['label' => 'Japanese Yen', 'decimals' => 0],
            'BIF' => ['label' => 'Burundian Franc', 'decimals' => 0],
        ];
    }
}

if (!function_exists('bk_supported_currency_options')) {
    function bk_supported_currency_options(): array
    {
        $options = [];
        foreach (bk_supported_currencies() as $code => $meta) {
            $options[$code] = $code . ' - ' . (string) ($meta['label'] ?? $code);
        }
        return $options;
    }
}

if (!function_exists('bk_currency_code')) {
    function bk_currency_code(?string $currency, string $fallback = 'RWF'): string
    {
        $code = strtoupper(trim((string) $currency));
        $fallbackCode = strtoupper(trim($fallback)) ?: 'RWF';
        $supported = bk_supported_currencies();
        if (isset($supported[$code])) {
            return $code;
        }
        return isset($supported[$fallbackCode]) ? $fallbackCode : 'RWF';
    }
}

if (!function_exists('bk_currency_decimals')) {
    function bk_currency_decimals(?string $currency): int
    {
        $code = bk_currency_code($currency);
        $meta = bk_supported_currencies()[$code] ?? [];
        return max(0, min(4, (int) ($meta['decimals'] ?? 2)));
    }
}

if (!function_exists('bk_asset_supported_currency_codes')) {
    function bk_asset_supported_currency_codes(?string $assetClass = null): array
    {
        $assetKey = strtolower(trim((string) $assetClass));
        $all = array_keys(bk_supported_currencies());

        return match ($assetKey) {
            // BK listed shares are treated as local RWF securities unless Finance records a broader investment account.
            'shares_securities', 'shares' => ['RWF'],
            default => $all,
        };
    }
}

if (!function_exists('bk_asset_currency_supported')) {
    function bk_asset_currency_supported(?string $assetClass, ?string $currency): bool
    {
        return in_array(bk_currency_code($currency), bk_asset_supported_currency_codes($assetClass), true);
    }
}

if (!function_exists('bk_asset_currency_code')) {
    function bk_asset_currency_code(?string $assetClass, ?string $currency, string $fallback = 'RWF'): string
    {
        $code = bk_currency_code($currency, $fallback);
        if (bk_asset_currency_supported($assetClass, $code)) {
            return $code;
        }

        $allowed = bk_asset_supported_currency_codes($assetClass);
        return $allowed[0] ?? bk_currency_code($fallback);
    }
}

if (!function_exists('bk_amount_totals_label')) {
    function bk_amount_totals_label(array $totalsByCurrency, string $fallback = 'Not declared'): string
    {
        $labels = [];
        foreach (bk_supported_currencies() as $code => $_meta) {
            if (!array_key_exists($code, $totalsByCurrency)) {
                continue;
            }
            $value = $totalsByCurrency[$code];
            if ($value === null || $value === '') {
                continue;
            }
            if (function_exists('bk_claim_amount_display')) {
                $labels[] = bk_claim_amount_display($value, $code, '');
            } elseif (is_numeric($value)) {
                $labels[] = $code . ' ' . number_format((float) $value, bk_currency_decimals($code));
            }
        }

        return !empty($labels) ? implode(' | ', $labels) : $fallback;
    }
}
