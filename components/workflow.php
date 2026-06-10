<?php
// Tags: [FLOW] [SCHEMA] [ASSIGN] [AUDIT]
// [FLOW] Queue assignment + schema guards + activity logging.
require_once __DIR__ . '/claims_v2.php';

if (!function_exists('bk_db_identifier')) {
    function bk_db_identifier(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: '';
    }
}

if (!function_exists('bk_db_has_column')) {
    function bk_db_has_column(mysqli $conn, string $table, string $column): bool
    {
        $tableName = bk_db_identifier($table);
        $columnName = bk_db_identifier($column);
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
        $query = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        return $query !== false && mysqli_num_rows($query) > 0;
    }
}

if (!function_exists('bk_db_has_index')) {
    function bk_db_has_index(mysqli $conn, string $table, string $index): bool
    {
        $tableName = bk_db_identifier($table);
        $indexName = bk_db_identifier($index);
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
        $query = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        return $query !== false && mysqli_num_rows($query) > 0;
    }
}

if (!function_exists('bk_claims_ensure_workflow_schema')) {
    // [SCHEMA] Ensure columns/indexes required for routing exist.
    function bk_claims_ensure_workflow_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        // [SCHEMA] Run once per request.
        $done = true;

        if (!bk_db_has_column($conn, 'claims', 'assigned_to')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD COLUMN assigned_to INT NULL");
        }
        if (!bk_db_has_column($conn, 'claims', 'assigned_legal_id')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD COLUMN assigned_legal_id INT NULL");
        }
        if (!bk_db_has_column($conn, 'claims', 'assigned_finance_id')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD COLUMN assigned_finance_id INT NULL");
        }
        if (!bk_db_has_column($conn, 'claims', 'finance_assessed_amount')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD COLUMN finance_assessed_amount DECIMAL(15,2) NULL");
        }

        if (!bk_db_has_index($conn, 'claims', 'idx_claims_assigned_to')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD INDEX idx_claims_assigned_to (assigned_to)");
        }
        if (!bk_db_has_index($conn, 'claims', 'idx_claims_assigned_legal')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD INDEX idx_claims_assigned_legal (assigned_legal_id)");
        }
        if (!bk_db_has_index($conn, 'claims', 'idx_claims_assigned_finance')) {
            @mysqli_query($conn, "ALTER TABLE claims ADD INDEX idx_claims_assigned_finance (assigned_finance_id)");
        }
    }
}

if (!function_exists('bk_activity_ensure_schema')) {
    // [AUDIT] Ensure activity_logs table exists.
    function bk_activity_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        @mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                actor_id INT NULL,
                actor_role VARCHAR(32) NULL,
                claim_id INT NULL,
                action_key VARCHAR(80) NOT NULL,
                action_label VARCHAR(160) NOT NULL,
                details TEXT NULL,
                meta_json LONGTEXT NULL,
                ip_address VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activity_actor (actor_id),
                INDEX idx_activity_claim (claim_id),
                INDEX idx_activity_action (action_key),
                INDEX idx_activity_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('bk_activity_log')) {
    // [AUDIT] Write one immutable event row.
    function bk_activity_log(mysqli $conn, array $entry): void
    {
        bk_activity_ensure_schema($conn);

        $actorId = (int) ($entry['actor_id'] ?? 0);
        $claimId = (int) ($entry['claim_id'] ?? 0);
        $actorRole = trim((string) ($entry['actor_role'] ?? 'system'));
        $actionKey = trim((string) ($entry['action_key'] ?? 'unknown'));
        $actionLabel = trim((string) ($entry['action_label'] ?? 'Action'));
        $details = trim((string) ($entry['details'] ?? ''));

        // [AUDIT] Optional metadata payload.
        $metaPayload = $entry['meta'] ?? null;
        $metaJson = '';
        if (is_array($metaPayload)) {
            $encoded = json_encode($metaPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $metaJson = is_string($encoded) ? $encoded : '';
        } elseif (is_string($metaPayload)) {
            $metaJson = $metaPayload;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO activity_logs (
                actor_id,
                actor_role,
                claim_id,
                action_key,
                action_label,
                details,
                meta_json,
                ip_address,
                user_agent,
                created_at
            ) VALUES (
                NULLIF(?, 0),
                NULLIF(?, ''),
                NULLIF(?, 0),
                ?,
                ?,
                NULLIF(?, ''),
                NULLIF(?, ''),
                NULLIF(?, ''),
                NULLIF(?, ''),
                NOW()
            )"
        );
        if (!$stmt) {
            return;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'isissssss',
            $actorId,
            $actorRole,
            $claimId,
            $actionKey,
            $actionLabel,
            $details,
            $metaJson,
            $ip,
            $ua
        );
        @mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('bk_activity_user_role')) {
    // [AUDIT] Resolve a user role safely for activity metadata.
    function bk_activity_user_role(mysqli $conn, int $userId): string
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return '';
        }

        $stmt = mysqli_prepare($conn, "SELECT role FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return '';
        }
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        if (!$result || mysqli_num_rows($result) === 0) {
            return '';
        }

        $row = mysqli_fetch_assoc($result);
        return strtolower(trim((string) ($row['role'] ?? '')));
    }
}

if (!function_exists('bk_activity_backfill_account_creation_events')) {
    // [AUDIT] Backfill account-created events for users created before audit logging existed.
    function bk_activity_backfill_account_creation_events(mysqli $conn, int $limit = 200): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;

        bk_activity_ensure_schema($conn);

        $limit = max(1, min(500, (int) $limit));
        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                u.id,
                u.role,
                u.email,
                u.created_at
             FROM users u
             LEFT JOIN activity_logs a
               ON a.actor_id = u.id
              AND a.action_key IN ('claimant_account_created', 'staff_account_created')
             WHERE a.id IS NULL
             ORDER BY u.created_at ASC, u.id ASC
             LIMIT ?"
        );
        $result = false;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $limit);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
            }
            mysqli_stmt_close($stmt);
        }

        if (!$result) {
            return;
        }

        while ($row = mysqli_fetch_assoc($result)) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $role = strtolower(trim((string) ($row['role'] ?? '')));
            $isClaimant = $role === 'claimant';
            bk_activity_log($conn, [
                'actor_id' => $userId,
                'actor_role' => $role !== '' ? $role : 'system',
                'action_key' => $isClaimant ? 'claimant_account_created' : 'staff_account_created',
                'action_label' => $isClaimant ? 'Claimant Account Created' : 'Staff Account Created',
                'details' => $isClaimant
                    ? 'New claimant account was created.'
                    : 'New staff account was created.',
                'meta' => [
                    'email' => (string) ($row['email'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ],
            ]);
        }
    }
}

if (!function_exists('bk_claim_open_statuses_for_role')) {
    function bk_claim_open_statuses_for_role(string $role): array
    {
        $roleKey = strtolower(trim($role));
        return match ($roleKey) {
            'legal' => [
                'Pending Legal Review',
                'Manual Legal Review Required',
                'More Information Required',
            ],
            'finance' => [
                'Pending Finance Review',
                'Returned by Finance',
                'Approved for Disbursement',
            ],
            default => [],
        };
    }
}

if (!function_exists('bk_pick_staff_assignee')) {
    // [ASSIGN] Pick least-loaded active officer for the stage.
    function bk_pick_staff_assignee(mysqli $conn, string $role, string $stage = ''): ?int
    {
        bk_claims_ensure_workflow_schema($conn);
        udcs_claims_v2_ensure_schema($conn);

        $roleKey = strtolower(trim($role));
        if (!in_array($roleKey, ['legal', 'finance'], true)) {
            return null;
        }

        $assignmentColumn = $roleKey === 'legal' ? 'assigned_legal_id' : 'assigned_finance_id';
        $openStatuses = bk_claim_open_statuses_for_role($roleKey);
        if (empty($openStatuses)) {
            return null;
        }
        $statusPlaceholders = implode(', ', array_fill(0, count($openStatuses), '?'));
        // [ASSIGN] Count only open stage items.
        $query = "
            SELECT
                u.id,
                COUNT(c.id) AS load_count
            FROM users u
            LEFT JOIN claims c
                ON c.$assignmentColumn = u.id
               AND COALESCE(NULLIF(c.status, ''), c.claim_status) IN ($statusPlaceholders)
               AND COALESCE(NULLIF(c.model_version, ''), 'legacy') = 'v2'
            WHERE u.role = ?
              AND COALESCE(u.acceptance, 'No') = 'Yes'
            GROUP BY u.id
            ORDER BY load_count ASC, u.id ASC
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $query);
        $result = false;
        if ($stmt) {
            $types = str_repeat('s', count($openStatuses)) . 's';
            $params = array_merge($openStatuses, [$roleKey]);
            if (udcs_db_stmt_bind($stmt, $types, $params) && mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
            }
            mysqli_stmt_close($stmt);
        }
        if (!$result || mysqli_num_rows($result) === 0) {
            return null;
        }

        $row = mysqli_fetch_assoc($result);
        $candidate = (int) ($row['id'] ?? 0);
        return $candidate > 0 ? $candidate : null;
    }
}

if (!function_exists('bk_assign_claim_to_legal')) {
    // [ASSIGN] Route claim to legal queue.
    function bk_assign_claim_to_legal(mysqli $conn, int $claimId): ?int
    {
        bk_claims_ensure_workflow_schema($conn);
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return null;
        }

        $beforeStmt = mysqli_prepare(
            $conn,
            "SELECT assigned_legal_id, assigned_to FROM claims WHERE id = ? LIMIT 1"
        );
        $beforeResult = false;
        if ($beforeStmt) {
            mysqli_stmt_bind_param($beforeStmt, 'i', $claimId);
            if (mysqli_stmt_execute($beforeStmt)) {
                $beforeResult = mysqli_stmt_get_result($beforeStmt);
            }
            mysqli_stmt_close($beforeStmt);
        }
        $beforeAssignedLegalId = 0;
        $beforeAssignedTo = 0;
        if ($beforeResult && mysqli_num_rows($beforeResult) === 1) {
            $beforeRow = mysqli_fetch_assoc($beforeResult);
            $beforeAssignedLegalId = (int) ($beforeRow['assigned_legal_id'] ?? 0);
            $beforeAssignedTo = (int) ($beforeRow['assigned_to'] ?? 0);
        }

        $assigneeId = bk_pick_staff_assignee($conn, 'legal', 'legal');
        if ($assigneeId === null) {
            // [ASSIGN] No legal officer available.
            $clearStmt = mysqli_prepare(
                $conn,
                "UPDATE claims SET assigned_legal_id = NULL, assigned_to = NULL WHERE id = ? LIMIT 1"
            );
            if ($clearStmt) {
                mysqli_stmt_bind_param($clearStmt, 'i', $claimId);
                @mysqli_stmt_execute($clearStmt);
                mysqli_stmt_close($clearStmt);
            }
            if (mysqli_affected_rows($conn) > 0 || $beforeAssignedLegalId > 0 || $beforeAssignedTo > 0) {
                bk_activity_log($conn, [
                    'actor_id' => 0,
                    'actor_role' => 'system',
                    'claim_id' => $claimId,
                    'action_key' => 'claim_assignment_pending_legal',
                    'action_label' => 'Awaiting Legal Assignment',
                    'details' => 'Claim is waiting because no approved legal officer is currently available.',
                ]);
                udcs_claim_history_log($conn, $claimId, 'system', 'Awaiting Legal Assignment', 'Claim is waiting because no approved legal officer is currently available.');
            }
            return null;
        }

        $assignStmt = mysqli_prepare(
            $conn,
            "UPDATE claims
             SET assigned_legal_id = ?,
                 assigned_to = ?
             WHERE id = ?
             LIMIT 1"
        );
        if ($assignStmt) {
            mysqli_stmt_bind_param($assignStmt, 'iii', $assigneeId, $assigneeId, $claimId);
            @mysqli_stmt_execute($assignStmt);
            mysqli_stmt_close($assignStmt);
        }
        if (mysqli_affected_rows($conn) > 0 || $beforeAssignedLegalId !== $assigneeId || $beforeAssignedTo !== $assigneeId) {
            bk_activity_log($conn, [
                'actor_id' => 0,
                'actor_role' => 'system',
                'claim_id' => $claimId,
                'action_key' => 'claim_assigned_to_legal',
                'action_label' => 'Assigned to Legal Officer',
                'details' => 'Claim assignment was updated for legal review.',
                'meta' => [
                    'assigned_legal_id' => $assigneeId,
                ],
            ]);
            udcs_claim_history_log($conn, $claimId, 'system', 'Assigned to Legal Officer', 'Claim assignment was updated for legal review.');
        }

        return $assigneeId;
    }
}

if (!function_exists('bk_assign_claim_to_finance')) {
    // [ASSIGN] Route claim to finance queue.
    function bk_assign_claim_to_finance(mysqli $conn, int $claimId): ?int
    {
        bk_claims_ensure_workflow_schema($conn);
        udcs_claims_v2_ensure_schema($conn);
        $claimId = (int) $claimId;
        if ($claimId <= 0) {
            return null;
        }

        $beforeStmt = mysqli_prepare(
            $conn,
            "SELECT assigned_finance_id, assigned_to FROM claims WHERE id = ? LIMIT 1"
        );
        $beforeResult = false;
        if ($beforeStmt) {
            mysqli_stmt_bind_param($beforeStmt, 'i', $claimId);
            if (mysqli_stmt_execute($beforeStmt)) {
                $beforeResult = mysqli_stmt_get_result($beforeStmt);
            }
            mysqli_stmt_close($beforeStmt);
        }
        $beforeAssignedFinanceId = 0;
        $beforeAssignedTo = 0;
        if ($beforeResult && mysqli_num_rows($beforeResult) === 1) {
            $beforeRow = mysqli_fetch_assoc($beforeResult);
            $beforeAssignedFinanceId = (int) ($beforeRow['assigned_finance_id'] ?? 0);
            $beforeAssignedTo = (int) ($beforeRow['assigned_to'] ?? 0);
        }

        $assigneeId = bk_pick_staff_assignee($conn, 'finance', 'finance');
        if ($assigneeId === null) {
            // [ASSIGN] No finance officer available.
            $clearStmt = mysqli_prepare(
                $conn,
                "UPDATE claims SET assigned_finance_id = NULL WHERE id = ? LIMIT 1"
            );
            if ($clearStmt) {
                mysqli_stmt_bind_param($clearStmt, 'i', $claimId);
                @mysqli_stmt_execute($clearStmt);
                mysqli_stmt_close($clearStmt);
            }
            if (mysqli_affected_rows($conn) > 0 || $beforeAssignedFinanceId > 0) {
                bk_activity_log($conn, [
                    'actor_id' => 0,
                    'actor_role' => 'system',
                    'claim_id' => $claimId,
                    'action_key' => 'claim_assignment_pending_finance',
                    'action_label' => 'Awaiting Finance Assignment',
                    'details' => 'Claim is waiting because no approved finance officer is currently available.',
                ]);
                udcs_claim_history_log($conn, $claimId, 'system', 'Awaiting Finance Assignment', 'Claim is waiting because no approved finance officer is currently available.');
            }
            return null;
        }

        $assignStmt = mysqli_prepare(
            $conn,
            "UPDATE claims
             SET assigned_finance_id = ?,
                 assigned_to = ?
             WHERE id = ?
             LIMIT 1"
        );
        if ($assignStmt) {
            mysqli_stmt_bind_param($assignStmt, 'iii', $assigneeId, $assigneeId, $claimId);
            @mysqli_stmt_execute($assignStmt);
            mysqli_stmt_close($assignStmt);
        }
        if (mysqli_affected_rows($conn) > 0 || $beforeAssignedFinanceId !== $assigneeId || $beforeAssignedTo !== $assigneeId) {
            bk_activity_log($conn, [
                'actor_id' => 0,
                'actor_role' => 'system',
                'claim_id' => $claimId,
                'action_key' => 'claim_assigned_to_finance',
                'action_label' => 'Assigned to Finance Officer',
                'details' => 'Claim assignment was updated for finance review.',
                'meta' => [
                    'assigned_finance_id' => $assigneeId,
                ],
            ]);
        }

        return $assigneeId;
    }
}

if (!function_exists('bk_backfill_unassigned_claims')) {
    // [ASSIGN] Backfill legacy claims missing assignees.
    function bk_backfill_unassigned_claims(mysqli $conn, int $limit = 200): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;

        bk_claims_ensure_workflow_schema($conn);
        udcs_claims_v2_ensure_schema($conn);

        $limit = max(1, min(500, $limit));

        // [ASSIGN] Legal backfill pass (missing + orphaned assignees).
        $pendingStmt = mysqli_prepare(
            $conn,
            "SELECT c.id
             FROM claims c
             LEFT JOIN users u ON u.id = c.assigned_legal_id
             WHERE COALESCE(NULLIF(c.model_version, ''), 'legacy') = 'v2'
               AND COALESCE(NULLIF(c.status, ''), c.claim_status) IN ('Pending Legal Review', 'Manual Legal Review Required', 'More Information Required')
               AND (
                    c.assigned_legal_id IS NULL
                    OR c.assigned_legal_id = 0
                    OR u.id IS NULL
                    OR LOWER(TRIM(COALESCE(u.role, ''))) <> 'legal'
                    OR COALESCE(u.acceptance, 'No') <> 'Yes'
               )
             ORDER BY submitted_at ASC
             LIMIT ?"
        );
        $pendingResult = false;
        if ($pendingStmt) {
            mysqli_stmt_bind_param($pendingStmt, 'i', $limit);
            if (mysqli_stmt_execute($pendingStmt)) {
                $pendingResult = mysqli_stmt_get_result($pendingStmt);
            }
            mysqli_stmt_close($pendingStmt);
        }
        if ($pendingResult) {
            while ($row = mysqli_fetch_assoc($pendingResult)) {
                $claimId = (int) ($row['id'] ?? 0);
                if ($claimId <= 0) {
                    continue;
                }
                $assignee = bk_pick_staff_assignee($conn, 'legal', 'legal');
                if ($assignee === null) {
                    $clearStmt = mysqli_prepare(
                        $conn,
                        "UPDATE claims
                         SET assigned_legal_id = NULL,
                             assigned_to = NULL
                         WHERE id = ?
                         LIMIT 1"
                    );
                    if ($clearStmt) {
                        mysqli_stmt_bind_param($clearStmt, 'i', $claimId);
                        @mysqli_stmt_execute($clearStmt);
                        mysqli_stmt_close($clearStmt);
                    }
                    if (mysqli_affected_rows($conn) > 0) {
                        bk_activity_log($conn, [
                            'actor_id' => 0,
                            'actor_role' => 'system',
                            'claim_id' => $claimId,
                            'action_key' => 'claim_assignment_pending_legal_auto',
                            'action_label' => 'Awaiting Legal Assignment',
                            'details' => 'No approved legal officer is currently available for assignment.',
                        ]);
                    }
                    continue;
                }
                $assignStmt = mysqli_prepare(
                    $conn,
                    "UPDATE claims
                     SET assigned_legal_id = ?,
                         assigned_to = ?
                     WHERE id = ?
                     LIMIT 1"
                );
                if ($assignStmt) {
                    mysqli_stmt_bind_param($assignStmt, 'iii', $assignee, $assignee, $claimId);
                    @mysqli_stmt_execute($assignStmt);
                    mysqli_stmt_close($assignStmt);
                }
                if (mysqli_affected_rows($conn) > 0) {
                    bk_activity_log($conn, [
                        'actor_id' => 0,
                        'actor_role' => 'system',
                        'claim_id' => $claimId,
                        'action_key' => 'claim_assigned_to_legal_auto',
                        'action_label' => 'Assigned to Legal Officer',
                        'details' => 'Claim was assigned to an available legal officer.',
                        'meta' => [
                            'assigned_legal_id' => $assignee,
                        ],
                    ]);
                }
            }
        }

        // [ASSIGN] Finance backfill pass (missing + orphaned assignees).
        $financeStmt = mysqli_prepare(
            $conn,
            "SELECT c.id
             FROM claims c
             LEFT JOIN users u ON u.id = c.assigned_finance_id
             WHERE COALESCE(NULLIF(c.model_version, ''), 'legacy') = 'v2'
               AND COALESCE(NULLIF(c.status, ''), c.claim_status) IN ('Pending Finance Review', 'Returned by Finance', 'Approved for Disbursement')
               AND (
                    c.assigned_finance_id IS NULL
                    OR c.assigned_finance_id = 0
                    OR u.id IS NULL
                    OR LOWER(TRIM(COALESCE(u.role, ''))) <> 'finance'
                    OR COALESCE(u.acceptance, 'No') <> 'Yes'
               )
             ORDER BY updated_at ASC
             LIMIT ?"
        );
        $financeResult = false;
        if ($financeStmt) {
            mysqli_stmt_bind_param($financeStmt, 'i', $limit);
            if (mysqli_stmt_execute($financeStmt)) {
                $financeResult = mysqli_stmt_get_result($financeStmt);
            }
            mysqli_stmt_close($financeStmt);
        }
        if ($financeResult) {
            while ($row = mysqli_fetch_assoc($financeResult)) {
                $claimId = (int) ($row['id'] ?? 0);
                if ($claimId <= 0) {
                    continue;
                }
                $assignee = bk_pick_staff_assignee($conn, 'finance', 'finance');
                if ($assignee === null) {
                    $clearStmt = mysqli_prepare(
                        $conn,
                        "UPDATE claims
                         SET assigned_finance_id = NULL,
                             assigned_to = NULL
                         WHERE id = ?
                         LIMIT 1"
                    );
                    if ($clearStmt) {
                        mysqli_stmt_bind_param($clearStmt, 'i', $claimId);
                        @mysqli_stmt_execute($clearStmt);
                        mysqli_stmt_close($clearStmt);
                    }
                    if (mysqli_affected_rows($conn) > 0) {
                        bk_activity_log($conn, [
                            'actor_id' => 0,
                            'actor_role' => 'system',
                            'claim_id' => $claimId,
                            'action_key' => 'claim_assignment_pending_finance_auto',
                            'action_label' => 'Awaiting Finance Assignment',
                            'details' => 'No approved finance officer is currently available for assignment.',
                        ]);
                        udcs_claim_history_log($conn, $claimId, 'system', 'Awaiting Finance Assignment', 'No approved finance officer is currently available for assignment.');
                    }
                    continue;
                }
                $assignStmt = mysqli_prepare(
                    $conn,
                    "UPDATE claims
                     SET assigned_finance_id = ?,
                         assigned_to = ?
                     WHERE id = ?
                     LIMIT 1"
                );
                if ($assignStmt) {
                    mysqli_stmt_bind_param($assignStmt, 'iii', $assignee, $assignee, $claimId);
                    @mysqli_stmt_execute($assignStmt);
                    mysqli_stmt_close($assignStmt);
                }
                if (mysqli_affected_rows($conn) > 0) {
                    bk_activity_log($conn, [
                        'actor_id' => 0,
                        'actor_role' => 'system',
                        'claim_id' => $claimId,
                        'action_key' => 'claim_assigned_to_finance_auto',
                        'action_label' => 'Assigned to Finance Officer',
                        'details' => 'Claim was assigned to an available finance officer.',
                        'meta' => [
                            'assigned_finance_id' => $assignee,
                        ],
                    ]);
                }
            }
        }
    }
}
