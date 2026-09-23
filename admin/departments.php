<?php
// Departments Management & Chairperson Assignment
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'create') {
        $collegeId = (int)$_POST['college_id'];
        $abbr = trim($_POST['department_abbreviation'] ?? '');
        $name = trim($_POST['department_full_name'] ?? '');
        $chairId = !empty($_POST['chairperson_id']) ? (int)$_POST['chairperson_id'] : null;

        if (empty($abbr) || empty($name) || $collegeId <= 0) {
            $_SESSION['flash_error'] = 'College, abbreviation, and department name are required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO `departments` (`college_id`, `department_abbreviation`, `department_full_name`, `chairperson_id`) VALUES (?, ?, ?, ?)");
            $stmt->execute([$collegeId, $abbr, $name, $chairId]);
            logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Created Department: {$abbr} - {$name}");
            $_SESSION['flash_success'] = "Department '{$abbr}' created successfully.";
        }
        header("Location: /tams/admin/departments.php");
        exit;
    } elseif ($action === 'update') {
        $id = (int)$_POST['department_id'];
        $collegeId = (int)$_POST['college_id'];
        $abbr = trim($_POST['department_abbreviation'] ?? '');
        $name = trim($_POST['department_full_name'] ?? '');
        $chairId = !empty($_POST['chairperson_id']) ? (int)$_POST['chairperson_id'] : null;

        $stmt = $pdo->prepare("UPDATE `departments` SET `college_id` = ?, `department_abbreviation` = ?, `department_full_name` = ?, `chairperson_id` = ? WHERE `department_id` = ?");
        $stmt->execute([$collegeId, $abbr, $name, $chairId, $id]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Updated Department ID {$id}: {$abbr}");
        $_SESSION['flash_success'] = "Department updated.";
        header("Location: /tams/admin/departments.php");
        exit;
    } elseif ($action === 'delete') {
        $id = (int)$_POST['department_id'];
        $pdo->prepare("DELETE FROM `departments` WHERE `department_id` = ?")->execute([$id]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Deleted Department ID {$id}");
        $_SESSION['flash_success'] = "Department deleted.";
        header("Location: /tams/admin/departments.php");
        exit;
    }
}

// Fetch all departments with College & Chairperson info
$departments = $pdo->query("
    SELECT d.*, c.abbreviation as college_abbr, c.full_name as college_name,
           t.first_name as chair_first, t.last_name as chair_last
    FROM `departments` d
    JOIN `colleges` c ON d.college_id = c.college_id
    LEFT JOIN `teachers` t ON d.chairperson_id = t.teacher_id
    ORDER BY c.abbreviation ASC, d.department_abbreviation ASC
")->fetchAll();

$colleges = $pdo->query("SELECT college_id, abbreviation, full_name FROM `colleges` ORDER BY abbreviation ASC")->fetchAll();
$teachers = $pdo->query("SELECT teacher_id, last_name, first_name FROM `teachers` ORDER BY last_name ASC")->fetchAll();

$pageTitle = "Departments & Chairperson Assignments";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Departments & Chairperson Assignments</h1>
            <p class="text-sm text-gray-500">Manage academic departments linked to colleges and assign Department Chairpersons.</p>
        </div>
        <button onclick="toggleModal('modalCreateDept')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm">
            + Add New Department
        </button>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Department</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Parent College</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Assigned Chairperson</th>
                    <th class="px-6 py-3 text-right font-semibold text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                <?php if (empty($departments)): ?>
                    <tr><td colspan="4" class="px-6 py-4 text-center text-gray-500">No departments configured.</td></tr>
                <?php else: foreach ($departments as $d): ?>
                    <tr>
                        <td class="px-6 py-4">
                            <div class="font-bold text-indigo-700"><?= e($d['department_abbreviation']) ?></div>
                            <div class="text-xs text-gray-500"><?= e($d['department_full_name']) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-800">
                                <?= e($d['college_abbr']) ?>
                            </span>
                            <div class="text-xs text-gray-500 mt-0.5"><?= e($d['college_name']) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($d['chairperson_id']): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                    Chair: <?= e($d['chair_first'] . ' ' . $d['chair_last']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-gray-400 italic text-xs">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right space-x-2">
                            <button onclick="editDept(<?= htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8') ?>)" class="text-xs text-indigo-600 hover:text-indigo-900 font-medium underline">
                                Edit / Assign Chair
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this department?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="delete">
                                <input type="hidden" name="department_id" value="<?= (int)$d['department_id'] ?>">
                                <button type="submit" class="text-xs text-red-600 hover:text-red-900 font-medium underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Create Department -->
<div id="modalCreateDept" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add New Department</h3>
            <button onclick="toggleModal('modalCreateDept')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="create">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Parent College</label>
                <select name="college_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <?php foreach ($colleges as $col): ?>
                        <option value="<?= (int)$col['college_id'] ?>"><?= e($col['abbreviation'] . ' - ' . $col['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Department Abbreviation</label>
                <input type="text" name="department_abbreviation" required placeholder="e.g. CS" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Full Department Name</label>
                <input type="text" name="department_full_name" required placeholder="e.g. Department of Computer Science" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Assign Chairperson</label>
                <select name="chairperson_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="">-- None (Unassigned) --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['teacher_id'] ?>"><?= e($t['last_name'] . ', ' . $t['first_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalCreateDept')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium">Create Department</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Department -->
<div id="modalEditDept" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit Department & Chairperson</h3>
            <button onclick="toggleModal('modalEditDept')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="update">
            <input type="hidden" name="department_id" id="edit_department_id">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Parent College</label>
                <select name="college_id" id="edit_college_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <?php foreach ($colleges as $col): ?>
                        <option value="<?= (int)$col['college_id'] ?>"><?= e($col['abbreviation'] . ' - ' . $col['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Department Abbreviation</label>
                <input type="text" name="department_abbreviation" id="edit_department_abbreviation" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Full Department Name</label>
                <input type="text" name="department_full_name" id="edit_department_full_name" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Assign Chairperson</label>
                <select name="chairperson_id" id="edit_chairperson_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="">-- None (Unassigned) --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['teacher_id'] ?>"><?= e($t['last_name'] . ', ' . $t['first_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalEditDept')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editDept(dept) {
    document.getElementById('edit_department_id').value = dept.department_id;
    document.getElementById('edit_college_id').value = dept.college_id;
    document.getElementById('edit_department_abbreviation').value = dept.department_abbreviation;
    document.getElementById('edit_department_full_name').value = dept.department_full_name;
    document.getElementById('edit_chairperson_id').value = dept.chairperson_id || '';
    toggleModal('modalEditDept');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
