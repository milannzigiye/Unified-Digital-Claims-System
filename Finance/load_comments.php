<?php
// Tags: [FINANCE] [CLAIM] [STATUS]
include '../connect.php';
require_once '../security.php';
secure_session_start();

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'finance') {
    exit('Unauthorized');
}

$financeEmail = trim((string) ($_SESSION['email'] ?? ''));
$financeUserId = udcs_db_fetch_user_id_by_email_role($conn, $financeEmail, 'finance');
if ($financeUserId <= 0) {
    exit('Unauthorized');
}

if (!function_exists('finance_history_esc')) {
    function finance_history_esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('finance_history_entries')) {
    function finance_history_entries(string $raw): array
    {
        $entries = [];
        $current = null;
        $lines = preg_split('/\R+/', $raw) ?: [];

        foreach ($lines as $lineRaw) {
            $line = trim((string) $lineRaw);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})\s*-\s*(.+)$/', $line, $matches)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'time' => (string) ($matches[1] ?? ''),
                    'title' => trim((string) ($matches[2] ?? 'History Update')),
                    'notes' => [],
                ];
                continue;
            }

            if ($current === null) {
                $current = [
                    'time' => '',
                    'title' => 'History Update',
                    'notes' => [],
                ];
            }

            $skipLine = preg_match(
                '/^(Finance Manual Verification (Record|Snapshot)|Decision action:|Claimant identity and payout destination confirmed:|Internal systems checked for restrictions or holds:|Decision recorded in finance audit records:)/i',
                $line
            ) === 1;
            if ($skipLine) {
                continue;
            }

            $current['notes'][] = $line;
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return array_reverse($entries);
    }
}

$claim_id = (int) ($_GET['claim_id'] ?? 0);
if ($claim_id <= 0) {
    echo '<div class="history-empty">No review history yet.</div>';
    exit();
}

$stmt = mysqli_prepare($conn, 'SELECT comment FROM claims WHERE id = ? AND assigned_finance_id = ? LIMIT 1');
$row = null;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'ii', $claim_id, $financeUserId);
    if (mysqli_stmt_execute($stmt)) {
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
    }
}

$rawHistory = trim((string) ($row['comment'] ?? ''));
if ($rawHistory === '') {
    echo '<div class="history-empty">No review history yet.</div>';
    exit();
}

$entries = finance_history_entries($rawHistory);
if (empty($entries)) {
    echo '<div class="history-empty">No review history yet.</div>';
    exit();
}

echo '<div class="history-list">';
foreach ($entries as $entry) {
    $rawTime = (string) ($entry['time'] ?? '');
    $timeLabel = 'Time not recorded';
    if ($rawTime !== '') {
        $timestamp = strtotime($rawTime);
        $timeLabel = $timestamp !== false ? date('d M Y H:i', $timestamp) : $rawTime;
    }

    $title = (string) ($entry['title'] ?? 'History Update');
    $notes = is_array($entry['notes'] ?? null) ? $entry['notes'] : [];

    echo '<article class="history-item">';
    echo '<div class="history-head">';
    echo '<div class="history-title">' . finance_history_esc($title) . '</div>';
    echo '<div class="history-time">' . finance_history_esc($timeLabel) . '</div>';
    echo '</div>';

    if (!empty($notes)) {
        echo '<ul class="history-notes">';
        foreach ($notes as $note) {
            $noteText = trim((string) $note);
            if ($noteText === '') {
                continue;
            }
            echo '<li>' . finance_history_esc($noteText) . '</li>';
        }
        echo '</ul>';
    }

    echo '</article>';
}
echo '</div>';

