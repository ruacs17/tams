<?php
// Secondary Monitoring Office Re-Audit & VP Forwarding
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $teacherId = (int)$_POST['teacher_id'];
    $year = $_POST['school_year'];
    $term = $_POST['school_term'];
    $remarks = trim($_POST['audit_remarks'] ?? '');

    // Get summary ID
    $stmtSum = $pdo->prepare("SELECT summary_id FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
    $stmtSum->execute([$teacherId, $year, $term]);
    $summaryId = (int)$stmtSum->fetchColumn();

    // Advance logs: submitted_dean -> verified_monitoring
    $stmtLogs = $pdo->prepare("
        UPDATE `attendance_logs`
        SET `workflow_status` = 'verified_monitoring'
        WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'submitted_dean'
    ");
    $stmtLogs->execute([$teacherId, $year, $term]);

    logReportAuditTrail(
        $pdo, $summaryId, $teacherId, $year, $term,
        (int)$_SESSION['user_id'], 'monitoring_head', 'SECONDARY_REAUDIT_VERIFIED',
        'submitted_dean', 'verified_monitoring', $remarks
    );

    $_SESSION['flash_success'] = "Report audited and verified. Successfully routed to Vice President for Academics for final institutional approval.";
    header("Location: /tams/monitoring/reaudit.php");
    exit;
}

// Fetch reports currently at submitted_dean stage (Awaiting Re-Audit)
$stmtAwaiting = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name,
           COUNT(al.log_id) as total_logs,
           trs.overall_remarks, trs.summary_id
    FROM `teachers` t
    JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id
    LEFT JOIN `teacher_report_summaries` trs ON t.teacher_id = trs.teacher_id AND trs.school_year = ? AND trs.school_term = ?
    WHERE al.school_year = ? AND al.school_term = ? AND al.workflow_status = 'submitted_dean'
    GROUP BY t.teacher_id
");
$stmtAwaiting->execute([
    $activeSettings['current_school_year'], $activeSettings['current_school_term'],
    $activeSettings['current_school_year'], $activeSettings['current_school_term']
]);
$awaitingReports = $stmtAwaiting->fetchAll();

// Fetch returned reports
$stmtReturned = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name,
           COUNT(al.log_id) as total_logs,
           trs.overall_remarks, trs.summary_id
    FROM `teachers` t
    JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id
    LEFT JOIN `teacher_report_summaries` trs ON t.teacher_id = trs.teacher_id AND trs.school_year = ? AND trs.school_term = ?
    WHERE al.school_year = ? AND al.school_term = ? AND al.workflow_status = 'returned_to_teacher'
    GROUP BY t.teacher_id
");
$stmtReturned->execute([
    $activeSettings['current_school_year'], $activeSettings['current_school_term'],
    $activeSettings['current_school_year'], $activeSettings['current_school_term']
]);
$returnedReports = $stmtReturned->fetchAll();

$pageTitle = "Secondary Re-Audit";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Secondary Monitoring Audit & Executive Forwarding</h1>
        <p class="text-sm text-gray-500">
            Conduct institutional compliance re-audit on reports approved by College Deans before routing to the VP for Academics.
        </p>
    </div>

    <!-- Dean Approved Reports Ready for Verification -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-indigo-50 border-b border-indigo-100 flex justify-between items-center">
            <h2 class="text-base font-bold text-indigo-900">Dean-Approved Reports Awaiting Monitoring Re-Audit</h2>
            <span class="text-xs bg-indigo-200 text-indigo-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($awaitingReports) ?> Reports</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Logs Count</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Overall Remarks Snapshot</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Re-Audit Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($awaitingReports)): ?>
                        <tr><td colspan="4" class="px-6 py-6 text-center text-gray-500">No reports awaiting secondary monitoring audit.</td></tr>
                    <?php else: foreach ($awaitingReports as $ar): ?>
                        <tr>
                            <td class="px-6 py-4 font-bold text-gray-900"><?= e($ar['last_name'] . ', ' . $ar['first_name']) ?></td>
                            <td class="px-6 py-4 font-semibold text-gray-700"><?= (int)$ar['total_logs'] ?> logs</td>
                            <td class="px-6 py-4 text-xs text-gray-600"><?= e($ar['overall_remarks'] ?: 'None') ?></td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$ar['teacher_id'] ?>" class="text-xs text-gray-600 hover:text-gray-900 underline mr-2">Audit Logs</a>
                                <form method="POST" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="teacher_id" value="<?= (int)$ar['teacher_id'] ?>">
                                    <input type="hidden" name="school_year" value="<?= e($activeSettings['current_school_year']) ?>">
                                    <input type="hidden" name="school_term" value="<?= e($activeSettings['current_school_term']) ?>">
                                    <input type="hidden" name="audit_remarks" value="Verified by Monitoring Office. Compliant with institutional guidelines.">
                                    <button type="submit" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded text-xs font-semibold shadow-sm">
                                        Verify & Pass to VP
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Returned / Disputed Reports Monitoring Tracker -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-amber-50 border-b border-amber-100 flex justify-between items-center">
            <h2 class="text-base font-bold text-amber-900">Returned Reports Tracker (Chairperson / Dean Disagreement)</h2>
            <span class="text-xs bg-amber-200 text-amber-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($returnedReports) ?> In Remediation</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Logs Count</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Disagreement Remarks</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Inspect</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($returnedReports)): ?>
                        <tr><td colspan="4" class="px-6 py-6 text-center text-gray-500">No reports currently in returned/remediation status.</td></tr>
                    <?php else: foreach ($returnedReports as $rr): ?>
                        <tr>
                            <td class="px-6 py-4 font-bold text-gray-900"><?= e($rr['last_name'] . ', ' . $rr['first_name']) ?></td>
                            <td class="px-6 py-4 font-semibold text-gray-700"><?= (int)$rr['total_logs'] ?></td>
                            <td class="px-6 py-4 text-xs text-red-600 font-medium"><?= e($rr['overall_remarks'] ?: 'Returned for correction') ?></td>
                            <td class="px-6 py-4 text-right">
                                <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$rr['teacher_id'] ?>" class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold underline">
                                    Review Logs & Evidence &rarr;
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
