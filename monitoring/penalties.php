<?php
// Attendance Penalty Engine Dashboard (3 Lates = 1 Absent Rule)
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';
require_once __DIR__ . '/../includes/attendance_engine.php';

requireAuth(['monitoring_head']);
$pdo = getDBConnection();
$activeSettings = getActiveSystemSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['post_action'] ?? '';

    if ($action === 'recompute_all') {
        $teachers = $pdo->query("SELECT teacher_id FROM `teachers`")->fetchAll(PDO::FETCH_COLUMN);
        $recalculatedTotal = 0;
        foreach ($teachers as $tId) {
            recomputeTeacherPenalties($pdo, (int)$tId, $activeSettings['current_school_year'], $activeSettings['current_school_term']);
            $recalculatedTotal++;
        }
        logSystemAudit($pdo, (int)$_SESSION['user_id'], 'monitoring_head', "Recomputed penalties for {$recalculatedTotal} teachers");
        $_SESSION['flash_success'] = "Penalty engine recalculated late-to-absent conversion for all faculty members.";
        header("Location: /tams/monitoring/penalties.php");
        exit;
    }
}

// Fetch penalty summaries with exemption statistics
$stmtPenalties = $pdo->prepare("
    SELECT t.teacher_id, t.first_name, t.last_name,
           COALESCE(ap.accumulated_lates_count, 0) as lates,
           COALESCE(ap.converted_absents_count, 0) as converted_absents,
           SUM(CASE WHEN al.status = 'Holiday' THEN 1 ELSE 0 END) as hol_count,
           SUM(CASE WHEN al.status = 'Suspended' THEN 1 ELSE 0 END) as sos_count,
           SUM(CASE WHEN al.status = 'Partial Suspension' THEN 1 ELSE 0 END) as pse_count
    FROM `teachers` t
    LEFT JOIN `attendance_penalties` ap ON t.teacher_id = ap.teacher_id 
         AND ap.school_year = ? AND ap.school_term = ?
    LEFT JOIN `attendance_logs` al ON t.teacher_id = al.teacher_id 
         AND al.school_year = ? AND al.school_term = ?
    GROUP BY t.teacher_id
    ORDER BY lates DESC, t.last_name ASC
");
$stmtPenalties->execute([
    $activeSettings['current_school_year'], $activeSettings['current_school_term'],
    $activeSettings['current_school_year'], $activeSettings['current_school_term']
]);
$penaltyRows = $stmtPenalties->fetchAll();

$pageTitle = "Penalty Engine & Late Conversion";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Penalty Engine: Late-to-Absent Conversion</h1>
            <p class="text-sm text-gray-500">
                Institutional Policy: <strong>3 Late Arrivals = 1 Unexcused Absent</strong> (Discounting HOL, SOS, & PSE exemptions).
            </p>
        </div>
        <form method="POST" class="inline">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="recompute_all">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md shadow-sm">
                Recalculate All Faculty Penalties
            </button>
        </form>
    </div>

    <!-- Info Banner -->
    <div class="bg-blue-50 border-l-4 border-blue-600 p-4 rounded-md shadow-sm text-xs text-blue-900 space-y-1">
        <div class="font-bold">Business Logic & Calendar Scope Integrity:</div>
        <div>
            Late arrivals occurring during full-day holidays (<span class="font-mono font-bold bg-purple-100 text-purple-800 px-1 rounded">HOL</span>),
            emergency closures (<span class="font-mono font-bold bg-blue-100 text-blue-800 px-1 rounded">SOS</span>), or within partial suspension windows (<span class="font-mono font-bold bg-indigo-100 text-indigo-800 px-1 rounded">PSE</span>)
            are strictly exempted from the late penalty accumulator.
        </div>
    </div>

    <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Faculty Teacher</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Accumulated Lates</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Converted Absents (3:1)</th>
                    <th class="px-6 py-3 text-left font-semibold text-gray-600">Exemptions Discounted</th>
                    <th class="px-6 py-3 text-right font-semibold text-gray-600">Audit Link</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                <?php if (empty($penaltyRows)): ?>
                    <tr><td colspan="5" class="px-6 py-6 text-center text-gray-500">No teachers found.</td></tr>
                <?php else: foreach ($penaltyRows as $pr): ?>
                    <tr>
                        <td class="px-6 py-4 font-bold text-gray-900"><?= e($pr['last_name'] . ', ' . $pr['first_name']) ?></td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-yellow-100 text-yellow-800">
                                <?= (int)$pr['lates'] ?> Lates
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold <?= $pr['converted_absents'] > 0 ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600' ?>">
                                <?= (int)$pr['converted_absents'] ?> Converted Absents
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs space-x-1">
                            <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded font-mono"><?= (int)$pr['hol_count'] ?> HOL</span>
                            <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded font-mono"><?= (int)$pr['sos_count'] ?> SOS</span>
                            <span class="bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded font-mono"><?= (int)$pr['pse_count'] ?> PSE</span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="/tams/teacher/my_attendance.php?view_teacher_id=<?= (int)$pr['teacher_id'] ?>" class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold underline">
                                Detailed Logs &rarr;
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
