<?php
// Holiday, Emergency Closure & Class Suspension Management Module
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';
require_once __DIR__ . '/../includes/attendance_engine.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();

// Handle Event Declarations & Recalculation Triggers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'create_event') {
        $type = $_POST['event_type'] ?? 'holiday';
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? $startDate;
        $startTime = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
        $endTime = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
        $scopeType = $_POST['scope_type'] ?? 'institution';
        $scopeId = !empty($_POST['scope_id']) ? (int)$_POST['scope_id'] : null;
        $requireCheckin = isset($_POST['require_event_checkin']) ? 1 : 0;
        $userId = (int)$_SESSION['user_id'];

        if (empty($title) || empty($startDate)) {
            $_SESSION['flash_error'] = 'Title and Start Date are required.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `calendar_events` 
                (`event_type`, `title`, `description`, `start_date`, `end_date`, `start_time`, `end_time`, `scope_type`, `scope_id`, `require_event_checkin`, `created_by`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $type, $title, $desc, $startDate, $endDate, $startTime, $endTime, $scopeType, $scopeId, $requireCheckin, $userId
            ]);
            $eventId = (int)$pdo->lastInsertId();

            // Run Retroactive Recalculation Engine automatically
            $recalculated = recalculateRetroactiveEvent($pdo, $eventId);

            logSystemAudit($pdo, $userId, 'monitoring_head', "Declared Calendar Event: {$type} - {$title} (Scope: {$scopeType}, Affected logs recalculated: {$recalculated})", $eventId);
            $_SESSION['flash_success'] = "Event '{$title}' published. Retroactive recalculation updated {$recalculated} affected attendance records.";
        }
        header("Location: /tams/admin/calendar_events.php");
        exit;

    } elseif ($action === 'recalculate_event') {
        $eventId = (int)$_POST['event_id'];
        $recalculated = recalculateRetroactiveEvent($pdo, $eventId);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Manual trigger: Retroactive recalculation on Event ID {$eventId} (Updated {$recalculated} records)", $eventId);
        $_SESSION['flash_success'] = "Retroactive Recalculation complete. {$recalculated} records adjusted to reflect suspension/holiday status.";
        header("Location: /tams/admin/calendar_events.php");
        exit;

    } elseif ($action === 'delete_event') {
        $eventId = (int)$_POST['event_id'];
        $pdo->prepare("DELETE FROM `calendar_events` WHERE `event_id` = ?")->execute([$eventId]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Deleted Calendar Event ID {$eventId}", $eventId);
        $_SESSION['flash_success'] = "Calendar event removed.";
        header("Location: /tams/admin/calendar_events.php");
        exit;
    }
}

// Fetch all calendar events
$events = $pdo->query("
    SELECT * FROM `calendar_events`
    ORDER BY `start_date` DESC, `created_at` DESC
")->fetchAll();

$colleges = $pdo->query("SELECT college_id, abbreviation, full_name FROM `colleges` ORDER BY abbreviation ASC")->fetchAll();
$departments = $pdo->query("SELECT department_id, department_abbreviation, department_full_name FROM `departments` ORDER BY department_abbreviation ASC")->fetchAll();
$loads = $pdo->query("SELECT load_id, offer_code, subject_name FROM `teacher_loads` ORDER BY offer_code ASC")->fetchAll();

$pageTitle = "Calendar Events & Suspensions";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Holiday, Emergency Closure & Suspension Engine</h1>
            <p class="text-sm text-gray-500">
                Primary Administrative Authority for Academic Calendar Exemptions & Class Suspensions (No HR Module Required).
            </p>
        </div>
        <button onclick="toggleModal('modalCreateEvent')" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md shadow-sm">
            + Declare New Calendar Event
        </button>
    </div>

    <!-- Suspension Matrix Reference Alert -->
    <div class="bg-indigo-50 border-l-4 border-indigo-500 p-4 rounded-md shadow-sm text-xs text-indigo-900">
        <span class="font-bold">System Status Codes & Reporting Rules:</span>
        <span class="ml-2 font-mono font-bold bg-purple-100 text-purple-800 px-1.5 py-0.5 rounded">HOL</span> Full-Day Holiday &bull;
        <span class="ml-2 font-mono font-bold bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded">SOS</span> Full Suspension &bull;
        <span class="ml-2 font-mono font-bold bg-indigo-100 text-indigo-800 px-1.5 py-0.5 rounded">PSE</span> Partial Suspension (Window-bound) &bull;
        <span class="ml-2">15-minute grace buffer applies for overlapping class ends. Full-day & targeted suspensions trigger the <strong>Retroactive Recalculation Engine</strong> to clear false penalties.</span>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Declared Calendar Declarations</h2>
            <span class="text-xs bg-purple-100 text-purple-800 font-semibold px-2.5 py-0.5 rounded-full"><?= count($events) ?> Events</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Type</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Title & Justification</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Date Range</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Time Window</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Target Scope</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Check-in Req.</th>
                        <th class="px-4 py-3 text-right font-semibold text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($events)): ?>
                        <tr><td colspan="7" class="px-6 py-4 text-center text-gray-500">No calendar events or suspensions declared.</td></tr>
                    <?php else: foreach ($events as $ev): ?>
                        <tr>
                            <td class="px-4 py-3">
                                <?php if ($ev['event_type'] === 'holiday'): ?>
                                    <span class="px-2 py-0.5 rounded font-mono text-xs font-bold bg-purple-100 text-purple-800">HOL (Holiday)</span>
                                <?php elseif ($ev['event_type'] === 'emergency_closure'): ?>
                                    <span class="px-2 py-0.5 rounded font-mono text-xs font-bold bg-blue-100 text-blue-800">SOS (Closure)</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded font-mono text-xs font-bold bg-indigo-100 text-indigo-800">PSE (Partial)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900"><?= e($ev['title']) ?></div>
                                <div class="text-xs text-gray-500"><?= e($ev['description']) ?></div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-700 whitespace-nowrap">
                                <?= e($ev['start_date']) ?>
                                <?= ($ev['start_date'] !== $ev['end_date']) ? ' to ' . e($ev['end_date']) : '' ?>
                            </td>
                            <td class="px-4 py-3 text-xs font-mono text-gray-600">
                                <?php if ($ev['start_time']): ?>
                                    <?= date('h:i A', strtotime($ev['start_time'])) ?> - <?= date('h:i A', strtotime($ev['end_time'])) ?>
                                <?php else: ?>
                                    <span class="text-gray-400 italic">All Day</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 text-xs rounded font-medium bg-gray-100 text-gray-800 uppercase tracking-wider">
                                    <?= e($ev['scope_type']) ?>
                                    <?= $ev['scope_id'] ? ' (ID: ' . (int)$ev['scope_id'] . ')' : '' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($ev['require_event_checkin']): ?>
                                    <span class="text-xs font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded">Mandatory Check-in</span>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">Exempt</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                <form method="POST" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="recalculate_event">
                                    <input type="hidden" name="event_id" value="<?= (int)$ev['event_id'] ?>">
                                    <button type="submit" title="Recalculate affected logs and clear false penalties" class="text-xs text-purple-700 hover:text-purple-900 font-semibold underline">
                                        Re-run Engine
                                    </button>
                                </form>

                                <form method="POST" class="inline" onsubmit="return confirm('Revoke and delete this calendar declaration?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="delete_event">
                                    <input type="hidden" name="event_id" value="<?= (int)$ev['event_id'] ?>">
                                    <button type="submit" class="text-xs text-red-600 hover:text-red-900 font-medium underline">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Declare Event -->
<div id="modalCreateEvent" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full p-6 max-h-screen overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Declare Calendar Event / Suspension</h3>
            <button onclick="toggleModal('modalCreateEvent')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="create_event">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Declaration Type</label>
                <select name="event_type" id="ev_type_select" onchange="toggleTimeFields()" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="holiday">Full-Day Pre-Scheduled Holiday (HOL)</option>
                    <option value="emergency_closure">Full-Day Emergency Closure / Suspension (SOS)</option>
                    <option value="partial_suspension">Time-Bound Partial Class Suspension (PSE)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Title / Name of Event</label>
                <input type="text" name="title" required placeholder="e.g. Typhoon Warning / Mass Sponsorship" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Justification / Description</label>
                <textarea name="description" rows="2" placeholder="Administrative basis, weather advisory details, or memo number..." class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Start Date</label>
                    <input type="date" name="start_date" required value="<?= date('Y-m-d') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">End Date</label>
                    <input type="date" name="end_date" required value="<?= date('Y-m-d') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
            </div>

            <!-- Time-Bound window (For Partial Suspensions) -->
            <div id="time_window_box" class="grid grid-cols-2 gap-3 p-3 bg-purple-50 rounded border border-purple-200">
                <div>
                    <label class="block text-xs font-semibold text-purple-900 uppercase">Start Time</label>
                    <input type="time" name="start_time" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-purple-900 uppercase">End Time</label>
                    <input type="time" name="end_time" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
            </div>

            <!-- Targeting Hierarchy -->
            <div class="border border-gray-200 p-3 rounded bg-gray-50 space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Target Scope</label>
                    <select name="scope_type" id="scope_type_select" onchange="toggleScopeInputs()" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                        <option value="institution">1. Institution (Global Campus-Wide)</option>
                        <option value="college">2. College Scope</option>
                        <option value="department">3. Department Scope</option>
                        <option value="subject_load">4. Subject Load Scope</option>
                    </select>
                </div>

                <div id="scope_college_box" class="hidden">
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Target College</label>
                    <select name="scope_college_id" id="sel_college" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                        <?php foreach ($colleges as $c): ?>
                            <option value="<?= (int)$c['college_id'] ?>"><?= e($c['abbreviation'] . ' - ' . $c['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="scope_dept_box" class="hidden">
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Target Department</label>
                    <select name="scope_dept_id" id="sel_dept" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['department_id'] ?>"><?= e($d['department_abbreviation'] . ' - ' . $d['department_full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="scope_load_box" class="hidden">
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Target Subject Load</label>
                    <select name="scope_load_id" id="sel_load" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                        <?php foreach ($loads as $l): ?>
                            <option value="<?= (int)$l['load_id'] ?>"><?= e($l['offer_code'] . ' - ' . $l['subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <input type="hidden" name="scope_id" id="final_scope_id">
            </div>

            <!-- Mandatory Check-in toggle -->
            <div class="flex items-center space-x-2">
                <input type="checkbox" name="require_event_checkin" id="ev_checkin_toggle" value="1" class="h-4 w-4 text-purple-600 rounded">
                <label for="ev_checkin_toggle" class="text-xs font-medium text-gray-700">
                    Require Event Check-in (Teachers must record attendance during window to earn PSE exemption)
                </label>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalCreateEvent')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" onclick="prepareScopeId()" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-md text-sm font-medium">Publish Event & Recalculate</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleTimeFields() {
    const type = document.getElementById('ev_type_select').value;
    const timeBox = document.getElementById('time_window_box');
    if (type === 'partial_suspension') {
        timeBox.classList.remove('hidden');
    } else {
        timeBox.classList.add('hidden');
    }
}

function toggleScopeInputs() {
    const scope = document.getElementById('scope_type_select').value;
    document.getElementById('scope_college_box').classList.add('hidden');
    document.getElementById('scope_dept_box').classList.add('hidden');
    document.getElementById('scope_load_box').classList.add('hidden');

    if (scope === 'college') {
        document.getElementById('scope_college_box').classList.remove('hidden');
    } else if (scope === 'department') {
        document.getElementById('scope_dept_box').classList.remove('hidden');
    } else if (scope === 'subject_load') {
        document.getElementById('scope_load_box').classList.remove('hidden');
    }
}

function prepareScopeId() {
    const scope = document.getElementById('scope_type_select').value;
    let val = '';
    if (scope === 'college') {
        val = document.getElementById('sel_college').value;
    } else if (scope === 'department') {
        val = document.getElementById('sel_dept').value;
    } else if (scope === 'subject_load') {
        val = document.getElementById('sel_load').value;
    }
    document.getElementById('final_scope_id').value = val;
}

// Initialize display state
toggleTimeFields();
toggleScopeInputs();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
