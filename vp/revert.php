<?php
// Exclusive VP Reversion Mechanism
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['vp']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);
$vpTeacherId = (int)$_SESSION['teacher_id'];

// Handle Revert Decision
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $teacherId = (int)$_POST['teacher_id'];
    $year = $_POST['school_year'];
    $term = $_POST['school_term'];
    $revertReason = trim($_POST['revert_reason'] ?? '');
    $targetState = $_POST['target_state'] ?? 'returned_to_teacher';

    if (empty($revertReason)) {
        $_SESSION['flash_error'] = "Mandatory executive justification is required to revert a decision.";
        header("Location: /tams/vp/revert.php");
        exit;
    }

    // Fetch existing summary
    $stmtSum = $pdo->prepare("SELECT summary_id, overall_remarks FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
    $stmtSum->execute([$teacherId, $year, $term]);
    $summary = $stmtSum->fetch();
    $summaryId = $summary ? (int)$summary['summary_id'] : null;

    $updatedRemarks = ($summary['overall_remarks'] ?? '') . "\n[VP Exclusive Reversion]: " . $revertReason;

    // Get previous status
    $stmtPrev = $pdo->prepare("SELECT MAX(workflow_status) FROM `attendance_logs` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
    $stmtPrev->execute([$teacherId, $year, $term]);
    $prevStatus = $stmtPrev->fetchColumn() ?: 'unknown';

    // Update attendance_logs to targetState
    $stmtUpd = $pdo->prepare("
        UPDATE `attendance_logs`
        SET `workflow_status` = ?
        WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?
    ");
    $stmtUpd->execute([$targetState, $teacherId, $year, $term]);

    // Unlock flags in teacher_report_summaries based on targetState
    if ($targetState === 'returned_to_teacher') {
        $pdo->prepare("
            UPDATE `teacher_report_summaries`
            SET `is_locked_teacher` = 0, `is_locked_chairperson` = 0, `is_locked_dean` = 0, `overall_remarks` = ?
            WHERE `summary_id` = ?
        ")->execute([$updatedRemarks, $summaryId]);
    } elseif ($targetState === 'submitted_operator') {
        $pdo->prepare("
            UPDATE `teacher_report_summaries`
            SET `is_locked_monitoring` = 0, `is_locked_teacher` = 0, `is_locked_chairperson` = 0, `is_locked_dean` = 0, `overall_remarks` = ?
            WHERE `summary_id` = ?
        ")->execute([$updatedRemarks, $summaryId]);
    } else {
        $pdo->prepare("
            UPDATE `teacher_report_summaries`
            SET `overall_remarks` = ?
            WHERE `summary_id` = ?
        ")->execute([$updatedRemarks, $summaryId]);
    }

    logReportAuditTrail(
        $pdo, $summaryId, $teacherId, $year, $term,
        $vpTeacherId, 'vp', 'VP_REVERT_DECISION_TRIGGERED',
        $prevStatus, $targetState, $revertReason
    );

    logSystemAudit($pdo, $vpTeacherId, 'vp', "VP executed Revert Decision on Teacher ID {$teacherId} (From {$prevStatus} to {$targetState})");

    $_SESSION['flash_success'] = "Decision successfully reverted. Report unlocked and sent down the audit chain to '{$targetState}'.";
    header("Location: /tams/vp/revert.php");
    exit;
}

// Fetch all reports in the system for active or recent periods
$stmtAll = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name,
           COUNT(al.log_id) as total_logs,
           MAX(al.workflow_status) as current_status,
           al.school_year, al.school_term,
           trs.overall_remarks
    FROM `teachers` t
    JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id
    LEFT JOIN `teacher_report_summaries` trs ON t.teacher_id = trs.teacher_id 
         AND trs.school_year = al.school_year AND trs.school_term = al.school_term
    GROUP BY t.teacher_id, al.school_year, al.school_term
    ORDER BY al.school_year DESC, al.school_term DESC, t.last_name ASC
");
$stmtAll->execute();
$allReports = $stmtAll->fetchAll();

$pageTitle = "VP Exclusive Revert Decision";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Executive Revert Decision Protocol</h1>
            <p class="text-sm text-gray-500">
                Exclusive Vice President for Academics authority to unlock finalized reports and route them backward for remediation.
            </p>
        </div>
        <a href="/tams/vp/final_approval.php" class="text-xs px-3 py-1.5 bg-gray-800 text-white rounded font-medium hover:bg-gray-700">
            &larr; Return to Final Approvals
        </a>
    </div>

    <!-- Alert -->
    <div class="bg-amber-50 border-l-4 border-amber-600 p-4 rounded-md shadow-sm text-xs text-amber-900">
        <span class="font-bold">Executive Authority Warning:</span>
        Executing a revert decision alters immutable report states, clears administrative lockouts, and sends notification trails to the Teacher, Chairperson, Dean, and Monitoring Office.
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Faculty Reports Available for Reversion</h2>
            <span class="text-xs bg-gray-200 text-gray-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($allReports) ?> Reports</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Member</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Academic Period</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Total Logs</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Current Status</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Executive Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($allReports)): ?>
                        <tr><td colspan="5" class="px-6 py-6 text-center text-gray-500">No attendance reports found in the system.</td></tr>
                    <?php else: foreach ($allReports as $rep): ?>
                        <tr>
                            <td class="px-6 py-4 font-bold text-gray-900"><?= e($rep['last_name'] . ', ' . $rep['first_name']) ?></td>
                            <td class="px-6 py-4 font-mono text-xs text-gray-600">S.Y. <?= e($rep['school_year']) ?> (<?= e($rep['school_term']) ?>)</td>
                            <td class="px-6 py-4 font-semibold text-gray-800"><?= (int)$rep['total_logs'] ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2.5 py-0.5 text-xs rounded-full font-bold uppercase tracking-wider
                                    <?= $rep['current_status'] === 'final_approved_vp' ? 'bg-green-100 text-green-800' : 'bg-indigo-100 text-indigo-800' ?>">
                                    <?= e(str_replace('_', ' ', $rep['current_status'])) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$rep['teacher_id'] ?>&year=<?= urlencode($rep['school_year']) ?>&term=<?= urlencode($rep['school_term']) ?>"
                                   class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold underline mr-2">
                                    Audit &rarr;
                                </a>
                                <button onclick="openRevertModal(<?= (int)$rep['teacher_id'] ?>, '<?= e($rep['first_name'] . ' ' . $rep['last_name']) ?>', '<?= e($rep['school_year']) ?>', '<?= e($rep['school_term']) ?>')"
                                        class="px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded text-xs font-bold shadow-sm">
                                    Revert Decision
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Revert Decision -->
<div id="modalRevertDecision" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-lg font-bold text-red-900 mb-2">Execute VP Revert Decision</h3>
        <p class="text-xs text-gray-500 mb-4" id="revertTeacherInfo"></p>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="teacher_id" id="revertTeacherId">
            <input type="hidden" name="school_year" id="revertYear">
            <input type="hidden" name="school_term" id="revertTerm">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Target Reversion Workflow State</label>
                <select name="target_state" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="returned_to_teacher">Rollback to Teacher Portal (returned_to_teacher)</option>
                    <option value="submitted_operator">Rollback to Monitoring Review (submitted_operator)</option>
                    <option value="confirmed_monitoring">Rollback to Teacher Review (confirmed_monitoring)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-red-800 uppercase">Executive Justification / Reversal Notes</label>
                <textarea name="revert_reason" required rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm" placeholder="Specify mandatory audit findings or policy justification for rolling back this report..."></textarea>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalRevertDecision')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white rounded-md text-sm font-bold shadow-sm">Confirm Revert Decision</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRevertModal(tId, name, year, term) {
    document.getElementById('revertTeacherId').value = tId;
    document.getElementById('revertYear').value = year;
    document.getElementById('revertTerm').value = term;
    document.getElementById('revertTeacherInfo').innerText = 'Faculty: ' + name + ' | S.Y. ' + year + ' (' + term + ')';
    toggleModal('modalRevertDecision');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
