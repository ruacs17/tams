<?php
// Personnel Management (Attendance Checkers & Monitoring Heads)
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();

$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';

// Handle POST actions (Create, Update, Reset Lockout, Toggle Status, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'create_personnel') {
        $type = $_POST['personnel_type'] ?? '';
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $teacherId = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;

        if (empty($username) || empty($password)) {
            $_SESSION['flash_error'] = 'Username and password are required.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            try {
                if ($type === 'checker') {
                    $stmt = $pdo->prepare("INSERT INTO `attendance_checkers` (`teacher_id`, `username`, `password_hash`, `status`) VALUES (?, ?, ?, 'active')");
                    $stmt->execute([$teacherId, $username, $hash]);
                    logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Created Attendance Checker account: {$username}");
                    $_SESSION['flash_success'] = "Attendance Checker '{$username}' created successfully.";
                } elseif ($type === 'monitoring_head') {
                    $stmt = $pdo->prepare("INSERT INTO `monitoring_heads` (`teacher_id`, `username`, `password_hash`, `status`) VALUES (?, ?, ?, 'active')");
                    $stmt->execute([$teacherId, $username, $hash]);
                    logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Created Monitoring Head account: {$username}");
                    $_SESSION['flash_success'] = "Monitoring Head '{$username}' created successfully.";
                }
                header("Location: /tams/admin/personnel.php");
                exit;
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Database error: Username may already exist.';
            }
        }
    } elseif ($postAction === 'reset_lockout') {
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($type === 'checker') {
            $pdo->prepare("UPDATE `attendance_checkers` SET `failed_attempts` = 0, `lockout_until` = NULL WHERE `checker_id` = ?")->execute([$id]);
        } elseif ($type === 'monitoring_head') {
            $pdo->prepare("UPDATE `monitoring_heads` SET `failed_attempts` = 0, `lockout_until` = NULL WHERE `head_id` = ?")->execute([$id]);
        }
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Reset lockout for {$type} ID {$id}");
        $_SESSION['flash_success'] = "Lockout reset successfully.";
        header("Location: /tams/admin/personnel.php");
        exit;
    } elseif ($postAction === 'toggle_status') {
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
        if ($type === 'checker') {
            $pdo->prepare("UPDATE `attendance_checkers` SET `status` = ? WHERE `checker_id` = ?")->execute([$newStatus, $id]);
        } elseif ($type === 'monitoring_head') {
            $pdo->prepare("UPDATE `monitoring_heads` SET `status` = ? WHERE `head_id` = ?")->execute([$newStatus, $id]);
        }
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Updated status of {$type} ID {$id} to {$newStatus}");
        $_SESSION['flash_success'] = "Account status updated to {$newStatus}.";
        header("Location: /tams/admin/personnel.php");
        exit;
    } elseif ($postAction === 'delete_personnel') {
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($type === 'checker') {
            $pdo->prepare("DELETE FROM `attendance_checkers` WHERE `checker_id` = ?")->execute([$id]);
        } elseif ($type === 'monitoring_head') {
            // Prevent deleting self
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['flash_error'] = "Cannot delete your own active account.";
                header("Location: /tams/admin/personnel.php");
                exit;
            }
            $pdo->prepare("DELETE FROM `monitoring_heads` WHERE `head_id` = ?")->execute([$id]);
        }
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Deleted {$type} record ID {$id}");
        $_SESSION['flash_success'] = "Account deleted.";
        header("Location: /tams/admin/personnel.php");
        exit;
    }
}

// Fetch Checkers
$checkers = $pdo->query("
    SELECT c.*, t.first_name, t.last_name 
    FROM `attendance_checkers` c
    LEFT JOIN `teachers` t ON c.teacher_id = t.teacher_id
    ORDER BY c.checker_id DESC
")->fetchAll();

// Fetch Monitoring Heads
$heads = $pdo->query("
    SELECT h.*, t.first_name, t.last_name 
    FROM `monitoring_heads` h
    LEFT JOIN `teachers` t ON h.teacher_id = t.teacher_id
    ORDER BY h.head_id DESC
")->fetchAll();

// Fetch Teachers list for assignment
$allTeachers = $pdo->query("SELECT teacher_id, last_name, first_name FROM `teachers` ORDER BY last_name ASC")->fetchAll();

$pageTitle = "Personnel Management";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Personnel Account Management</h1>
            <p class="text-sm text-gray-500">Manage credentials, lockouts, and active statuses for Monitoring Heads & Attendance Checkers.</p>
        </div>
        <button onclick="toggleModal('modalCreatePersonnel')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm">
            + Add New Personnel
        </button>
    </div>

    <!-- Attendance Checkers Section -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Attendance Checkers (Operators)</h2>
            <span class="text-xs bg-blue-100 text-blue-800 font-semibold px-2.5 py-0.5 rounded-full"><?= count($checkers) ?> Accounts</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">ID</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Username</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Linked Teacher Profile</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Status</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Failed Logins</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Lockout State</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($checkers)): ?>
                        <tr><td colspan="7" class="px-6 py-4 text-center text-gray-500">No attendance checkers configured.</td></tr>
                    <?php else: foreach ($checkers as $c): 
                        $isLocked = !empty($c['lockout_until']) && strtotime($c['lockout_until']) > time();
                    ?>
                        <tr>
                            <td class="px-6 py-4 font-mono text-xs"><?= (int)$c['checker_id'] ?></td>
                            <td class="px-6 py-4 font-medium text-gray-900"><?= e($c['username']) ?></td>
                            <td class="px-6 py-4 text-gray-600">
                                <?= $c['teacher_id'] ? e($c['first_name'] . ' ' . $c['last_name']) : '<span class="text-gray-400 italic">None</span>' ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 text-xs rounded-full font-semibold <?= $c['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= e($c['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-600"><?= (int)$c['failed_attempts'] ?></td>
                            <td class="px-6 py-4">
                                <?php if ($isLocked): ?>
                                    <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded font-bold">
                                        Locked until <?= date('h:i A', strtotime($c['lockout_until'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-xs text-green-600 font-medium">Unlocked</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <?php if ($isLocked || $c['failed_attempts'] > 0): ?>
                                    <form method="POST" class="inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="post_action" value="reset_lockout">
                                        <input type="hidden" name="type" value="checker">
                                        <input type="hidden" name="id" value="<?= (int)$c['checker_id'] ?>">
                                        <button type="submit" class="text-xs text-amber-600 hover:text-amber-800 font-medium underline">Reset Lockout</button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="toggle_status">
                                    <input type="hidden" name="type" value="checker">
                                    <input type="hidden" name="id" value="<?= (int)$c['checker_id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $c['status'] === 'active' ? 'inactive' : 'active' ?>">
                                    <button type="submit" class="text-xs text-indigo-600 hover:text-indigo-900 font-medium underline">
                                        <?= $c['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>

                                <form method="POST" class="inline" onsubmit="return confirm('Delete this checker permanently?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="delete_personnel">
                                    <input type="hidden" name="type" value="checker">
                                    <input type="hidden" name="id" value="<?= (int)$c['checker_id'] ?>">
                                    <button type="submit" class="text-xs text-red-600 hover:text-red-900 font-medium underline">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Monitoring Office Heads Section -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Monitoring Office Heads (Administrators)</h2>
            <span class="text-xs bg-purple-100 text-purple-800 font-semibold px-2.5 py-0.5 rounded-full"><?= count($heads) ?> Accounts</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">ID</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Username</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Linked Teacher Profile</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Status</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Failed Logins</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Lockout State</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($heads)): ?>
                        <tr><td colspan="7" class="px-6 py-4 text-center text-gray-500">No monitoring heads configured.</td></tr>
                    <?php else: foreach ($heads as $h): 
                        $isLocked = !empty($h['lockout_until']) && strtotime($h['lockout_until']) > time();
                    ?>
                        <tr>
                            <td class="px-6 py-4 font-mono text-xs"><?= (int)$h['head_id'] ?></td>
                            <td class="px-6 py-4 font-medium text-gray-900"><?= e($h['username']) ?></td>
                            <td class="px-6 py-4 text-gray-600">
                                <?= $h['teacher_id'] ? e($h['first_name'] . ' ' . $h['last_name']) : '<span class="text-gray-400 italic">None</span>' ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 text-xs rounded-full font-semibold <?= $h['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= e($h['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-600"><?= (int)$h['failed_attempts'] ?></td>
                            <td class="px-6 py-4">
                                <?php if ($isLocked): ?>
                                    <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded font-bold">
                                        Locked until <?= date('h:i A', strtotime($h['lockout_until'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-xs text-green-600 font-medium">Unlocked</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <?php if ($isLocked || $h['failed_attempts'] > 0): ?>
                                    <form method="POST" class="inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="post_action" value="reset_lockout">
                                        <input type="hidden" name="type" value="monitoring_head">
                                        <input type="hidden" name="id" value="<?= (int)$h['head_id'] ?>">
                                        <button type="submit" class="text-xs text-amber-600 hover:text-amber-800 font-medium underline">Reset Lockout</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ((int)$h['head_id'] !== (int)$_SESSION['user_id']): ?>
                                    <form method="POST" class="inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="post_action" value="toggle_status">
                                        <input type="hidden" name="type" value="monitoring_head">
                                        <input type="hidden" name="id" value="<?= (int)$h['head_id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $h['status'] === 'active' ? 'inactive' : 'active' ?>">
                                        <button type="submit" class="text-xs text-indigo-600 hover:text-indigo-900 font-medium underline">
                                            <?= $h['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>

                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this monitoring head permanently?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="post_action" value="delete_personnel">
                                        <input type="hidden" name="type" value="monitoring_head">
                                        <input type="hidden" name="id" value="<?= (int)$h['head_id'] ?>">
                                        <button type="submit" class="text-xs text-red-600 hover:text-red-900 font-medium underline">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400 italic">Current User</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Add Personnel -->
<div id="modalCreatePersonnel" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Create New Personnel Account</h3>
            <button onclick="toggleModal('modalCreatePersonnel')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="create_personnel">

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Personnel Type</label>
                <select name="personnel_type" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="checker">Attendance Checker (Operator)</option>
                    <option value="monitoring_head">Monitoring Office Head</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Username</label>
                <input type="text" name="username" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm" placeholder="e.g. checker_room101">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Password</label>
                <input type="password" name="password" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm" placeholder="Account password">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Link Teacher Profile (Optional)</label>
                <select name="teacher_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="">-- None (Standalone account) --</option>
                    <?php foreach ($allTeachers as $t): ?>
                        <option value="<?= (int)$t['teacher_id'] ?>"><?= e($t['last_name'] . ', ' . $t['first_name']) ?> (ID: <?= (int)$t['teacher_id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalCreatePersonnel')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium">Create Account</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
