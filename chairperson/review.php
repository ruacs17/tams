<?php
// Department Chairperson Faculty Reports Review & Return Protocol
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['chairperson']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);
$chairTeacherId = (int)$_SESSION['teacher_id'];

// Get Chairperson's Department
$stmtDept = $pdo->prepare("SELECT * FROM `departments` WHERE `chairperson_id` = ? LIMIT 1");
$stmtDept->execute([$chairTeacherId]);
$dept = $stmtDept->fetch();
$deptId = $dept ? (int)$dept['department_id'] : 0;

if ($deptId <= 0) {
    die("Error: No department is assigned to your account as Chairperson.");
}

// Handle Chairperson Actions: Approve OR Return / Disagree
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';
    $teacherId = (int)$_POST['teacher_id'];
    $year = $activeSettings['current_school_year'];
    $term = $activeSettings['current_school_term'];

    // Fetch Summary ID
    $stmtSum = $pdo->prepare("SELECT summary_id, overall_remarks FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
    $stmtSum->execute([$teacherId, $year, $term]);
    $summary = $stmtSum->fetch();
    $summaryId = $summary ? (int)$summary['summary_id'] : null;

    if ($action === 'approve_report') {
        $chairRemarks = trim($_POST['chair_remarks'] ?? 'Approved by Department Chairperson.');
        $newRemarks = ($summary['overall_remarks'] ?? '') . "\n[Chairperson Approval Note]: " . $chairRemarks;

        // Advance logs: submitted_teacher -> submitted_chairperson
        $stmtLogs = $pdo->prepare("
            UPDATE `attendance_logs`
            SET `workflow_status` = 'submitted_chairperson'
            WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'submitted_teacher'
        ");
        $stmtLogs->execute([$teacherId, $year, $term]);

        // Lock chairperson edit state
        $pdo->prepare("
            UPDATE `teacher_report_summaries`
            SET `is_locked_chairperson` = 1, `overall_remarks` = ?
            WHERE `summary_id` = ?
        ")->execute([$newRemarks, $summaryId]);

        logReportAuditTrail(
            $pdo, $summaryId, $teacherId, $year, $term,
            $chairTeacherId, 'chairperson', 'CHAIRPERSON_APPROVED',
            'submitted_teacher', 'submitted_chairperson', $newRemarks
        );

        logSystemAudit($pdo, $chairTeacherId, 'chairperson', "Approved attendance report for Teacher ID {$teacherId}");

        $_SESSION['flash_success'] = "Report approved and forwarded to the College Dean.";
        header("Location: /tams/chairperson/review.php");
        exit;

    } elseif ($action === 'return_report') {
        // Chairperson Disagreement & Return Protocol
        $disapprovalRemarks = trim($_POST['disapproval_remarks'] ?? '');
        if (empty($disapprovalRemarks)) {
            $_SESSION['flash_error'] = "Mandatory itemized disapproval remarks are required to return a report.";
            header("Location: /tams/chairperson/review.php");
            exit;
        }

        $newRemarks = ($summary['overall_remarks'] ?? '') . "\n[Chairperson Return / Disagreement]: " . $disapprovalRemarks;

        // Rollback logs: submitted_teacher -> returned_to_teacher
        $stmtLogs = $pdo->prepare("
            UPDATE `attendance_logs`
            SET `workflow_status` = 'returned_to_teacher'
            WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'submitted_teacher'
        ");
        $stmtLogs->execute([$teacherId, $year, $term]);

        // Unlock teacher editing permissions
        $pdo->prepare("
            UPDATE `teacher_report_summaries`
            SET `is_locked_teacher` = 0, `is_locked_chairperson` = 0, `overall_remarks` = ?
            WHERE `summary_id` = ?
        ")->execute([$newRemarks, $summaryId]);

        logReportAuditTrail(
            $pdo, $summaryId, $teacherId, $year, $term,
            $chairTeacherId, 'chairperson', 'CHAIRPERSON_RETURNED_DISAGREED',
            'submitted_teacher', 'returned_to_teacher', $disapprovalRemarks
        );

        logSystemAudit($pdo, $chairTeacherId, 'chairperson', "Returned attendance report for Teacher ID {$teacherId} with remarks: {$disapprovalRemarks}");

        $_SESSION['flash_success'] = "Report returned to Faculty Teacher for remediation and revision.";
        header("Location: /tams/chairperson/review.php");
        exit;
    }
}

// Fetch all department faculty reports for active cycle
$stmtReports = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name, td.status as emp_status,
           COUNT(al.log_id) as total_logs,
           SUM(CASE WHEN al.status = 'Late' THEN 1 ELSE 0 END) as lates,
           SUM(CASE WHEN al.status = 'Absent' THEN 1 ELSE 0 END) as absents,
           MAX(al.workflow_status) as current_status,
           trs.overall_remarks, trs.is_locked_chairperson
    FROM `teacher_department` td
    JOIN `teachers` t ON td.teacher_id = t.teacher_id
    LEFT JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id 
         AND al.school_year = td.school_year AND al.school_term = td.school_term
    LEFT JOIN `teacher_report_summaries` trs ON t.teacher_id = trs.teacher_id
         AND trs.school_year = td.school_year AND trs.school_term = td.school_term
    WHERE td.department_id = ? 
      AND td.school_year = ? AND td.school_term = ?
    GROUP BY t.teacher_id
    ORDER BY t.last_name ASC
");
$stmtReports->execute([$deptId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$reports = $stmtReports->fetchAll();

$pageTitle = "Chairperson - Review Reports";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Department Faculty Attendance Review</h1>
            <p class="text-sm text-gray-500">
                Department: <span class="font-bold text-amber-700"><?= e($dept['department_abbreviation']) ?></span> &bull; 
                Active Cycle: <span class="font-semibold text-gray-800">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <div class="text-xs text-gray-500 bg-white p-2.5 rounded border border-gray-200">
            <strong>Action Protocol:</strong> Approve &rarr; Sends to Dean &bull; Return &rarr; Loops back to Teacher
        </div>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Affiliated Faculty Attendance Summaries</h2>
            <span class="text-xs bg-amber-100 text-amber-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($reports) ?> Faculty</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Status</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Logs Count</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Lates / Absents</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Workflow Stage</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Review & Governance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($reports)): ?>
                        <tr><td colspan="6" class="px-6 py-6 text-center text-gray-500">No faculty members affiliated with this department for the active period.</td></tr>
                    <?php else: foreach ($reports as $r): 
                        $wf = $r['current_status'];
                        $isPendingChair = ($wf === 'submitted_teacher');
                    ?>
                        <tr>
                            <td class="px-6 py-4">
                                <div class="font-bold text-gray-900"><?= e($r['last_name'] . ', ' . $r['first_name']) ?></div>
                                <span class="text-xs text-gray-500">Employment: <?= ucfirst(e($r['emp_status'])) ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-700">
                                    <?= ucfirst(e($r['emp_status'])) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 font-semibold text-gray-800"><?= (int)$r['total_logs'] ?></td>
                            <td class="px-6 py-4">
                                <span class="text-xs font-mono font-bold text-yellow-700"><?= (int)$r['lates'] ?> L</span> &bull;
                                <span class="text-xs font-mono font-bold text-red-700"><?= (int)$r['absents'] ?> A</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2.5 py-0.5 text-xs rounded-full font-bold uppercase tracking-wider
                                    <?= $wf === 'submitted_teacher' ? 'bg-amber-100 text-amber-800 animate-pulse' : ($wf === 'submitted_chairperson' ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-600') ?>">
                                    <?= e(str_replace('_', ' ', $wf ?: 'None')) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2 whitespace-nowrap">
                                <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$r['teacher_id'] ?>" 
                                   class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold underline mr-2">
                                    Inspect Logs & Evidence &rarr;
                                </a>

                                <?php if ($isPendingChair): ?>
                                    <button onclick="openApproveModal(<?= (int)$r['teacher_id'] ?>, '<?= e($r['first_name'] . ' ' . $r['last_name']) ?>')"
                                            class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded text-xs font-semibold shadow-sm">
                                        Approve & Forward
                                    </button>
                                    <button onclick="openReturnModal(<?= (int)$r['teacher_id'] ?>, '<?= e($r['first_name'] . ' ' . $r['last_name']) ?>')"
                                            class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs font-semibold shadow-sm">
                                        Return / Disagree
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Approve Report -->
<div id="modalApproveReport" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-2">Chairperson Report Approval</h3>
        <p class="text-xs text-gray-500 mb-4" id="approveTeacherName"></p>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="approve_report">
            <input type="hidden" name="teacher_id" id="approveTeacherId">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Chairperson Endorsement Remarks</label>
                <textarea name="chair_remarks" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm" placeholder="e.g. Endorsed for Dean approval. All subject contact hours verified."></textarea>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalApproveReport')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-semibold">Confirm Approval & Forward to Dean</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Return / Disagree Report -->
<div id="modalReturnReport" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-lg font-bold text-red-900 mb-2">Return Report to Teacher (Disagreement)</h3>
        <p class="text-xs text-gray-500 mb-4" id="returnTeacherName"></p>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="return_report">
            <input type="hidden" name="teacher_id" id="returnTeacherId">

            <div>
                <label class="block text-xs font-semibold text-red-800 uppercase">Mandatory Itemized Disapproval Remarks</label>
                <textarea name="disapproval_remarks" required rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm" placeholder="Itemize required revisions, missing excuse documents, or disputed class hours..."></textarea>
            </div>

            <div class="p-3 bg-red-50 rounded text-xs text-red-700">
                <strong>Remediation Note:</strong> Returning this report immediately unlocks the Faculty Teacher's portal so they can upload additional evidence or adjust remarks.
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalReturnReport')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-semibold">Execute Return to Teacher</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(tId, name) {
    document.getElementById('approveTeacherId').value = tId;
    document.getElementById('approveTeacherName').innerText = 'Faculty: ' + name;
    toggleModal('modalApproveReport');
}

function openReturnModal(tId, name) {
    document.getElementById('returnTeacherId').value = tId;
    document.getElementById('returnTeacherName').innerText = 'Faculty: ' + name;
    toggleModal('modalReturnReport');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
