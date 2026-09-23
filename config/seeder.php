<?php
// TAMS Database Seeder
// Seeds initial structural data, roles, and faculty accounts

function seedDatabase(PDO $pdo) {
    // Check if already seeded
    $stmt = $pdo->query("SELECT COUNT(*) FROM `system_settings`");
    if ($stmt->fetchColumn() > 0) {
        return; // Already initialized
    }

    $defaultPasswordHash = password_hash('Password123!', PASSWORD_BCRYPT);

    // 1. Insert Key Faculty Teachers
    $teachers = [
        ['last_name' => 'Pierce', 'first_name' => 'Victoria', 'username' => 'vp_academics'],
        ['last_name' => 'Evans', 'first_name' => 'Donald', 'username' => 'dean_cet'],
        ['last_name' => 'Henderson', 'first_name' => 'Claire', 'username' => 'chair_cs'],
        ['last_name' => 'Turing', 'first_name' => 'Alan', 'username' => 'teacher1'],
        ['last_name' => 'Lovelace', 'first_name' => 'Ada', 'username' => 'teacher2'],
        ['last_name' => 'Hopper', 'first_name' => 'Grace', 'username' => 'teacher3'],
    ];

    $teacherIds = [];
    // Demo seed accounts get must_change_password=0 (pre-configured, known passwords).
    // Real teachers imported via CSV get must_change_password=1 and use their numeric
    // teacher_id as both username and temporary password.
    $stmtTeacher = $pdo->prepare("INSERT INTO `teachers` (`last_name`, `first_name`, `username`, `password_hash`, `status`, `must_change_password`) VALUES (?, ?, ?, ?, 'active', 0)");
    foreach ($teachers as $t) {
        $stmtTeacher->execute([$t['last_name'], $t['first_name'], $t['username'], $defaultPasswordHash]);
        $teacherIds[$t['username']] = (int)$pdo->lastInsertId();
    }

    $vpId = $teacherIds['vp_academics'];
    $deanCetId = $teacherIds['dean_cet'];
    $chairCsId = $teacherIds['chair_cs'];
    $turingId = $teacherIds['teacher1'];
    $lovelaceId = $teacherIds['teacher2'];
    $hopperId = $teacherIds['teacher3'];

    // 2. System Settings
    $stmtSettings = $pdo->prepare("INSERT INTO `system_settings` (`current_school_year`, `current_school_term`, `vp_academics_id`, `is_active`) VALUES ('2025-2026', '1st Term', ?, 1)");
    $stmtSettings->execute([$vpId]);

    // 3. Monitoring Heads
    $stmtHead = $pdo->prepare("INSERT INTO `monitoring_heads` (`username`, `password_hash`, `status`) VALUES (?, ?, 'active')");
    $stmtHead->execute(['head_admin', $defaultPasswordHash]);

    // 4. Attendance Checkers
    $stmtChecker = $pdo->prepare("INSERT INTO `attendance_checkers` (`username`, `password_hash`, `status`) VALUES (?, ?, 'active')");
    $stmtChecker->execute(['checker1', $defaultPasswordHash]);

    // 5. Colleges
    $stmtCollege = $pdo->prepare("INSERT INTO `colleges` (`abbreviation`, `full_name`, `dean_id`) VALUES (?, ?, ?)");
    $stmtCollege->execute(['CET', 'College of Engineering and Technology', $deanCetId]);
    $cetId = (int)$pdo->lastInsertId();

    $stmtCollege->execute(['CAS', 'College of Arts and Sciences', null]);
    $casId = (int)$pdo->lastInsertId();

    // 6. Departments
    $stmtDept = $pdo->prepare("INSERT INTO `departments` (`college_id`, `department_abbreviation`, `department_full_name`, `chairperson_id`) VALUES (?, ?, ?, ?)");
    $stmtDept->execute([$cetId, 'CS', 'Department of Computer Science', $chairCsId]);
    $csDeptId = (int)$pdo->lastInsertId();

    $stmtDept->execute([$cetId, 'IT', 'Department of Information Technology', null]);
    $itDeptId = (int)$pdo->lastInsertId();

    $stmtDept->execute([$casId, 'MATH', 'Department of Mathematics & Physics', null]);
    $mathDeptId = (int)$pdo->lastInsertId();

    // 7. Teacher Department Affiliations (Active Year: 2025-2026, 1st Term)
    $stmtAffil = $pdo->prepare("INSERT INTO `teacher_department` (`teacher_id`, `department_id`, `status`, `school_term`, `school_year`) VALUES (?, ?, ?, '1st Term', '2025-2026')");
    $stmtAffil->execute([$chairCsId, $csDeptId, 'permanent']);
    $stmtAffil->execute([$deanCetId, $csDeptId, 'permanent']);
    $stmtAffil->execute([$turingId, $csDeptId, 'permanent']);
    $stmtAffil->execute([$lovelaceId, $csDeptId, 'probationary']);
    $stmtAffil->execute([$hopperId, $itDeptId, 'part time']);

    // 8. Teacher Loads (Sample schedules)
    $stmtLoad = $pdo->prepare("INSERT INTO `teacher_loads` (`offer_code`, `teacher_id`, `subject_name`, `subject_description`, `days`, `time`, `room`, `department_id`, `school_term`, `school_year`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '1st Term', '2025-2026')");
    
    // Turing's Loads
    $stmtLoad->execute(['CS101-A', $turingId, 'CS 101', 'Introduction to Computing', 'MWF', '08:00 am - 09:00 am', 'Lab 301', $csDeptId]);
    $stmtLoad->execute(['CS201-B', $turingId, 'CS 201', 'Data Structures & Algorithms', 'TTh', '09:00 am - 10:30 am', 'Room 402', $csDeptId]);
    
    // Lovelace's Loads
    $stmtLoad->execute(['CS302-A', $lovelaceId, 'CS 302', 'Operating Systems', 'MWF', '10:00 am - 11:00 am', 'Lab 302', $csDeptId]);
    $stmtLoad->execute(['CS401-A', $lovelaceId, 'CS 401', 'Compiler Design', 'TTh', '01:00 pm - 02:30 pm', 'Room 405', $csDeptId]);

    // Placeholder Load Example (*** O P E N *** schedule to be assigned later by Monitoring Head)
    $stmtLoad->execute(['CS-OPEN-1', null, 'CS 405', 'Cloud Computing Special Topics', '*** O P E N ***', '*** O P E N ***', 'TBA', $csDeptId]);

    // 9. Initial Teacher Report Summaries & Penalty Accumulators for testing
    $stmtSummary = $pdo->prepare("INSERT INTO `teacher_report_summaries` (`teacher_id`, `school_year`, `school_term`, `overall_remarks`, `is_locked_operator`, `is_locked_monitoring`, `is_locked_teacher`, `is_locked_chairperson`, `is_locked_dean`) VALUES (?, '2025-2026', '1st Term', ?, 0, 0, 0, 0, 0)");
    $stmtSummary->execute([$turingId, 'Initial term summary initialized for Prof. Turing.']);
    $stmtSummary->execute([$lovelaceId, 'Initial term summary initialized for Prof. Lovelace.']);

    $stmtPenalty = $pdo->prepare("INSERT INTO `attendance_penalties` (`teacher_id`, `school_year`, `school_term`, `accumulated_lates_count`, `converted_absents_count`) VALUES (?, '2025-2026', '1st Term', 0, 0)");
    $stmtPenalty->execute([$turingId]);
    $stmtPenalty->execute([$lovelaceId]);
}
