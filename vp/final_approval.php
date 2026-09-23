<?php
// Vice President for Academics Final Approval Interface
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['vp']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);
$vpTeacherId = (int)$_SESSION['teacher_id'];

// Handle Final Approval
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $teacherId = (int)$_POST['teacher_id'];
    $year = $activeSettings['current_school_year'];
    $term = $activeSettings['current_school_term'];
    $vpRemarks = trim($_POST['vp_remarks'] ?? 'Final approval granted by Vice President for Academics.');

    // Fetch Summary
    $stmtSum = $pdo->prepare("SELECT summary_id, overall_remarks FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
    $stmtSum->execute([$teacherId, $year, $term]);
    $summary = $stmtSum->fetch();
    $summaryId = $summary ? (int)$summary['summary_id'] : null;

    $newRemarks = ($summary['overall_remarks'] ?? '') . "\n[VP for Academics Final Approval]: " . $vpRemarks;

    // Advance logs: verified_monitoring -> final_approved_vp
    $stmtLogs = $pdo->prepare("
        UPDATE `attendance_logs`
        SET `workflow_status` = 'final_approved_vp'
        WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'verified_monitoring'
    ");
    $stmtLogs->execute([$teacherId, $year, $term]);

    // Update overall remarks
    if ($summaryId) {
        $pdo->prepare("UPDATE `teacher_report_summaries` SET `overall_remarks` = ? WHERE `summary_id` = ?")->execute([$newRemarks, $summaryId]);
    }

    logReportAuditTrail(
        $pdo, $summaryId, $teacherId, $year, $term,
        $vpTeacherId, 'vp', 'VP_FINAL_APPROVED',
        'verified_monitoring', 'final_approved_vp', $newRemarks
    );

    logSystemAudit($pdo, $vpTeacherId, 'vp', "VP granted final institutional approval for Teacher ID {$teacherId}");

    $_SESSION['flash_success'] = "Report officially granted final institutional approval by the VP for Academics.";
    header("Location: /tams/vp/final_approval.php");
    exit;
}

// Fetch reports ready for VP final approval (status = 'verified_monitoring')
$stmtReady = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name,
           COUNT(al.log_id) as total_logs,
           SUM(CASE WHEN al.status = 'Late' THEN 1 ELSE 0 END) as lates,
           SUM(CASE WHEN al.status = 'Absent' THEN 1 ELSE 0 END) as absents,
           trs.overall_remarks
    FROM `teachers` t
    JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id
    LEFT JOIN `teacher_report_summaries` trs ON t.teacher_id = trs.teacher_id 
         AND trs.school_year = ? AND trs.school_term = ?
    WHERE al.school_year = ? AND al.school_term = ? AND al.workflow_status = 'verified_monitoring'
    GROUP BY t.teacher_id
    ORDER BY t.last_name ASC
");
$stmtReady->execute([
    $activeSettings['current_school_year'], $activeSettings['current_school_term'],
    $activeSettings['current_school_year'], $activeSettings['current_school_term']
]);
$readyReports = $stmtReady->fetchAll();

$pageTitle = "VP Final Approval";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">VP Final Institutional Approval</h1>
            <p class="text-sm text-gray-500">
                Grant final executive sign-off for compliance-verified faculty attendance reports.
            </p>
        </div>
        <a href="/tams/vp/revert.php" class="text-xs px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded font-medium shadow-sm">
            Exclusive Revert Decision &rarr;
        </a>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Verified Reports Awaiting Final Approval</h2>
            <span class="text-xs bg-red-100 text-red-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($readyReports) ?> Reports</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Total Logs</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Lates / Absents</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Audit Trail Remarks</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($readyReports)): ?>
                        <tr><td colspan="5" class="px-6 py-6 text-center text-gray-500">No verified reports currently awaiting final VP approval.</td></tr>
                    <?php else: foreach ($readyReports as $rr): ?>
                        <tr>
                            <td class="px-6 py-4 font-bold text-gray-900"><?= e($rr['last_name'] . ', ' . $rr['first_name']) ?></td>
                            <td class="px-6 py-4 font-semibold text-gray-800"><?= (int)$rr['total_logs'] ?></td>
                            <td class="px-6 py-4">
                                <span class="text-xs font-mono font-bold text-yellow-700"><?= (int)$rr['lates'] ?> Lates</span> &bull;
                                <span class="text-xs font-mono font-bold text-red-700"><?= (int)$rr['absents'] ?> Absents</span>
                            </td>
                            <td class="px-6 py-4 text-xs text-gray-600 max-w-xs truncate" title="<?= e($rr['overall_remarks']) ?>">
                                <?= e($rr['overall_remarks'] ?: 'None') ?>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                                <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$rr['teacher_id'] ?>" 
                                   class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold underline mr-2">
                                    Audit Logs &rarr;
                                </a>
                                <button onclick="openFinalApprovalModal(<?= (int)$rr['teacher_id'] ?>, '<?= e($rr['first_name'] . ' ' . $rr['last_name']) ?>')"
                                        class="px-4 py-1.5 bg-red-700 hover:bg-red-800 text-white rounded text-xs font-bold shadow-sm">
                                    Grant Final Approval
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Final Approval -->
<div id="modalFinalApproval" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-2">VP Final Institutional Approval</h3>
        <p class="text-xs text-gray-500 mb-4" id="finalTeacherName"></p>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="teacher_id" id="finalTeacherId">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Executive Approval Comments</label>
                <textarea name="vp_remarks" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm" placeholder="e.g. Official academic compliance validated. Approved for institutional payroll processing."></textarea>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalFinalApproval')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white rounded-md text-sm font-bold shadow-sm">Confirm Final Approval</button>
            </div>
        </form>
    </div>
</div>

<script>
function openFinalApprovalModal(tId, name) {
    document.getElementById('finalTeacherId').value = tId;
    document.getElementById('finalTeacherName').innerText = 'Faculty: ' + name;
    toggleModal('modalFinalApproval');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
