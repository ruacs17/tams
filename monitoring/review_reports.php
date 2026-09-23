<?php
// Monitoring Office Head Report Review & "Unlock & Revise" Correction Protocol
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';
require_once __DIR__ . '/../includes/attendance_engine.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

// Handle Workflow Transitions: Confirm to Teacher OR Unlock & Revise
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'confirm_to_teacher') {
        $teacherId = (int)$_POST['teacher_id'];
        $year = $_POST['school_year'];
        $term = $_POST['school_term'];
        $remarks = trim($_POST['overall_remarks'] ?? '');

        // Update summaries table
        $stmtSum = $pdo->prepare("
            INSERT INTO `teacher_report_summaries`
            (`teacher_id`, `school_year`, `school_term`, `overall_remarks`, `is_locked_operator`, `is_locked_monitoring`)
            VALUES (?, ?, ?, ?, 1, 1)
            ON DUPLICATE KEY UPDATE
                `overall_remarks` = VALUES(`overall_remarks`),
                `is_locked_operator` = 1,
                `is_locked_monitoring` = 1
        ");
        $stmtSum->execute([$teacherId, $year, $term, $remarks]);

        // Get summary ID
        $stmtId = $pdo->prepare("SELECT summary_id FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
        $stmtId->execute([$teacherId, $year, $term]);
        $summaryId = (int)$stmtId->fetchColumn();

        // Transition logs from submitted_operator -> confirmed_monitoring
        $stmtLogs = $pdo->prepare("
            UPDATE `attendance_logs`
            SET `workflow_status` = 'confirmed_monitoring'
            WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'submitted_operator'
        ");
        $stmtLogs->execute([$teacherId, $year, $term]);

        // Log into report_audit_trails
        logReportAuditTrail(
            $pdo, $summaryId, $teacherId, $year, $term,
            (int)$_SESSION['user_id'], 'monitoring_head', 'CONFIRM_AND_ROUTE_TO_TEACHER',
            'submitted_operator', 'confirmed_monitoring', $remarks
        );

        $_SESSION['flash_success'] = "Attendance report confirmed and released to Faculty Teacher for review.";
        header("Location: /tams/monitoring/review_reports.php");
        exit;

    } elseif ($action === 'unlock_and_revise') {
        // Monitoring Head Post-Submission Correction Protocol
        $teacherId = (int)$_POST['teacher_id'];
        $year = $_POST['school_year'];
        $term = $_POST['school_term'];
        $unlockReason = trim($_POST['unlock_reason'] ?? 'Monitoring Head requested schedule/log revisions');

        // Check if downstream roles (Chairperson, Dean, VP) have already locked or finalized
        $stmtCheck = $pdo->prepare("
            SELECT is_locked_chairperson, is_locked_dean, summary_id 
            FROM `teacher_report_summaries` 
            WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?
        ");
        $stmtCheck->execute([$teacherId, $year, $term]);
        $sumInfo = $stmtCheck->fetch();

        if ($sumInfo && ($sumInfo['is_locked_chairperson'] || $sumInfo['is_locked_dean'])) {
            $_SESSION['flash_error'] = "Cannot unlock: Downstream administrative reviews (Chairperson / Dean) have already begun or locked this record.";
        } else {
            // Safely rollback workflow_status from confirmed_monitoring to submitted_operator / draft
            $stmtRollback = $pdo->prepare("
                UPDATE `attendance_logs`
                SET `workflow_status` = 'submitted_operator'
                WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `workflow_status` IN ('confirmed_monitoring', 'submitted_teacher')
            ");
            $stmtRollback->execute([$teacherId, $year, $term]);

            // Release is_locked_monitoring
            $pdo->prepare("
                UPDATE `teacher_report_summaries`
                SET `is_locked_monitoring` = 0, `is_locked_teacher` = 0
                WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?
            ")->execute([$teacherId, $year, $term]);

            $summaryId = $sumInfo ? (int)$sumInfo['summary_id'] : null;

            logReportAuditTrail(
                $pdo, $summaryId, $teacherId, $year, $term,
                (int)$_SESSION['user_id'], 'monitoring_head', 'UNLOCK_AND_REVISE_TRIGGERED',
                'confirmed_monitoring', 'submitted_operator', "Unlock Reason: " . $unlockReason
            );

            logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Triggered Unlock & Revise on Teacher ID {$teacherId} for S.Y. {$year} {$term}");

            $_SESSION['flash_success'] = "Report unlocked successfully. Working state reverted to allow corrections.";
        }

        header("Location: /tams/monitoring/review_reports.php");
        exit;
    }
}

// Fetch list of teachers with submitted logs in the active period
$stmtReports = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name,
           COUNT(al.log_id) as total_logs,
           SUM(CASE WHEN al.status = 'Late' THEN 1 ELSE 0 END) as late_count,
           SUM(CASE WHEN al.status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
           MAX(al.workflow_status) as current_status,
           trs.overall_remarks, trs.is_locked_monitoring, trs.is_locked_chairperson, trs.is_locked_dean
    FROM `teachers` t
    JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id
    LEFT JOIN `teacher_report_summaries` trs ON t.teacher_id = trs.teacher_id 
         AND trs.school_year = ? AND trs.school_term = ?
    WHERE al.school_year = ? AND al.school_term = ?
    GROUP BY t.teacher_id
    ORDER BY t.last_name ASC
");
$stmtReports->execute([
    $activeSettings['current_school_year'], $activeSettings['current_school_term'],
    $activeSettings['current_school_year'], $activeSettings['current_school_term']
]);
$reports = $stmtReports->fetchAll();

$pageTitle = "Monitoring Review & Unlock";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Monitoring Office Report Confirmation & Correction Protocol</h1>
        <p class="text-sm text-gray-500">
            Review operator-submitted attendance logs, release confirmed reports to Faculty Portals, or execute <strong>Unlock & Revise</strong> rollback.
        </p>
    </div>

    <!-- Active Cycle Alert -->
    <div class="bg-purple-50 border-l-4 border-purple-600 p-4 rounded-md shadow-sm flex items-center justify-between">
        <div class="text-sm text-purple-900">
            <span class="font-bold">Current Cycle:</span> S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)
        </div>
        <span class="text-xs text-purple-700 bg-white px-3 py-1 rounded shadow-sm font-medium">Downstream Review Safeguard Active</span>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Member</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Total Logs</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Lates / Absents</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Current Workflow State</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Overall Remarks Snapshot</th>
                    <th class="px-6 py-3 text-right font-semibold text-gray-600">Actions & Controls</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                <?php if (empty($reports)): ?>
                    <tr><td colspan="6" class="px-6 py-6 text-center text-gray-500">No attendance reports submitted for review.</td></tr>
                <?php else: foreach ($reports as $r): 
                    $status = $r['current_status'];
                    $canConfirm = ($status === 'submitted_operator');
                    $canUnlock = ($status === 'confirmed_monitoring' || $status === 'submitted_teacher') && empty($r['is_locked_chairperson']) && empty($r['is_locked_dean']);
                ?>
                    <tr>
                        <td class="px-6 py-4">
                            <div class="font-bold text-gray-900"><?= e($r['last_name'] . ', ' . $r['first_name']) ?></div>
                            <div class="text-xs text-gray-400">ID: <?= (int)$r['teacher_id'] ?></div>
                        </td>
                        <td class="px-6 py-4 font-semibold text-gray-800"><?= (int)$r['total_logs'] ?></td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-mono px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 font-bold"><?= (int)$r['late_count'] ?> Lates</span>
                            <span class="text-xs font-mono px-2 py-0.5 rounded bg-red-100 text-red-800 font-bold ml-1"><?= (int)$r['absent_count'] ?> Absents</span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2.5 py-0.5 text-xs rounded-full font-bold uppercase tracking-wider
                                <?= $status === 'submitted_operator' ? 'bg-yellow-100 text-yellow-800' : ($status === 'confirmed_monitoring' ? 'bg-purple-100 text-purple-800' : 'bg-indigo-100 text-indigo-800') ?>">
                                <?= e(str_replace('_', ' ', $status)) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs text-gray-600 max-w-xs truncate">
                            <?= e($r['overall_remarks'] ?: 'None') ?>
                        </td>
                        <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                            <?php if ($canConfirm): ?>
                                <button onclick="openConfirmModal(<?= (int)$r['teacher_id'] ?>, '<?= e($r['first_name'] . ' ' . $r['last_name']) ?>', '<?= e($r['overall_remarks'] ?? '') ?>')" 
                                        class="px-3 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-medium shadow-sm">
                                    Confirm to Teacher
                                </button>
                            <?php endif; ?>

                            <?php if ($canUnlock): ?>
                                <button onclick="openUnlockModal(<?= (int)$r['teacher_id'] ?>, '<?= e($r['first_name'] . ' ' . $r['last_name']) ?>')" 
                                        class="px-3 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded text-xs font-medium shadow-sm">
                                    Unlock & Revise
                                </button>
                            <?php endif; ?>

                            <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$r['teacher_id'] ?>" 
                               class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold underline">
                                View Logs &rarr;
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Confirm to Teacher -->
<div id="modalConfirmTeacher" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-2">Confirm Report to Faculty Teacher</h3>
        <p class="text-xs text-gray-500 mb-4" id="confirmTeacherName"></p>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="confirm_to_teacher">
            <input type="hidden" name="teacher_id" id="confirmTeacherId">
            <input type="hidden" name="school_year" value="<?= e($activeSettings['current_school_year']) ?>">
            <input type="hidden" name="school_term" value="<?= e($activeSettings['current_school_term']) ?>">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Monitoring Office Remarks</label>
                <textarea name="overall_remarks" id="confirmRemarks" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm" placeholder="Add verification remarks before passing to teacher..."></textarea>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalConfirmTeacher')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium">Confirm & Send to Teacher</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Unlock & Revise -->
<div id="modalUnlockRevise" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-lg font-bold text-amber-900 mb-2">Execute "Unlock & Revise" Protocol</h3>
        <p class="text-xs text-gray-500 mb-4" id="unlockTeacherName"></p>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="unlock_and_revise">
            <input type="hidden" name="teacher_id" id="unlockTeacherId">
            <input type="hidden" name="school_year" value="<?= e($activeSettings['current_school_year']) ?>">
            <input type="hidden" name="school_term" value="<?= e($activeSettings['current_school_term']) ?>">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Justification / Correction Reason</label>
                <textarea name="unlock_reason" required rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm" placeholder="Explain schedule changes (e.g. replaced OPEN placeholder, retroactive window adjustment)..."></textarea>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalUnlockRevise')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-sm font-medium">Unlock & Rollback to Working State</button>
            </div>
        </form>
    </div>
</div>

<script>
function openConfirmModal(teacherId, teacherName, currentRemarks) {
    document.getElementById('confirmTeacherId').value = teacherId;
    document.getElementById('confirmTeacherName').innerText = 'Faculty: ' + teacherName;
    document.getElementById('confirmRemarks').value = currentRemarks || '';
    toggleModal('modalConfirmTeacher');
}

function openUnlockModal(teacherId, teacherName) {
    document.getElementById('unlockTeacherId').value = teacherId;
    document.getElementById('unlockTeacherName').innerText = 'Faculty: ' + teacherName;
    toggleModal('modalUnlockRevise');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
