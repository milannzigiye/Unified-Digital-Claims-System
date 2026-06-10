<?php
// Tags: [ADMIN] [AUDIT] [REPORT] [PDF]
require_once '../security.php';
secure_session_start();

require_once '../connect.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/workflow.php';
require_once dirname(__DIR__) . '/components/claims_v2.php';

if (!isset($_SESSION['email']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    exit('Unauthorized');
}

$tcpdfPath = dirname(__DIR__) . '/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    exit('TCPDF library not found.');
}
require_once $tcpdfPath;
require_once dirname(__DIR__) . '/components/pdf_report_theme.php';

bk_activity_ensure_schema($conn);
udcs_claims_v2_ensure_schema($conn);
bk_activity_backfill_account_creation_events($conn, 500);

$filterRole = trim((string) ($_GET['role'] ?? ''));
$filterAction = trim((string) ($_GET['action'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

if (!function_exists('admin_activity_humanize_text')) {
    function admin_activity_humanize_text(?string $raw): string
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return '-';
        }

        $text = preg_replace_callback(
            '/\b[a-z0-9]+(?:_[a-z0-9]+)+\b/i',
            static function (array $matches): string {
                $token = strtolower((string) ($matches[0] ?? ''));
                return ucwords(str_replace('_', ' ', $token));
            },
            $text
        ) ?? $text;

        // Hide implementation wording from PDF labels.
        $text = preg_replace('/\bbackfill\b\s*:?\s*/i', '', $text) ?? $text;
        $text = str_ireplace('backfilled', 'assigned', $text);

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text) !== '' ? trim($text) : '-';
    }
}

if (!function_exists('admin_activity_meta_summary')) {
    function admin_activity_meta_summary(?string $rawMeta): string
    {
        $text = trim((string) $rawMeta);
        if ($text === '') {
            return '';
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return admin_activity_humanize_text($text);
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === 'backfilled') {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }

            $label = ucwords(str_replace('_', ' ', trim((string) $key)));
            $cleanValue = admin_activity_humanize_text((string) $value);
            if ($label === '' || $cleanValue === '-') {
                continue;
            }
            $parts[] = $label . ': ' . $cleanValue;
        }

        return !empty($parts) ? implode(' | ', $parts) : admin_activity_humanize_text($text);
    }
}

$whereParts = ['1=1'];
$types = '';
$params = [];
if ($filterRole !== '') {
    $whereParts[] = 'a.actor_role = ?';
    $types .= 's';
    $params[] = $filterRole;
}
if ($filterAction !== '') {
    $whereParts[] = '(a.action_key LIKE ? OR a.action_label LIKE ?)';
    $term = '%' . $filterAction . '%';
    $types .= 'ss';
    $params[] = $term;
    $params[] = $term;
}
if ($search !== '') {
    $whereParts[] = '(a.details LIKE ? OR a.meta_json LIKE ? OR a.action_label LIKE ? OR u.full_name LIKE ? OR CAST(a.claim_id AS CHAR) LIKE ?)';
    $term = '%' . $search . '%';
    $types .= 'sssss';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $whereParts[] = 'DATE(a.created_at) >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $whereParts[] = 'DATE(a.created_at) <= ?';
    $types .= 's';
    $params[] = $dateTo;
}
$whereSql = implode(' AND ', $whereParts);

$sql = "
SELECT
    a.id,
    a.actor_role,
    a.claim_id,
    a.action_key,
    a.action_label,
    a.details,
    a.meta_json,
    DATE_FORMAT(a.created_at, '%Y-%m-%d %H:%i') AS created_label,
    u.full_name AS actor_name
FROM activity_logs a
LEFT JOIN users u ON u.id = a.actor_id
WHERE $whereSql
ORDER BY a.created_at DESC
";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    udcs_exit_public_error('Admin activity export failed during statement preparation.', 'The activity report could not be generated right now.', $conn);
}

if (!udcs_db_stmt_bind($stmt, $types, $params) || !mysqli_stmt_execute($stmt)) {
    udcs_exit_public_error('Admin activity export failed during statement execution.', 'The activity report could not be generated right now.', $conn);
}

$result = mysqli_stmt_get_result($stmt);
if (!$result) {
    udcs_exit_public_error('Admin activity export failed while reading the result set.', 'The activity report could not be generated right now.', $conn);
}

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $claimId = (int) ($row['claim_id'] ?? 0);
    $claimCode = $claimId > 0 ? ('CL-' . str_pad((string) $claimId, 6, '0', STR_PAD_LEFT)) : 'N/A';
    $contract = $claimId > 0 ? udcs_claim_fetch_review_contract($conn, $claimId) : null;
    $claimLabel = $claimCode;
    if (is_array($contract)) {
        $peopleSummary = (array) ($contract['people']['summary'] ?? []);
        $assetSummary = (array) ($contract['assets']['summary'] ?? []);
        $statusLabel = (string) ($contract['status']['label'] ?? '');
        $deceased = trim((string) ($peopleSummary['deceased_name'] ?? ''));
        $assetLabel = trim((string) ($assetSummary['label'] ?? ''));
        $claimParts = [$claimCode];
        if ($statusLabel !== '') {
            $claimParts[] = $statusLabel;
        }
        if ($deceased !== '') {
            $claimParts[] = 'Deceased: ' . $deceased;
        }
        if ($assetLabel !== '') {
            $claimParts[] = 'Assets: ' . $assetLabel;
        }
        $claimLabel = implode(' | ', $claimParts);
    }
    $actorName = trim((string) ($row['actor_name'] ?? ''));
    $actorLabel = $actorName !== '' ? $actorName : 'System';
    $role = ucwords(str_replace('_', ' ', trim((string) ($row['actor_role'] ?? 'system'))));
    $actionLabel = trim((string) ($row['action_label'] ?? ''));
    $actionKey = trim((string) ($row['action_key'] ?? ''));
    if ($actionLabel !== '') {
        $action = admin_activity_humanize_text($actionLabel);
    } elseif ($actionKey !== '') {
        $action = admin_activity_humanize_text($actionKey);
    } else {
        $action = 'Activity';
    }

    $details = admin_activity_humanize_text((string) ($row['details'] ?? '-'));
    $metaSummary = admin_activity_meta_summary((string) ($row['meta_json'] ?? ''));
    $detailParts = [];
    if ($claimLabel !== '' && $claimLabel !== 'N/A') {
        $detailParts[] = 'Claim: ' . $claimLabel;
    }
    if ($details !== '' && $details !== '-') {
        $detailParts[] = 'Event: ' . $details;
    }
    if ($metaSummary !== '') {
        $detailParts[] = 'Context: ' . $metaSummary;
    }
    $details = !empty($detailParts) ? implode("\n", $detailParts) : '-';

    $rows[] = [
        'time' => (string) ($row['created_label'] ?? '-'),
        'actor' => $actorLabel,
        'role' => $role !== '' ? $role : 'System',
        'action' => $action,
        'details' => $details,
    ];
}

$pdf = udcs_pdf_create_document([
    'title' => 'System Activity Trail Export',
    'portal' => 'Admin Portal',
    'model' => 'Audit Model',
    'panel' => 'System Activity Trail',
    'timestamp' => date('Y-m-d H:i:s'),
]);

$filterRows = [];
if ($filterRole !== '') {
    $filterRows[] = ['label' => 'Role', 'value' => ucfirst($filterRole)];
}
if ($filterAction !== '') {
    $filterRows[] = ['label' => 'Action', 'value' => admin_activity_humanize_text($filterAction)];
}
if ($search !== '') {
    $filterRows[] = ['label' => 'Search', 'value' => $search];
}
if ($dateFrom !== '' || $dateTo !== '') {
    $filterRows[] = [
        'label' => 'Date Range',
        'value' => ($dateFrom !== '' ? $dateFrom : 'Any') . ' to ' . ($dateTo !== '' ? $dateTo : 'Any'),
    ];
}
udcs_pdf_render_filter_summary($pdf, $filterRows);

$columns = [
    ['key' => 'time', 'label' => 'Time', 'weight' => 1.25, 'align' => 'L'],
    ['key' => 'actor', 'label' => 'Actor', 'weight' => 1.0, 'align' => 'L'],
    ['key' => 'role', 'label' => 'Role', 'weight' => 0.8, 'align' => 'L'],
    ['key' => 'action', 'label' => 'Action', 'weight' => 1.25, 'align' => 'L'],
    ['key' => 'details', 'label' => 'Details', 'weight' => 3.7, 'align' => 'L'],
];

udcs_pdf_render_table($pdf, $columns, $rows, [
    'line_height' => 4.5,
    'header_height' => 7.2,
    'max_lines_per_cell' => 0,
]);

while (ob_get_level() > 0) {
    ob_end_clean();
}

$pdf->Output('system_activity_trail_' . date('Ymd_His') . '.pdf', 'I');
exit();

