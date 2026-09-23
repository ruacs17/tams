<?php
// Session Termination & Audit Log
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/audit_service.php';

if (isset($_SESSION['user_id'])) {
    $pdo = getDBConnection();
    logSystemAudit($pdo, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'unknown', 'User logged out');
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header("Location: /tams/auth/login.php");
exit;
