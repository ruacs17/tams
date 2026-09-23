<?php
// Central Database Connection & Automated Setup
// Teacher Attendance Monitoring System (TAMS)

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '1234');
define('DB_NAME', 'tams');

function getDBConnection(): PDO {
    static $pdoInstance = null;

    if ($pdoInstance !== null) {
        return $pdoInstance;
    }

    try {
        // Step 1: Connect to server without database to ensure DB existence
        $dsnWithoutDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        $pdoInit = new PDO($dsnWithoutDb, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Step 2: Ensure database exists
        $pdoInit->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Step 3: Connect directly to the database
        $dsnWithDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdoInstance = new PDO($dsnWithDb, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Step 4: Verify schema existence and execute migration if needed
        $stmt = $pdoInstance->query("SHOW TABLES LIKE 'system_settings'");
        if ($stmt->rowCount() === 0) {
            $schemaFile = __DIR__ . '/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdoInstance->exec($sql);
            }
        }

        // Step 5: Seed initial accounts and academic records if not present
        require_once __DIR__ . '/seeder.php';
        seedDatabase($pdoInstance);

        return $pdoInstance;
    } catch (PDOException $e) {
        die("Database Connection Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
}
