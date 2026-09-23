<?php
// Attendance Checker Draft Submission to Monitoring Head
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['checker']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'submit_all_drafts') {
        // Find all distinct teachers with draft logs
        $stmtTeachers = $pdo->prepare("
            SELECT DISTINCT teacher_id 
            FROM `attendance_logs` 
            WHERE `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'draft'
        ");
        $stmtTeachers->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
        $teacherIds = $stmtTeachers->fetchAll(PDO::FETCH_COLUMN);

        $submittedCount = 0;
        foreach ($teacherIds as $tId) {
            // Upsert teacher_report_summaries
            $stmtSum = $pdo->prepare("
                INSERT INTO `teacher_report_summaries`
                (`teacher_id`, `school_year`, `school_term`, `is_locked_operator`)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE `is_locked_operator` = 1
            ");
            $stmtSum->execute([$tId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);

            // Get summary_id
            $stmtId = $pdo->prepare("SELECT summary_id FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
            $stmtId->execute([$tId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
            $summaryId = (int)$stmtId->fetchColumn();

            // Transition logs: draft -> submitted_operator
            $stmtUpd = $pdo->prepare("
                UPDATE `attendance_logs`
                SET `workflow_status` = 'submitted_operator'
                WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'draft'
            ");
            $stmtUpd->execute([$tId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);

            logReportAuditTrail(
                $pdo, $summaryId, (int)$tId,
                $activeSettings['current_school_year'], $activeSettings['current_school_term'],
                (int)$_SESSION['user_id'], 'checker', 'SUBMIT_DRAFTS_TO_MONITORING',
                'draft', 'submitted_operator', 'Checker finalized room attendance logs'
            );
            $submittedCount++;
        }

        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'checker', "Submitted attendance drafts to Monitoring Head for {$submittedCount} teachers");
        $_SESSION['flash_success'] = "Successfully submitted draft attendance reports for {$submittedCount} teachers to the Monitoring Office.";
        header("Location: /tams/checker/submissions.php");
        exit;
    }
}

// Fetch grouped draft counts per teacher
$stmtDrafts = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name,
           COUNT(al.log_id) as draft_logs_count,
           MIN(al.date) as earliest_date,
           MAX(al.date) as latest_date
    FROM `attendance_logs` al
    JOIN `teachers` t ON al.teacher_id = t.teacher_id
    WHERE al.school_year = ? AND al.school_term = ? AND al.workflow_status = 'draft'
    GROUP BY t.teacher_id
    ORDER BY t.last_name ASC
");
$stmtDrafts->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$draftGroups = $stmtDrafts->fetchAll();

// Fetch already submitted batches
$stmtSubmitted = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name,
           COUNT(al.log_id) as submitted_logs_count,
           al.workflow_status
    FROM `attendance_logs` al
    JOIN `teachers` t ON al.teacher_id = t.teacher_id
    WHERE al.school_year = ? AND al.school_term = ? AND al.workflow_status != 'draft'
    GROUP BY t.teacher_id, al.workflow_status
    ORDER BY t.last_name ASC
");
$stmtSubmitted->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$submittedGroups = $stmtSubmitted->fetchAll();

$pageTitle = "Checker Submissions";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Operator Attendance Submission Pipeline</h1>
            <p class="text-sm text-gray-500">
                Review and finalize draft logs for transmission to the Monitoring Office Head.
            </p>
        </div>
        <?php if (!empty($draftGroups)): ?>
            <form method="POST" onsubmit="return confirm('Submit all draft attendance logs to the Monitoring Office? Your edits will be locked.');">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="submit_all_drafts">
                <button type="submit" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold shadow-sm">
                    Submit All Drafts to Monitoring Head &rarr;
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Pending Drafts Table -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-amber-50 border-b border-amber-100 flex justify-between items-center">
            <h2 class="text-base font-bold text-amber-900">Pending Draft Logs (Awaiting Submission)</h2>
            <span class="text-xs bg-amber-200 text-amber-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($draftGroups) ?> Teachers</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Draft Logs</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Date Range</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($draftGroups)): ?>
                        <tr><td colspan="4" class="px-6 py-6 text-center text-gray-500">No pending drafts to submit. All entries are current.</td></tr>
                    <?php else: foreach ($draftGroups as $dg): ?>
                        <tr>
                            <td class="px-6 py-4 font-bold text-gray-900"><?= e($dg['last_name'] . ', ' . $dg['first_name']) ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2.5 py-0.5 text-xs font-bold rounded-full bg-amber-100 text-amber-800">
                                    <?= (int)$dg['draft_logs_count'] ?> Drafts
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs font-mono text-gray-600">
                                <?= e($dg['earliest_date']) ?> to <?= e($dg['latest_date']) ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="/tams/checker/log_attendance.php?date=<?= urlencode($dg['latest_date']) ?>" class="text-xs text-blue-600 hover:text-blue-900 underline font-medium">
                                    Review Logs &rarr;
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Active Submitted Reports (Locked from Checker Editing) -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Active Submitted Reports (Locked from Operator Editing)</h2>
            <span class="text-xs bg-gray-200 text-gray-700 font-bold px-2.5 py-0.5 rounded-full"><?= count($submittedGroups) ?> Groups</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Submitted Logs</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Current Workflow State</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Lock State</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($submittedGroups)): ?>
                        <tr><td colspan="4" class="px-6 py-6 text-center text-gray-500">No submitted reports yet.</td></tr>
                    <?php else: foreach ($submittedGroups as $sg): ?>
                        <tr>
                            <td class="px-6 py-4 font-medium text-gray-900"><?= e($sg['last_name'] . ', ' . $sg['first_name']) ?></td>
                            <td class="px-6 py-4 font-semibold text-gray-700"><?= (int)$sg['submitted_logs_count'] ?> logs</td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 text-xs rounded font-bold uppercase tracking-wider bg-indigo-50 text-indigo-700 border border-indigo-200">
                                    <?= e(str_replace('_', ' ', $sg['workflow_status'])) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span class="inline-flex items-center px-2 py-0.5 text-xs rounded bg-red-50 text-red-700 font-semibold border border-red-200">
                                    <svg class="w-3.5 h-3.5 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                                    Operator Locked
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
