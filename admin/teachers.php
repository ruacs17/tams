<?php
// Faculty Teachers Management & CSV Bulk Ingestion Engine
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();

// 1. Handle Sample CSV Download
if (isset($_GET['download_sample']) && $_GET['download_sample'] === 'teachers') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teachers_sample_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['teacher_id', 'last_name', 'first_name', 'username', 'password']);
    fputcsv($out, ['', 'Knuth', 'Donald', 'dknuth', 'Password123!']);
    fputcsv($out, ['', 'Ritchie', 'Dennis', 'dritchie', 'Password123!']);
    fputcsv($out, ['', 'Thompson', 'Ken', 'kthompson', 'Password123!']);
    fputcsv($out, ['', 'Stallman', 'Richard', 'rms', 'Password123!']);
    fclose($out);
    exit;
}

$teacherIrregularities = [];
if (isset($_SESSION['teacher_irregularities'])) {
    $teacherIrregularities = $_SESSION['teacher_irregularities'];
    unset($_SESSION['teacher_irregularities']);
}

// 2. Handle Form Submissions (Create, Update, Delete, CSV Bulk Upload)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'create_teacher') {
        $lastName = trim($_POST['last_name'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (empty($lastName) || empty($firstName)) {
            $_SESSION['flash_error'] = 'Last name and first name are required.';
        } else {
            try {
                // Step 1: Insert with a temporary placeholder username to get the auto-increment ID
                $stmt = $pdo->prepare("INSERT INTO `teachers` (`last_name`, `first_name`, `username`, `password_hash`, `status`, `must_change_password`) VALUES (?, ?, '__tmp__', '__tmp__', ?, 1)");
                $stmt->execute([$lastName, $firstName, $status]);
                $newId = (int)$pdo->lastInsertId();

                // Step 2: Set username = teacher_id and password = hash(teacher_id)
                $idStr = (string)$newId;
                $hash = password_hash($idStr, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE `teachers` SET `username` = ?, `password_hash` = ? WHERE `teacher_id` = ?")
                    ->execute([$idStr, $hash, $newId]);

                logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Created Teacher: {$lastName}, {$firstName} (ID: {$newId}, Username: {$idStr})");
                $_SESSION['flash_success'] = "Teacher {$firstName} {$lastName} created. Username & temporary password: <strong>{$idStr}</strong>. They will be forced to change password on first login.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Database error: " . $e->getMessage();
            }
        }
        header("Location: /tams/admin/teachers.php");
        exit;

    } elseif ($action === 'update_teacher') {
        $teacherId = (int)$_POST['teacher_id'];
        $lastName = trim($_POST['last_name'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $newPassword = $_POST['new_password'] ?? '';

        if (!empty($newPassword)) {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE `teachers` SET `last_name` = ?, `first_name` = ?, `username` = ?, `status` = ?, `password_hash` = ? WHERE `teacher_id` = ?");
            $stmt->execute([$lastName, $firstName, $username, $status, $hash, $teacherId]);
        } else {
            $stmt = $pdo->prepare("UPDATE `teachers` SET `last_name` = ?, `first_name` = ?, `username` = ?, `status` = ? WHERE `teacher_id` = ?");
            $stmt->execute([$lastName, $firstName, $username, $status, $teacherId]);
        }
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Updated Teacher ID {$teacherId}");
        $_SESSION['flash_success'] = "Teacher profile updated.";
        header("Location: /tams/admin/teachers.php");
        exit;

    } elseif ($action === 'delete_teacher') {
        $teacherId = (int)$_POST['teacher_id'];
        $pdo->prepare("DELETE FROM `teachers` WHERE `teacher_id` = ?")->execute([$teacherId]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Deleted Teacher ID {$teacherId}");
        $_SESSION['flash_success'] = "Teacher record removed.";
        header("Location: /tams/admin/teachers.php");
        exit;

    } elseif ($action === 'csv_bulk_teachers') {
        // Teacher CSV Ingestion Engine
        if (!isset($_FILES['teacher_csv_file']) || $_FILES['teacher_csv_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please select a valid CSV file for upload.';
            header("Location: /tams/admin/teachers.php");
            exit;
        }

        $tmpPath = $_FILES['teacher_csv_file']['tmp_name'];
        $handle = fopen($tmpPath, 'r');
        if (!$handle) {
            $_SESSION['flash_error'] = 'Could not read uploaded CSV file.';
            header("Location: /tams/admin/teachers.php");
            exit;
        }

        $rowNum = 0;
        $importedCount = 0;
        $irregularities = [];

        // Preload existing usernames
        $existingUsers = $pdo->query("SELECT username FROM `teachers` WHERE username IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $existingUserSet = array_flip($existingUsers);

        while (($data = fgetcsv($handle, 2000, ",")) !== false) {
            $rowNum++;
            if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
                continue;
            }

            // Skip header row
            if ($rowNum === 1 && (strtolower(trim($data[0])) === 'teacher_id' || strtolower(trim($data[1] ?? '')) === 'last_name')) {
                continue;
            }

            // Expected columns:
            // 0: teacher_id (optional)
            // 1: last_name
            // 2: first_name
            // 3: username (optional)
            // 4: password (optional, default: Password123!)
            $rawId = trim($data[0] ?? '');
            $lastName = trim($data[1] ?? '');
            $firstName = trim($data[2] ?? '');
            $rawUser = trim($data[3] ?? '');
            $rawPass = trim($data[4] ?? 'Password123!');

            if (empty($lastName) && empty($firstName)) {
                continue;
            }

            if (empty($rawUser)) {
                $rawUser = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', substr($firstName, 0, 1) . $lastName));
                // Disambiguate if taken
                $base = $rawUser;
                $counter = 1;
                while (isset($existingUserSet[$rawUser])) {
                    $rawUser = $base . $counter++;
                }
            } elseif (isset($existingUserSet[$rawUser])) {
                $irregularities[] = [
                    'row' => $rowNum,
                    'name' => "{$lastName}, {$firstName}",
                    'username' => $rawUser,
                    'issue' => "Username '{$rawUser}' already exists. A disambiguated suffix was applied."
                ];
                $rawUser = $rawUser . '_' . rand(100, 999);
            }

            $existingUserSet[$rawUser] = true;
            $hash = password_hash($rawPass ?: 'Password123!', PASSWORD_BCRYPT);

            try {
                if (!empty($rawId) && is_numeric($rawId)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO `teachers` (`teacher_id`, `last_name`, `first_name`, `username`, `password_hash`, `status`)
                        VALUES (?, ?, ?, ?, ?, 'active')
                        ON DUPLICATE KEY UPDATE
                            `last_name` = VALUES(`last_name`),
                            `first_name` = VALUES(`first_name`),
                            `username` = VALUES(`username`)
                    ");
                    $stmt->execute([(int)$rawId, $lastName, $firstName, $rawUser, $hash]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO `teachers` (`last_name`, `first_name`, `username`, `password_hash`, `status`)
                        VALUES (?, ?, ?, ?, 'active')
                    ");
                    $stmt->execute([$lastName, $firstName, $rawUser, $hash]);
                }
                $importedCount++;
            } catch (PDOException $e) {
                $irregularities[] = [
                    'row' => $rowNum,
                    'name' => "{$lastName}, {$firstName}",
                    'username' => $rawUser,
                    'issue' => "Database insertion error: " . $e->getMessage()
                ];
            }
        }
        fclose($handle);

        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Teacher CSV Bulk Ingest: {$importedCount} faculty ingested with " . count($irregularities) . " notices");

        $_SESSION['flash_success'] = "Teacher CSV Bulk Ingestion complete. Successfully ingested {$importedCount} teacher records.";
        if (!empty($irregularities)) {
            $_SESSION['teacher_irregularities'] = $irregularities;
        }

        header("Location: /tams/admin/teachers.php");
        exit;
    }
}

// Fetch all teachers with department affiliations
$teachers = $pdo->query("
    SELECT t.*,
           GROUP_CONCAT(DISTINCT d.department_abbreviation SEPARATOR ', ') as depts
    FROM `teachers` t
    LEFT JOIN `teacher_department` td ON t.teacher_id = td.teacher_id
    LEFT JOIN `departments` d ON td.department_id = d.department_id
    GROUP BY t.teacher_id
    ORDER BY t.last_name ASC
")->fetchAll();

$pageTitle = "Faculty Teachers & CSV Ingestion";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Faculty Teachers & CSV Bulk Ingestion</h1>
            <p class="text-sm text-gray-500">
                Manage faculty instructor profiles, credentials, and perform bulk CSV imports.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/tams/admin/teachers.php?download_sample=teachers" class="px-4 py-2 bg-gray-700 hover:bg-gray-800 text-white text-sm font-medium rounded-md shadow-sm flex items-center space-x-1">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                <span>Download Sample CSV Template</span>
            </a>
            <button onclick="toggleModal('modalBulkTeacherUpload')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md shadow-sm flex items-center space-x-1">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                <span>CSV Bulk Ingestion</span>
            </button>
            <button onclick="toggleModal('modalCreateTeacher')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm">
                + Add New Teacher
            </button>
        </div>
    </div>

    <!-- Teacher Ingestion Irregularities Alert -->
    <?php if (!empty($teacherIrregularities)): ?>
    <div class="bg-amber-50 border-l-4 border-amber-500 p-5 rounded-lg shadow-sm">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-base font-bold text-amber-900">
                    Teacher Upload Notices & Disambiguations (<?= count($teacherIrregularities) ?> Notices)
                </h3>
                <p class="text-xs text-amber-700 mt-1">
                    Some rows required username disambiguation or were formatted with existing data.
                </p>
            </div>
            <button onclick="this.closest('.bg-amber-50').remove()" class="text-amber-800 font-bold">&times;</button>
        </div>
        <div class="mt-3 overflow-x-auto max-h-48 bg-white rounded border border-amber-200 p-2 text-xs">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-amber-100 font-semibold text-amber-900">
                    <tr>
                        <th class="px-2 py-1 text-left">CSV Row</th>
                        <th class="px-2 py-1 text-left">Faculty Name</th>
                        <th class="px-2 py-1 text-left">Username Assigned</th>
                        <th class="px-2 py-1 text-left">Notice / Resolution</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($teacherIrregularities as $ti): ?>
                        <tr>
                            <td class="px-2 py-1 font-mono"><?= (int)$ti['row'] ?></td>
                            <td class="px-2 py-1 font-medium"><?= e($ti['name']) ?></td>
                            <td class="px-2 py-1 font-mono text-indigo-700"><?= e($ti['username']) ?></td>
                            <td class="px-2 py-1 text-amber-700"><?= e($ti['issue']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Teachers Table -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Faculty Teacher Directory</h2>
            <span class="text-xs bg-indigo-100 text-indigo-800 font-semibold px-2.5 py-0.5 rounded-full"><?= count($teachers) ?> Registered Teachers</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">ID</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Full Name</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Portal Username</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Affiliated Departments</th>
                        <th class="px-6 py-3 text-left font-semibold text-gray-600">Status</th>
                        <th class="px-6 py-3 text-right font-semibold text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($teachers)): ?>
                        <tr><td colspan="6" class="px-6 py-6 text-center text-gray-500">No teachers registered in the system.</td></tr>
                    <?php else: foreach ($teachers as $t): ?>
                        <tr>
                            <td class="px-6 py-4 font-mono text-xs text-gray-500"><?= (int)$t['teacher_id'] ?></td>
                            <td class="px-6 py-4 font-bold text-gray-900"><?= e($t['last_name'] . ', ' . $t['first_name']) ?></td>
                            <td class="px-6 py-4 font-mono text-xs text-indigo-700 font-semibold"><?= e($t['username'] ?: 'None') ?></td>
                            <td class="px-6 py-4 text-xs text-gray-600"><?= e($t['depts'] ?: 'None assigned') ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 text-xs rounded-full font-semibold <?= $t['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= e($t['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$t['teacher_id'] ?>" class="text-xs text-indigo-600 hover:text-indigo-900 font-medium underline mr-1">
                                    Logs
                                </a>
                                <button onclick="editTeacher(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>)" class="text-xs text-blue-600 hover:text-blue-900 font-medium underline">
                                    Edit
                                </button>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this teacher profile permanently?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="delete_teacher">
                                    <input type="hidden" name="teacher_id" value="<?= (int)$t['teacher_id'] ?>">
                                    <button type="submit" class="text-xs text-red-600 hover:text-red-900 font-medium underline">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: CSV Bulk Teacher Upload -->
<div id="modalBulkTeacherUpload" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">CSV Bulk Teacher Ingestion</h3>
            <button onclick="toggleModal('modalBulkTeacherUpload')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="csv_bulk_teachers">

            <div class="p-3 bg-gray-50 border border-gray-200 rounded text-xs text-gray-600 space-y-1">
                <div class="font-bold text-gray-800">CSV Header & Column Specification:</div>
                <code>teacher_id, last_name, first_name, username, password</code>
                <div class="pt-1 text-gray-500">
                    If <code>username</code> is omitted, it is automatically derived from name. If <code>password</code> is omitted, default <code>Password123!</code> is used.
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Select CSV File</label>
                <input type="file" name="teacher_csv_file" accept=".csv,text/csv" required
                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <div class="pt-4 flex justify-between items-center border-t border-gray-200">
                <a href="/tams/admin/teachers.php?download_sample=teachers" class="text-xs text-indigo-600 hover:underline">Download Template</a>
                <div class="space-x-2">
                    <button type="button" onclick="toggleModal('modalBulkTeacherUpload')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm font-semibold">Start CSV Ingestion</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Create Teacher -->
<div id="modalCreateTeacher" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add New Faculty Teacher</h3>
            <button onclick="toggleModal('modalCreateTeacher')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="create_teacher">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">First Name</label>
                    <input type="text" name="first_name" required placeholder="e.g. Donald" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Last Name</label>
                    <input type="text" name="last_name" required placeholder="e.g. Knuth" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Username (Optional - auto generated if empty)</label>
                <input type="text" name="username" placeholder="e.g. dknuth" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Password (Default: Password123!)</label>
                <input type="password" name="password" placeholder="Password123!" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Status</label>
                <select name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalCreateTeacher')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold">Save Teacher</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Teacher -->
<div id="modalEditTeacher" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit Faculty Profile</h3>
            <button onclick="toggleModal('modalEditTeacher')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="update_teacher">
            <input type="hidden" name="teacher_id" id="editTeacherId">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">First Name</label>
                    <input type="text" name="first_name" id="editFirstName" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Last Name</label>
                    <input type="text" name="last_name" id="editLastName" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Username</label>
                <input type="text" name="username" id="editUsername" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Change Password (Leave blank to keep current)</label>
                <input type="password" name="new_password" placeholder="New password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Status</label>
                <select name="status" id="editStatus" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalEditTeacher')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-semibold">Update Profile</button>
            </div>
        </form>
    </div>
</div>

<script>
function editTeacher(t) {
    document.getElementById('editTeacherId').value = t.teacher_id;
    document.getElementById('editFirstName').value = t.first_name;
    document.getElementById('editLastName').value = t.last_name;
    document.getElementById('editUsername').value = t.username || '';
    document.getElementById('editStatus').value = t.status || 'active';
    toggleModal('modalEditTeacher');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
