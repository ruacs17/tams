<?php
// College Dean Historical Archives
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['dean']);
$pdo = getDBConnection();
$deanTeacherId = (int)$_SESSION['teacher_id'];

// Get College headed
$stmtCol = $pdo->prepare("SELECT * FROM `colleges` WHERE `dean_id` = ? LIMIT 1");
$stmtCol->execute([$deanTeacherId]);
$college = $stmtCol->fetch();
$collegeId = $college ? (int)$college['college_id'] : 0;

$filterYear = $_GET['year'] ?? '2025-2026';
$filterTerm = $_GET['term'] ?? '1st Term';

// Fetch teachers affiliated with departments under this college in that historical year/term
$stmtFaculty = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name, d.department_abbreviation,
           COUNT(al.log_id) as total_logs,
           ap.accumulated_lates_count, ap.converted_absents_count
    FROM `teacher_department` td
    JOIN `teachers` t ON td.teacher_id = t.teacher_id
    JOIN `departments` d ON td.department_id = d.department_id
    LEFT JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id 
         AND al.school_year = td.school_year AND al.school_term = td.school_term
    LEFT JOIN `attendance_penalties` ap ON t.teacher_id = ap.teacher_id 
         AND ap.school_year = td.school_year AND ap.school_term = td.school_term
    WHERE d.college_id = ? AND td.school_year = ? AND td.school_term = ?
    GROUP BY t.teacher_id
    ORDER BY d.department_abbreviation ASC, t.last_name ASC
");
$stmtFaculty->execute([$collegeId, $filterYear, $filterTerm]);
$facultyRecords = $stmtFaculty->fetchAll();

$pageTitle = "Dean - College Archives";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Historical College Attendance Archives</h1>
            <p class="text-sm text-gray-500">
                College: <span class="font-bold text-indigo-700"><?= e($college['abbreviation']) ?></span> &bull; 
                Governed by strict historical college structural mapping.
            </p>
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <div>
                <label class="text-xs font-semibold text-gray-600 uppercase">Year:</label>
                <input type="text" name="year" value="<?= e($filterYear) ?>" class="border border-gray-300 rounded px-2.5 py-1 text-xs font-mono w-28">
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-600 uppercase">Term:</label>
                <select name="term" class="border border-gray-300 rounded px-2.5 py-1 text-xs">
                    <option value="1st Term" <?= $filterTerm === '1st Term' ? 'selected' : '' ?>>1st Term</option>
                    <option value="2nd Term" <?= $filterTerm === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
                    <option value="Summer" <?= $filterTerm === 'Summer' ? 'selected' : '' ?>>Summer</option>
                </select>
            </div>
            <button type="submit" class="px-3 py-1 bg-gray-800 text-white rounded text-xs font-semibold hover:bg-gray-700">Filter</button>
        </form>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Historical Records for S.Y. <?= e($filterYear) ?> (<?= e($filterTerm) ?>)</h2>
            <span class="text-xs bg-gray-200 text-gray-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($facultyRecords) ?> Faculty</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3 text-left">Faculty Teacher</th>
                        <th class="px-6 py-3 text-left">Department</th>
                        <th class="px-6 py-3 text-left">Total Logs</th>
                        <th class="px-6 py-3 text-left">Accumulated Lates</th>
                        <th class="px-6 py-3 text-left">Converted Absents</th>
                        <th class="px-6 py-3 text-right">Archival Logs</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($facultyRecords)): ?>
                        <tr><td colspan="6" class="px-6 py-6 text-center text-gray-500">No historical records found for this period under your college.</td></tr>
                    <?php else: foreach ($facultyRecords as $fr): ?>
                        <tr>
                            <td class="px-6 py-4 font-bold text-gray-900"><?= e($fr['last_name'] . ', ' . $fr['first_name']) ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-700">
                                    <?= e($fr['department_abbreviation']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 font-semibold text-gray-800"><?= (int)$fr['total_logs'] ?></td>
                            <td class="px-6 py-4 font-mono text-yellow-700 font-bold"><?= (int)$fr['accumulated_lates_count'] ?> Lates</td>
                            <td class="px-6 py-4 font-mono text-red-700 font-bold"><?= (int)$fr['converted_absents_count'] ?> Absents</td>
                            <td class="px-6 py-4 text-right">
                                <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$fr['teacher_id'] ?>&year=<?= urlencode($filterYear) ?>&term=<?= urlencode($filterTerm) ?>" 
                                   class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold underline">
                                    View Archival Logs &rarr;
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
