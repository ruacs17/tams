<?php
// Teacher Dispute Remarks & Supporting Evidence Upload Interface
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';
require_once __DIR__ . '/../includes/upload_service.php';

requireAuth(['teacher']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);
$teacherId = (int)$_SESSION['teacher_id'];

// Check if teacher is locked for the current term
$stmtSum = $pdo->prepare("SELECT is_locked_teacher, summary_id FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
$stmtSum->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$sumData = $stmtSum->fetch();

if ($sumData && !empty($sumData['is_locked_teacher'])) {
    $_SESSION['flash_error'] = "Your report for this academic cycle has already been submitted to the Chairperson. Edit permissions are locked.";
    header("Location: /tams/teacher/index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $logId = (int)($_POST['log_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    // Validate log belongs to this teacher in the active cycle
    $stmtLog = $pdo->prepare("SELECT * FROM `attendance_logs` WHERE `log_id` = ? AND `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
    $stmtLog->execute([$logId, $teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
    $log = $stmtLog->fetch();

    if (!$log) {
        $_SESSION['flash_error'] = 'Invalid attendance record selected.';
    } elseif (!isset($_FILES['evidence_file']) || $_FILES['evidence_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = 'Please select a valid supporting document (JPG, PNG, or PDF).';
    } else {
        // Execute hardened upload service
        $uploadResult = handleEvidenceUpload($_FILES['evidence_file']);

        if (!$uploadResult['success']) {
            $_SESSION['flash_error'] = 'Upload Rejected: ' . $uploadResult['error'];
        } else {
            $filePath = $uploadResult['filePath'];

            // Insert into attendance_evidence
            $stmtEv = $pdo->prepare("
                INSERT INTO `attendance_evidence` (`log_id`, `teacher_id`, `file_path`, `remarks`, `uploaded_at`)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmtEv->execute([$logId, $teacherId, $filePath, $remarks]);

            // Update log's schedule_remarks if provided
            if (!empty($remarks)) {
                $existingRemarks = $log['schedule_remarks'] ? ($log['schedule_remarks'] . ' | ') : '';
                $updatedRemarks = $existingRemarks . "Teacher Dispute Note: " . $remarks;
                $pdo->prepare("UPDATE `attendance_logs` SET `schedule_remarks` = ? WHERE `log_id` = ?")->execute([$updatedRemarks, $logId]);
            }

            $summaryId = $sumData ? (int)$sumData['summary_id'] : null;

            // Immutable Report-Level Audit Trail Entry
            logReportAuditTrail(
                $pdo, $summaryId, $teacherId,
                $activeSettings['current_school_year'], $activeSettings['current_school_term'],
                $teacherId, 'teacher', 'UPLOADED_EVIDENCE_DISPUTE',
                $log['workflow_status'], $log['workflow_status'],
                "Evidence file: {$uploadResult['fileName']} uploaded for Log ID {$logId}. Remarks: {$remarks}"
            );

            logSystemAudit($pdo, $teacherId, 'teacher', "Uploaded attendance dispute evidence for Log ID {$logId}");

            $_SESSION['flash_success'] = "Supporting evidence uploaded and securely sanitized. Your remarks have been attached to the attendance record.";
            header("Location: /tams/teacher/my_attendance.php");
            exit;
        }
    }
}

// Fetch logs for current cycle that teacher can dispute (e.g. Late, Absent, or during suspension)
$stmtLogs = $pdo->prepare("
    SELECT al.*, tl.offer_code, tl.subject_name
    FROM `attendance_logs` al
    LEFT JOIN `teacher_loads` tl ON al.load_id = tl.load_id
    WHERE al.teacher_id = ? AND al.school_year = ? AND al.school_term = ?
    ORDER BY al.date DESC
");
$stmtLogs->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$logs = $stmtLogs->fetchAll();

$pageTitle = "Upload Supporting Evidence";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Upload Dispute Evidence & File Remarks</h1>
        <p class="text-sm text-gray-500">
            Submit medical certificates, official travel orders, or biometric mismatch explanations for administrative review.
        </p>
    </div>

    <!-- Security Warning Banner -->
    <div class="bg-indigo-50 border-l-4 border-indigo-600 p-4 rounded-md shadow-sm text-xs text-indigo-900 space-y-1">
        <div class="font-bold">Anti-Malware & File Integrity Verification:</div>
        <div>
            All uploads undergo strict MIME verification, cryptographic renaming, and GD image payload neutralization.
            Accepted formats: <code>.jpg</code>, <code>.png</code>, and <code>.pdf</code> (Max 10MB).
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-4">
        <?= csrfField() ?>

        <div>
            <label class="block text-xs font-semibold text-gray-700 uppercase">Target Attendance Log Entry</label>
            <select name="log_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                <option value="">-- Select Specific Attendance Record --</option>
                <?php foreach ($logs as $l): ?>
                    <option value="<?= (int)$l['log_id'] ?>">
                        <?= e($l['date']) ?> &bull; <?= e($l['offer_code']) ?> &bull; Status: <?= e($l['status']) ?> 
                        <?= $l['late_minutes'] > 0 ? "(+{$l['late_minutes']}m late)" : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 uppercase">Select File (JPG, PNG, or PDF)</label>
            <input type="file" name="evidence_file" required accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                   class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 uppercase">Explanation / Justification Remarks</label>
            <textarea name="remarks" rows="3" required placeholder="Detail the reason for discrepancy (e.g. Approved official travel memorandum, field research, medical consultation)..."
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm"></textarea>
        </div>

        <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
            <a href="/tams/teacher/my_attendance.php" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold shadow-sm">
                Upload & Attach Evidence
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
