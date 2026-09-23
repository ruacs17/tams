<?php
// Admin / Monitoring Head Dashboard
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

// Dashboard Metrics
$totalTeachers = (int)$pdo->query("SELECT COUNT(*) FROM `teachers`")->fetchColumn();
$totalLoads = (int)$pdo->query("SELECT COUNT(*) FROM `teacher_loads` WHERE `school_year` = " . $pdo->quote($activeSettings['current_school_year']) . " AND `school_term` = " . $pdo->quote($activeSettings['current_school_term']))->fetchColumn();
$totalColleges = (int)$pdo->query("SELECT COUNT(*) FROM `colleges`")->fetchColumn();
$totalDepartments = (int)$pdo->query("SELECT COUNT(*) FROM `departments`")->fetchColumn();

// Active Suspensions / Holidays
$stmtActiveEvents = $pdo->prepare("
    SELECT * FROM `calendar_events`
    WHERE CURRENT_DATE() BETWEEN `start_date` AND `end_date`
    ORDER BY `start_date` DESC
");
$stmtActiveEvents->execute();
$activeEvents = $stmtActiveEvents->fetchAll();

// Logs Breakdown for active term
$stmtLogs = $pdo->prepare("
    SELECT status, COUNT(*) as count 
    FROM `attendance_logs` 
    WHERE `school_year` = ? AND `school_term` = ? 
    GROUP BY status
");
$stmtLogs->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$logStats = [];
while ($row = $stmtLogs->fetch()) {
    $logStats[$row['status']] = $row['count'];
}

// Workflow Status Counts
$stmtWorkflow = $pdo->prepare("
    SELECT workflow_status, COUNT(*) as count
    FROM `attendance_logs`
    WHERE `school_year` = ? AND `school_term` = ?
    GROUP BY workflow_status
");
$stmtWorkflow->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$workflowStats = [];
while ($row = $stmtWorkflow->fetch()) {
    $workflowStats[$row['workflow_status']] = $row['count'];
}

$pageTitle = "Monitoring Head Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <!-- Top Welcome Banner -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Monitoring Office Authority Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">
                Active Cycle: <span class="font-semibold text-indigo-700">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <div class="mt-4 md:mt-0 flex flex-wrap gap-2">
            <a href="/tams/monitoring/review_reports.php" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm">
                Review & Unlock Reports
            </a>
            <a href="/tams/admin/calendar_events.php" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md shadow-sm">
                Declare Suspension / Holiday
            </a>
            <a href="/tams/admin/teachers.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md shadow-sm">
                CSV Teacher Ingestion
            </a>
            <a href="/tams/admin/loads.php" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md shadow-sm">
                CSV Load Ingestion
            </a>
        </div>
    </div>

    <!-- Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
            <div class="text-sm font-medium text-gray-500">Registered Faculty</div>
            <div class="mt-2 text-3xl font-extrabold text-gray-900"><?= $totalTeachers ?></div>
            <div class="mt-1 text-xs text-gray-400">Total institutional teachers</div>
        </div>

        <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
            <div class="text-sm font-medium text-gray-500">Active Course Loads</div>
            <div class="mt-2 text-3xl font-extrabold text-indigo-600"><?= $totalLoads ?></div>
            <div class="mt-1 text-xs text-gray-400">Current semester schedules</div>
        </div>

        <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
            <div class="text-sm font-medium text-gray-500">Colleges & Departments</div>
            <div class="mt-2 text-3xl font-extrabold text-gray-900"><?= $totalColleges ?> <span class="text-lg font-medium text-gray-400">/ <?= $totalDepartments ?></span></div>
            <div class="mt-1 text-xs text-gray-400">Academic structure units</div>
        </div>

        <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
            <div class="text-sm font-medium text-gray-500">Active Calendar Events</div>
            <div class="mt-2 text-3xl font-extrabold text-purple-600"><?= count($activeEvents) ?></div>
            <div class="mt-1 text-xs text-gray-400">Holidays & suspensions today</div>
        </div>
    </div>

    <!-- Active Calendar Events Banner -->
    <?php if (!empty($activeEvents)): ?>
    <div class="bg-purple-50 border-l-4 border-purple-600 p-4 rounded-md shadow-sm">
        <h3 class="text-sm font-bold text-purple-900 flex items-center">
            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
            </svg>
            Active Institutional Calendar Declarations For Today
        </h3>
        <div class="mt-2 space-y-2">
            <?php foreach ($activeEvents as $ev): ?>
                <div class="text-xs text-purple-800 flex items-center justify-between bg-white bg-opacity-70 p-2 rounded">
                    <div>
                        <span class="font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-purple-200 text-purple-900 mr-2">
                            <?= e($ev['event_type']) ?>
                        </span>
                        <span class="font-medium"><?= e($ev['title']) ?></span>
                        <span class="text-gray-500 ml-2">Scope: <?= e($ev['scope_type']) ?> (ID: <?= e($ev['scope_id'] ?? 'Global') ?>)</span>
                    </div>
                    <div>
                        <?= e($ev['start_date']) ?> to <?= e($ev['end_date']) ?>
                        <?php if ($ev['start_time']): ?>
                            (<?= e(date('h:i A', strtotime($ev['start_time']))) ?> - <?= e(date('h:i A', strtotime($ev['end_time']))) ?>)
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Workflow Progress Overview -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
            <h2 class="text-base font-bold text-gray-900 mb-4">Workflow Pipeline (Active Term)</h2>
            <div class="space-y-3">
                <?php
                $stages = [
                    'draft' => 'Draft (Checker in progress)',
                    'submitted_operator' => 'Submitted by Checker (Awaiting Monitoring)',
                    'confirmed_monitoring' => 'Confirmed by Monitoring (At Teacher)',
                    'submitted_teacher' => 'Acknowledged by Teacher (At Chairperson)',
                    'submitted_chairperson' => 'Approved by Chairperson (At Dean)',
                    'submitted_dean' => 'Approved by Dean (At Monitoring Re-Audit)',
                    'returned_to_teacher' => 'Returned / Disagreed (Remediation)',
                    'verified_monitoring' => 'Verified by Monitoring (At VP for Academics)',
                    'final_approved_vp' => 'Final Approved by VP'
                ];
                foreach ($stages as $key => $label):
                    $count = $workflowStats[$key] ?? 0;
                ?>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600"><?= e($label) ?></span>
                    <span class="font-semibold px-2.5 py-0.5 rounded-full <?= $count > 0 ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-400' ?>">
                        <?= $count ?> logs
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
            <h2 class="text-base font-bold text-gray-900 mb-4">Attendance Status Distribution</h2>
            <div class="space-y-3">
                <?php
                $statuses = [
                    'Present' => ['code' => 'PRE', 'color' => 'bg-green-100 text-green-800'],
                    'Late'    => ['code' => 'LTE', 'color' => 'bg-yellow-100 text-yellow-800'],
                    'Absent'  => ['code' => 'ABS', 'color' => 'bg-red-100 text-red-800'],
                    'Holiday' => ['code' => 'HOL', 'color' => 'bg-purple-100 text-purple-800'],
                    'Suspended' => ['code' => 'SOS', 'color' => 'bg-blue-100 text-blue-800'],
                    'Partial Suspension' => ['code' => 'PSE', 'color' => 'bg-indigo-100 text-indigo-800'],
                    'Excused' => ['code' => 'EXM', 'color' => 'bg-gray-100 text-gray-800'],
                ];
                foreach ($statuses as $st => $info):
                    $cnt = $logStats[$st] ?? 0;
                ?>
                <div class="flex items-center justify-between text-sm">
                    <div class="flex items-center space-x-2">
                        <span class="text-xs px-2 py-0.5 rounded font-mono font-bold <?= $info['color'] ?>"><?= $info['code'] ?></span>
                        <span class="text-gray-700"><?= $st ?></span>
                    </div>
                    <span class="font-semibold text-gray-900"><?= $cnt ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
