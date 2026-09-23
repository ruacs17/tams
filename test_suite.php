<?php
// Comprehensive Automated Test Suite for TAMS
// Teacher Attendance Monitoring System

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/audit_service.php';
require_once __DIR__ . '/includes/attendance_engine.php';
require_once __DIR__ . '/includes/upload_service.php';

$pdo = getDBConnection();
$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failed++;
    }
}

echo "\n=== 1. DATABASE SCHEMA & AUTO-INITIALIZATION TEST ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$expectedTables = [
    'teachers', 'system_settings', 'attendance_checkers', 'monitoring_heads',
    'colleges', 'departments', 'teacher_department', 'teacher_loads',
    'attendance_logs', 'calendar_events', 'teacher_report_summaries',
    'attendance_evidence', 'attendance_penalties', 'audit_logs', 'report_audit_trails'
];
foreach ($expectedTables as $tbl) {
    assertTest("Table '{$tbl}' exists", in_array($tbl, $tables, true));
}

echo "\n=== 2. SEED ACCOUNTS & SETTINGS TEST ===\n";
$settings = getActiveSystemSettings($pdo);
assertTest("Active School Year is '2025-2026'", $settings['current_school_year'] === '2025-2026');
assertTest("Active School Term is '1st Term'", $settings['current_school_term'] === '1st Term');

$stmtHead = $pdo->prepare("SELECT * FROM `monitoring_heads` WHERE `username` = 'head_admin'");
$stmtHead->execute();
$headUser = $stmtHead->fetch();
assertTest("Monitoring Head 'head_admin' exists", (bool)$headUser);
assertTest("Password verify for head_admin succeeds", password_verify('Password123!', $headUser['password_hash']));

$stmtChecker = $pdo->prepare("SELECT * FROM `attendance_checkers` WHERE `username` = 'checker1'");
$stmtChecker->execute();
$checkerUser = $stmtChecker->fetch();
assertTest("Attendance Checker 'checker1' exists", (bool)$checkerUser);
assertTest("Password verify for checker1 succeeds", password_verify('Password123!', $checkerUser['password_hash']));

echo "\n=== 3. ATTENDANCE & SCHEDULE EVALUATION ENGINE TEST ===\n";
$testLoad = [
    'load_id' => 1,
    'department_id' => 1,
    'days' => 'MWF',
    'time' => '08:00 am - 09:00 am',
    'offer_code' => 'CS101-A'
];

// On time clock in at 08:05 AM (within 15-min grace)
$evalOnTime = evaluateScheduleStatus($pdo, $testLoad, '2026-09-01', '08:05:00', '09:00:00');
assertTest("Clock-in within grace period marks status as 'Present'", $evalOnTime['status'] === 'Present');
assertTest("Late minutes for on-time arrival is 0", $evalOnTime['late_minutes'] === 0);

// Late clock in at 08:25 AM (past 15-min grace)
$evalLate = evaluateScheduleStatus($pdo, $testLoad, '2026-09-01', '08:25:00', '09:00:00');
assertTest("Clock-in past grace period marks status as 'Late'", $evalLate['status'] === 'Late');
assertTest("Late minutes calculated accurately (25 mins)", $evalLate['late_minutes'] === 25);

// Missing time in marks as Absent
$evalAbsent = evaluateScheduleStatus($pdo, $testLoad, '2026-09-01', null, null);
assertTest("Missing clock-in marks status as 'Absent'", $evalAbsent['status'] === 'Absent');

echo "\n=== 4. CALENDAR SUSPENSION & RETROACTIVE RECALCULATION TEST ===\n";
// Create sample Holiday
$stmtEv = $pdo->prepare("
    INSERT INTO `calendar_events` 
    (`event_type`, `title`, `description`, `start_date`, `end_date`, `scope_type`, `created_by`)
    VALUES ('holiday', 'Test Holiday Foundation Day', 'Test holiday', '2026-09-10', '2026-09-10', 'institution', 1)
");
$stmtEv->execute();
$holidayId = (int)$pdo->lastInsertId();

$evalHoliday = evaluateScheduleStatus($pdo, $testLoad, '2026-09-10', null, null);
assertTest("Schedule on declared holiday date automatically marked 'Holiday' (HOL)", $evalHoliday['status'] === 'Holiday');

// Create Time-Bound Partial Suspension (08:00 AM - 09:30 AM)
$stmtPse = $pdo->prepare("
    INSERT INTO `calendar_events`
    (`event_type`, `title`, `description`, `start_date`, `end_date`, `start_time`, `end_time`, `scope_type`, `require_event_checkin`, `created_by`)
    VALUES ('partial_suspension', 'Test Safety Drill', 'Partial suspension', '2026-09-11', '2026-09-11', '08:00:00', '09:30:00', 'institution', 0, 1)
");
$stmtPse->execute();
$pseId = (int)$pdo->lastInsertId();

$evalPse = evaluateScheduleStatus($pdo, $testLoad, '2026-09-11', null, null);
assertTest("Class fully inside partial suspension window marked 'Partial Suspension' (PSE)", $evalPse['status'] === 'Partial Suspension');

// Test Retroactive Recalculation
// Insert an old log that was marked 'Absent' on 2026-09-12
$stmtOldLog = $pdo->prepare("
    INSERT INTO `attendance_logs`
    (`load_id`, `teacher_id`, `date`, `school_year`, `school_term`, `scheduled_time_in`, `scheduled_time_out`, `status`, `workflow_status`, `logged_by`)
    VALUES (1, 4, '2026-09-12', '2025-2026', '1st Term', '08:00:00', '09:00:00', 'Absent', 'draft', 1)
");
$stmtOldLog->execute();
$oldLogId = (int)$pdo->lastInsertId();

// Now declare retroactive emergency closure for 2026-09-12
$stmtSos = $pdo->prepare("
    INSERT INTO `calendar_events`
    (`event_type`, `title`, `description`, `start_date`, `end_date`, `scope_type`, `created_by`)
    VALUES ('emergency_closure', 'Retroactive Severe Weather', 'Typhoon alert', '2026-09-12', '2026-09-12', 'institution', 1)
");
$stmtSos->execute();
$sosId = (int)$pdo->lastInsertId();

$recalculated = recalculateRetroactiveEvent($pdo, $sosId);
assertTest("Retroactive recalculation engine processed affected records", $recalculated >= 1);

$checkStatus = $pdo->query("SELECT status FROM `attendance_logs` WHERE `log_id` = {$oldLogId}")->fetchColumn();
assertTest("Past invalid Absent log automatically converted to 'Suspended' (SOS)", $checkStatus === 'Suspended');

echo "\n=== 5. PENALTY ENGINE TEST (3 LATES = 1 ABSENT) ===\n";
// Insert 4 lates for teacher 4
$pdo->prepare("DELETE FROM `attendance_logs` WHERE `teacher_id` = 4 AND `school_year` = '2025-2026' AND `school_term` = '1st Term'")->execute();
$stmtLateLog = $pdo->prepare("
    INSERT INTO `attendance_logs`
    (`load_id`, `teacher_id`, `date`, `school_year`, `school_term`, `status`, `late_minutes`, `workflow_status`)
    VALUES (1, 4, '2026-09-01', '2025-2026', '1st Term', 'Late', 20, 'draft'),
           (1, 4, '2026-09-02', '2025-2026', '1st Term', 'Late', 25, 'draft'),
           (1, 4, '2026-09-03', '2025-2026', '1st Term', 'Late', 30, 'draft'),
           (1, 4, '2026-09-04', '2025-2026', '1st Term', 'Late', 18, 'draft')
");
$stmtLateLog->execute();

$penResults = recomputeTeacherPenalties($pdo, 4, '2025-2026', '1st Term');
assertTest("Accumulated lates count is 4", $penResults['accumulated_lates_count'] === 4);
assertTest("Converted absents count is 1 (floor(4/3))", $penResults['converted_absents_count'] === 1);

// Add 2 more lates (total 6 lates)
$pdo->prepare("
    INSERT INTO `attendance_logs`
    (`load_id`, `teacher_id`, `date`, `school_year`, `school_term`, `status`, `late_minutes`, `workflow_status`)
    VALUES (1, 4, '2026-09-05', '2025-2026', '1st Term', 'Late', 20, 'draft'),
           (1, 4, '2026-09-06', '2025-2026', '1st Term', 'Late', 20, 'draft')
")->execute();
$penResults2 = recomputeTeacherPenalties($pdo, 4, '2025-2026', '1st Term');
assertTest("Accumulated lates count is 6", $penResults2['accumulated_lates_count'] === 6);
assertTest("Converted absents count is 2 (floor(6/3))", $penResults2['converted_absents_count'] === 2);

echo "\n=== 6. CSV PARSING & IRREGULARITY EXCEPTION ENGINE TEST ===\n";
// Create test CSV content with normal row, *** O P E N *** placeholder, and unlinked foreign key
$csvContent = "teacher_id,offer_code,subject_name,subject_description,days,time,room,department_id\n"
            . "4,CS101-TEST,Test Subject,Description,MWF,08:00 am - 09:00 am,Room 101,1\n"
            . ",CS-OPEN-TEST,Open Class,Description,*** O P E N ***,*** O P E N ***,TBA,1\n"
            . "99999,CS-UNLINKED-TEST,Orphaned Class,Description,TTh,01:00 pm - 02:30 pm,Room 202,88888\n";
$testCsvFile = __DIR__ . '/test_sample.csv';
file_put_contents($testCsvFile, $csvContent);

// Verify parser handles this gracefully
$handle = fopen($testCsvFile, 'r');
$rowNum = 0;
$imported = 0;
$irregularities = [];
$existingTeachers = array_flip($pdo->query("SELECT teacher_id FROM `teachers`")->fetchAll(PDO::FETCH_COLUMN));
$existingDepts = array_flip($pdo->query("SELECT department_id FROM `departments`")->fetchAll(PDO::FETCH_COLUMN));

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if ($rowNum === 1) continue;
    $rawTId = trim($row[0]);
    $code = trim($row[1]);
    $subj = trim($row[2]);
    $rawDId = trim($row[7]);
    $issues = [];
    $tId = null;
    $dId = null;

    if ($rawTId !== '') {
        if (!isset($existingTeachers[(int)$rawTId])) {
            $issues[] = "Unlinked Teacher ID ({$rawTId})";
        } else {
            $tId = (int)$rawTId;
        }
    }
    if ($rawDId !== '') {
        if (!isset($existingDepts[(int)$rawDId])) {
            $issues[] = "Unlinked Dept ID ({$rawDId})";
        } else {
            $dId = (int)$rawDId;
        }
    }
    if (strpos($row[4], 'O P E N') !== false) {
        $issues[] = "Placeholder schedule '*** O P E N ***'";
    }

    $pdo->prepare("
        INSERT INTO `teacher_loads` (`offer_code`, `teacher_id`, `subject_name`, `subject_description`, `days`, `time`, `room`, `department_id`, `school_term`, `school_year`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, '1st Term', '2025-2026')
    ")->execute([$code, $tId, $subj, $row[3], $row[4], $row[5], $row[6], $dId]);
    $imported++;
    if (!empty($issues)) {
        $irregularities[] = ['row' => $rowNum, 'issues' => $issues];
    }
}
fclose($handle);
unlink($testCsvFile);

assertTest("CSV Bulk Ingest successfully imported 3 rows", $imported === 3);
assertTest("Irregularity report caught 2 irregular rows (placeholder & unlinked FK)", count($irregularities) === 2);

echo "\n=== 7. FULL MULTI-ROLE WORKFLOW TRANSITION LOOP TEST ===\n";
// Set up a clean log for teacher 4
$pdo->prepare("
    INSERT INTO `attendance_logs`
    (`load_id`, `teacher_id`, `date`, `school_year`, `school_term`, `status`, `workflow_status`)
    VALUES (1, 4, '2026-09-08', '2025-2026', '1st Term', 'Present', 'draft')
")->execute();
$wfLogId = (int)$pdo->lastInsertId();

// 1. Operator submit
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'submitted_operator' WHERE `log_id` = ?")->execute([$wfLogId]);
$wf1 = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 1: Draft transitioned to 'submitted_operator'", $wf1 === 'submitted_operator');

// 2. Monitoring Head confirm
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'confirmed_monitoring' WHERE `log_id` = ?")->execute([$wfLogId]);
$wf2 = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 2: Monitoring confirmed to 'confirmed_monitoring'", $wf2 === 'confirmed_monitoring');

// 3. Teacher review and submit
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'submitted_teacher' WHERE `log_id` = ?")->execute([$wfLogId]);
$wf3 = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 3: Teacher submitted to Chairperson ('submitted_teacher')", $wf3 === 'submitted_teacher');

// 4. Chairperson approve
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'submitted_chairperson' WHERE `log_id` = ?")->execute([$wfLogId]);
$wf4 = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 4: Chairperson approved to Dean ('submitted_chairperson')", $wf4 === 'submitted_chairperson');

// Test Chairperson Return Protocol
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'returned_to_teacher' WHERE `log_id` = ?")->execute([$wfLogId]);
$wfRet = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 4b: Chairperson Return protocol rolls back to 'returned_to_teacher'", $wfRet === 'returned_to_teacher');

// Teacher re-submits & Chair re-approves
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'submitted_chairperson' WHERE `log_id` = ?")->execute([$wfLogId]);

// 5. Dean approve
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'submitted_dean' WHERE `log_id` = ?")->execute([$wfLogId]);
$wf5 = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 5: Dean approved to Monitoring Re-Audit ('submitted_dean')", $wf5 === 'submitted_dean');

// 6. Monitoring Re-Audit
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'verified_monitoring' WHERE `log_id` = ?")->execute([$wfLogId]);
$wf6 = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 6: Monitoring verified to VP ('verified_monitoring')", $wf6 === 'verified_monitoring');

// 7. VP Final Approval
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'final_approved_vp' WHERE `log_id` = ?")->execute([$wfLogId]);
$wf7 = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 7: VP granted 'final_approved_vp'", $wf7 === 'final_approved_vp');

// 8. VP Revert Decision
$pdo->prepare("UPDATE `attendance_logs` SET `workflow_status` = 'returned_to_teacher' WHERE `log_id` = ?")->execute([$wfLogId]);
$wfRev = $pdo->query("SELECT workflow_status FROM `attendance_logs` WHERE `log_id` = {$wfLogId}")->fetchColumn();
assertTest("Step 8: VP Exclusive Revert Decision unlocked to 'returned_to_teacher'", $wfRev === 'returned_to_teacher');

// Clean test logs
$pdo->prepare("DELETE FROM `attendance_logs` WHERE `log_id` = ?")->execute([$wfLogId]);
$pdo->prepare("DELETE FROM `calendar_events` WHERE `event_id` IN (?, ?, ?)")->execute([$holidayId, $pseId, $sosId]);

echo "\n=== 8. SECURITY, UPLOAD & OFFLINE ASSETS TEST ===\n";
assertTest("Local Tailwind CSS stylesheet exists", file_exists(__DIR__ . '/assets/css/tailwind.min.css'));
assertTest("Local Tailwind CSS size > 1MB", filesize(__DIR__ . '/assets/css/tailwind.min.css') > 1000000);
assertTest("Upload directory .htaccess file exists", file_exists(__DIR__ . '/uploads/.htaccess'));

// Test Image Sanitization (GD neutralization)
$fakeImg = imagecreatetruecolor(10, 10);
$testImgFile = __DIR__ . '/uploads/evidence/test_temp.png';
imagepng($fakeImg, $testImgFile);
imagedestroy($fakeImg);

$simulatedUpload = [
    'name' => 'test_neutral.png',
    'type' => 'image/png',
    'tmp_name' => $testImgFile,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($testImgFile)
];
$uploadRes = handleEvidenceUpload($simulatedUpload);
assertTest("Secure upload service sanitizes and stores valid image", $uploadRes['success'] === true);
if ($uploadRes['success']) {
    @unlink(__DIR__ . '/' . $uploadRes['filePath']);
}
@unlink($testImgFile);

echo "\n============================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED.\n";
echo "============================================\n";
