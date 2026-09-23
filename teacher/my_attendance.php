<?php
// Detailed Teacher Attendance Logs & Historical Archival Access
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['teacher', 'monitoring_head', 'chairperson', 'dean', 'vp']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

// Determine target teacher
$targetTeacherId = (int)($_SESSION['teacher_id'] ?? 0);
if (isset($_GET['view_teacher_id']) && in_array($_SESSION['role'], ['monitoring_head', 'chairperson', 'dean', 'vp'], true)) {
    $targetTeacherId = (int)$_GET['view_teacher_id'];
}

// Year and Term Selection
$selectedYear = $_GET['year'] ?? $activeSettings['current_school_year'];
$selectedTerm = $_GET['term'] ?? $activeSettings['current_school_term'];

// Security & Historical Archival Access Verification
if (!verifyArchivalAccess($pdo, $targetTeacherId, $selectedYear, $selectedTerm)) {
    http_response_code(403);
    die("Access Denied: You do not have permission to view historical records for this faculty member in S.Y. {$selectedYear} ({$selectedTerm}).");
}

// Fetch teacher info
$stmtT = $pdo->prepare("SELECT * FROM `teachers` WHERE `teacher_id` = ?");
$stmtT->execute([$targetTeacherId]);
$teacher = $stmtT->fetch();

// Fetch report summary
$stmtSum = $pdo->prepare("SELECT * FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
$stmtSum->execute([$targetTeacherId, $selectedYear, $selectedTerm]);
$summary = $stmtSum->fetch();

// Fetch penalties
$stmtPen = $pdo->prepare("SELECT * FROM `attendance_penalties` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
$stmtPen->execute([$targetTeacherId, $selectedYear, $selectedTerm]);
$penalty = $stmtPen->fetch() ?: ['accumulated_lates_count' => 0, 'converted_absents_count' => 0];

// Fetch attendance logs with load details and evidence
$stmtLogs = $pdo->prepare("
    SELECT al.*, tl.offer_code, tl.subject_name, tl.room, tl.days, tl.time as sched_time_str
    FROM `attendance_logs` al
    LEFT JOIN `teacher_loads` tl ON al.load_id = tl.load_id
    WHERE al.teacher_id = ? AND al.school_year = ? AND al.school_term = ?
    ORDER BY al.date DESC, al.scheduled_time_in ASC
");
$stmtLogs->execute([$targetTeacherId, $selectedYear, $selectedTerm]);
$logs = $stmtLogs->fetchAll();

// Fetch attached evidence
$evidenceMap = [];
if (!empty($logs)) {
    $logIds = array_column($logs, 'log_id');
    $inPlaceholders = implode(',', array_fill(0, count($logIds), '?'));
    $stmtEv = $pdo->prepare("SELECT * FROM `attendance_evidence` WHERE `log_id` IN ($inPlaceholders)");
    $stmtEv->execute($logIds);
    while ($ev = $stmtEv->fetch()) {
        $evidenceMap[$ev['log_id']][] = $ev;
    }
}

// Available terms and years for archival dropdown
$stmtTerms = $pdo->prepare("SELECT DISTINCT school_year, school_term FROM `attendance_logs` WHERE `teacher_id` = ? ORDER BY school_year DESC, school_term DESC");
$stmtTerms->execute([$targetTeacherId]);
$availablePeriods = $stmtTerms->fetchAll();

$pageTitle = "Attendance Logs - " . e($teacher['first_name'] . ' ' . $teacher['last_name']);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Faculty Attendance Logs & Historical Archive</h1>
            <p class="text-sm text-gray-500">
                Faculty: <span class="font-bold text-gray-900"><?= e($teacher['first_name'] . ' ' . $teacher['last_name']) ?></span> (ID: <?= (int)$targetTeacherId ?>)
            </p>
        </div>

        <!-- Archival Term Filter -->
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <?php if (isset($_GET['view_teacher_id'])): ?>
                <input type="hidden" name="view_teacher_id" value="<?= (int)$targetTeacherId ?>">
            <?php endif; ?>
            <div class="flex items-center space-x-1">
                <label class="text-xs font-semibold text-gray-600 uppercase">Archive:</label>
                <input type="text" name="year" value="<?= e($selectedYear) ?>" placeholder="YYYY-YYYY" class="border border-gray-300 rounded px-2.5 py-1 text-xs font-mono w-28">
            </div>
            <div>
                <select name="term" class="border border-gray-300 rounded px-2.5 py-1 text-xs">
                    <option value="1st Term" <?= $selectedTerm === '1st Term' ? 'selected' : '' ?>>1st Term</option>
                    <option value="2nd Term" <?= $selectedTerm === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
                    <option value="Summer" <?= $selectedTerm === 'Summer' ? 'selected' : '' ?>>Summer</option>
                </select>
            </div>
            <button type="submit" class="px-3 py-1 bg-gray-800 text-white rounded text-xs font-semibold hover:bg-gray-700">
                Load Archive
            </button>
        </form>
    </div>

    <!-- Summary Box -->
    <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="space-y-1">
            <div class="text-xs font-bold text-gray-500 uppercase">Period Overview</div>
            <div class="text-lg font-bold text-gray-900">
                S.Y. <?= e($selectedYear) ?> &bull; <?= e($selectedTerm) ?>
            </div>
            <div class="text-xs text-gray-600">
                Overall Remarks: <span class="italic"><?= e($summary['overall_remarks'] ?? 'No remarks provided yet.') ?></span>
            </div>
        </div>

        <div class="flex items-center space-x-3">
            <div class="bg-yellow-50 border border-yellow-200 rounded px-3 py-2 text-center">
                <div class="text-xs font-semibold text-yellow-800 uppercase">Accumulated Lates</div>
                <div class="text-xl font-extrabold text-yellow-700"><?= (int)$penalty['accumulated_lates_count'] ?></div>
            </div>
            <div class="bg-red-50 border border-red-200 rounded px-3 py-2 text-center">
                <div class="text-xs font-semibold text-red-800 uppercase">Converted Absents</div>
                <div class="text-xl font-extrabold text-red-700"><?= (int)$penalty['converted_absents_count'] ?></div>
            </div>
            <?php if ($_SESSION['role'] === 'teacher' && empty($summary['is_locked_teacher'])): ?>
                <a href="/tams/teacher/evidence_upload.php" class="px-3 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded text-xs font-semibold shadow-sm">
                    Upload Dispute
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Detailed Logs Table -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Itemized Attendance Log Entries</h2>
            <span class="text-xs bg-indigo-100 text-indigo-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($logs) ?> Entries</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 text-left">Date</th>
                        <th class="px-4 py-3 text-left">Course / Offering</th>
                        <th class="px-4 py-3 text-left">Scheduled Window</th>
                        <th class="px-4 py-3 text-left">Actual In / Out</th>
                        <th class="px-4 py-3 text-left">Status Code</th>
                        <th class="px-4 py-3 text-left">Late / Undertime</th>
                        <th class="px-4 py-3 text-left">Supporting Evidence</th>
                        <th class="px-4 py-3 text-left">Remarks & Observations</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white text-xs">
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="8" class="px-6 py-6 text-center text-gray-500">No attendance entries found for this academic period.</td></tr>
                    <?php else: foreach ($logs as $l): 
                        $st = $l['status'];
                        $badgeStyles = [
                            'Present' => 'bg-green-100 text-green-800 border-green-200',
                            'Late'    => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                            'Absent'  => 'bg-red-100 text-red-800 border-red-200',
                            'Holiday' => 'bg-purple-100 text-purple-800 border-purple-200',
                            'Suspended' => 'bg-blue-100 text-blue-800 border-blue-200',
                            'Partial Suspension' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
                            'Excused' => 'bg-gray-100 text-gray-800 border-gray-200'
                        ];
                        $statusBadge = $badgeStyles[$st] ?? 'bg-gray-100 text-gray-800 border-gray-200';
                        $logEvidences = $evidenceMap[$l['log_id']] ?? [];
                    ?>
                        <tr>
                            <td class="px-4 py-3 font-mono font-medium text-gray-900 whitespace-nowrap">
                                <?= e($l['date']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-bold text-indigo-700"><?= e($l['offer_code']) ?></div>
                                <div class="text-gray-500"><?= e($l['subject_name']) ?> (<?= e($l['room']) ?>)</div>
                            </td>
                            <td class="px-4 py-3 font-mono text-gray-600 whitespace-nowrap">
                                <?= e($l['sched_time_str'] ?? ($l['scheduled_time_in'] . ' - ' . $l['scheduled_time_out'])) ?>
                            </td>
                            <td class="px-4 py-3 font-mono whitespace-nowrap">
                                <span class="font-bold text-gray-800"><?= $l['actual_time_in'] ? date('h:i A', strtotime($l['actual_time_in'])) : 'None' ?></span>
                                &rarr;
                                <span class="text-gray-600"><?= $l['actual_time_out'] ? date('h:i A', strtotime($l['actual_time_out'])) : 'None' ?></span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded font-mono font-bold border <?= $statusBadge ?>">
                                    <?= e($st) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono">
                                <?php if ($l['late_minutes'] > 0): ?>
                                    <span class="text-yellow-700 font-bold block">+<?= (int)$l['late_minutes'] ?>m late</span>
                                <?php endif; ?>
                                <?php if ($l['undertime_minutes'] > 0): ?>
                                    <span class="text-red-700 font-bold block">-<?= (int)$l['undertime_minutes'] ?>m undertime</span>
                                <?php endif; ?>
                                <?php if ($l['late_minutes'] == 0 && $l['undertime_minutes'] == 0): ?>
                                    <span class="text-gray-400">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if (empty($logEvidences)): ?>
                                    <span class="text-gray-400 italic">None</span>
                                <?php else: foreach ($logEvidences as $ev): 
                                    $isImg = preg_match('/\.(jpg|jpeg|png)$/i', $ev['file_path']);
                                ?>
                                    <div class="mb-1">
                                        <a href="/tams/<?= e($ev['file_path']) ?>" target="_blank" class="inline-flex items-center text-xs text-blue-600 hover:text-blue-800 font-medium underline">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                            <?= $isImg ? 'Image Evidence' : 'PDF Document' ?>
                                        </a>
                                        <?php if ($ev['remarks']): ?>
                                            <div class="text-gray-500 italic text-[11px]"><?= e($ev['remarks']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; endif; ?>
                            </td>
                            <td class="px-4 py-3 text-gray-700 max-w-xs truncate">
                                <?= e($l['schedule_remarks'] ?: 'None') ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
