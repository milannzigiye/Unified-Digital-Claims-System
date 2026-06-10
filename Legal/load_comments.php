<?php
// Tags: [LEGAL] [CLAIM] [STATUS]
include '../connect.php';
require_once '../security.php';
secure_session_start();

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'legal') {
    exit('Unauthorized');
}

$legalEmail = trim((string) ($_SESSION['email'] ?? ''));
$legalUserId = udcs_db_fetch_user_id_by_email_role($conn, $legalEmail, 'legal');
if ($legalUserId <= 0) {
    exit('Unauthorized');
}

$claim_id = (int) ($_GET['claim_id'] ?? 0);
if ($claim_id <= 0) {
    echo '<p class="text-muted mb-0">No comments yet.</p>';
    exit();
}

$stmt = mysqli_prepare($conn, 'SELECT comment FROM claims WHERE id = ? AND assigned_legal_id = ? LIMIT 1');
$row = null;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'ii', $claim_id, $legalUserId);
    if (mysqli_stmt_execute($stmt)) {
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
    }
}

if (!empty($row['comment'])) {
    echo '<article class="legal-note-entry">';
    echo '<div class="legal-note-entry-head">';
    echo '<span class="legal-note-entry-title"><i class="bi bi-journal-check"></i>Latest legal note</span>';
    echo '<span class="legal-note-entry-meta">Claim-level record</span>';
    echo '</div>';
    echo '<div class="legal-note-entry-copy">' . nl2br(htmlspecialchars((string) $row['comment'], ENT_QUOTES, 'UTF-8')) . '</div>';
    echo '</article>';
} else {
    echo '<div class="legal-note-empty">';
    echo '<strong>No legal note recorded yet.</strong>';
    echo '<span>Use Take Action to add a note, request corrections, approve, or reject this claim.</span>';
    echo '</div>';
}


