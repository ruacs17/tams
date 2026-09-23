<?php
// Main Application Entry Point & Dispatcher
// Teacher Attendance Monitoring System (TAMS)

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';

// Trigger database connection to ensure DB and schema initialization
$pdo = getDBConnection();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: /tams/auth/login.php");
    exit;
}

switch ($_SESSION['role']) {
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
        header("Location: /tams/auth/login.php");
        exit;
}
