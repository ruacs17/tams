<?php
// Vice President for Academics Dashboard
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['vp']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

// Count reports awaiting VP final approval (status = 'verified_monitoring')
$stmtPending = $pdo->prepare("
    SELECT COUNT(DISTINCT teacher_id) 
    FROM `attendance_logs` 
    WHERE `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'verified_monitoring'
");
$stmtPending->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$pendingVpCount = (int)$stmtPending->fetchColumn();

// Count finalized reports (status = 'final_approved_vp')
$stmtFinal = $pdo->prepare("
    SELECT COUNT(DISTINCT teacher_id) 
    FROM `attendance_logs` 
    WHERE `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'final_approved_vp'
");
$stmtFinal->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$finalCount = (int)$stmtFinal->fetchColumn();

$pageTitle = "VP for Academics Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Vice President for Academics Executive Portal</h1>
            <p class="text-sm text-gray-500 mt-1">
                Highest Institutional Approval & Reversion Authority &bull; Active Cycle: <span class="font-semibold text-red-700">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/tams/vp/final_approval.php" class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white text-sm font-semibold rounded-md shadow-sm">
                Final Approvals (<?= $pendingVpCount ?>)
            </a>
            <a href="/tams/vp/revert.php" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-md shadow-sm">
                Exclusive Revert Decision
            </a>
            <a href="/tams/vp/archives.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold rounded-md shadow-sm">
                Institutional Archives
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Awaiting VP Final Approval</div>
            <div class="mt-2 text-3xl font-extrabold text-red-700"><?= $pendingVpCount ?></div>
            <div class="mt-1 text-xs text-gray-400">Verified by Monitoring Office</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Final Approved Reports</div>
            <div class="mt-2 text-3xl font-extrabold text-green-700"><?= $finalCount ?></div>
            <div class="mt-1 text-xs text-gray-400">Completed academic reports</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Executive Authority</div>
            <div class="mt-2 text-sm font-bold text-gray-900">Revert Decision Safeguard</div>
            <div class="mt-1 text-xs text-gray-500">VP possesses sole authority to unlock finalized reports back down the audit chain.</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
