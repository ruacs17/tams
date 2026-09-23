<?php
// Department Chairperson Dashboard
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['chairperson']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);
$chairTeacherId = (int)$_SESSION['teacher_id'];

// Find department chaired by this teacher
$stmtDept = $pdo->prepare("SELECT * FROM `departments` WHERE `chairperson_id` = ? LIMIT 1");
$stmtDept->execute([$chairTeacherId]);
$dept = $stmtDept->fetch();

$deptId = $dept ? (int)$dept['department_id'] : 0;

// Count reports awaiting Chairperson review (status = 'submitted_teacher')
$pendingCount = 0;
if ($deptId > 0) {
    $stmtCount = $pdo->prepare("
        SELECT COUNT(DISTINCT al.teacher_id)
        FROM `attendance_logs` al
        JOIN `teacher_department` td ON al.teacher_id = td.teacher_id
        WHERE td.department_id = ? 
          AND al.school_year = ? AND al.school_term = ?
          AND al.workflow_status = 'submitted_teacher'
    ");
    $stmtCount->execute([$deptId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
    $pendingCount = (int)$stmtCount->fetchColumn();
}

$pageTitle = "Chairperson Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Department Chairperson Portal</h1>
            <p class="text-sm text-gray-500 mt-1">
                Department: <span class="font-bold text-amber-700"><?= $dept ? e($dept['department_abbreviation'] . ' - ' . $dept['department_full_name']) : 'Unassigned' ?></span> &bull; 
                Active Cycle: <span class="font-semibold text-gray-800">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <div class="flex space-x-2">
            <a href="/tams/chairperson/review.php" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-md shadow-sm">
                Review Faculty Reports (<?= $pendingCount ?>)
            </a>
            <a href="/tams/chairperson/archives.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold rounded-md shadow-sm">
                Department Archives
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Pending Faculty Reviews</div>
            <div class="mt-2 text-3xl font-extrabold text-amber-600"><?= $pendingCount ?></div>
            <div class="mt-1 text-xs text-gray-400">Reports submitted by teachers</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Return / Disagree Safeguard</div>
            <div class="mt-2 text-sm font-bold text-gray-900">Remediation Loopback Active</div>
            <div class="mt-1 text-xs text-gray-500">Return reports with itemized disapproval remarks to unlock teacher corrections.</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Upstream Routing</div>
            <div class="mt-2 text-sm font-bold text-indigo-700">College Dean &rarr; VP</div>
            <div class="mt-1 text-xs text-gray-500">Approved reports advance directly to the College Dean.</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
