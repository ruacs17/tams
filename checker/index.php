<?php
// Attendance Checker (Operator) Dashboard
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['checker']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

// Metric: drafts pending submission
$stmtDrafts = $pdo->prepare("
    SELECT COUNT(*) 
    FROM `attendance_logs` 
    WHERE `school_year` = ? AND `school_term` = ? AND `workflow_status` = 'draft'
");
$stmtDrafts->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$draftCount = (int)$stmtDrafts->fetchColumn();

// Metric: today's logs
$today = date('Y-m-d');
$stmtToday = $pdo->prepare("SELECT COUNT(*) FROM `attendance_logs` WHERE `date` = ?");
$stmtToday->execute([$today]);
$todayLogCount = (int)$stmtToday->fetchColumn();

// Active calendar events for today
$stmtEvents = $pdo->prepare("SELECT * FROM `calendar_events` WHERE ? BETWEEN `start_date` AND `end_date`");
$stmtEvents->execute([$today]);
$todayEvents = $stmtEvents->fetchAll();

$pageTitle = "Checker Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Attendance Checker Portal</h1>
            <p class="text-sm text-gray-500 mt-1">
                Active Cycle Bound: <span class="font-semibold text-blue-700">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <div class="flex space-x-2">
            <a href="/tams/checker/log_attendance.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md shadow-sm">
                Log Room-to-Room Attendance
            </a>
            <a href="/tams/checker/submissions.php" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-md shadow-sm">
                Submit Drafts (<?= $draftCount ?>)
            </a>
        </div>
    </div>

    <!-- Active Declarations Alert -->
    <?php if (!empty($todayEvents)): ?>
    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-md shadow-sm">
        <h3 class="text-sm font-bold text-blue-900 flex items-center">
            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
            Active Institutional Calendar Declarations For Today
        </h3>
        <ul class="mt-2 text-xs text-blue-800 space-y-1">
            <?php foreach ($todayEvents as $ev): ?>
                <li>
                    <strong>[<?= strtoupper(e($ev['event_type'])) ?>]</strong> <?= e($ev['title']) ?> &mdash; Scope: <?= e($ev['scope_type']) ?>
                    <?php if ($ev['start_time']): ?> (<?= date('h:i A', strtotime($ev['start_time'])) ?> - <?= date('h:i A', strtotime($ev['end_time'])) ?>)<?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Today's Recorded Logs</div>
            <div class="mt-2 text-3xl font-extrabold text-blue-600"><?= $todayLogCount ?></div>
            <div class="mt-1 text-xs text-gray-400">Date: <?= date('F j, Y') ?></div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Pending Draft Logs</div>
            <div class="mt-2 text-3xl font-extrabold text-amber-600"><?= $draftCount ?></div>
            <div class="mt-1 text-xs text-gray-400">Unsubmitted working entries</div>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="text-xs font-semibold text-gray-500 uppercase">Operational Constraint</div>
            <div class="mt-2 text-sm font-semibold text-gray-800">Strict Term Isolation</div>
            <div class="mt-1 text-xs text-gray-500">Checker account is locked to active academic cycle. Past logs cannot be modified.</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
