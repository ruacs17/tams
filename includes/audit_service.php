<?php
// Dual Logging Framework: Administrative Audit Logs & Report-Level Audit Trails
// Teacher Attendance Monitoring System (TAMS)

function getClientIP(): string {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ipList[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * System-Level Administrative Audit Logging
 */
function logSystemAudit(PDO $pdo, int $userId, string $role, string $action, ?int $targetRecordId = null): void {
    try {
        $ip = getClientIP();
        $stmt = $pdo->prepare("
            INSERT INTO `audit_logs` (`user_id`, `role`, `action`, `target_record_id`, `ip_address`, `timestamp`)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $role, $action, $targetRecordId, $ip]);
    } catch (PDOException $e) {
        error_log("Failed to log system audit: " . $e->getMessage());
    }
}

/**
 * Granular Report-Level Audit Trail Logging
 */
function logReportAuditTrail(
    PDO $pdo,
    ?int $summaryId,
    int $teacherId,
    string $schoolYear,
    string $schoolTerm,
    int $actorId,
    string $actorRole,
    string $actionType,
    ?string $prevStatus,
    ?string $newStatus,
    ?string $remarksSnapshot = null
): void {
    try {
        $ip = getClientIP();
        $stmt = $pdo->prepare("
            INSERT INTO `report_audit_trails` (
                `summary_id`, `teacher_id`, `school_year`, `school_term`,
                `actor_id`, `actor_role`, `action_type`,
                `previous_workflow_status`, `new_workflow_status`,
                `remarks_snapshot`, `ip_address`, `timestamp`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $summaryId,
            $teacherId,
            $schoolYear,
            $schoolTerm,
            $actorId,
            $actorRole,
            $actionType,
            $prevStatus,
            $newStatus,
            $remarksSnapshot,
            $ip
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log report audit trail: " . $e->getMessage());
    }
}
