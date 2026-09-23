<?php
// Unified Hardened Authentication with Brute-Force Protection
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

$pdo = getDBConnection();
$error = '';
$info = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'session_timeout') {
    $info = 'Your session has expired after 20 minutes of inactivity. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $requestedRole = $_POST['role'] ?? 'auto';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $userFound = false;
        $authSuccess = false;
        $now = date('Y-m-d H:i:s');
        $resolvedRole = '';
        $userData = null;

        // 1. Check Monitoring Heads table
        if ($requestedRole === 'auto' || $requestedRole === 'monitoring_head') {
            $stmt = $pdo->prepare("SELECT * FROM `monitoring_heads` WHERE `username` = ? LIMIT 1");
            $stmt->execute([$username]);
            $head = $stmt->fetch();
            if ($head) {
                $userFound = true;
                // Check lockout
                if (!empty($head['lockout_until']) && strtotime($head['lockout_until']) > time()) {
                    $error = 'Account is temporarily locked due to consecutive failed attempts until ' . date('h:i:s A', strtotime($head['lockout_until'])) . '. Please wait.';
                } elseif (password_verify($password, $head['password_hash'])) {
                    if ($head['status'] !== 'active') {
                        $error = 'Your account has been deactivated. Please contact an administrator.';
                    } else {
                        $authSuccess = true;
                        $resolvedRole = 'monitoring_head';
                        $userData = $head;
                        // Reset failed attempts
                        $pdo->prepare("UPDATE `monitoring_heads` SET `failed_attempts` = 0, `lockout_until` = NULL WHERE `head_id` = ?")->execute([$head['head_id']]);
                    }
                } else {
                    // Record failed attempt
                    $newAttempts = $head['failed_attempts'] + 1;
                    $lockoutUntil = null;
                    if ($newAttempts >= 5) {
                        $lockoutUntil = date('Y-m-d H:i:s', time() + 300); // 5-minute lockout
                        $error = 'Too many failed login attempts. Your account is locked for 5 minutes.';
                    } else {
                        $remaining = 5 - $newAttempts;
                        $error = "Invalid password. {$remaining} attempts remaining before temporary lockout.";
                    }
                    $pdo->prepare("UPDATE `monitoring_heads` SET `failed_attempts` = ?, `lockout_until` = ? WHERE `head_id` = ?")->execute([$newAttempts, $lockoutUntil, $head['head_id']]);
                }
            }
        }

        // 2. Check Attendance Checkers table if not yet resolved
        if (!$userFound && ($requestedRole === 'auto' || $requestedRole === 'checker')) {
            $stmt = $pdo->prepare("SELECT * FROM `attendance_checkers` WHERE `username` = ? LIMIT 1");
            $stmt->execute([$username]);
            $checker = $stmt->fetch();
            if ($checker) {
                $userFound = true;
                if (!empty($checker['lockout_until']) && strtotime($checker['lockout_until']) > time()) {
                    $error = 'Account is temporarily locked until ' . date('h:i:s A', strtotime($checker['lockout_until'])) . '. Please wait.';
                } elseif (password_verify($password, $checker['password_hash'])) {
                    if ($checker['status'] !== 'active') {
                        $error = 'Your account has been deactivated. Please contact an administrator.';
                    } else {
                        $authSuccess = true;
                        $resolvedRole = 'checker';
                        $userData = $checker;
                        $pdo->prepare("UPDATE `attendance_checkers` SET `failed_attempts` = 0, `lockout_until` = NULL WHERE `checker_id` = ?")->execute([$checker['checker_id']]);
                    }
                } else {
                    $newAttempts = $checker['failed_attempts'] + 1;
                    $lockoutUntil = null;
                    if ($newAttempts >= 5) {
                        $lockoutUntil = date('Y-m-d H:i:s', time() + 300);
                        $error = 'Too many failed login attempts. Your account is locked for 5 minutes.';
                    } else {
                        $remaining = 5 - $newAttempts;
                        $error = "Invalid password. {$remaining} attempts remaining before temporary lockout.";
                    }
                    $pdo->prepare("UPDATE `attendance_checkers` SET `failed_attempts` = ?, `lockout_until` = ? WHERE `checker_id` = ?")->execute([$newAttempts, $lockoutUntil, $checker['checker_id']]);
                }
            }
        }

        // 3. Check Teachers table (Faculty, Chairperson, Dean, VP)
        if (!$userFound) {
            $stmt = $pdo->prepare("SELECT * FROM `teachers` WHERE `username` = ? LIMIT 1");
            $stmt->execute([$username]);
            $teacher = $stmt->fetch();
            if ($teacher) {
                $userFound = true;
                if (!empty($teacher['lockout_until']) && strtotime($teacher['lockout_until']) > time()) {
                    $error = 'Account is temporarily locked until ' . date('h:i:s A', strtotime($teacher['lockout_until'])) . '. Please wait.';
                } elseif (password_verify($password, $teacher['password_hash'])) {
                    if ($teacher['status'] !== 'active') {
                        $error = 'Your account has been deactivated.';
                    } else {
                        $authSuccess = true;
                        $userData = $teacher;
                        $pdo->prepare("UPDATE `teachers` SET `failed_attempts` = 0, `lockout_until` = NULL WHERE `teacher_id` = ?")->execute([$teacher['teacher_id']]);

                        // Determine highest role or match requested role
                        $teacherId = (int)$teacher['teacher_id'];

                        // Check VP
                        $stmtVp = $pdo->prepare("SELECT setting_id FROM `system_settings` WHERE `vp_academics_id` = ? LIMIT 1");
                        $stmtVp->execute([$teacherId]);
                        $isVp = (bool)$stmtVp->fetch();

                        // Check Dean
                        $stmtDean = $pdo->prepare("SELECT college_id FROM `colleges` WHERE `dean_id` = ? LIMIT 1");
                        $stmtDean->execute([$teacherId]);
                        $isDean = (bool)$stmtDean->fetch();

                        // Check Chair
                        $stmtChair = $pdo->prepare("SELECT department_id FROM `departments` WHERE `chairperson_id` = ? LIMIT 1");
                        $stmtChair->execute([$teacherId]);
                        $isChair = (bool)$stmtChair->fetch();

                        if ($requestedRole !== 'auto') {
                            // Validate teacher actually holds this role
                            if ($requestedRole === 'vp' && $isVp) {
                                $resolvedRole = 'vp';
                            } elseif ($requestedRole === 'dean' && $isDean) {
                                $resolvedRole = 'dean';
                            } elseif ($requestedRole === 'chairperson' && $isChair) {
                                $resolvedRole = 'chairperson';
                            } elseif ($requestedRole === 'teacher') {
                                $resolvedRole = 'teacher';
                            } else {
                                $resolvedRole = 'teacher'; // Fallback
                            }
                        } else {
                            // Automatic role priority: VP -> Dean -> Chairperson -> Teacher
                            if ($isVp) {
                                $resolvedRole = 'vp';
                            } elseif ($isDean) {
                                $resolvedRole = 'dean';
                            } elseif ($isChair) {
                                $resolvedRole = 'chairperson';
                            } else {
                                $resolvedRole = 'teacher';
                            }
                        }
                    }
                } else {
                    $newAttempts = $teacher['failed_attempts'] + 1;
                    $lockoutUntil = null;
                    if ($newAttempts >= 5) {
                        $lockoutUntil = date('Y-m-d H:i:s', time() + 300);
                        $error = 'Too many failed login attempts. Your account is locked for 5 minutes.';
                    } else {
                        $remaining = 5 - $newAttempts;
                        $error = "Invalid password. {$remaining} attempts remaining before temporary lockout.";
                    }
                    $pdo->prepare("UPDATE `teachers` SET `failed_attempts` = ?, `lockout_until` = ? WHERE `teacher_id` = ?")->execute([$newAttempts, $lockoutUntil, $teacher['teacher_id']]);
                }
            }
        }

        if (!$userFound && empty($error)) {
            $error = 'Account not found. Please check your username.';
        }

        // Authentication Successful
        if ($authSuccess && $userData && empty($error)) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)($userData['head_id'] ?? $userData['checker_id'] ?? $userData['teacher_id']);
            $_SESSION['teacher_id'] = isset($userData['teacher_id']) ? (int)$userData['teacher_id'] : null;
            $_SESSION['username'] = $userData['username'];
            $_SESSION['role'] = $resolvedRole;
            $_SESSION['user_full_name'] = isset($userData['first_name']) ? ($userData['first_name'] . ' ' . $userData['last_name']) : $userData['username'];
            $_SESSION['last_activity'] = time();

            // Force-change-password flag for teacher-family accounts
            $teacherRoles = ['teacher', 'chairperson', 'dean', 'vp'];
            if (in_array($resolvedRole, $teacherRoles, true) && !empty($userData['must_change_password'])) {
                $_SESSION['must_change_password'] = 1;
                logSystemAudit($pdo, $_SESSION['user_id'], $resolvedRole, "First-time login – force password change required.");
                header("Location: /tams/auth/change_password.php");
                exit;
            }

            // Log successful authentication
            logSystemAudit($pdo, $_SESSION['user_id'], $resolvedRole, "User logged in successfully as {$resolvedRole}");

            // Route to appropriate role dashboard
            switch ($resolvedRole) {
                case 'monitoring_head':
                    header("Location: /tams/admin/index.php");
                    exit;
                case 'checker':
                    header("Location: /tams/checker/index.php");
                    exit;
                case 'teacher':
                    header("Location: /tams/teacher/index.php");
                    exit;
                case 'chairperson':
                    header("Location: /tams/chairperson/index.php");
                    exit;
                case 'dean':
                    header("Location: /tams/dean/index.php");
                    exit;
                case 'vp':
                    header("Location: /tams/vp/index.php");
                    exit;
                default:
                    header("Location: /tams/index.php");
                    exit;
            }
        }
    }
}

$pageTitle = "Login";
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - TAMS</title>
    <!-- Offline local Tailwind CSS -->
    <link rel="stylesheet" href="/tams/assets/css/tailwind.min.css">
</head>
<body class="h-full flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-100">

<div class="max-w-md w-full space-y-8 bg-white p-8 rounded-xl shadow-lg border border-gray-200">
    <div class="text-center">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-indigo-100 text-indigo-700 mb-3">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
        </div>
        <h2 class="text-2xl font-extrabold text-gray-900">TAMS Academic Portal</h2>
        <p class="text-sm text-gray-600 mt-1">Teacher Attendance Monitoring & Review System</p>
    </div>

    <?php if (!empty($info)): ?>
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded text-sm text-blue-700">
            <?= e($info) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded text-sm text-red-700">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form class="mt-6 space-y-5" method="POST" action="/tams/auth/login.php">
        <?= csrfField() ?>

        <div>
            <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
            <input id="username" name="username" type="text" required value="<?= e($_POST['username'] ?? '') ?>"
                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                   placeholder="e.g. head_admin, checker1, teacher1">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <input id="password" name="password" type="password" required
                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                   placeholder="Enter your account password">
        </div>

        <div>
            <label for="role" class="block text-sm font-medium text-gray-700">Role Portal Login</label>
            <select id="role" name="role"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm bg-white">
                <option value="auto">Auto-Detect Role (Recommended)</option>
                <option value="monitoring_head">Monitoring Office Head / Admin</option>
                <option value="checker">Attendance Checker (Operator)</option>
                <option value="teacher">Faculty Teacher</option>
                <option value="chairperson">Department Chairperson</option>
                <option value="dean">College Dean</option>
                <option value="vp">VP for Academics</option>
            </select>
        </div>

        <div>
            <button type="submit"
                    class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-700 hover:bg-indigo-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                Sign In to System
            </button>
        </div>
    </form>

    <div class="mt-6 border-t border-gray-200 pt-4">
        <div class="text-xs text-gray-500">
            <span class="font-semibold text-gray-700">Pre-Configured Seed Accounts:</span>
            <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                <div class="bg-gray-50 p-2 rounded border border-gray-200">
                    <span class="font-bold">Monitoring Head:</span><br>
                    <code class="text-indigo-600">head_admin</code> / <code class="text-gray-600">Password123!</code>
                </div>
                <div class="bg-gray-50 p-2 rounded border border-gray-200">
                    <span class="font-bold">Attendance Checker:</span><br>
                    <code class="text-indigo-600">checker1</code> / <code class="text-gray-600">Password123!</code>
                </div>
                <div class="bg-gray-50 p-2 rounded border border-gray-200">
                    <span class="font-bold">Faculty Teacher:</span><br>
                    <code class="text-indigo-600">teacher1</code> / <code class="text-gray-600">Password123!</code>
                </div>
                <div class="bg-gray-50 p-2 rounded border border-gray-200">
                    <span class="font-bold">Chairperson:</span><br>
                    <code class="text-indigo-600">chair_cs</code> / <code class="text-gray-600">Password123!</code>
                </div>
                <div class="bg-gray-50 p-2 rounded border border-gray-200">
                    <span class="font-bold">College Dean:</span><br>
                    <code class="text-indigo-600">dean_cet</code> / <code class="text-gray-600">Password123!</code>
                </div>
                <div class="bg-gray-50 p-2 rounded border border-gray-200">
                    <span class="font-bold">VP Academics:</span><br>
                    <code class="text-indigo-600">vp_academics</code> / <code class="text-gray-600">Password123!</code>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
