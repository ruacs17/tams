<?php
// Colleges Management & Dean Assignment
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
        $abbr = trim($_POST['abbreviation'] ?? '');
        $name = trim($_POST['full_name'] ?? '');
        $deanId = !empty($_POST['dean_id']) ? (int)$_POST['dean_id'] : null;

        if (empty($abbr) || empty($name)) {
            $_SESSION['flash_error'] = 'Abbreviation and full college name are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO `colleges` (`abbreviation`, `full_name`, `dean_id`) VALUES (?, ?, ?)");
                $stmt->execute([$abbr, $name, $deanId]);
                logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Created College: {$abbr} - {$name}");
                $_SESSION['flash_success'] = "College '{$abbr}' created successfully.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Error: College abbreviation may already exist.';
            }
        }
        header("Location: /tams/admin/colleges.php");
        exit;
    } elseif ($action === 'update') {
        $id = (int)($_POST['college_id'] ?? 0);
        $abbr = trim($_POST['abbreviation'] ?? '');
        $name = trim($_POST['full_name'] ?? '');
        $deanId = !empty($_POST['dean_id']) ? (int)$_POST['dean_id'] : null;

        $stmt = $pdo->prepare("UPDATE `colleges` SET `abbreviation` = ?, `full_name` = ?, `dean_id` = ? WHERE `college_id` = ?");
        $stmt->execute([$abbr, $name, $deanId, $id]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Updated College ID {$id}: {$abbr}");
        $_SESSION['flash_success'] = "College updated.";
        header("Location: /tams/admin/colleges.php");
        exit;
    } elseif ($action === 'delete') {
        $id = (int)($_POST['college_id'] ?? 0);
        $pdo->prepare("DELETE FROM `colleges` WHERE `college_id` = ?")->execute([$id]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Deleted College ID {$id}");
        $_SESSION['flash_success'] = "College deleted.";
        header("Location: /tams/admin/colleges.php");
        exit;
    }
}

// Fetch all colleges with Dean names
$colleges = $pdo->query("
    SELECT c.*, t.first_name as dean_first, t.last_name as dean_last
    FROM `colleges` c
    LEFT JOIN `teachers` t ON c.dean_id = t.teacher_id
    ORDER BY c.abbreviation ASC
")->fetchAll();

// Fetch Teachers for Dean assignment
$teachers = $pdo->query("SELECT teacher_id, last_name, first_name FROM `teachers` ORDER BY last_name ASC")->fetchAll();

$pageTitle = "Colleges & Dean Assignments";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Colleges & Dean Assignments</h1>
            <p class="text-sm text-gray-500">Manage academic colleges and assign Faculty Deans.</p>
        </div>
        <button onclick="toggleModal('modalCreateCollege')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm">
            + Add New College
        </button>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Abbreviation</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Full Name</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Assigned College Dean</th>
                    <th class="px-6 py-3 text-right font-semibold text-gray-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                <?php if (empty($colleges)): ?>
                    <tr><td colspan="4" class="px-6 py-4 text-center text-gray-500">No colleges registered.</td></tr>
                <?php else: foreach ($colleges as $c): ?>
                    <tr>
                        <td class="px-6 py-4 font-bold text-indigo-700"><?= e($c['abbreviation']) ?></td>
                        <td class="px-6 py-4 text-gray-900 font-medium"><?= e($c['full_name']) ?></td>
                        <td class="px-6 py-4 text-gray-700">
                            <?php if ($c['dean_id']): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                    Dean: <?= e($c['dean_first'] . ' ' . $c['dean_last']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-gray-400 italic text-xs">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right space-x-2">
                            <button onclick="editCollege(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)" class="text-xs text-indigo-600 hover:text-indigo-900 font-medium underline">
                                Edit / Assign Dean
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirm('Delete this college and associated departments?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="delete">
                                <input type="hidden" name="college_id" value="<?= (int)$c['college_id'] ?>">
                                <button type="submit" class="text-xs text-red-600 hover:text-red-900 font-medium underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Create College -->
<div id="modalCreateCollege" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add New College</h3>
            <button onclick="toggleModal('modalCreateCollege')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="create">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Abbreviation</label>
                <input type="text" name="abbreviation" required placeholder="e.g. CET" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">College Full Name</label>
                <input type="text" name="full_name" required placeholder="e.g. College of Engineering and Technology" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Assign Dean</label>
                <select name="dean_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="">-- Select Faculty Dean (Optional) --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['teacher_id'] ?>"><?= e($t['last_name'] . ', ' . $t['first_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalCreateCollege')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium">Create College</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit College -->
<div id="modalEditCollege" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit College & Dean</h3>
            <button onclick="toggleModal('modalEditCollege')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="update">
            <input type="hidden" name="college_id" id="edit_college_id">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Abbreviation</label>
                <input type="text" name="abbreviation" id="edit_abbreviation" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">College Full Name</label>
                <input type="text" name="full_name" id="edit_full_name" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Assign Dean</label>
                <select name="dean_id" id="edit_dean_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="">-- None (Unassigned) --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['teacher_id'] ?>"><?= e($t['last_name'] . ', ' . $t['first_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalEditCollege')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCollege(college) {
    document.getElementById('edit_college_id').value = college.college_id;
    document.getElementById('edit_abbreviation').value = college.abbreviation;
    document.getElementById('edit_full_name').value = college.full_name;
    document.getElementById('edit_dean_id').value = college.dean_id || '';
    toggleModal('modalEditCollege');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
