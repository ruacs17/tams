<?php
// College Dean Portal Dashboard
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['dean']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);
$deanTeacherId = (int)$_SESSION['teacher_id'];

// Get College headed by this Dean
$stmtCol = $pdo->prepare("SELECT * FROM `colleges` WHERE `dean_id` = ? LIMIT 1");
$stmtCol->execute([$deanTeacherId]);
$college = $stmtCol->fetch();
$collegeId = $college ? (int)$college['college_id'] : 0;

// Count reports awaiting Dean review (status = 'submitted_chairperson')
$pendingCount = 0;
if ($collegeId > 0) {
    $stmtCount = $pdo->prepare("
        SELECT COUNT(DISTINCT al.teacher_id)
        FROM `attendance_logs` al
        JOIN `teacher_department` td ON al.teacher_id = td.teacher_id
        JOIN `departments` d ON td.department_id = d.department_id
        WHERE d.college_id = ?
          AND al.school_year = ? AND al.school_term = ?
          AND al.workflow_status = 'submitted_chairperson'
    ");
    $stmtCount->execute([$collegeId, $activeSettings['current_school_year'], $activeSettings['current_school_term']]);
    $pendingCount = (int)$stmtCount->fetchColumn();
}

$pageTitle = "College Dean Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">College Dean Portal</h1>
            <p class="text-sm text-gray-500 mt-1">
                College: <span class="font-bold text-indigo-700"><?= $college ? e($college['abbreviation'] . ' - ' . $college['full_name']) : 'Unassigned' ?></span> &bull; 
                Active Cycle: <span class="font-semibold text-gray-800">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <div class="flex space-x-2">
            <a href="/tams/dean/review.php" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-md shadow-sm">
                Review Endorsed Reports (<?= $pendingCount ?>)
            </a>
            <a href="/tams/dean/archives.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold rounded-md shadow-sm">
                College Archives
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Awaiting Dean Approval</div>
            <div class="mt-2 text-3xl font-extrabold text-indigo-600"><?= $pendingCount ?></div>
            <div class="mt-1 text-xs text-gray-400">Reports endorsed by Department Chairs</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Return / Disagree Protocol</div>
            <div class="mt-2 text-sm font-bold text-gray-900">Remediation Loopback Active</div>
            <div class="mt-1 text-xs text-gray-500">Itemize disapproval remarks to return reports for teacher revision.</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Upstream Next Stage</div>
            <div class="mt-2 text-sm font-bold text-purple-700">Monitoring Re-Audit &rarr; VP</div>
            <div class="mt-1 text-xs text-gray-500">Dean approval advances reports to the Monitoring Office Re-Audit.</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
