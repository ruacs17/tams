<?php
// Attendance Evaluation, Suspension Matching & Penalty Engine
// Teacher Attendance Monitoring System (TAMS)

/**
 * Parses time string like "08:00 am - 09:00 am" or "1:00 pm - 2:30 pm"
 * Returns ['start' => '08:00:00', 'end' => '09:00:00'] or null
 */
function parseScheduleTimeInterval(string $timeStr): ?array {
    $timeStr = trim($timeStr);
    if ($timeStr === '*** O P E N ***' || empty($timeStr)) {
        return null;
    }

    $parts = explode('-', $timeStr);
    if (count($parts) !== 2) {
        return null;
    }

    $startRaw = trim($parts[0]);
    $endRaw = trim($parts[1]);

    $startTs = strtotime($startRaw);
    $endTs = strtotime($endRaw);

    if ($startTs === false || $endTs === false) {
        return null;
    }

    return [
        'start' => date('H:i:s', $startTs),
        'end' => date('H:i:s', $endTs)
    ];
}

/**
 * Checks if a given load matches the scope of a calendar event
 */
function doesLoadMatchEventScope(PDO $pdo, array $load, array $event): bool {
    $scopeType = $event['scope_type'];
    $scopeId = (int)($event['scope_id'] ?? 0);

    if ($scopeType === 'institution') {
        return true;
    }

    $deptId = (int)($load['department_id'] ?? 0);
    $loadId = (int)($load['load_id'] ?? 0);

    if ($scopeType === 'subject_load') {
        return ($loadId === $scopeId);
    }

    if ($scopeType === 'department') {
        return ($deptId === $scopeId);
    }

    if ($scopeType === 'college') {
        if ($deptId <= 0) return false;
        $stmt = $pdo->prepare("SELECT college_id FROM `departments` WHERE `department_id` = ?");
        $stmt->execute([$deptId]);
        $collegeId = (int)$stmt->fetchColumn();
        return ($collegeId === $scopeId);
    }

    return false;
}

/**
 * Evaluates whether an attendance record falls into an active Calendar Event (Holiday, SOS, PSE)
 * Returns evaluated status string ('Present', 'Late', 'Absent', 'Holiday', 'Suspended', 'Partial Suspension', 'Excused')
 */
function evaluateScheduleStatus(
    PDO $pdo,
    array $load,
    string $date,
    ?string $actualTimeIn,
    ?string $actualTimeOut,
    ?string $overrideStatus = null
): array {
    if (!empty($overrideStatus) && in_array($overrideStatus, ['Present', 'Late', 'Absent', 'Excused', 'Holiday', 'Suspended', 'Partial Suspension'], true)) {
        return [
            'status' => $overrideStatus,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'remark' => 'Manual override'
        ];
    }

    // Retrieve active calendar events for this date
    $stmt = $pdo->prepare("
        SELECT * FROM `calendar_events`
        WHERE ? BETWEEN `start_date` AND `end_date`
    ");
    $stmt->execute([$date]);
    $events = $stmt->fetchAll();

    // Check for full-day holidays or emergency closures first
    foreach ($events as $event) {
        if (doesLoadMatchEventScope($pdo, $load, $event)) {
            if ($event['event_type'] === 'holiday') {
                return [
                    'status' => 'Holiday',
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'remark' => 'Covered by Holiday: ' . $event['title']
                ];
            }
            if ($event['event_type'] === 'emergency_closure') {
                return [
                    'status' => 'Suspended',
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'remark' => 'Covered by Emergency Closure: ' . $event['title']
                ];
            }
        }
    }

    // Schedule time parsing
    $parsedTime = parseScheduleTimeInterval($load['time'] ?? '');
    if (!$parsedTime) {
        // Placeholder or non-standard time
        return [
            'status' => !empty($actualTimeIn) ? 'Present' : 'Absent',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'remark' => 'Non-standard schedule time'
        ];
    }

    $schedStartStr = $parsedTime['start'];
    $schedEndStr = $parsedTime['end'];
    $schedStartTs = strtotime($date . ' ' . $schedStartStr);
    $schedEndTs = strtotime($date . ' ' . $schedEndStr);

    // Check for partial suspensions (PSE)
    foreach ($events as $event) {
        if ($event['event_type'] === 'partial_suspension' && doesLoadMatchEventScope($pdo, $load, $event)) {
            $evStartStr = $event['start_time'] ?? '00:00:00';
            $evEndStr = $event['end_time'] ?? '23:59:59';
            $evStartTs = strtotime($date . ' ' . $evStartStr);
            $evEndTs = strtotime($date . ' ' . $evEndStr);

            // Case A: Class Fully Inside Window
            if ($schedStartTs >= $evStartTs && $schedEndTs <= $evEndTs) {
                // If event requires checkin and no clock-in occurred
                if (!empty($event['require_event_checkin']) && empty($actualTimeIn)) {
                    return [
                        'status' => 'Absent',
                        'late_minutes' => 0,
                        'undertime_minutes' => 0,
                        'remark' => 'Unverified Check-in for Partial Suspension: ' . $event['title']
                    ];
                }
                return [
                    'status' => 'Partial Suspension',
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'remark' => 'Class fully inside suspension window: ' . $event['title']
                ];
            }

            // Case B: Class Overlapping Window End (e.g. window ends at 10:30, class is 10:00-11:30)
            if ($schedStartTs <= $evEndTs && $schedEndTs > $evEndTs) {
                // Configurable Automated Grace Buffer: 15 minutes post-window
                $graceLimitTs = $evEndTs + (15 * 60);

                if (!empty($actualTimeIn)) {
                    $actualInTs = strtotime($date . ' ' . $actualTimeIn);
                    if ($actualInTs <= $graceLimitTs) {
                        return [
                            'status' => 'Present',
                            'late_minutes' => 0,
                            'undertime_minutes' => 0,
                            'remark' => 'Arrived within post-suspension grace window: ' . $event['title']
                        ];
                    } else {
                        $lateMins = (int)ceil(($actualInTs - $graceLimitTs) / 60);
                        return [
                            'status' => 'Late',
                            'late_minutes' => max(0, $lateMins),
                            'undertime_minutes' => 0,
                            'remark' => 'Late after suspension grace window'
                        ];
                    }
                } else {
                    return [
                        'status' => 'Absent',
                        'late_minutes' => 0,
                        'undertime_minutes' => 0,
                        'remark' => 'Absent after suspension window ended'
                    ];
                }
            }
        }
    }

    // Standard Attendance Evaluation (No active suspension/holiday)
    if (empty($actualTimeIn)) {
        return [
            'status' => 'Absent',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'remark' => 'No time-in recorded'
        ];
    }

    $actualInTs = strtotime($date . ' ' . $actualTimeIn);
    $standardGraceTs = $schedStartTs + (15 * 60); // 15-minute standard grace period

    $lateMinutes = 0;
    $status = 'Present';

    if ($actualInTs > $standardGraceTs) {
        $lateMinutes = (int)ceil(($actualInTs - $schedStartTs) / 60);
        $status = 'Late';
    }

    $undertimeMinutes = 0;
    if (!empty($actualTimeOut)) {
        $actualOutTs = strtotime($date . ' ' . $actualTimeOut);
        if ($actualOutTs < $schedEndTs) {
            $undertimeMinutes = (int)ceil(($schedEndTs - $actualOutTs) / 60);
        }
    }

    return [
        'status' => $status,
        'late_minutes' => $lateMinutes,
        'undertime_minutes' => $undertimeMinutes,
        'remark' => ($status === 'Late' ? "Late by {$lateMinutes} mins" : 'On time')
    ];
}

/**
 * Retroactive Recalculation Engine
 * Re-evaluates attendance logs matching an event's date and scope, converting invalid ABS/LTE to SOS/PSE
 */
function recalculateRetroactiveEvent(PDO $pdo, int $eventId): int {
    $stmtEv = $pdo->prepare("SELECT * FROM `calendar_events` WHERE `event_id` = ?");
    $stmtEv->execute([$eventId]);
    $event = $stmtEv->fetch();
    if (!$event) return 0;

    $startDate = $event['start_date'];
    $endDate = $event['end_date'];

    // Select logs within the date range that could be affected
    $stmtLogs = $pdo->prepare("
        SELECT al.*, tl.department_id, tl.time, tl.offer_code
        FROM `attendance_logs` al
        LEFT JOIN `teacher_loads` tl ON al.load_id = tl.load_id
        WHERE al.date BETWEEN ? AND ?
    ");
    $stmtLogs->execute([$startDate, $endDate]);
    $logs = $stmtLogs->fetchAll();

    $updatedCount = 0;
    $affectedTeachers = [];

    foreach ($logs as $log) {
        $loadData = [
            'load_id' => $log['load_id'],
            'department_id' => $log['department_id'],
            'time' => $log['time'],
            'offer_code' => $log['offer_code']
        ];

        if (doesLoadMatchEventScope($pdo, $loadData, $event)) {
            // Re-evaluate
            $eval = evaluateScheduleStatus($pdo, $loadData, $log['date'], $log['actual_time_in'], $log['actual_time_out']);

            // If status changed to Holiday, Suspended, or Partial Suspension
            if ($eval['status'] !== $log['status']) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE `attendance_logs`
                    SET `status` = ?, `late_minutes` = ?, `undertime_minutes` = ?, `schedule_remarks` = ?
                    WHERE `log_id` = ?
                ");
                $stmtUpdate->execute([
                    $eval['status'],
                    $eval['late_minutes'],
                    $eval['undertime_minutes'],
                    'Retroactive update: ' . $eval['remark'],
                    $log['log_id']
                ]);
                $updatedCount++;
                $affectedTeachers[$log['teacher_id']] = [
                    'year' => $log['school_year'],
                    'term' => $log['school_term']
                ];
            }
        }
    }

    // Recompute penalties for all affected teachers
    foreach ($affectedTeachers as $tId => $period) {
        recomputeTeacherPenalties($pdo, $tId, $period['year'], $period['term']);
    }

    return $updatedCount;
}

/**
 * Recalculates and updates attendance_penalties table
 * Business rule: 3 lates = 1 absent (discounting holidays, suspensions, partial suspensions)
 */
function recomputeTeacherPenalties(PDO $pdo, int $teacherId, string $schoolYear, string $schoolTerm): array {
    // Count lates for the specified term/year
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as late_count
        FROM `attendance_logs`
        WHERE `teacher_id` = ? 
          AND `school_year` = ? 
          AND `school_term` = ?
          AND `status` = 'Late'
    ");
    $stmt->execute([$teacherId, $schoolYear, $schoolTerm]);
    $lateCount = (int)$stmt->fetchColumn();

    $convertedAbsents = (int)floor($lateCount / 3);

    // Upsert into attendance_penalties
    $stmtUpsert = $pdo->prepare("
        INSERT INTO `attendance_penalties` (`teacher_id`, `school_year`, `school_term`, `accumulated_lates_count`, `converted_absents_count`)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            `accumulated_lates_count` = VALUES(`accumulated_lates_count`),
            `converted_absents_count` = VALUES(`converted_absents_count`)
    ");
    $stmtUpsert->execute([$teacherId, $schoolYear, $schoolTerm, $lateCount, $convertedAbsents]);

    return [
        'accumulated_lates_count' => $lateCount,
        'converted_absents_count' => $convertedAbsents
    ];
}
