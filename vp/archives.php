<?php
// Vice President for Academics Full Institutional Archives
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

requireAuth(['vp']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

$filterYear = $_GET['year'] ?? $activeSettings['current_school_year'];
$filterTerm = $_GET['term'] ?? $activeSettings['current_school_term'];
$filterCollege = !empty($_GET['college_id']) ? (int)$_GET['college_id'] : 0;

$whereClauses = ["td.school_year = ?", "td.school_term = ?"];
$params = [$filterYear, $filterTerm];

if ($filterCollege > 0) {
    $whereClauses[] = "d.college_id = ?";
    $params[] = $filterCollege;
}

$whereSql = implode(' AND ', $whereClauses);

// Fetch all institutional faculty reports matching filters
$stmtFaculty = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name, 
           d.department_abbreviation, c.abbreviation as college_abbr,
           COUNT(al.log_id) as total_logs,
           SUM(CASE WHEN al.status = 'Late' THEN 1 ELSE 0 END) as lates,
           SUM(CASE WHEN al.status = 'Absent' THEN 1 ELSE 0 END) as absents,
           ap.accumulated_lates_count, ap.converted_absents_count,
           MAX(al.workflow_status) as current_status
    FROM `teacher_department` td
    JOIN `teachers` t ON td.teacher_id = t.teacher_id
    JOIN `departments` d ON td.department_id = d.department_id
    JOIN `colleges` c ON d.college_id = c.college_id
    LEFT JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id 
         AND al.school_year = td.school_year AND al.school_term = td.school_term
    LEFT JOIN `attendance_penalties` ap ON t.teacher_id = ap.teacher_id 
         AND ap.school_year = td.school_year AND ap.school_term = td.school_term
    WHERE {$whereSql}
    GROUP BY t.teacher_id, d.department_abbreviation, c.abbreviation
    ORDER BY c.abbreviation ASC, d.department_abbreviation ASC, t.last_name ASC
");
$stmtFaculty->execute($params);
$facultyRecords = $stmtFaculty->fetchAll();

$colleges = $pdo->query("SELECT college_id, abbreviation, full_name FROM `colleges` ORDER BY abbreviation ASC")->fetchAll();

$pageTitle = "VP - Institutional Archives";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Full Institutional Attendance Archives</h1>
            <p class="text-sm text-gray-500">
                Vice President for Academics Unrestricted Historical Cross-College & Cross-Term View.
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
            <div>
                <label class="text-xs font-semibold text-gray-600 uppercase">College:</label>
                <select name="college_id" class="border border-gray-300 rounded px-2.5 py-1 text-xs">
                    <option value="">-- All Colleges --</option>
                    <?php foreach ($colleges as $col): ?>
                        <option value="<?= (int)$col['college_id'] ?>" <?= $filterCollege === (int)$col['college_id'] ? 'selected' : '' ?>>
                            <?= e($col['abbreviation']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-3 py-1 bg-red-700 text-white rounded text-xs font-semibold hover:bg-red-800">Filter Archives</button>
        </form>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-bold text-gray-800">Campus-Wide Records for S.Y. <?= e($filterYear) ?> (<?= e($filterTerm) ?>)</h2>
            <span class="text-xs bg-red-100 text-red-800 font-bold px-2.5 py-0.5 rounded-full"><?= count($facultyRecords) ?> Faculty Records</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3 text-left">Faculty Teacher</th>
                        <th class="px-6 py-3 text-left">College & Dept</th>
                        <th class="px-6 py-3 text-left">Total Logs</th>
                        <th class="px-6 py-3 text-left">Lates</th>
                        <th class="px-6 py-3 text-left">Converted Absents</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php if (empty($facultyRecords)): ?>
                        <tr><td colspan="7" class="px-6 py-6 text-center text-gray-500">No institutional records found matching criteria.</td></tr>
                    <?php else: foreach ($facultyRecords as $fr): ?>
                        <tr>
                            <td class="px-6 py-4 font-bold text-gray-900"><?= e($fr['last_name'] . ', ' . $fr['first_name']) ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 rounded text-xs font-bold bg-indigo-100 text-indigo-800"><?= e($fr['college_abbr']) ?></span>
                                <span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-700 ml-1"><?= e($fr['department_abbreviation']) ?></span>
                            </td>
                            <td class="px-6 py-4 font-semibold text-gray-800"><?= (int)$fr['total_logs'] ?></td>
                            <td class="px-6 py-4 font-mono text-yellow-700 font-bold"><?= (int)$fr['accumulated_lates_count'] ?></td>
                            <td class="px-6 py-4 font-mono text-red-700 font-bold"><?= (int)$fr['converted_absents_count'] ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 text-xs rounded-full font-bold uppercase tracking-wider bg-gray-100 text-gray-700">
                                    <?= e(str_replace('_', ' ', $fr['current_status'] ?: 'None')) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$fr['teacher_id'] ?>&year=<?= urlencode($filterYear) ?>&term=<?= urlencode($filterTerm) ?>" 
                                   class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold underline">
                                    Audit Logs &rarr;
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
