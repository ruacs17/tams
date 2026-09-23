<?php
// Immutable Audit Trail Logging Viewer (System & Report-Level)
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['monitoring_head', 'vp']);
$pdo = getDBConnection();

$viewTab = $_GET['tab'] ?? 'report_trails';

// Fetch Report-Level Audit Trails
$reportTrails = $pdo->query("
    SELECT rat.*, t.first_name as teacher_first, t.last_name as teacher_last
    FROM `report_audit_trails` rat
    JOIN `teachers` t ON rat.teacher_id = t.teacher_id
    ORDER BY rat.timestamp DESC
    LIMIT 100
")->fetchAll();

// Fetch System-Level Administrative Audit Logs
$systemLogs = $pdo->query("
    SELECT * FROM `audit_logs`
    ORDER BY `timestamp` DESC
    LIMIT 100
")->fetchAll();

$pageTitle = "Audit Trail & Compliance Logs";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Immutable Audit Trail & Compliance Framework</h1>
            <p class="text-sm text-gray-500">
                Non-repudiation logging of every report touchpoint, status transition, remarks snapshot, actor identity, and client IP address.
            </p>
        </div>
        <div class="flex space-x-2">
            <a href="/tams/admin/audit_logs.php?tab=report_trails" 
               class="px-4 py-2 text-sm font-medium rounded-md <?= $viewTab === 'report_trails' ? 'bg-indigo-700 text-white' : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50' ?>">
                Report-Level Audit Trails
            </a>
            <a href="/tams/admin/audit_logs.php?tab=system_logs" 
               class="px-4 py-2 text-sm font-medium rounded-md <?= $viewTab === 'system_logs' ? 'bg-indigo-700 text-white' : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50' ?>">
                System Admin Logs
            </a>
        </div>
    </div>

    <?php if ($viewTab === 'report_trails'): ?>
    <!-- Report-Level Audit Trails Table -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Report Workflow Transitions & Touchpoints</h2>
            <span class="text-xs bg-indigo-100 text-indigo-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($reportTrails) ?> Recent Records</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Timestamp</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Faculty Subject</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Actor Identity & Role</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Action Type</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Status Transition</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Remarks Snapshot</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Client IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white text-xs">
                    <?php if (empty($reportTrails)): ?>
                        <tr><td colspan="7" class="px-6 py-6 text-center text-gray-500">No report audit records logged yet.</td></tr>
                    <?php else: foreach ($reportTrails as $rt): ?>
                        <tr>
                            <td class="px-4 py-3 font-mono text-gray-600 whitespace-nowrap"><?= e($rt['timestamp']) ?></td>
                            <td class="px-4 py-3 font-medium text-gray-900"><?= e($rt['teacher_last'] . ', ' . $rt['teacher_first']) ?></td>
                            <td class="px-4 py-3">
                                <span class="font-bold text-gray-800">ID: <?= (int)$rt['actor_id'] ?></span>
                                <span class="inline-block px-1.5 py-0.5 rounded bg-gray-100 text-gray-700 font-mono uppercase ml-1">
                                    <?= e($rt['actor_role']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-semibold text-indigo-700 font-mono"><?= e($rt['action_type']) ?></td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="text-gray-500"><?= e($rt['previous_workflow_status'] ?: 'start') ?></span>
                                <span class="text-gray-400">&rarr;</span>
                                <span class="font-bold text-indigo-800"><?= e($rt['new_workflow_status']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-gray-700 max-w-xs truncate" title="<?= e($rt['remarks_snapshot']) ?>">
                                <?= e($rt['remarks_snapshot'] ?: '<span class="text-gray-400 italic">None</span>') ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-gray-500"><?= e($rt['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- System Administrative Logs Table -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">System Administrative & Operation Logs</h2>
            <span class="text-xs bg-purple-100 text-purple-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($systemLogs) ?> Recent Records</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Timestamp</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">User ID</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Role</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Action Performed</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Target Record</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Client IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white text-xs">
                    <?php if (empty($systemLogs)): ?>
                        <tr><td colspan="6" class="px-6 py-6 text-center text-gray-500">No system audit logs recorded.</td></tr>
                    <?php else: foreach ($systemLogs as $sl): ?>
                        <tr>
                            <td class="px-4 py-3 font-mono text-gray-600 whitespace-nowrap"><?= e($sl['timestamp']) ?></td>
                            <td class="px-4 py-3 font-mono text-gray-900 font-bold"><?= (int)$sl['user_id'] ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-800 font-mono uppercase font-semibold">
                                    <?= e($sl['role']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-800"><?= e($sl['action']) ?></td>
                            <td class="px-4 py-3 font-mono text-gray-500"><?= $sl['target_record_id'] ? (int)$sl['target_record_id'] : '-' ?></td>
                            <td class="px-4 py-3 font-mono text-gray-500"><?= e($sl['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
