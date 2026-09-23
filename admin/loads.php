<?php
// Teacher Load Management & CSV Bulk Upload with Irregularity Reporting Engine
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';
require_once __DIR__ . '/../includes/attendance_engine.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

$irregularityReport = [];
if (isset($_SESSION['irregularity_report'])) {
    $irregularityReport = $_SESSION['irregularity_report'];
    unset($_SESSION['irregularity_report']);
}

// Handle Sample CSV Download
if (isset($_GET['download_sample']) && $_GET['download_sample'] === 'loads') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teacher_loads_sample_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['teacher_id', 'offer_code', 'subject_name', 'subject_description', 'days', 'time', 'room', 'department_id']);
    fputcsv($out, [4, 'CS101-A', 'CS 101', 'Introduction to Computing', 'MWF', '08:00 am - 09:00 am', 'Lab 301', 1]);
    fputcsv($out, [5, 'CS201-B', 'CS 201', 'Data Structures & Algorithms', 'TTh', '09:00 am - 10:30 am', 'Room 402', 1]);
    fputcsv($out, ['', 'CS-OPEN-1', 'CS 405', 'Cloud Computing Special Topics', '*** O P E N ***', '*** O P E N ***', 'TBA', 1]);
    fputcsv($out, [99999, 'CS-ORPHAN-EX', 'Sample Unlinked Course', 'Tests Irregularity Report', 'MWF', '01:00 pm - 02:00 pm', 'Room 205', 88888]);
    fclose($out);
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'create_load') {
        $offerCode = trim($_POST['offer_code'] ?? '');
        $teacherId = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
        $subjectName = trim($_POST['subject_name'] ?? '');
        $subjectDesc = trim($_POST['subject_description'] ?? '');
        $days = trim($_POST['days'] ?? '');
        $time = trim($_POST['time'] ?? '');
        $room = trim($_POST['room'] ?? '');
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $year = trim($_POST['school_year'] ?? $activeSettings['current_school_year']);
        $term = trim($_POST['school_term'] ?? $activeSettings['current_school_term']);

        if (empty($offerCode) || empty($subjectName)) {
            $_SESSION['flash_error'] = 'Offer code and Subject Name are required.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `teacher_loads` 
                (`offer_code`, `teacher_id`, `subject_name`, `subject_description`, `days`, `time`, `room`, `department_id`, `school_term`, `school_year`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$offerCode, $teacherId, $subjectName, $subjectDesc, $days, $time, $room, $deptId, $term, $year]);
            logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Created Load: {$offerCode} - {$subjectName}");
            $_SESSION['flash_success'] = "Course load {$offerCode} saved successfully.";
        }
        header("Location: /tams/admin/loads.php");
        exit;

    } elseif ($action === 'update_load') {
        $loadId = (int)$_POST['load_id'];
        $offerCode = trim($_POST['offer_code'] ?? '');
        $teacherId = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
        $subjectName = trim($_POST['subject_name'] ?? '');
        $subjectDesc = trim($_POST['subject_description'] ?? '');
        $days = trim($_POST['days'] ?? '');
        $time = trim($_POST['time'] ?? '');
        $room = trim($_POST['room'] ?? '');
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

        $stmt = $pdo->prepare("
            UPDATE `teacher_loads` 
            SET `offer_code` = ?, `teacher_id` = ?, `subject_name` = ?, `subject_description` = ?,
                `days` = ?, `time` = ?, `room` = ?, `department_id` = ?
            WHERE `load_id` = ?
        ");
        $stmt->execute([$offerCode, $teacherId, $subjectName, $subjectDesc, $days, $time, $room, $deptId, $loadId]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Updated Load ID {$loadId}: {$offerCode}");
        $_SESSION['flash_success'] = "Course load {$offerCode} updated.";
        header("Location: /tams/admin/loads.php");
        exit;

    } elseif ($action === 'delete_load') {
        $loadId = (int)$_POST['load_id'];
        $pdo->prepare("DELETE FROM `teacher_loads` WHERE `load_id` = ?")->execute([$loadId]);
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Deleted Load ID {$loadId}");
        $_SESSION['flash_success'] = "Course load removed.";
        header("Location: /tams/admin/loads.php");
        exit;

    } elseif ($action === 'csv_bulk_upload') {
        // Robust CSV Ingestion Engine
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please choose a valid CSV file for upload.';
            header("Location: /tams/admin/loads.php");
            exit;
        }

        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmpFile, 'r');
        if (!$handle) {
            $_SESSION['flash_error'] = 'Failed to read uploaded CSV file.';
            header("Location: /tams/admin/loads.php");
            exit;
        }

        $currentYear = $activeSettings['current_school_year'];
        $currentTerm = $activeSettings['current_school_term'];

        // Preload existing teachers and departments for irregularity checking
        $existingTeachers = $pdo->query("SELECT teacher_id FROM `teachers`")->fetchAll(PDO::FETCH_COLUMN);
        $existingTeacherSet = array_flip($existingTeachers);

        $existingDepts = $pdo->query("SELECT department_id FROM `departments`")->fetchAll(PDO::FETCH_COLUMN);
        $existingDeptSet = array_flip($existingDepts);

        $rowNum = 0;
        $insertedCount = 0;
        $irregularities = [];

        // Ingestion loop
        while (($data = fgetcsv($handle, 2000, ",")) !== false) {
            $rowNum++;
            // Skip empty rows
            if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
                continue;
            }

            // Check if header row
            if ($rowNum === 1 && (strtolower(trim($data[0])) === 'teacher_id' || strtolower(trim($data[1] ?? '')) === 'offer_code')) {
                continue;
            }

            // Expected columns:
            // 0: teacher_id
            // 1: offer_code
            // 2: subject_name
            // 3: subject_description
            // 4: days
            // 5: time
            // 6: room
            // 7: department_id
            $rawTeacherId = trim($data[0] ?? '');
            $offerCode = trim($data[1] ?? '');
            $subjectName = trim($data[2] ?? '');
            $subjectDesc = trim($data[3] ?? '');
            $days = trim($data[4] ?? '*** O P E N ***');
            $time = trim($data[5] ?? '*** O P E N ***');
            $room = trim($data[6] ?? 'TBA');
            $rawDeptId = trim($data[7] ?? '');

            if (empty($offerCode) && empty($subjectName)) {
                continue;
            }

            $teacherId = null;
            $deptId = null;
            $hasIrregularity = false;
            $issueNotes = [];

            // 1. Validate / check teacher_id
            if ($rawTeacherId !== '' && $rawTeacherId !== '0') {
                $parsedTId = (int)$rawTeacherId;
                if (!isset($existingTeacherSet[$parsedTId])) {
                    // Orphaned / unlinked foreign key
                    $hasIrregularity = true;
                    $issueNotes[] = "Unlinked Teacher ID ({$parsedTId}) does not exist in teachers table";
                    $teacherId = null; // Store as null or leave unlinked to avoid FK violation
                } else {
                    $teacherId = $parsedTId;
                }
            }

            // 2. Validate / check department_id
            if ($rawDeptId !== '' && $rawDeptId !== '0') {
                $parsedDId = (int)$rawDeptId;
                if (!isset($existingDeptSet[$parsedDId])) {
                    $hasIrregularity = true;
                    $issueNotes[] = "Unlinked Department ID ({$parsedDId}) does not exist in departments table";
                    $deptId = null;
                } else {
                    $deptId = $parsedDId;
                }
            }

            // 3. Placeholder handling: accept '*** O P E N ***' or empty
            $isOpenSchedule = (strpos($days, 'O P E N') !== false || strpos($time, 'O P E N') !== false);
            if ($isOpenSchedule) {
                $issueNotes[] = "Placeholder schedule marked as '*** O P E N ***'";
            }

            // 4. Insert row into database
            try {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO `teacher_loads`
                    (`offer_code`, `teacher_id`, `subject_name`, `subject_description`, `days`, `time`, `room`, `department_id`, `school_term`, `school_year`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsert->execute([
                    $offerCode,
                    $teacherId,
                    $subjectName,
                    $subjectDesc,
                    $days,
                    $time,
                    $room,
                    $deptId,
                    $currentTerm,
                    $currentYear
                ]);
                $insertedCount++;

                if (!empty($issueNotes)) {
                    $irregularities[] = [
                        'row' => $rowNum,
                        'offer_code' => $offerCode,
                        'subject_name' => $subjectName,
                        'raw_teacher_id' => $rawTeacherId,
                        'raw_dept_id' => $rawDeptId,
                        'issues' => $issueNotes
                    ];
                }
            } catch (PDOException $e) {
                $irregularities[] = [
                    'row' => $rowNum,
                    'offer_code' => $offerCode,
                    'subject_name' => $subjectName,
                    'raw_teacher_id' => $rawTeacherId,
                    'raw_dept_id' => $rawDeptId,
                    'issues' => ['SQL Insertion Error: ' . $e->getMessage()]
                ];
            }
        }
        fclose($handle);

        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "CSV Bulk Ingest: {$insertedCount} loads inserted with " . count($irregularities) . " irregularity notices");

        $_SESSION['flash_success'] = "CSV Bulk Ingestion complete. Successfully ingested {$insertedCount} course load records.";
        if (!empty($irregularities)) {
            $_SESSION['irregularity_report'] = $irregularities;
        }

        header("Location: /tams/admin/loads.php");
        exit;
    }
}

// Fetch all loads for active term/year
$stmtLoads = $pdo->prepare("
    SELECT tl.*, t.first_name, t.last_name, d.department_abbreviation
    FROM `teacher_loads` tl
    LEFT JOIN `teachers` t ON tl.teacher_id = t.teacher_id
    LEFT JOIN `departments` d ON tl.department_id = d.department_id
    WHERE tl.school_year = ? AND tl.school_term = ?
    ORDER BY tl.offer_code ASC
");
$stmtLoads->execute([$activeSettings['current_school_year'], $activeSettings['current_school_term']]);
$loads = $stmtLoads->fetchAll();

$teachers = $pdo->query("SELECT teacher_id, last_name, first_name FROM `teachers` ORDER BY last_name ASC")->fetchAll();
$departments = $pdo->query("SELECT department_id, department_abbreviation, department_full_name FROM `departments` ORDER BY department_abbreviation ASC")->fetchAll();

$pageTitle = "Teacher Loads & CSV Ingestion";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Teacher Load Management & Ingestion</h1>
            <p class="text-sm text-gray-500">
                Active Cycle: <span class="font-semibold text-indigo-700">S.Y. <?= e($activeSettings['current_school_year']) ?> (<?= e($activeSettings['current_school_term']) ?>)</span>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/tams/admin/loads.php?download_sample=loads" class="px-4 py-2 bg-gray-700 hover:bg-gray-800 text-white text-sm font-medium rounded-md shadow-sm flex items-center space-x-1">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                <span>Download Sample CSV Template</span>
            </a>
            <button onclick="toggleModal('modalBulkUpload')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md shadow-sm flex items-center space-x-1">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                <span>CSV Bulk Ingestion</span>
            </button>
            <button onclick="toggleModal('modalCreateLoad')" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm">
                + Add Course Load
            </button>
        </div>
    </div>

    <!-- Post-Upload Irregularity Report Alert / Modal View -->
    <?php if (!empty($irregularityReport)): ?>
    <div class="bg-amber-50 border-l-4 border-amber-500 p-5 rounded-lg shadow-sm">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-base font-bold text-amber-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-amber-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    Post-Upload Irregularity Report (<?= count($irregularityReport) ?> Notices)
                </h3>
                <p class="text-xs text-amber-700 mt-1">
                    The CSV batch was accepted and processed gracefully. However, some rows contained placeholders (e.g. <code>*** O P E N ***</code>) or unlinked foreign keys that require administrative attention.
                </p>
            </div>
            <button onclick="this.closest('.bg-amber-50').remove()" class="text-amber-800 font-bold">&times;</button>
        </div>

        <div class="mt-4 overflow-x-auto max-h-60 bg-white rounded border border-amber-200 p-2">
            <table class="min-w-full text-xs divide-y divide-gray-200">
                <thead class="bg-amber-100">
                    <tr>
                        <th class="px-2 py-1 text-left">CSV Row</th>
                        <th class="px-2 py-1 text-left">Offer Code</th>
                        <th class="px-2 py-1 text-left">Subject</th>
                        <th class="px-2 py-1 text-left">Teacher ID Input</th>
                        <th class="px-2 py-1 text-left">Dept ID Input</th>
                        <th class="px-2 py-1 text-left">Detected Exceptions / Irregularities</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($irregularityReport as $ir): ?>
                        <tr>
                            <td class="px-2 py-1 font-mono font-bold"><?= (int)$ir['row'] ?></td>
                            <td class="px-2 py-1 font-medium"><?= e($ir['offer_code']) ?></td>
                            <td class="px-2 py-1"><?= e($ir['subject_name']) ?></td>
                            <td class="px-2 py-1"><?= e($ir['raw_teacher_id'] ?: 'None') ?></td>
                            <td class="px-2 py-1"><?= e($ir['raw_dept_id'] ?: 'None') ?></td>
                            <td class="px-2 py-1 text-red-600 font-medium">
                                <?= implode('; ', array_map('htmlspecialchars', $ir['issues'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Loads Table -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Assigned Course Offerings & Loads</h2>
            <span class="text-xs bg-indigo-100 text-indigo-800 font-semibold px-2.5 py-0.5 rounded-full"><?= count($loads) ?> Schedules</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Offer Code</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Subject</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Assigned Teacher</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Schedule (Days & Time)</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Room</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Dept</th>
                        <th class="px-4 py-3 text-right font-semibold text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($loads)): ?>
                        <tr><td colspan="7" class="px-6 py-4 text-center text-gray-500">No loads registered for the active term.</td></tr>
                    <?php else: foreach ($loads as $l): 
                        $isOpen = (strpos($l['days'], 'O P E N') !== false || strpos($l['time'], 'O P E N') !== false);
                    ?>
                        <tr class="<?= $isOpen ? 'bg-amber-50' : '' ?>">
                            <td class="px-4 py-3 font-mono font-bold text-indigo-700"><?= e($l['offer_code']) ?></td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900"><?= e($l['subject_name']) ?></div>
                                <div class="text-xs text-gray-500"><?= e($l['subject_description']) ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($l['teacher_id']): ?>
                                    <span class="text-gray-900 font-medium"><?= e($l['last_name'] . ', ' . $l['first_name']) ?></span>
                                <?php else: ?>
                                    <span class="text-xs px-2 py-0.5 rounded bg-red-100 text-red-700 font-semibold">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($isOpen): ?>
                                    <span class="text-xs px-2 py-0.5 rounded bg-amber-200 text-amber-800 font-bold font-mono">*** O P E N ***</span>
                                <?php else: ?>
                                    <span class="font-semibold text-gray-800"><?= e($l['days']) ?></span>
                                    <span class="text-xs text-gray-600 block"><?= e($l['time']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs"><?= e($l['room']) ?></td>
                            <td class="px-4 py-3">
                                <span class="text-xs font-semibold px-2 py-0.5 rounded bg-gray-100 text-gray-700">
                                    <?= e($l['department_abbreviation'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                <button onclick="editLoad(<?= htmlspecialchars(json_encode($l), ENT_QUOTES, 'UTF-8') ?>)" class="text-xs text-indigo-600 hover:text-indigo-900 font-medium underline">
                                    Edit Schedule
                                </button>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this course load?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="delete_load">
                                    <input type="hidden" name="load_id" value="<?= (int)$l['load_id'] ?>">
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

<!-- Modal: CSV Bulk Upload -->
<div id="modalBulkUpload" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">CSV Bulk Load Ingestion Engine</h3>
            <button onclick="toggleModal('modalBulkUpload')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="csv_bulk_upload">

            <div class="p-3 bg-gray-50 border border-gray-200 rounded text-xs text-gray-600 space-y-1">
                <div class="font-bold text-gray-800">CSV Column Specifications:</div>
                <code>teacher_id, offer_code, subject_name, subject_description, days, time, room, department_id</code>
                <div class="pt-1 text-gray-500">
                    Supports <code>*** O P E N ***</code> placeholder schedules and gracefully isolates orphaned foreign keys into the post-upload irregularity report.
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Select CSV File</label>
                <input type="file" name="csv_file" accept=".csv,text/csv" required class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalBulkUpload')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm font-medium">Start CSV Ingestion</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add / Edit Load -->
<div id="modalCreateLoad" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full p-6 max-h-screen overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900" id="loadModalTitle">Add New Course Load</h3>
            <button onclick="toggleModal('modalCreateLoad')" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form method="POST" id="loadForm" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" id="loadPostAction" value="create_load">
            <input type="hidden" name="load_id" id="form_load_id">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Offer Code</label>
                    <input type="text" name="offer_code" id="form_offer_code" required placeholder="e.g. CS101-A" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Subject Code</label>
                    <input type="text" name="subject_name" id="form_subject_name" required placeholder="e.g. CS 101" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Subject Description</label>
                <input type="text" name="subject_description" id="form_subject_desc" placeholder="e.g. Introduction to Computing" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Days</label>
                    <input type="text" name="days" id="form_days" placeholder="MWF / TTh / *** O P E N ***" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Time Interval</label>
                    <input type="text" name="time" id="form_time" placeholder="08:00 am - 09:30 am" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase">Room</label>
                    <input type="text" name="room" id="form_room" placeholder="Lab 301 / TBA" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Assigned Teacher</label>
                <select name="teacher_id" id="form_teacher_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="">-- Unassigned --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['teacher_id'] ?>"><?= e($t['last_name'] . ', ' . $t['first_name']) ?> (ID: <?= (int)$t['teacher_id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 uppercase">Department</label>
                <select name="department_id" id="form_dept_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm border p-2 text-sm">
                    <option value="">-- None --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['department_id'] ?>"><?= e($d['department_abbreviation'] . ' - ' . $d['department_full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-4 flex justify-end space-x-2 border-t border-gray-200">
                <button type="button" onclick="toggleModal('modalCreateLoad')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium">Save Course Load</button>
            </div>
        </form>
    </div>
</div>

<script>
function editLoad(load) {
    document.getElementById('loadModalTitle').innerText = 'Edit Course Load Schedule';
    document.getElementById('loadPostAction').value = 'update_load';
    document.getElementById('form_load_id').value = load.load_id;
    document.getElementById('form_offer_code').value = load.offer_code;
    document.getElementById('form_subject_name').value = load.subject_name;
    document.getElementById('form_subject_desc').value = load.subject_description || '';
    document.getElementById('form_days').value = load.days;
    document.getElementById('form_time').value = load.time;
    document.getElementById('form_room').value = load.room;
    document.getElementById('form_teacher_id').value = load.teacher_id || '';
    document.getElementById('form_dept_id').value = load.department_id || '';
    toggleModal('modalCreateLoad');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
