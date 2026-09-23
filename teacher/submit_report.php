<?php
// Teacher Report Submission & Acknowledgment to Chairperson
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['teacher']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);
$teacherId = (int)$_SESSION['teacher_id'];

// Fetch Summary
$stmtSum = $pdo->prepare("SELECT * FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
$stmtSum->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$summary = $stmtSum->fetch();

if ($summary && !empty($summary['is_locked_teacher'])) {
    $_SESSION['flash_error'] = "Your attendance report has already been acknowledged and submitted to the Department Chairperson.";
    header("Location: /tams/teacher/index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $teacherRemarks = trim($_POST['teacher_remarks'] ?? '');

    // Existing overall remarks + teacher acknowledgment note
    $overallRemarks = $summary ? $summary['overall_remarks'] : '';
    if (!empty($teacherRemarks)) {
        $overallRemarks .= "\n[Teacher Submission Remarks]: " . $teacherRemarks;
    }

    // Update teacher_report_summaries: set is_locked_teacher = 1
    $stmtUpd = $pdo->prepare("
        INSERT INTO `teacher_report_summaries`
        (`teacher_id`, `school_year`, `school_term`, `overall_remarks`, `is_locked_teacher`)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            `overall_remarks` = VALUES(`overall_remarks`),
            `is_locked_teacher` = 1
    ");
    $stmtUpd->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term'], $overallRemarks]);

    // Get summary ID
    $stmtId = $pdo->prepare("SELECT summary_id FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
    $stmtId->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
    $summaryId = (int)$stmtId->fetchColumn();

    // Advance logs: confirmed_monitoring (or returned_to_teacher) -> submitted_teacher
    $stmtLogs = $pdo->prepare("
        UPDATE `attendance_logs`
        SET `workflow_status` = 'submitted_teacher'
        WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?
          AND `workflow_status` IN ('confirmed_monitoring', 'returned_to_teacher')
    ");
    $stmtLogs->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);

    // Report-level audit trail
    logReportAuditTrail(
        $pdo, $summaryId, $teacherId,
        $activeSettings['current_school_year'], $activeSettings['current_school_term'],
        $teacherId, 'teacher', 'TEACHER_ACKNOWLEDGED_AND_SUBMITTED',
        'confirmed_monitoring', 'submitted_teacher',
        $overallRemarks
    );

    logSystemAudit($pdo, $teacherId, 'teacher', "Acknowledged and submitted attendance report to Department Chairperson");

    $_SESSION['flash_success'] = "Attendance report successfully acknowledged and forwarded to your Department Chairperson for review.";
    header("Location: /tams/teacher/index.php");
    exit;
}

// Fetch totals for review
$stmtTotals = $pdo->prepare("
    SELECT COUNT(*) as total_logs,
           SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as lates,
           SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absents
    FROM `attendance_logs`
    WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?
");
$stmtTotals->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$totals = $stmtTotals->fetch();

$pageTitle = "Acknowledge & Submit Report";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-2xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Acknowledge & Submit Attendance Report</h1>
        <p class="text-sm text-gray-500">
            Confirm that you have reviewed your logged hours, partial suspension exemptions, and submitted any necessary evidence.
        </p>
    </div>

    <form method="POST" class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-6">
        <?= csrfField() ?>

        <div class="p-4 bg-gray-50 rounded-lg border border-gray-200 space-y-2">
            <h3 class="text-sm font-bold text-gray-800">Report Summary Overview</h3>
            <div class="grid grid-cols-3 gap-2 text-center pt-2">
                <div class="bg-white p-2 rounded border border-gray-100">
                    <div class="text-xs text-gray-500">Total Logs</div>
                    <div class="text-lg font-extrabold text-gray-900"><?= (int)$totals['total_logs'] ?></div>
                </div>
                <div class="bg-white p-2 rounded border border-gray-100">
                    <div class="text-xs text-gray-500">Total Lates</div>
                    <div class="text-lg font-extrabold text-yellow-600"><?= (int)$totals['lates'] ?></div>
                </div>
                <div class="bg-white p-2 rounded border border-gray-100">
                    <div class="text-xs text-gray-500">Unexcused Absents</div>
                    <div class="text-lg font-extrabold text-red-600"><?= (int)$totals['absents'] ?></div>
                </div>
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 uppercase">Optional Teacher Remarks / Acknowledgment Notes</label>
            <textarea name="teacher_remarks" rows="3" placeholder="Add any closing notes or acknowledgments for the Department Chairperson..."
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm"></textarea>
        </div>

        <div class="bg-yellow-50 border-l-4 border-yellow-500 p-3 rounded text-xs text-yellow-800">
            <strong>Lockout Notice:</strong> Upon submitting, your edit permissions for this academic period will be locked and routed to the Department Chairperson.
        </div>

        <div class="flex justify-end space-x-2">
            <a href="/tams/teacher/index.php" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-semibold shadow-sm">
                Confirm & Submit to Chairperson &rarr;
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
