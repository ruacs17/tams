<?php
// Teacher-Department Affiliations Management
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'create') {
        $teacherId = (int)$_POST['teacher_id'];
        $deptId = (int)$_POST['department_id'];
        $status = $_POST['status'] ?? 'permanent';
        $year = trim($_POST['school_year'] ?? '');
        $term = trim($_POST['school_term'] ?? '');

        if ($teacherId <= 0 || $deptId <= 0 || !isValidSchoolYear($year) || empty($term)) {
            $_SESSION['flash_error'] = 'All fields are required and School Year must match YYYY-YYYY.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO `teacher_department` (`teacher_id`, `department_id`, `status`, `school_term`, `school_year`) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$teacherId, $deptId, $status, $term, $year]);
            logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Created Affiliation for Teacher ID {$teacherId} in Dept {$deptId}");
            $_SESSION['flash_success'] = 'Faculty affiliation assigned successfully.';
        }
        header("Location: /tams/admin/affiliations.php");
        exit;
    } elseif ($action === 'delete') {
        $id = (int)$_POST['td_id'];
        $pdo->prepare("DELETE FROM `teacher_department` WHERE `td_id` = ?")->execute([$id]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Deleted Affiliation ID {$id}");
        $_SESSION['flash_success'] = 'Affiliation removed.';
        header("Location: /tams/admin/affiliations.php");
        exit;
    }
}

// Filter by term/year
$filterYear = $_GET['year'] ?? $activeSettings['current_school_year'];
$filterTerm = $_GET['term'] ?? $activeSettings['current_school_term'];

$stmtAffils = $pdo->prepare("
    SELECT td.*, t.first_name, t.last_name, d.department_abbreviation, d.department_full_name, c.abbreviation as college_abbr
    FROM `teacher_department` td
    JOIN `teachers` t ON td.teacher_id = t.teacher_id
    JOIN `departments` d ON td.department_id = d.department_id
    JOIN `colleges` c ON d.college_id = c.college_id
    WHERE td.school_year = ? AND td.school_term = ?
    ORDER BY t.last_name ASC
");
$stmtAffils->execute([$filterYear, $filterTerm]);
$affiliations = $stmtAffils->fetchAll();

$teachers = $pdo->query("SELECT teacher_id, last_name, first_name FROM `teachers` ORDER BY last_name ASC")->fetchAll();
$departments = $pdo->query("SELECT department_id, department_abbreviation, department_full_name FROM `departments` ORDER BY department_abbreviation ASC")->fetchAll();

$pageTitle = "Faculty Department Affiliations";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Faculty Department Affiliations</h1>
            <p class="text-sm text-gray-500">Manage teacher employment status and department affiliation per term & academic year.</p>
        </div>
        <button onclick="toggleModal('modalCreateAffil')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm">
            + Assign Teacher to Department
        </button>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
        <form method="GET" class="flex flex-wrap items-center gap-4 text-sm">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase">School Year</label>
                <input type="text" name="year" value="<?= e($filterYear) ?>" class="mt-1 border border-gray-300 rounded px-3 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase">School Term</label>
                <input type="text" name="term" value="<?= e($filterTerm) ?>" class="mt-1 border border-gray-300 rounded px-3 py-1.5 text-sm">
            </div>
            <div class="self-end">
                <button type="submit" class="px-4 py-1.5 bg-gray-800 text-white rounded text-sm hover:bg-gray-700">Filter</button>
            </div>
        </form>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Department & College</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Employment Status</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Academic Period</th>
                    <th class="px-6 py-3 text-right font-semibold text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                <?php if (empty($affiliations)): ?>
                    <tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">No affiliations recorded for this period.</td></tr>
                <?php else: foreach ($affiliations as $a): ?>
                    <tr>
                        <td class="px-6 py-4 font-semibold text-gray-900"><?= e($a['last_name'] . ', ' . $a['first_name']) ?></td>
                        <td class="px-6 py-4">
                            <span class="font-medium text-indigo-700"><?= e($a['department_abbreviation']) ?></span>
                            <span class="text-xs text-gray-500">(<?= e($a['college_abbr']) ?>)</span>
                            <div class="text-xs text-gray-500"><?= e($a['department_full_name']) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold 
                                <?= $a['status'] === 'permanent' ? 'bg-green-100 text-green-800' : ($a['status'] === 'probationary' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800') ?>">
                                <?= ucfirst(e($a['status'])) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-gray-600 font-mono text-xs">
                            S.Y. <?= e($a['school_year']) ?> (<?= e($a['school_term']) ?>)
                        </td>
                        <td class="px-6 py-4 text-right">
                            <form method="POST" class="inline" onsubmit="return confirm('Remove this faculty affiliation?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="delete">
                                <input type="hidden" name="td_id" value="<?= (int)$a['td_id'] ?>">
                                <button type="submit" class="text-xs text-red-600 hover:text-red-900 font-medium underline">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Assign Affiliation -->
<div id="modalCreateAffil" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Assign Teacher Affiliation</h3>
            <button onclick="toggleModal('modalCreateAffil')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="create">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Teacher</label>
                <select name="teacher_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['teacher_id'] ?>"><?= e($t['last_name'] . ', ' . $t['first_name']) ?> (ID: <?= (int)$t['teacher_id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Target Department</label>
                <select name="department_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['department_id'] ?>"><?= e($d['department_abbreviation'] . ' - ' . $d['department_full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Employment Status</label>
                <select name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="permanent">Permanent</option>
                    <option value="probationary">Probationary</option>
                    <option value="part time">Part Time</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">School Year</label>
                    <input type="text" name="school_year" required value="<?= e($activeSettings['current_school_year']) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">School Term</label>
                    <input type="text" name="school_term" required value="<?= e($activeSettings['current_school_term']) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalCreateAffil')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium">Save Affiliation</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
