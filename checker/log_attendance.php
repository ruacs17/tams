<?php
// Room-to-Room Daily Attendance Logger
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';
require_once __DIR__ . '/../includes/attendance_engine.php';

requireAuth(['checker']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Handle Attendance Logging Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'record_log') {
        $loadId = (int)$_POST['load_id'];
        $logDate = $_POST['log_date'];
        $source = $_POST['source'] ?? 'manual_operator';
        $actualIn = !empty($_POST['actual_time_in']) ? $_POST['actual_time_in'] : null;
        $actualOut = !empty($_POST['actual_time_out']) ? $_POST['actual_time_out'] : null;
        $overrideStatus = !empty($_POST['override_status']) ? $_POST['override_status'] : null;
        $remarks = trim($_POST['schedule_remarks'] ?? '');

        // Fetch load details
        $stmtLoad = $pdo->prepare("SELECT * FROM `teacher_loads` WHERE `load_id` = ?");
        $stmtLoad->execute([$loadId]);
        $load = $stmtLoad->fetch();

        if (!$load || empty($load['teacher_id'])) {
            $_SESSION['flash_error'] = 'Invalid course load or load has no assigned teacher.';
        } else {
            $teacherId = (int)$load['teacher_id'];
            $parsedTimes = parseScheduleTimeInterval($load['time']);
            $schedIn = $parsedTimes ? $parsedTimes['start'] : null;
            $schedOut = $parsedTimes ? $parsedTimes['end'] : null;

            // Evaluate attendance status against calendar events & schedule window
            $eval = evaluateScheduleStatus($pdo, $load, $logDate, $actualIn, $actualOut, $overrideStatus);

            $finalStatus = $eval['status'];
            $lateMins = (int)$eval['late_minutes'];
            $undertimeMins = (int)$eval['undertime_minutes'];

            if (!empty($eval['remark']) && empty($remarks)) {
                $remarks = $eval['remark'];
            }

            // Insert attendance log (Initial workflow status = 'draft')
            $stmtInsert = $pdo->prepare("
                INSERT INTO `attendance_logs`
                (`load_id`, `teacher_id`, `date`, `school_year`, `school_term`,
                 `scheduled_time_in`, `scheduled_time_out`, `actual_time_in`, `actual_time_out`,
                 `source`, `late_minutes`, `undertime_minutes`, `status`,
                 `workflow_status`, `schedule_remarks`, `logged_by`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
            ");
            $stmtInsert->execute([
                $loadId, $teacherId, $logDate,
                $activeSettings['current_school_year'], $activeSettings['current_school_term'],
                $schedIn, $schedOut, $actualIn, $actualOut,
                $source, $lateMins, $undertimeMins, $finalStatus,
                $remarks, (int)$_SESSION['user_id']
            ]);

            logSystemAudit($pdo, (int)$_SESSION['user_id'], 'checker', "Logged attendance for Load {$load['offer_code']} on {$logDate}: {$finalStatus}");
            $_SESSION['flash_success'] = "Attendance entry recorded for {$load['offer_code']} ({$finalStatus}).";
        }

        header("Location: /tams/checker/log_attendance.php?date=" . urlencode($logDate));
        exit;

    } elseif ($action === 'delete_draft_log') {
        $logId = (int)$_POST['log_id'];
        $pdo->prepare("DELETE FROM `attendance_logs` WHERE `log_id` = ? AND `workflow_status` = 'draft'")->execute([$logId]);
        $_SESSION['flash_success'] = "Draft attendance entry deleted.";
        header("Location: /tams/checker/log_attendance.php?date=" . urlencode($selectedDate));
        exit;
    }
}

// Fetch active loads for dropdown
$stmtLoads = $pdo->prepare("
    SELECT tl.*, t.first_name, t.last_name, d.department_abbreviation
    FROM `teacher_loads` tl
    JOIN `teachers` t ON tl.teacher_id = t.teacher_id
    LEFT JOIN `departments` d ON tl.department_id = d.department_id
    WHERE tl.school_year = ? AND tl.school_term = ?
    ORDER BY tl.offer_code ASC
");
$stmtLoads->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$availableLoads = $stmtLoads->fetchAll();

// Fetch logs recorded for the selected date
$stmtLogs = $pdo->prepare("
    SELECT al.*, tl.offer_code, tl.subject_name, tl.room, tl.days, tl.time as sched_time_str,
           t.first_name, t.last_name
    FROM `attendance_logs` al
    LEFT JOIN `teacher_loads` tl ON al.load_id = tl.load_id
    JOIN `teachers` t ON al.teacher_id = t.teacher_id
    WHERE al.date = ? AND al.school_year = ? AND al.school_term = ?
    ORDER BY al.timestamp DESC
");
$stmtLogs->execute([$selectedDate, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$recordedLogs = $stmtLogs->fetchAll();

$pageTitle = "Room-to-Room Daily Logger";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Room-to-Room Daily Attendance Logger</h1>
            <p class="text-sm text-gray-500">
                Cycle: <span class="font-semibold text-blue-700">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <form method="GET" class="flex items-center space-x-2">
            <label class="text-xs font-semibold text-gray-600 uppercase">Log Date:</label>
            <input type="date" name="date" value="<?= e($selectedDate) ?>" class="border border-gray-300 rounded px-3 py-1.5 text-sm" onchange="this.form.submit()">
            <noscript><button type="submit" class="px-3 py-1.5 bg-gray-800 text-white text-xs rounded">Go</button></noscript>
        </form>
    </div>

    <!-- Logger Input Card -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
        <h2 class="text-base font-bold text-gray-800 mb-4 flex items-center">
            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            Record Attendance Entry for <?= date('l, F j, Y', strtotime($selectedDate)) ?>
        </h2>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="record_log">
            <input type="hidden" name="log_date" value="<?= e($selectedDate) ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Course Schedule / Offering</label>
                    <select name="load_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                        <option value="">-- Select Class Schedule --</option>
                        <?php foreach ($availableLoads as $al): ?>
                            <option value="<?= (int)$al['load_id'] ?>">
                                <?= e($al['offer_code']) ?> &bull; <?= e($al['subject_name']) ?> (<?= e($al['room']) ?> &bull; <?= e($al['days']) ?> <?= e($al['time']) ?>) &mdash; Prof. <?= e($al['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Verification Source</label>
                    <select name="source" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                        <option value="manual_operator">Manual Operator (Room Checking)</option>
                        <option value="device_biometric">Biometric Device Simulation</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Actual Time In</label>
                    <input type="time" name="actual_time_in" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm font-mono">
                    <span class="text-xs text-gray-400">Leave blank if teacher did not arrive (Absent)</span>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Actual Time Out</label>
                    <input type="time" name="actual_time_out" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Status Override (Optional)</label>
                    <select name="override_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                        <option value="">Auto-Calculate (Recommended)</option>
                        <option value="Present">Present (PRE)</option>
                        <option value="Late">Late Arrival (LTE)</option>
                        <option value="Absent">Unexcused Absent (ABS)</option>
                        <option value="Excused">Official Duty / Excused (EXM)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Remarks / Observations</label>
                <input type="text" name="schedule_remarks" placeholder="e.g. Conducted class activity, early dismissal due to seminar, etc." class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-semibold shadow-sm">
                    Record Attendance Entry
                </button>
            </div>
        </form>
    </div>

    <!-- Recorded Logs Table for Selected Date -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Recorded Attendance Entries for <?= date('M j, Y', strtotime($selectedDate)) ?></h2>
            <span class="text-xs bg-blue-100 text-blue-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($recordedLogs) ?> Logs</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Offer Code</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Scheduled Time</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Actual Clock-In / Out</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Lates / Undertime</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Workflow State</th>
                        <th class="px-4 py-3 text-right font-semibold text-gray-600">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($recordedLogs)): ?>
                        <tr><td colspan="8" class="px-6 py-6 text-center text-gray-500">No attendance entries recorded yet for this date.</td></tr>
                    <?php else: foreach ($recordedLogs as $rl): 
                        $isDraft = ($rl['workflow_status'] === 'draft');
                    ?>
                        <tr>
                            <td class="px-4 py-3 font-mono font-bold text-indigo-700"><?= e($rl['offer_code']) ?></td>
                            <td class="px-4 py-3 font-semibold text-gray-900"><?= e($rl['last_name'] . ', ' . $rl['first_name']) ?></td>
                            <td class="px-4 py-3 text-xs text-gray-600 font-mono">
                                <?= e($rl['sched_time_str'] ?? ($rl['scheduled_time_in'] . ' - ' . $rl['scheduled_time_out'])) ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                <span class="font-bold text-gray-800"><?= $rl['actual_time_in'] ? date('h:i A', strtotime($rl['actual_time_in'])) : 'None' ?></span>
                                &rarr;
                                <span class="text-gray-600"><?= $rl['actual_time_out'] ? date('h:i A', strtotime($rl['actual_time_out'])) : 'None' ?></span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 text-xs rounded-full font-mono font-bold
                                    <?= $rl['status'] === 'Present' ? 'bg-green-100 text-green-800' : ($rl['status'] === 'Late' ? 'bg-yellow-100 text-yellow-800' : ($rl['status'] === 'Absent' ? 'bg-red-100 text-red-800' : 'bg-purple-100 text-purple-800')) ?>">
                                    <?= e($rl['status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs font-mono">
                                <?= $rl['late_minutes'] > 0 ? "<span class='text-yellow-700 font-bold'>+{$rl['late_minutes']}m late</span>" : "<span class='text-gray-400'>0</span>" ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded text-xs font-bold uppercase tracking-wider <?= $isDraft ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700' ?>">
                                    <?= e($rl['workflow_status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <?php if ($isDraft): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this draft entry?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="post_action" value="delete_draft_log">
                                        <input type="hidden" name="log_id" value="<?= (int)$rl['log_id'] ?>">
                                        <button type="submit" class="text-xs text-red-600 hover:text-red-900 underline font-medium">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400 italic">Locked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
