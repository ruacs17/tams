<?php
// Faculty Teacher Portal Dashboard
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/attendance_engine.php';

// Permitted for teachers, or administrators inspecting teacher dashboards
requireAuth(['teacher', 'monitoring_head', 'chairperson', 'dean', 'vp']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

// Determine target teacher
$teacherId = (int)($_SESSION['teacher_id'] ?? 0);
if (isset($_GET['view_teacher_id']) && in_array($_SESSION['role'], ['monitoring_head', 'chairperson', 'dean', 'vp'], true)) {
    $teacherId = (int)$_GET['view_teacher_id'];
}

if ($teacherId <= 0) {
    http_response_code(403);
    die("No faculty profile associated with your session.");
}

// Fetch Teacher Info
$stmtT = $pdo->prepare("SELECT * FROM `teachers` WHERE `teacher_id` = ?");
$stmtT->execute([$teacherId]);
$teacher = $stmtT->fetch();

// Fetch Penalty Information for active period
$stmtPen = $pdo->prepare("SELECT * FROM `attendance_penalties` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
$stmtPen->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$penalties = $stmtPen->fetch() ?: ['accumulated_lates_count' => 0, 'converted_absents_count' => 0];

// Fetch Report Summary
$stmtSum = $pdo->prepare("SELECT * FROM `teacher_report_summaries` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?");
$stmtSum->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$summary = $stmtSum->fetch();

// Fetch Logs Breakdown
$stmtLogs = $pdo->prepare("
    SELECT status, workflow_status, COUNT(*) as count 
    FROM `attendance_logs` 
    WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ?
    GROUP BY status, workflow_status
");
$stmtLogs->execute([$teacherId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$statusBreakdown = [];
$latestWorkflow = 'draft';
while ($row = $stmtLogs->fetch()) {
    $statusBreakdown[$row['status']] = ($statusBreakdown[$row['status']] ?? 0) + $row['count'];
    $latestWorkflow = $row['workflow_status'];
}

// Check if teacher is locked
$isTeacherLocked = !empty($summary['is_locked_teacher']) || !in_array($latestWorkflow, ['confirmed_monitoring', 'returned_to_teacher'], true);

$pageTitle = "Teacher Portal - " . ($teacher ? ($teacher['first_name'] . ' ' . $teacher['last_name']) : 'Dashboard');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Faculty Attendance Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">
                Faculty: <span class="font-bold text-gray-900"><?= e($teacher['first_name'] . ' ' . $teacher['last_name']) ?></span> &bull; 
                Active Cycle: <span class="font-semibold text-green-700">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/tams/teacher/my_attendance.php<?= isset($_GET['view_teacher_id']) ? '?view_teacher_id=' . (int)$teacherId : '' ?>" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-md shadow-sm">
                View Detailed Logs
            </a>
            <?php if (!$isTeacherLocked && $_SESSION['role'] === 'teacher'): ?>
                <a href="/tams/teacher/evidence_upload.php" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-md shadow-sm">
                    Upload Dispute Evidence
                </a>
                <a href="/tams/teacher/submit_report.php" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-md shadow-sm">
                    Acknowledge & Submit to Chair &rarr;
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Workflow Progress Bar -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4">Academic Review Workflow Progress</h2>
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-semibold">
            <div class="px-3 py-1.5 rounded <?= in_array($latestWorkflow, ['draft', 'submitted_operator', 'confirmed_monitoring', 'submitted_teacher', 'submitted_chairperson', 'submitted_dean', 'verified_monitoring', 'final_approved_vp']) ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-400' ?>">
                1. Operator Ingestion
            </div>
            <span>&rarr;</span>
            <div class="px-3 py-1.5 rounded <?= in_array($latestWorkflow, ['confirmed_monitoring', 'submitted_teacher', 'submitted_chairperson', 'submitted_dean', 'verified_monitoring', 'final_approved_vp']) ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-400' ?>">
                2. Monitoring Confirmed
            </div>
            <span>&rarr;</span>
            <div class="px-3 py-1.5 rounded <?= in_array($latestWorkflow, ['submitted_teacher', 'submitted_chairperson', 'submitted_dean', 'verified_monitoring', 'final_approved_vp']) ? 'bg-green-100 text-green-800' : ($latestWorkflow === 'confirmed_monitoring' ? 'bg-amber-100 text-amber-800 animate-pulse' : 'bg-gray-100 text-gray-400') ?>">
                3. Teacher Review / Dispute
            </div>
            <span>&rarr;</span>
            <div class="px-3 py-1.5 rounded <?= in_array($latestWorkflow, ['submitted_chairperson', 'submitted_dean', 'verified_monitoring', 'final_approved_vp']) ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-400' ?>">
                4. Chairperson Review
            </div>
            <span>&rarr;</span>
            <div class="px-3 py-1.5 rounded <?= in_array($latestWorkflow, ['submitted_dean', 'verified_monitoring', 'final_approved_vp']) ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-400' ?>">
                5. College Dean Review
            </div>
            <span>&rarr;</span>
            <div class="px-3 py-1.5 rounded <?= in_array($latestWorkflow, ['verified_monitoring', 'final_approved_vp']) ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-400' ?>">
                6. Monitoring Re-Audit
            </div>
            <span>&rarr;</span>
            <div class="px-3 py-1.5 rounded <?= ($latestWorkflow === 'final_approved_vp') ? 'bg-red-100 text-red-800 font-extrabold' : 'bg-gray-100 text-gray-400' ?>">
                7. VP Final Approved
            </div>
        </div>

        <?php if ($latestWorkflow === 'returned_to_teacher'): ?>
            <div class="mt-4 p-3 bg-red-50 border-l-4 border-red-500 rounded text-xs text-red-800 font-medium">
                <strong>Notice:</strong> This report has been returned by your Chairperson or Dean for correction. Please inspect remarks, upload required evidence, and re-submit.
            </div>
        <?php endif; ?>
    </div>

    <!-- Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Accumulated Lates</div>
            <div class="mt-2 text-3xl font-extrabold text-yellow-600"><?= (int)$penalties['accumulated_lates_count'] ?></div>
            <div class="mt-1 text-xs text-gray-400">Current cycle late count</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Converted Absents</div>
            <div class="mt-2 text-3xl font-extrabold text-red-600"><?= (int)$penalties['converted_absents_count'] ?></div>
            <div class="mt-1 text-xs text-gray-400">Calculated via 3 Lates = 1 Absent</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Exemptions Discounted</div>
            <div class="mt-2 text-2xl font-bold text-purple-600">
                <?= (int)($statusBreakdown['Holiday'] ?? 0) + (int)($statusBreakdown['Suspended'] ?? 0) + (int)($statusBreakdown['Partial Suspension'] ?? 0) ?>
            </div>
            <div class="mt-1 text-xs text-gray-400">Holidays & Class Suspensions</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Current Workflow Stage</div>
            <div class="mt-2 text-sm font-bold uppercase tracking-wider text-indigo-700"><?= e(str_replace('_', ' ', $latestWorkflow)) ?></div>
            <div class="mt-1 text-xs text-gray-400">
                <?= $isTeacherLocked ? '<span class="text-red-500 font-semibold">Teacher Window Locked</span>' : '<span class="text-green-600 font-semibold">Review Window Open</span>' ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
