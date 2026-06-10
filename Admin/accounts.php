<?php
// Tags: [ADMIN] [USERS]
include '../connect.php';
require_once '../security.php';
secure_session_start();

require_once dirname(__DIR__) . '/components/head.php';
require_once dirname(__DIR__) . '/components/helpers.php';
require_once dirname(__DIR__) . '/components/alert.php';
require_once dirname(__DIR__) . '/components/workflow.php';

if (!isset($_SESSION['email']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
$user_data = udcs_db_fetch_user_by_email_role($conn, $sessionEmail, 'admin');
if (!$user_data) {
    header('Location: ../login.php');
    exit();
}

$user_email = $sessionEmail;
$admin_id = (int) ($user_data['id'] ?? 0);
$admin_name = (string) ($user_data['full_name'] ?? 'Administrator');
$userPhoto = (string) ($user_data['photo'] ?? '');
$photo = $userPhoto !== '' ? '../uploads/' . ltrim($userPhoto, '/\\') : '../Images/logo.png';

if (!function_exists('admin_accounts_page_url')) {
    function admin_accounts_page_url(int $page, array $params): string
    {
        $params['page'] = $page;
        return '?' . http_build_query($params);
    }
}

if (!function_exists('admin_acceptance_yes')) {
    function admin_acceptance_yes(?string $acceptance): bool
    {
        return strtolower(trim((string) $acceptance)) === 'yes';
    }
}

$redirectQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
$redirectTarget = 'accounts.php' . ($redirectQuery !== '' ? ('?' . $redirectQuery) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';

    if ($targetId <= 0 || !in_array($action, ['update_acceptance', 'delete_user'], true)) {
        $_SESSION['accounts_alert'] = ['type' => 'danger', 'message' => 'Invalid request.'];
        header('Location: ' . $redirectTarget);
        exit();
    }

    $targetStmt = mysqli_prepare(
        $conn,
        "SELECT id, full_name, email, role, acceptance
         FROM users
         WHERE id = ? AND role IN ('legal','finance')
         LIMIT 1"
    );
    $targetUser = null;
    if ($targetStmt) {
        mysqli_stmt_bind_param($targetStmt, 'i', $targetId);
        if (mysqli_stmt_execute($targetStmt)) {
            $targetResult = mysqli_stmt_get_result($targetStmt);
            $targetUser = $targetResult ? mysqli_fetch_assoc($targetResult) : null;
        }
    }
    if (!$targetUser) {
        $_SESSION['accounts_alert'] = ['type' => 'danger', 'message' => 'Staff account not found.'];
        header('Location: ' . $redirectTarget);
        exit();
    }

    $targetName = (string) ($targetUser['full_name'] ?? 'User');
    $targetRole = (string) ($targetUser['role'] ?? '');
    $targetEmail = (string) ($targetUser['email'] ?? '');

    if ($action === 'update_acceptance') {
        $rawAcceptance = trim((string) ($_POST['acceptance'] ?? 'No'));
        $acceptance = admin_acceptance_yes($rawAcceptance) ? 'Yes' : 'No';
        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE users
             SET acceptance = ?
             WHERE id = ? AND role IN ('legal','finance')
             LIMIT 1"
        );
        $updateOk = false;
        if ($updateStmt) {
            mysqli_stmt_bind_param($updateStmt, 'si', $acceptance, $targetId);
            $updateOk = mysqli_stmt_execute($updateStmt);
        }
        if ($updateOk) {
            // Keep claim queues valid when staff access changes.
            bk_backfill_unassigned_claims($conn, 500);

            $isApproved = strtolower($acceptance) === 'yes';
            bk_activity_log($conn, [
                'actor_id' => $admin_id,
                'actor_role' => 'admin',
                'action_key' => $isApproved ? 'staff_access_approved' : 'staff_access_revoked',
                'action_label' => $isApproved ? 'Staff Access Approved' : 'Staff Access Revoked',
                'details' => 'Admin updated staff access status for ' . $targetName . ' (' . ucfirst($targetRole) . ') to ' . $acceptance . '.',
                'meta' => [
                    'target_user_id' => $targetId,
                    'target_role' => $targetRole,
                    'target_email' => (string) ($targetUser['email'] ?? ''),
                    'access' => $acceptance,
                ],
            ]);

            $note = "Your account access status was updated to $acceptance.";
            udcs_db_insert_notification($conn, (string) $targetId, (string) $admin_id, $note);
            $_SESSION['accounts_alert'] = ['type' => 'success', 'message' => "$targetName ($targetRole) updated to $acceptance."];
        } else {
            $_SESSION['accounts_alert'] = ['type' => 'danger', 'message' => 'Failed to update staff access status.'];
        }

        header('Location: ' . $redirectTarget);
        exit();
    }

    mysqli_begin_transaction($conn);
    $ok = true;

    $targetIdString = (string) $targetId;
    $deleteNotifStmt = mysqli_prepare(
        $conn,
        'DELETE FROM notifications WHERE receiver = ? OR sender = ? OR receiver = ? OR sender = ?'
    );
    if ($deleteNotifStmt) {
        mysqli_stmt_bind_param($deleteNotifStmt, 'ssss', $targetIdString, $targetIdString, $targetEmail, $targetEmail);
        $ok = $ok && mysqli_stmt_execute($deleteNotifStmt);
    } else {
        $ok = false;
    }

    $deleteMsgStmt = mysqli_prepare($conn, 'DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?');
    if ($deleteMsgStmt) {
        mysqli_stmt_bind_param($deleteMsgStmt, 'ii', $targetId, $targetId);
        $ok = $ok && mysqli_stmt_execute($deleteMsgStmt);
    } else {
        $ok = false;
    }

    $deleteUserStmt = mysqli_prepare(
        $conn,
        "DELETE FROM users
         WHERE id = ? AND role IN ('legal','finance')
         LIMIT 1"
    );
    if ($deleteUserStmt) {
        mysqli_stmt_bind_param($deleteUserStmt, 'i', $targetId);
        $ok = $ok && mysqli_stmt_execute($deleteUserStmt);
    } else {
        $ok = false;
    }

    if ($ok) {
        mysqli_commit($conn);
        bk_activity_log($conn, [
            'actor_id' => $admin_id,
            'actor_role' => 'admin',
            'action_key' => 'staff_account_deleted',
            'action_label' => 'Staff Account Deleted',
            'details' => 'Admin removed staff account for ' . $targetName . ' (' . ucfirst($targetRole) . ').',
            'meta' => [
                'target_user_id' => $targetId,
                'target_role' => $targetRole,
                'target_email' => (string) ($targetUser['email'] ?? ''),
            ],
        ]);
        // Re-route any orphaned claims immediately after staff removal.
        bk_backfill_unassigned_claims($conn, 500);
        $_SESSION['accounts_alert'] = ['type' => 'success', 'message' => "$targetName ($targetRole) has been removed."];
    } else {
        mysqli_rollback($conn);
        $_SESSION['accounts_alert'] = ['type' => 'danger', 'message' => 'Failed to remove staff account.'];
    }

    header('Location: ' . $redirectTarget);
    exit();
}

$flash = $_SESSION['accounts_alert'] ?? null;
unset($_SESSION['accounts_alert']);

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$filter_name = trim((string) ($_GET['filter_name'] ?? ''));
$filter_role = trim((string) ($_GET['filter_role'] ?? ''));
$filter_status = trim((string) ($_GET['filter_status'] ?? ''));

$whereParts = ["(role='legal' OR role='finance')"];

if ($filter_name !== '') {
    $whereParts[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
}

if (in_array($filter_role, ['legal', 'finance'], true)) {
    $whereParts[] = 'role = ?';
}

if ($filter_status !== '') {
    if (strtolower($filter_status) === 'yes') {
        $whereParts[] = "LOWER(COALESCE(acceptance,''))='yes'";
    } elseif (strtolower($filter_status) === 'no') {
        $whereParts[] = "(LOWER(COALESCE(acceptance,''))='no' OR COALESCE(acceptance,'')='')";
    }
}

$whereSql = implode(' AND ', $whereParts);
$filterTypes = '';
$filterParams = [];
if ($filter_name !== '') {
    $term = '%' . $filter_name . '%';
    $filterTypes .= 'sss';
    $filterParams[] = $term;
    $filterParams[] = $term;
    $filterParams[] = $term;
}
if (in_array($filter_role, ['legal', 'finance'], true)) {
    $filterTypes .= 's';
    $filterParams[] = $filter_role;
}

$countSql = "SELECT COUNT(*) AS total FROM users WHERE $whereSql";
$countStmt = mysqli_prepare($conn, $countSql);
$totalRows = 0;
if ($countStmt && udcs_db_stmt_bind($countStmt, $filterTypes, $filterParams) && mysqli_stmt_execute($countStmt)) {
    $countResult = mysqli_stmt_get_result($countStmt);
    $totalRows = (int) ((mysqli_fetch_assoc($countResult)['total'] ?? 0));
}
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$listSql = "
    SELECT id, full_name, email, phone, role, acceptance, created_at
    FROM users
    WHERE $whereSql
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
";
$listTypes = $filterTypes . 'ii';
$listParams = $filterParams;
$listParams[] = $limit;
$listParams[] = $offset;
$listStmt = mysqli_prepare($conn, $listSql);
$listResult = false;
if ($listStmt && udcs_db_stmt_bind($listStmt, $listTypes, $listParams) && mysqli_stmt_execute($listStmt)) {
    $listResult = mysqli_stmt_get_result($listStmt);
}

$statsStmt = mysqli_prepare(
    $conn,
    "
    SELECT
        COUNT(*) AS total_staff,
        SUM(CASE WHEN LOWER(COALESCE(acceptance,''))='yes' THEN 1 ELSE 0 END) AS accepted_staff,
        SUM(CASE WHEN role='legal' THEN 1 ELSE 0 END) AS legal_staff,
        SUM(CASE WHEN role='finance' THEN 1 ELSE 0 END) AS finance_staff
    FROM users
    WHERE role IN ('legal','finance')
"
);
$statsResult = false;
if ($statsStmt && mysqli_stmt_execute($statsStmt)) {
    $statsResult = mysqli_stmt_get_result($statsStmt);
}
$stats = $statsResult
    ? (mysqli_fetch_assoc($statsResult) ?: ['total_staff' => 0, 'accepted_staff' => 0, 'legal_staff' => 0, 'finance_staff' => 0])
    : ['total_staff' => 0, 'accepted_staff' => 0, 'legal_staff' => 0, 'finance_staff' => 0];

$baseParams = $_GET;
unset($baseParams['page']);

$headExtra = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php render_head('Staff Accounts | Admin Dashboard', '..', $headExtra); ?>
    <style>
        .wrap { padding: 1rem 1.25rem 2rem; }
        .hero, .panel { border: 1px solid rgba(var(--bk-border-rgb),1); border-radius: 1rem; background: rgb(var(--bk-surface-rgb)); box-shadow: var(--shadow-soft); }
        .hero { padding: 1.2rem; background: linear-gradient(135deg, rgba(var(--bk-primary-rgb),.16), rgba(var(--bk-primary-rgb),.03) 50%, rgba(var(--bk-surface-rgb),.98)); }
        .tag { display:inline-flex; border-radius:999px; border:1px solid rgba(var(--bk-primary-rgb),.3); background:rgba(var(--bk-primary-rgb),.12); color:rgb(var(--bk-primary-rgb)); font-size:.72rem; font-weight:700; padding:.22rem .6rem; text-transform:uppercase; letter-spacing:.05em; }
        .hero h1 { margin:.45rem 0 0; font-size:clamp(1.5rem,2.3vw,2.1rem); font-family:var(--app-display-font),var(--app-font),sans-serif; }
        .hero p { margin:.45rem 0 0; color:rgb(var(--bk-muted-rgb)); font-size:.92rem; }
        .stats { margin-top: .95rem; display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.75rem; }
        .card { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.9rem; background:rgb(var(--bk-surface-rgb)); padding:.8rem .9rem; box-shadow:var(--shadow-soft); }
        .card .k { margin:0; font-size:.72rem; text-transform:uppercase; color:rgb(var(--bk-muted-rgb)); letter-spacing:.05em; }
        .card .v { margin:.2rem 0 0; font-size:1.45rem; font-weight:700; color:rgb(var(--bk-text-rgb)); }
        .panel { margin-top: .95rem; overflow:hidden; }
        .panel-head { display:flex; justify-content:space-between; align-items:center; gap:.6rem; padding:.8rem .9rem; border-bottom:1px solid rgba(var(--bk-border-rgb),1); background:rgba(var(--bk-primary-rgb),.06); }
        .panel-title { margin:0; font-size:1rem; font-weight:700; }
        .panel-sub { margin:.1rem 0 0; font-size:.8rem; color:rgb(var(--bk-muted-rgb)); }
        .filters { display:grid; grid-template-columns:1.5fr 1fr 1fr auto; gap:.7rem; padding:.85rem .9rem; border-bottom:1px solid rgba(var(--bk-border-rgb),1); background:rgba(var(--bk-bg-rgb),.45); align-items:end; }
        .table-wrap { padding:.75rem .9rem .9rem; }
        .table-scroll { overflow-x:auto; border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.8rem; }
        table { width:100%; min-width:920px; border-collapse:collapse; }
        th { background:rgba(var(--bk-primary-rgb),.08); font-size:.74rem; text-transform:uppercase; letter-spacing:.04em; text-align:left; padding:.58rem .68rem; border-bottom:1px solid rgba(var(--bk-border-rgb),1); }
        td { padding:.58rem .68rem; border-bottom:1px solid rgba(var(--bk-border-rgb),.82); font-size:.84rem; vertical-align:middle; }
        tbody tr:hover td { background:rgba(var(--bk-primary-rgb),.04); }
        .muted { font-size:.76rem; color:rgb(var(--bk-muted-rgb)); }
        .pill { display:inline-flex; border-radius:999px; font-size:.73rem; font-weight:700; padding:.3rem .52rem; border:1px solid transparent; }
        .role-legal, .role-finance { background:rgba(var(--bk-primary-rgb),.14); border-color:rgba(var(--bk-primary-rgb),.32); color:rgb(var(--bk-primary-rgb)); }
        .ok { background:rgba(var(--bk-success-rgb),.14); border-color:rgba(var(--bk-success-rgb),.32); color:rgb(var(--bk-success-rgb)); }
        .no { background:rgba(var(--bk-danger-rgb),.14); border-color:rgba(var(--bk-danger-rgb),.32); color:rgb(var(--bk-danger-rgb)); }
        .actions { display:inline-flex; gap:.4rem; }
        .icon-btn { width:2.1rem; min-width:2.1rem; height:2.1rem; padding:0; border-radius:.68rem; }
        .pager { margin-top:.85rem; display:flex; justify-content:center; gap:.35rem; flex-wrap:wrap; }
        .pager a { display:inline-flex; min-width:2rem; height:2rem; align-items:center; justify-content:center; border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.65rem; text-decoration:none; color:rgb(var(--bk-text-rgb)); font-size:.8rem; font-weight:600; padding:0 .4rem; background:rgb(var(--bk-surface-rgb)); }
        .pager a.current { background:rgb(var(--bk-primary-rgb)); color:#fff; border-color:rgba(var(--bk-primary-rgb),.9); }
        .note { margin-top:.4rem; text-align:center; color:rgb(var(--bk-muted-rgb)); font-size:.76rem; }
        .empty { text-align:center; padding:1.8rem 1rem; color:rgb(var(--bk-muted-rgb)); }
        .modal { position:fixed; inset:0; z-index:1300; display:none; align-items:center; justify-content:center; padding:1rem; background:rgba(3,78,162,.86); }
        .modal.open { display:flex; }
        .modal-panel { width:min(33rem,100%); max-height:92vh; overflow:auto; border:1px solid rgba(var(--bk-border-rgb),1); border-radius:1rem; background:rgb(var(--bk-surface-rgb)); box-shadow:var(--shadow); }
        .modal-head { display:flex; justify-content:space-between; align-items:center; padding:.8rem .9rem; border-bottom:1px solid rgba(var(--bk-border-rgb),1); background:rgba(var(--bk-primary-rgb),.08); }
        .modal-body { padding:.9rem; display:grid; gap:.75rem; }
        .mline { border:1px solid rgba(var(--bk-border-rgb),1); border-radius:.75rem; padding:.56rem .62rem; background:rgba(var(--bk-bg-rgb),.45); }
        .mline .k { margin:0; font-size:.69rem; color:rgb(var(--bk-muted-rgb)); text-transform:uppercase; letter-spacing:.04em; }
        .mline .v { margin:.18rem 0 0; font-size:.84rem; font-weight:600; word-break:break-word; }
        @media (max-width:1080px) { .stats { grid-template-columns:repeat(2,minmax(0,1fr)); } .filters { grid-template-columns:repeat(2,minmax(0,1fr)); } .filters .f-actions { grid-column:span 2; } }
        @media (max-width:760px) { .wrap { padding:.9rem .8rem 1.4rem; } .stats,.filters { grid-template-columns:1fr; } .filters .f-actions { grid-column:span 1; } }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content wrap">
    <section class="hero">
        <h1>Staff Accounts</h1>
        <p>Manage legal and finance access, open direct messages, and keep active users properly approved.</p>
    </section>

    <?php if ($flash): ?>
        <div class="mt-4"><?php render_alert((string) ($flash['message'] ?? ''), ['type' => (string) ($flash['type'] ?? 'info')]); ?></div>
    <?php endif; ?>

    <section class="stats">
        <article class="card"><p class="k">Total Staff</p><p class="v"><?php echo number_format((int) ($stats['total_staff'] ?? 0)); ?></p></article>
        <article class="card"><p class="k">Approved Access</p><p class="v"><?php echo number_format((int) ($stats['accepted_staff'] ?? 0)); ?></p></article>
        <article class="card"><p class="k">Legal</p><p class="v"><?php echo number_format((int) ($stats['legal_staff'] ?? 0)); ?></p></article>
        <article class="card"><p class="k">Finance</p><p class="v"><?php echo number_format((int) ($stats['finance_staff'] ?? 0)); ?></p></article>
    </section>

    <section class="panel">
        <header class="panel-head">
            <div>
                <h2 class="panel-title">Access Register</h2>
                <p class="panel-sub">Use manage to change approval status or remove a staff account.</p>
            </div>
        </header>

        <form method="GET" class="filters">
            <div class="ui-field">
                <label class="ui-label" for="filter_name">Search</label>
                <input id="filter_name" name="filter_name" class="ui-input" value="<?php echo bk_e($filter_name); ?>" placeholder="Name, email, phone">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="filter_role">Role</label>
                <select id="filter_role" name="filter_role" class="ui-select">
                    <option value="">All roles</option>
                    <option value="legal" <?php echo $filter_role === 'legal' ? 'selected' : ''; ?>>Legal</option>
                    <option value="finance" <?php echo $filter_role === 'finance' ? 'selected' : ''; ?>>Finance</option>
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-label" for="filter_status">Access</label>
                <select id="filter_status" name="filter_status" class="ui-select">
                    <option value="">All statuses</option>
                    <option value="yes" <?php echo strtolower($filter_status) === 'yes' ? 'selected' : ''; ?>>Approved</option>
                    <option value="no" <?php echo strtolower($filter_status) === 'no' ? 'selected' : ''; ?>>Not Approved</option>
                </select>
            </div>
            <div class="f-actions flex gap-2">
                <button class="ui-btn ui-btn-md ui-btn-primary" type="submit"><i class="bi bi-funnel"></i><span>Apply</span></button>
                <a class="ui-btn ui-btn-md ui-btn-secondary" href="accounts.php">Reset</a>
            </div>
        </form>

        <div class="table-wrap">
            <div class="table-scroll bk-table-shell">
                <table class="dash-table">
                    <thead>
                    <tr>
                        <th><span class="table-entity-label"><i class="bi bi-hash"></i>#</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-person-badge"></i>Staff</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-envelope-at"></i>Contact</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-shield-check"></i>Role</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-check2-circle"></i>Status</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-calendar2-week"></i>Joined</span></th>
                        <th><span class="table-entity-label"><i class="bi bi-sliders"></i>Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($listResult && mysqli_num_rows($listResult) > 0): ?>
                        <?php $index = $offset + 1; ?>
                        <?php while ($row = mysqli_fetch_assoc($listResult)): ?>
                            <?php
                            $isAccepted = admin_acceptance_yes((string) ($row['acceptance'] ?? ''));
                            $joined = !empty($row['created_at']) ? date('d M Y', strtotime((string) $row['created_at'])) : 'N/A';
                            ?>
                            <tr>
                                <td><?php echo $index++; ?></td>
                                <td><div><?php echo bk_e((string) ($row['full_name'] ?? 'Unknown')); ?></div><div class="muted">ID: <?php echo (int) ($row['id'] ?? 0); ?></div></td>
                                <td><div><?php echo bk_e((string) ($row['email'] ?? '')); ?></div><div class="muted"><?php echo bk_e((string) ($row['phone'] ?: 'No phone')); ?></div></td>
                                <td><span class="pill role-<?php echo bk_e((string) ($row['role'] ?? '')); ?>"><?php echo bk_e(ucfirst((string) ($row['role'] ?? 'staff'))); ?></span></td>
                                <td><span class="pill <?php echo $isAccepted ? 'ok' : 'no'; ?>"><?php echo $isAccepted ? 'Approved' : 'Not Approved'; ?></span></td>
                                <td><?php echo bk_e($joined); ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="ui-btn ui-btn-sm ui-btn-secondary icon-btn" href="messaging.php?contact_id=<?php echo (int) ($row['id'] ?? 0); ?>" title="Open DM"><i class="bi bi-chat-dots"></i></a>
                                        <button
                                            type="button"
                                            class="ui-btn ui-btn-sm ui-btn-secondary icon-btn"
                                            data-open="manage"
                                            data-id="<?php echo (int) ($row['id'] ?? 0); ?>"
                                            data-name="<?php echo bk_e((string) ($row['full_name'] ?? '')); ?>"
                                            data-email="<?php echo bk_e((string) ($row['email'] ?? '')); ?>"
                                            data-phone="<?php echo bk_e((string) ($row['phone'] ?? '')); ?>"
                                            data-role="<?php echo bk_e((string) ($row['role'] ?? '')); ?>"
                                            data-joined="<?php echo bk_e($joined); ?>"
                                            data-acceptance="<?php echo $isAccepted ? 'Yes' : 'No'; ?>"
                                            title="Manage account"
                                        ><i class="bi bi-sliders"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7"><div class="empty"><i class="bi bi-people"></i><p>No staff found for this filter.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pager">
                    <?php if ($page > 1): ?><a href="<?php echo bk_e(admin_accounts_page_url($page - 1, $baseParams)); ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="<?php echo bk_e(admin_accounts_page_url($i, $baseParams)); ?>" class="<?php echo $i === $page ? 'current' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="<?php echo bk_e(admin_accounts_page_url($page + 1, $baseParams)); ?>"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
                </nav>
                <p class="note">Page <?php echo $page; ?> of <?php echo $totalPages; ?> &bull; <?php echo number_format($totalRows); ?> records</p>
            <?php endif; ?>
        </div>
    </section>
</main>

<section id="manageModal" class="modal" aria-hidden="true">
    <article class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="manageTitle">
        <header class="modal-head">
            <h2 id="manageTitle" class="panel-title">Manage Staff Account</h2>
            <button type="button" id="closeModal" class="ui-btn ui-btn-sm ui-btn-ghost"><i class="bi bi-x-lg"></i></button>
        </header>
        <form method="POST" class="modal-body" id="manageForm">
            <input type="hidden" name="user_id" id="mId">

            <article class="mline"><p class="k">Name</p><p class="v" id="mName">-</p></article>
            <article class="mline"><p class="k">Email</p><p class="v" id="mEmail">-</p></article>
            <article class="mline"><p class="k">Phone</p><p class="v" id="mPhone">-</p></article>
            <article class="mline"><p class="k">Role</p><p class="v" id="mRole">-</p></article>
            <article class="mline"><p class="k">Joined</p><p class="v" id="mJoined">-</p></article>

            <div class="ui-field">
                <label class="ui-label" for="mAcceptance">Access Approval</label>
                <select id="mAcceptance" name="acceptance" class="ui-select">
                    <option value="Yes">Approved</option>
                    <option value="No">Not Approved</option>
                </select>
            </div>

            <div class="flex gap-2 justify-between">
                <button type="submit" class="ui-btn ui-btn-md ui-btn-primary" name="action" value="update_acceptance">
                    <i class="bi bi-check2-circle"></i><span>Save Status</span>
                </button>
                <button type="submit" class="ui-btn ui-btn-md ui-btn-primary bg-bk-danger hover:bg-bk-danger/90" name="action" value="delete_user" id="deleteBtn">
                    <i class="bi bi-trash3"></i><span>Delete Account</span>
                </button>
            </div>
        </form>
    </article>
</section>

<script>
(() => {
    const modal = document.getElementById('manageModal');
    const closeBtn = document.getElementById('closeModal');
    const deleteBtn = document.getElementById('deleteBtn');
    const form = document.getElementById('manageForm');

    const mId = document.getElementById('mId');
    const mName = document.getElementById('mName');
    const mEmail = document.getElementById('mEmail');
    const mPhone = document.getElementById('mPhone');
    const mRole = document.getElementById('mRole');
    const mJoined = document.getElementById('mJoined');
    const mAcceptance = document.getElementById('mAcceptance');

    const setText = (node, value, fallback = '-') => {
        if (!node) return;
        node.textContent = (value && String(value).trim() !== '') ? String(value) : fallback;
    };

    const openModal = (button) => {
        mId.value = button.dataset.id || '';
        setText(mName, button.dataset.name);
        setText(mEmail, button.dataset.email);
        setText(mPhone, button.dataset.phone, 'No phone');
        setText(mRole, button.dataset.role ? button.dataset.role.charAt(0).toUpperCase() + button.dataset.role.slice(1) : '-');
        setText(mJoined, button.dataset.joined);
        mAcceptance.value = button.dataset.acceptance === 'Yes' ? 'Yes' : 'No';

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    document.querySelectorAll('[data-open="manage"]').forEach((btn) => {
        btn.addEventListener('click', () => openModal(btn));
    });

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('open')) closeModal(); });

    deleteBtn.addEventListener('click', (e) => {
        const name = mName.textContent || 'this account';
        if (!confirm(`Delete ${name}? This cannot be undone.`)) {
            e.preventDefault();
        }
    });

    form.addEventListener('submit', (e) => {
        const action = e.submitter ? e.submitter.value : '';
        if (action === 'update_acceptance' && !confirm('Save access status changes?')) {
            e.preventDefault();
        }
    });
})();
</script>
</body>
</html>



