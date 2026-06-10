<?php
// Tags: [PDF] [THEME] [UDCS]

if (!function_exists('udcs_pdf_clean_text')) {
    function udcs_pdf_clean_text($value, string $fallback = '-'): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return $fallback;
        }

        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        return $text !== '' ? $text : $fallback;
    }
}

if (!function_exists('udcs_pdf_compact_text')) {
    function udcs_pdf_compact_text(string $text, int $maxLength): string
    {
        $maxLength = max(8, $maxLength);
        $safe = udcs_pdf_clean_text($text, '-');

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($safe, 'UTF-8') <= $maxLength) {
                return $safe;
            }
            return rtrim((string) mb_substr($safe, 0, $maxLength - 1, 'UTF-8')) . '...';
        }

        if (strlen($safe) <= $maxLength) {
            return $safe;
        }

        return rtrim(substr($safe, 0, $maxLength - 1)) . '...';
    }
}

if (!function_exists('udcs_pdf_soft_wrap_token_text')) {
    function udcs_pdf_soft_wrap_token_text(string $text, int $chunkLength = 18): string
    {
        $chunkLength = max(8, $chunkLength);
        $pattern = '/\S{' . ($chunkLength + 1) . ',}/';

        $wrapped = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($chunkLength): string {
                $token = (string) ($matches[0] ?? '');
                if ($token === '') {
                    return '';
                }

                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    $parts = [];
                    $length = (int) mb_strlen($token, 'UTF-8');
                    for ($offset = 0; $offset < $length; $offset += $chunkLength) {
                        $parts[] = (string) mb_substr($token, $offset, $chunkLength, 'UTF-8');
                    }
                    return implode(' ', $parts);
                }

                return trim((string) chunk_split($token, $chunkLength, ' '));
            },
            $text
        );

        return $wrapped ?? $text;
    }
}

if (!function_exists('udcs_pdf_fit_inline_text')) {
    function udcs_pdf_fit_inline_text($pdf, string $text, float $maxWidth, int $maxLength = 64): string
    {
        $maxWidth = max(10.0, $maxWidth);
        $maxLength = max(10, $maxLength);

        $candidate = udcs_pdf_compact_text($text, $maxLength);
        if ($pdf->GetStringWidth($candidate) <= $maxWidth) {
            return $candidate;
        }

        $length = $maxLength;
        while ($length > 10) {
            $length -= 2;
            $candidate = udcs_pdf_compact_text($text, $length);
            if ($pdf->GetStringWidth($candidate) <= $maxWidth) {
                return $candidate;
            }
        }

        return udcs_pdf_compact_text($text, 10);
    }
}

if (!function_exists('udcs_pdf_fit_block_text')) {
    function udcs_pdf_fit_block_text($pdf, string $text, float $innerWidth, int $maxLines = 0): array
    {
        $innerWidth = max(8.0, $innerWidth);
        $safeText = udcs_pdf_clean_text($text, '-');
        $wrapped = udcs_pdf_soft_wrap_token_text($safeText, 18);
        $lineCount = max(1, (int) $pdf->getNumLines($wrapped, $innerWidth));

        if ($maxLines <= 0 || $lineCount <= $maxLines) {
            return ['text' => $wrapped, 'lines' => $lineCount];
        }

        // Optional strict clamp mode (not used by default).
        $approxCharsPerLine = max(10, (int) floor($innerWidth / 1.7));
        $targetChars = $approxCharsPerLine * $maxLines;
        $candidate = udcs_pdf_compact_text($wrapped, $targetChars);
        $candidate = udcs_pdf_soft_wrap_token_text($candidate, 18);
        $candidateLines = max(1, (int) $pdf->getNumLines($candidate, $innerWidth));

        while ($candidateLines > $maxLines && $targetChars > 12) {
            $targetChars -= max(4, (int) floor($approxCharsPerLine * 0.5));
            $candidate = udcs_pdf_compact_text($wrapped, $targetChars);
            $candidate = udcs_pdf_soft_wrap_token_text($candidate, 18);
            $candidateLines = max(1, (int) $pdf->getNumLines($candidate, $innerWidth));
        }

        return ['text' => $candidate, 'lines' => min($candidateLines, $maxLines)];
    }
}

if (!function_exists('udcs_pdf_create_document')) {
    function udcs_pdf_create_document(array $config = [])
    {
        $title = trim((string) ($config['title'] ?? 'Report'));
        $portal = trim((string) ($config['portal'] ?? 'Portal'));
        $model = trim((string) ($config['model'] ?? ''));
        $panel = trim((string) ($config['panel'] ?? ''));
        $systemName = trim((string) ($config['system_name'] ?? 'UNIFIED DIGITAL CLAIMS SYSTEM'));
        $institution = trim((string) ($config['institution'] ?? 'Bank of Kigali'));
        $timestamp = trim((string) ($config['timestamp'] ?? date('Y-m-d H:i:s')));

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator($systemName);
        $pdf->SetAuthor($portal !== '' ? $portal : $systemName);
        $pdf->SetTitle($title !== '' ? $title : 'Report');
        $pdf->SetSubject($panel !== '' ? $panel : $title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(16, 14, 16);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->SetFontSubsetting(true);
        $pdf->setImageScale(2.0);
        $pdf->AddPage();

        udcs_pdf_render_report_header($pdf, [
            'title' => $title,
            'system_name' => $systemName,
            'institution' => $institution,
            'portal' => $portal,
            'model' => $model,
            'panel' => $panel,
            'timestamp' => $timestamp,
        ]);

        return $pdf;
    }
}

if (!function_exists('udcs_pdf_render_report_header')) {
    if (!function_exists('udcs_pdf_resolve_bk_logo_path')) {
        function udcs_pdf_resolve_bk_logo_path(): string
        {
            $baseDir = dirname(__DIR__);
            $hasImageExtension = extension_loaded('gd') || extension_loaded('imagick');

            $candidates = $hasImageExtension
                ? [
                    $baseDir . '/Images/logo.png',
                    $baseDir . '/Images/logo_nav.jpg',
                    $baseDir . '/Images/bk.jpg',
                ]
                : [
                    $baseDir . '/Images/logo_nav.jpg',
                    $baseDir . '/Images/bk.jpg',
                ];

            foreach ($candidates as $path) {
                if (!is_file($path)) {
                    continue;
                }
                return $path;
            }

            return '';
        }
    }

    function udcs_pdf_render_report_header($pdf, array $config = []): void
    {
        $systemName = udcs_pdf_clean_text((string) ($config['system_name'] ?? 'UNIFIED DIGITAL CLAIMS SYSTEM'), 'UNIFIED DIGITAL CLAIMS SYSTEM');
        $institution = udcs_pdf_clean_text((string) ($config['institution'] ?? 'Bank of Kigali'), 'Bank of Kigali');
        $reportTitle = udcs_pdf_clean_text((string) ($config['title'] ?? 'Report'), 'Report');
        $timestamp = udcs_pdf_clean_text((string) ($config['timestamp'] ?? date('Y-m-d H:i:s')), date('Y-m-d H:i:s'));

        $margins = $pdf->getMargins();
        $left = (float) $margins['left'];
        $right = (float) ($pdf->getPageWidth() - $margins['right']);
        $usableWidth = $right - $left;

        $startY = max((float) $pdf->GetY(), 10.0);
        $logoPath = udcs_pdf_resolve_bk_logo_path();
        $hasLogo = $logoPath !== '';
        $titleX = $left;
        $titleWidth = $usableWidth;
        $titleAlign = 'C';

        if ($hasLogo) {
            $logoSize = 11.0;
            $logoGap = 2.6;
            $textBlockWidth = min(132.0, max(62.0, $usableWidth - $logoSize - $logoGap));
            $groupWidth = min($usableWidth, $logoSize + $logoGap + $textBlockWidth);
            $groupX = $left + (($usableWidth - $groupWidth) / 2.0);

            try {
                $pdf->Image(
                    $logoPath,
                    $groupX,
                    $startY + 0.9,
                    $logoSize,
                    $logoSize,
                    '',
                    '',
                    '',
                    true,
                    300,
                    '',
                    false,
                    false,
                    0,
                    false,
                    false,
                    false
                );
            } catch (Throwable $e) {
                $fallbackLogo = dirname(__DIR__) . '/Images/logo_nav.jpg';
                if ($logoPath !== $fallbackLogo && is_file($fallbackLogo)) {
                    try {
                        $pdf->Image(
                            $fallbackLogo,
                            $groupX,
                            $startY + 0.9,
                            $logoSize,
                            $logoSize,
                            '',
                            '',
                            '',
                            true,
                            300,
                            '',
                            false,
                            false,
                            0,
                            false,
                            false,
                            false
                        );
                    } catch (Throwable $e2) {
                        $hasLogo = false;
                    }
                } else {
                    $hasLogo = false;
                }
            }

            if ($hasLogo) {
                $titleX = $groupX + $logoSize + $logoGap;
                $titleWidth = $groupWidth - $logoSize - $logoGap;
                $titleAlign = 'L';
            }
        }

        $pdf->SetY($startY);
        $pdf->SetX($titleX);

        $pdf->SetTextColor(3, 78, 162);
        $pdf->SetFont('helvetica', 'B', 17.5);
        $pdf->Cell($titleWidth, 8.8, udcs_pdf_fit_inline_text($pdf, $systemName, $titleWidth - 2.0, 72), 0, 1, $titleAlign, false);

        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('helvetica', '', 11.2);
        $pdf->SetX($titleX);
        $pdf->Cell($titleWidth, 6.4, udcs_pdf_fit_inline_text($pdf, $institution, $titleWidth - 2.0, 66), 0, 1, $titleAlign, false);

        $pdf->SetTextColor(3, 78, 162);
        $pdf->SetFont('helvetica', 'B', 10.6);
        $pdf->SetX($titleX);
        $pdf->Cell($titleWidth, 6.2, udcs_pdf_fit_inline_text($pdf, $reportTitle, $titleWidth - 2.0, 96), 0, 1, $titleAlign, false);

        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetFont('helvetica', '', 8.8);
        $pdf->SetX($titleX);
        $pdf->Cell($titleWidth, 5.6, 'Generated: ' . udcs_pdf_fit_inline_text($pdf, $timestamp, $titleWidth - 30.0, 40), 0, 1, $titleAlign, false);

        $lineY = (float) $pdf->GetY() + 1.4;
        $pdf->SetDrawColor(3, 78, 162);
        $pdf->SetLineWidth(0.18);
        $pdf->Line($left, $lineY, $right, $lineY);
        $pdf->SetLineWidth(0.1);
        $pdf->SetY($lineY + 4.3);
    }
}

if (!function_exists('udcs_pdf_render_filter_summary')) {
    function udcs_pdf_render_filter_summary($pdf, array $filters): void
    {
        $parts = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $label = udcs_pdf_clean_text((string) ($filter['label'] ?? ''), '');
            $value = trim((string) ($filter['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }

            $normalized = strtolower($value);
            if (in_array($normalized, ['all', 'any', 'any to any'], true)) {
                continue;
            }

            $parts[] = $label . ': ' . udcs_pdf_clean_text($value, '-');
        }

        if (empty($parts)) {
            return;
        }

        udcs_pdf_render_meta_rows($pdf, [
            ['label' => 'Filtered by', 'value' => implode(' | ', $parts)],
        ]);
    }
}

if (!function_exists('udcs_pdf_render_meta_rows')) {
    function udcs_pdf_render_meta_rows($pdf, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $margins = $pdf->getMargins();
        $usableWidth = $pdf->getPageWidth() - ($margins['left'] + $margins['right']);
        $bottomLimit = $pdf->getPageHeight() - $margins['bottom'];

        $pdf->SetDrawColor(211, 223, 242);
        $pdf->SetFillColor(247, 250, 255);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->SetFont('helvetica', '', 8.8);

        foreach ($rows as $key => $entry) {
            if (is_array($entry)) {
                $label = udcs_pdf_clean_text((string) ($entry['label'] ?? ''), '');
                $value = udcs_pdf_clean_text((string) ($entry['value'] ?? ''), '-');
            } else {
                $label = udcs_pdf_clean_text((string) $key, '');
                $value = udcs_pdf_clean_text((string) $entry, '-');
            }

            $lineText = $label !== '' ? ($label . ': ' . $value) : $value;
            $fit = udcs_pdf_fit_block_text($pdf, $lineText, max(10.0, $usableWidth - 2.0), 0);
            $lineCount = max(1, (int) ($fit['lines'] ?? 1));
            $height = max(6.2, ($lineCount * 4.5) + 0.8);

            if (($pdf->GetY() + $height) > $bottomLimit) {
                $pdf->AddPage();
            }

            $pdf->MultiCell(
                $usableWidth,
                $height,
                (string) ($fit['text'] ?? $lineText),
                1,
                'L',
                true,
                1,
                '',
                '',
                true,
                0,
                false,
                true,
                $height,
                'M',
                true
            );
        }

        $pdf->Ln(2.3);
    }
}

if (!function_exists('udcs_pdf_render_section_heading')) {
    function udcs_pdf_render_section_heading($pdf, string $heading): void
    {
        $title = udcs_pdf_clean_text($heading, 'Section');
        $margins = $pdf->getMargins();
        $left = (float) $margins['left'];
        $right = (float) ($pdf->getPageWidth() - $margins['right']);
        $usableWidth = $right - $left;

        $pdf->SetTextColor(3, 78, 162);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell($usableWidth, 6.4, udcs_pdf_fit_inline_text($pdf, $title, $usableWidth - 2.0, 60), 0, 1, 'L', false);

        $lineY = (float) $pdf->GetY() + 0.5;
        $pdf->SetDrawColor(211, 223, 242);
        $pdf->SetLineWidth(0.14);
        $pdf->Line($left, $lineY, $right, $lineY);
        $pdf->SetLineWidth(0.1);
        $pdf->SetY($lineY + 2.5);
    }
}

if (!function_exists('udcs_pdf_render_table')) {
    function udcs_pdf_render_table($pdf, array $columns, array $rows, array $options = []): void
    {
        if (empty($columns)) {
            return;
        }

        $lineHeight = (float) ($options['line_height'] ?? 4.7);
        $headerHeight = (float) ($options['header_height'] ?? 7.0);
        $rowPadding = (float) ($options['row_padding'] ?? 1.0);
        $maxLinesPerCell = (int) ($options['max_lines_per_cell'] ?? 0);

        $margins = $pdf->getMargins();
        $left = (float) $margins['left'];
        $usableWidth = $pdf->getPageWidth() - ($margins['left'] + $margins['right']);
        $bottomLimit = $pdf->getPageHeight() - $margins['bottom'];

        $totalWeight = 0.0;
        foreach ($columns as $column) {
            $totalWeight += (float) ($column['weight'] ?? 1.0);
        }
        if ($totalWeight <= 0.0) {
            $totalWeight = (float) count($columns);
        }

        $widths = [];
        $used = 0.0;
        foreach ($columns as $index => $column) {
            $weight = (float) ($column['weight'] ?? 1.0);
            $width = ($usableWidth * $weight) / $totalWeight;
            if ($index === count($columns) - 1) {
                $width = max(8.0, $usableWidth - $used);
            }
            $widths[] = $width;
            $used += $width;
        }

        if ($used > 0 && abs($used - $usableWidth) > 0.05) {
            $scale = $usableWidth / $used;
            foreach ($widths as $i => $width) {
                $widths[$i] = $width * $scale;
            }
        }

        $drawHeader = static function () use ($pdf, $columns, $widths, $headerHeight): void {
            $pdf->SetDrawColor(196, 211, 234);
            $pdf->SetFillColor(242, 247, 255);
            $pdf->SetTextColor(3, 78, 162);
            $pdf->SetFont('helvetica', 'B', 8.6);

            foreach ($columns as $index => $column) {
                $label = udcs_pdf_clean_text((string) ($column['label'] ?? 'Column'), 'Column');
                $text = udcs_pdf_fit_inline_text($pdf, $label, max(8.0, $widths[$index] - 1.4), 36);
                $pdf->Cell($widths[$index], $headerHeight, $text, 1, $index === count($columns) - 1 ? 1 : 0, 'C', true);
            }

            $pdf->SetTextColor(15, 23, 42);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetFont('helvetica', '', 8.6);
        };

        $drawHeader();

        if (empty($rows)) {
            $pdf->SetDrawColor(196, 211, 234);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($usableWidth, 9, 'No records found for current filters.', 1, 1, 'C', false);
            return;
        }

        $rowIndex = 0;
        foreach ($rows as $row) {
            $cellTexts = [];
            $maxLines = 1;

            foreach ($columns as $index => $column) {
                $key = (string) ($column['key'] ?? '');
                $rawValue = $key !== '' && array_key_exists($key, $row) ? $row[$key] : '';
                $text = udcs_pdf_clean_text((string) $rawValue, '-');
                $fit = udcs_pdf_fit_block_text($pdf, $text, max(8.0, $widths[$index] - 1.6), $maxLinesPerCell);
                $cellTexts[$index] = (string) ($fit['text'] ?? $text);
                $lineCount = max(1, (int) ($fit['lines'] ?? 1));
                if ($lineCount > $maxLines) {
                    $maxLines = $lineCount;
                }
            }

            $rowHeight = max($lineHeight + 1.1, ($maxLines * $lineHeight) + $rowPadding);

            if (($pdf->GetY() + $rowHeight) > $bottomLimit) {
                $pdf->AddPage();
                $drawHeader();
            }

            $y = (float) $pdf->GetY();
            $x = $left;
            $isAlt = ($rowIndex % 2) === 1;

            foreach ($columns as $index => $column) {
                $align = strtoupper((string) ($column['align'] ?? 'L'));
                if (!in_array($align, ['L', 'C', 'R'], true)) {
                    $align = 'L';
                }

                if ($isAlt) {
                    $pdf->SetFillColor(250, 252, 255);
                } else {
                    $pdf->SetFillColor(255, 255, 255);
                }
                $pdf->SetDrawColor(211, 223, 242);
                $pdf->SetXY($x, $y);
                $pdf->MultiCell(
                    $widths[$index],
                    $rowHeight,
                    $cellTexts[$index],
                    1,
                    $align,
                    true,
                    0,
                    '',
                    '',
                    true,
                    0,
                    false,
                    true,
                    $rowHeight,
                    'M',
                    true
                );

                $x += $widths[$index];
            }

            $pdf->SetY($y + $rowHeight);
            $rowIndex++;
        }
    }
}
