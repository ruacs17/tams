<?php
// Centralized Security & Session Hardening
// Teacher Attendance Monitoring System (TAMS)

// Start session with hardened cookie parameters if not already active
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Set Content Security Policy & Security Headers
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self';");

// 20-Minute Idle Session Timeout Enforcement
define('SESSION_IDLE_TIMEOUT', 1200); // 20 minutes * 60 seconds

if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        // Session expired due to inactivity
        session_unset();
        session_destroy();
        header("Location: /tams/auth/login.php?msg=session_timeout");
        exit;
    }
}
$_SESSION['last_activity'] = time();

/**
 * Context-aware XSS sanitization helper
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Validate and cast integer IDs strictly
 */
function castInt($val): int {
    return (int)$val;
}

/**
 * Validate School Year format (e.g. 2025-2026)
 */
function isValidSchoolYear(string $year): bool {
    return (bool)preg_match('/^\d{4}-\d{4}$/', trim($year));
}

/**
 * Generate CSRF Token for current session
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Renders hidden CSRF input field
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(generateCsrfToken()) . '">';
}

/**
 * Validate CSRF Token on POST requests
 */
function validateCsrfToken(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die("Security Error: Invalid or expired CSRF token.");
        }
    }
}

/**
 * Require active authentication and check permitted roles
 * Roles: 'monitoring_head', 'checker', 'teacher', 'chairperson', 'dean', 'vp'
 */
function requireAuth(array $allowedRoles = []): void {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: /tams/auth/login.php");
        exit;
    }

    // Force-password-change wall: teacher-family accounts must change on first login
    $teacherRoles = ['teacher', 'chairperson', 'dean', 'vp'];
    if (
        in_array($_SESSION['role'], $teacherRoles, true) &&
        !empty($_SESSION['must_change_password'])
    ) {
        // Allow access only to the change_password page itself to prevent redirect loops
        $currentScript = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        if (!str_ends_with($currentScript, '/auth/change_password.php')) {
            header("Location: /tams/auth/change_password.php");
            exit;
        }
    }

    if (!empty($allowedRoles) && !in_array($_SESSION['role'], $allowedRoles, true)) {
        http_response_code(403);
        die("Access Denied: You do not have permission to view this resource.");
    }
}

/**
 * Retrieve current active system settings (School Year & Term)
 */
function getActiveSystemSettings(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT * FROM `system_settings` WHERE `is_active` = 1 LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch();
    if (!$settings) {
        return [
            'setting_id' => 1,
            'current_school_year' => '2025-2026',
            'current_school_term' => '1st Term',
            'vp_academics_id' => null,
            'is_active' => 1
        ];
    }
    return $settings;
}

/**
 * Check if the active role is permitted historical archival access
 * - Attendance Checkers: Strictly locked to active term/year
 * - Teachers: Strictly locked to their own teacher_id
 * - Chairpersons: Can view historical logs IF target teacher was in their department that term/year
 * - Deans: Can view historical logs IF target teacher was in their college that term/year
 * - Monitoring Heads & VP: Unrestricted archival access
 */
function verifyArchivalAccess(PDO $pdo, int $targetTeacherId, string $schoolYear, string $schoolTerm): bool {
    $role = $_SESSION['role'] ?? '';
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $activeTeacherId = (int)($_SESSION['teacher_id'] ?? 0);

    if ($role === 'monitoring_head' || $role === 'vp') {
        return true;
    }

    if ($role === 'checker') {
        // Attendance Checkers strictly locked to active cycle
        $settings = getActiveSystemSettings($pdo);
        return ($settings['current_school_year'] === $schoolYear && $settings['current_school_term'] === $schoolTerm);
    }

    if ($role === 'teacher') {
        // Teachers strictly locked to their own teacher_id
        return ($activeTeacherId === $targetTeacherId);
    }

    if ($role === 'chairperson') {
        // Verify chairperson belongs to the department where target teacher was affiliated in that term/year
        $stmtChair = $pdo->prepare("SELECT department_id FROM `departments` WHERE `chairperson_id` = ?");
        $stmtChair->execute([$activeTeacherId]);
        $chairDeptIds = $stmtChair->fetchAll(PDO::FETCH_COLUMN);
        if (empty($chairDeptIds)) return false;

        $inClause = implode(',', array_map('intval', $chairDeptIds));
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM `teacher_department` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `department_id` IN ($inClause)");
        $stmtCheck->execute([$targetTeacherId, $schoolYear, $schoolTerm]);
        return ($stmtCheck->fetchColumn() > 0);
    }

    if ($role === 'dean') {
        // Verify dean belongs to the college where target teacher was affiliated in that term/year
        $stmtDean = $pdo->prepare("
            SELECT d.department_id 
            FROM `departments` d
            JOIN `colleges` c ON d.college_id = c.college_id
            WHERE c.dean_id = ?
        ");
        $stmtDean->execute([$activeTeacherId]);
        $deanDeptIds = $stmtDean->fetchAll(PDO::FETCH_COLUMN);
        if (empty($deanDeptIds)) return false;

        $inClause = implode(',', array_map('intval', $deanDeptIds));
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM `teacher_department` WHERE `teacher_id` = ? AND `school_year` = ? AND `school_term` = ? AND `department_id` IN ($inClause)");
        $stmtCheck->execute([$targetTeacherId, $schoolYear, $schoolTerm]);
        return ($stmtCheck->fetchColumn() > 0);
    }

    return false;
}
