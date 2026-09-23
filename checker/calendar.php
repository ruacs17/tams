<?php
// Attendance Checker View-Only Calendar Declarations
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['checker']);
$pdo = getDBConnection();

$events = $pdo->query("
    SELECT * FROM `calendar_events`
    ORDER BY `start_date` DESC
")->fetchAll();

$pageTitle = "Calendar Events (View Only)";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Declared Calendar Events & Suspensions</h1>
        <p class="text-sm text-gray-500">
            View-only reference for Attendance Checkers to cross-verify room-to-room attendance and duty exemptions.
        </p>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Type</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Title & Justification</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Date Range</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Time Window</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Target Scope</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                <?php if (empty($events)): ?>
                    <tr><td colspan="5" class="px-6 py-6 text-center text-gray-500">No calendar events declared.</td></tr>
                <?php else: foreach ($events as $ev): ?>
                    <tr>
                        <td class="px-6 py-4">
                            <?php if ($ev['event_type'] === 'holiday'): ?>
                                <span class="px-2 py-0.5 rounded font-mono text-xs font-bold bg-purple-100 text-purple-800">HOL (Holiday)</span>
                            <?php elseif ($ev['event_type'] === 'emergency_closure'): ?>
                                <span class="px-2 py-0.5 rounded font-mono text-xs font-bold bg-blue-100 text-blue-800">SOS (Closure)</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded font-mono text-xs font-bold bg-indigo-100 text-indigo-800">PSE (Partial)</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-bold text-gray-900"><?= e($ev['title']) ?></div>
                            <div class="text-xs text-gray-500"><?= e($ev['description']) ?></div>
                        </td>
                        <td class="px-6 py-4 text-xs font-mono text-gray-700">
                            <?= e($ev['start_date']) ?> <?= $ev['start_date'] !== $ev['end_date'] ? ' to ' . e($ev['end_date']) : '' ?>
                        </td>
                        <td class="px-6 py-4 text-xs font-mono text-gray-600">
                            <?= $ev['start_time'] ? (date('h:i A', strtotime($ev['start_time'])) . ' - ' . date('h:i A', strtotime($ev['end_time']))) : 'All Day' ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-0.5 text-xs rounded font-medium bg-gray-100 text-gray-800 uppercase">
                                <?= e($ev['scope_type']) ?> <?= $ev['scope_id'] ? '(ID: ' . (int)$ev['scope_id'] . ')' : '' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
