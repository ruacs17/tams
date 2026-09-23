<?php
// System Settings & Key Institutional Role Assignments
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $year = trim($_POST['current_school_year'] ?? '');
    $term = trim($_POST['current_school_term'] ?? '');
    $vpId = !empty($_POST['vp_academics_id']) ? (int)$_POST['vp_academics_id'] : null;

    if (!isValidSchoolYear($year) || empty($term)) {
        $_SESSION['flash_error'] = 'School Year must strictly follow YYYY-YYYY format (e.g. 2025-2026).';
    } else {
        $stmt = $pdo->prepare("
            UPDATE `system_settings` 
            SET `current_school_year` = ?, `current_school_term` = ?, `vp_academics_id` = ?
            WHERE `setting_id` = 1
        ");
        $stmt->execute([$year, $term, $vpId]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Updated System Settings: S.Y. {$year} {$term}, VP ID: {$vpId}");
        $_SESSION['flash_success'] = 'Academic cycle and key role assignments successfully updated.';
    }
    header("Location: /tams/admin/settings.php");
    exit;
}

$settings = getActiveSystemSettings($pdo);
$teachers = $pdo->query("SELECT teacher_id, last_name, first_name FROM `teachers` ORDER BY last_name ASC")->fetchAll();

// Fetch Deans
$deans = $pdo->query("
    SELECT c.abbreviation, c.full_name, t.first_name, t.last_name, t.teacher_id
    FROM `colleges` c
    LEFT JOIN `teachers` t ON c.dean_id = t.teacher_id
    ORDER BY c.abbreviation ASC
")->fetchAll();

// Fetch Chairpersons
$chairs = $pdo->query("
    SELECT d.department_abbreviation, d.department_full_name, t.first_name, t.last_name, t.teacher_id
    FROM `departments` d
    LEFT JOIN `teachers` t ON d.chairperson_id = t.teacher_id
    ORDER BY d.department_abbreviation ASC
")->fetchAll();

$pageTitle = "System Settings & Role Assignments";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Academic Period & Institutional Role Settings</h1>
        <p class="text-sm text-gray-500">Configure the active institutional academic cycle and assign executive faculty leadership.</p>
    </div>

    <form method="POST" class="bg-white shadow-sm rounded-lg border border-gray-200 p-6 space-y-6">
        <?= csrfField() ?>

        <div class="border-b border-gray-200 pb-4">
            <h2 class="text-base font-bold text-gray-800">Global Academic Period</h2>
            <p class="text-xs text-gray-500">Determines the active operational cycle for room-to-room checking and automated penalties.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Current School Year (YYYY-YYYY)</label>
                    <input type="text" name="current_school_year" required pattern="^\d{4}-\d{4}$" value="<?= e($settings['current_school_year']) ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm font-mono">
                    <span class="text-xs text-gray-400">Strict format: e.g. 2025-2026</span>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Current Academic Term</label>
                    <select name="current_school_term" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                        <option value="1st Term" <?= $settings['current_school_term'] === '1st Term' ? 'selected' : '' ?>>1st Term</option>
                        <option value="2nd Term" <?= $settings['current_school_term'] === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
                        <option value="Summer" <?= $settings['current_school_term'] === 'Summer' ? 'selected' : '' ?>>Summer</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="border-b border-gray-200 pb-4">
            <h2 class="text-base font-bold text-gray-800">Executive Leadership Assignment</h2>
            <div class="mt-4">
                <label class="block text-xs font-semibold text-gray-700 uppercase">Vice President for Academics</label>
                <select name="vp_academics_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="">-- Select Faculty VP --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['teacher_id'] ?>" <?= ((int)$settings['vp_academics_id'] === (int)$t['teacher_id']) ? 'selected' : '' ?>>
                            <?= e($t['last_name'] . ', ' . $t['first_name']) ?> (ID: <?= (int)$t['teacher_id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Holds final approval authority and the exclusive "Revert Decision" mechanism.</p>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold shadow-sm">
                Save System Settings
            </button>
        </div>
    </form>

    <!-- Overview of Deans and Chairs -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-sm font-bold text-gray-800">Assigned College Deans</h3>
                <a href="/tams/admin/colleges.php" class="text-xs text-indigo-600 hover:underline">Manage &rarr;</a>
            </div>
            <ul class="divide-y divide-gray-100 text-xs">
                <?php foreach ($deans as $dean): ?>
                    <li class="py-2 flex justify-between">
                        <span class="font-medium text-gray-800"><?= e($dean['abbreviation']) ?></span>
                        <span class="text-gray-600"><?= $dean['teacher_id'] ? e($dean['first_name'] . ' ' . $dean['last_name']) : '<span class="text-red-500 italic">Unassigned</span>' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200 shadow-sm">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-sm font-bold text-gray-800">Assigned Department Chairpersons</h3>
                <a href="/tams/admin/departments.php" class="text-xs text-indigo-600 hover:underline">Manage &rarr;</a>
            </div>
            <ul class="divide-y divide-gray-100 text-xs">
                <?php foreach ($chairs as $ch): ?>
                    <li class="py-2 flex justify-between">
                        <span class="font-medium text-gray-800"><?= e($ch['department_abbreviation']) ?></span>
                        <span class="text-gray-600"><?= $ch['teacher_id'] ? e($ch['first_name'] . ' ' . $ch['last_name']) : '<span class="text-red-500 italic">Unassigned</span>' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
