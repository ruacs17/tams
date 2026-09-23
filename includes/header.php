<?php
// Global Header Template with Local Offline Tailwind CSS
// Teacher Attendance Monitoring System (TAMS)

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../config/security.php';
}

$currentUserRole = $_SESSION['role'] ?? 'guest';
$currentUserName = $_SESSION['user_full_name'] ?? $_SESSION['username'] ?? 'Guest';
$activeSettings = isset($pdo) ? getActiveSystemSettings($pdo) : ['current_school_year' => '2025-2026', 'current_school_term' => '1st Term'];

$roleBadges = [
    'monitoring_head' => ['label' => 'Monitoring Head / Admin', 'color' => 'bg-purple-700 text-white'],
    'checker'         => ['label' => 'Attendance Checker (Operator)', 'color' => 'bg-blue-600 text-white'],
    'teacher'         => ['label' => 'Faculty Teacher', 'color' => 'bg-green-600 text-white'],
    'chairperson'     => ['label' => 'Department Chairperson', 'color' => 'bg-amber-600 text-white'],
    'dean'            => ['label' => 'College Dean', 'color' => 'bg-indigo-600 text-white'],
    'vp'              => ['label' => 'VP for Academics', 'color' => 'bg-red-700 text-white'],
    'guest'           => ['label' => 'Guest', 'color' => 'bg-gray-500 text-white']
];

$badge = $roleBadges[$currentUserRole] ?? $roleBadges['guest'];
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?>TAMS (Teacher Attendance Monitoring System)</title>
    <!-- Strictly Local Offline Tailwind CSS -->
    <link rel="stylesheet" href="/tams/assets/css/tailwind.min.css">
    <style>
        /* Essential responsive & print adjustments */
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="h-full flex flex-col font-sans text-gray-900 bg-gray-50">

<header class="bg-indigo-900 text-white shadow-md no-print">
    <!-- Top info bar -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center space-x-3">
                <a href="/tams/index.php" class="flex items-center space-x-2 text-white font-bold text-lg tracking-wide hover:text-indigo-200">
                    <svg class="w-7 h-7 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    <span>TAMS Academic Portal</span>
                </a>
                <span class="hidden md:inline-block px-2.5 py-0.5 text-xs font-semibold rounded-full bg-indigo-800 text-indigo-200 border border-indigo-700">
                    S.Y. <?= e($activeSettings['current_school_year']) ?> | <?= e($activeSettings['current_school_term']) ?>
                </span>
            </div>

            <?php if ($currentUserRole !== 'guest'): ?>
            <div class="flex items-center space-x-4">
                <div class="text-right hidden sm:block">
                    <div class="text-sm font-semibold"><?= e($currentUserName) ?></div>
                    <span class="inline-block text-xs px-2 py-0.5 rounded font-medium <?= $badge['color'] ?>">
                        <?= e($badge['label']) ?>
                    </span>
                </div>
                <a href="/tams/auth/logout.php" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none shadow-sm">
                    Logout
                </a>
            </div>
            <?php else: ?>
            <div>
                <a href="/tams/auth/login.php" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-indigo-900 bg-white hover:bg-gray-100 shadow-sm">
                    Sign In
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navigation Menu based on active role -->
    <?php if ($currentUserRole !== 'guest'): ?>
    <nav class="bg-indigo-800 border-t border-indigo-700 px-4 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto flex flex-wrap items-center space-x-1 sm:space-x-4 py-2 text-sm font-medium">
            
            <?php if ($currentUserRole === 'monitoring_head'): ?>
                <a href="/tams/admin/index.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Admin Dashboard</a>
                <a href="/tams/monitoring/review_reports.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Workflow Review & Unlock</a>
                <a href="/tams/monitoring/reaudit.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Secondary Re-Audit</a>
                <a href="/tams/admin/teachers.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Teachers & CSV Import</a>
                <a href="/tams/admin/loads.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Teacher Loads & CSV Import</a>
                <a href="/tams/admin/calendar_events.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Calendar & Suspensions</a>
                <a href="/tams/admin/personnel.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Personnel CRUD</a>
                <a href="/tams/admin/colleges.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Colleges & Deans</a>
                <a href="/tams/admin/departments.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Departments & Chairs</a>
                <a href="/tams/admin/affiliations.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Affiliations</a>
                <a href="/tams/monitoring/penalties.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Late Penalties</a>
                <a href="/tams/admin/audit_logs.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Audit Logs</a>
                <a href="/tams/admin/settings.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Settings</a>

            <?php elseif ($currentUserRole === 'checker'): ?>
                <a href="/tams/checker/index.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Checker Dashboard</a>
                <a href="/tams/checker/log_attendance.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Log Attendance</a>
                <a href="/tams/checker/submissions.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Submit Drafts</a>
                <a href="/tams/checker/calendar.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Calendar & Suspensions</a>

            <?php elseif ($currentUserRole === 'teacher'): ?>
                <a href="/tams/teacher/index.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">My Dashboard</a>
                <a href="/tams/teacher/my_attendance.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">My Attendance Logs</a>
                <a href="/tams/teacher/evidence_upload.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Upload Dispute Evidence</a>
                <a href="/tams/teacher/submit_report.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Acknowledge & Submit</a>

            <?php elseif ($currentUserRole === 'chairperson'): ?>
                <a href="/tams/chairperson/index.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Chairperson Dashboard</a>
                <a href="/tams/chairperson/review.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Review Faculty Reports</a>
                <a href="/tams/chairperson/archives.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Historical Archives</a>

            <?php elseif ($currentUserRole === 'dean'): ?>
                <a href="/tams/dean/index.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Dean Dashboard</a>
                <a href="/tams/dean/review.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Review College Reports</a>
                <a href="/tams/dean/archives.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Historical Archives</a>

            <?php elseif ($currentUserRole === 'vp'): ?>
                <a href="/tams/vp/index.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">VP Dashboard</a>
                <a href="/tams/vp/final_approval.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Final Approvals</a>
                <a href="/tams/vp/revert.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Revert Decisions</a>
                <a href="/tams/vp/archives.php" class="text-white hover:bg-indigo-700 px-3 py-1.5 rounded-md">Full Institutional Archives</a>
            <?php endif; ?>

        </div>
    </nav>
    <?php endif; ?>
</header>

<main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- Flash Notifications -->
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="mb-4 bg-green-50 border-l-4 border-green-500 p-4 rounded shadow-sm flex justify-between items-center">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span class="text-green-800 text-sm font-medium"><?= e($_SESSION['flash_success']) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800 font-bold">&times;</button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 rounded shadow-sm flex justify-between items-center">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-red-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <span class="text-red-800 text-sm font-medium"><?= e($_SESSION['flash_error']) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800 font-bold">&times;</button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_info'])): ?>
        <div class="mb-4 bg-blue-50 border-l-4 border-blue-500 p-4 rounded shadow-sm flex justify-between items-center">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-blue-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <span class="text-blue-800 text-sm font-medium"><?= e($_SESSION['flash_info']) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-blue-600 hover:text-blue-800 font-bold">&times;</button>
        </div>
        <?php unset($_SESSION['flash_info']); ?>
    <?php endif; ?>
