-- Teacher Attendance Monitoring System (TAMS) Database Schema
-- Supports MariaDB and MySQL 5.7+

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `teachers` (
  `teacher_id` INT AUTO_INCREMENT PRIMARY KEY,
  `last_name` VARCHAR(100) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `username` VARCHAR(100) UNIQUE NULL,
  `password_hash` VARCHAR(255) NULL,
  `failed_attempts` INT DEFAULT 0,
  `lockout_until` DATETIME NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `must_change_password` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
  `current_school_year` VARCHAR(20) NOT NULL,
  `current_school_term` VARCHAR(50) NOT NULL,
  `vp_academics_id` INT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  INDEX `idx_vp_academics` (`vp_academics_id`),
  CONSTRAINT `fk_settings_vp` FOREIGN KEY (`vp_academics_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_checkers` (
  `checker_id` INT AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT NULL,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `failed_attempts` INT DEFAULT 0,
  `lockout_until` DATETIME NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  INDEX `idx_checker_teacher` (`teacher_id`),
  CONSTRAINT `fk_checker_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `monitoring_heads` (
  `head_id` INT AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT NULL,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `failed_attempts` INT DEFAULT 0,
  `lockout_until` DATETIME NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  INDEX `idx_head_teacher` (`teacher_id`),
  CONSTRAINT `fk_head_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `colleges` (
  `college_id` INT AUTO_INCREMENT PRIMARY KEY,
  `abbreviation` VARCHAR(50) NOT NULL UNIQUE,
  `full_name` VARCHAR(255) NOT NULL,
  `dean_id` INT NULL,
  INDEX `idx_college_dean` (`dean_id`),
  CONSTRAINT `fk_college_dean` FOREIGN KEY (`dean_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `departments` (
  `department_id` INT AUTO_INCREMENT PRIMARY KEY,
  `college_id` INT NOT NULL,
  `department_abbreviation` VARCHAR(50) NOT NULL,
  `department_full_name` VARCHAR(255) NOT NULL,
  `chairperson_id` INT NULL,
  INDEX `idx_department_college` (`college_id`),
  INDEX `idx_department_chair` (`chairperson_id`),
  CONSTRAINT `fk_dept_college` FOREIGN KEY (`college_id`) REFERENCES `colleges` (`college_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dept_chair` FOREIGN KEY (`chairperson_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `teacher_department` (
  `td_id` INT AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT NOT NULL,
  `department_id` INT NOT NULL,
  `status` ENUM('permanent', 'probationary', 'part time') DEFAULT 'permanent',
  `school_term` VARCHAR(50) NOT NULL,
  `school_year` VARCHAR(20) NOT NULL,
  INDEX `idx_td_teacher` (`teacher_id`),
  INDEX `idx_td_department` (`department_id`),
  INDEX `idx_td_term_year` (`school_year`, `school_term`),
  CONSTRAINT `fk_td_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_td_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `teacher_loads` (
  `load_id` INT AUTO_INCREMENT PRIMARY KEY,
  `offer_code` VARCHAR(50) NOT NULL,
  `teacher_id` INT NULL,
  `subject_name` VARCHAR(100) NOT NULL,
  `subject_description` VARCHAR(255) NULL,
  `days` VARCHAR(50) NOT NULL,
  `time` VARCHAR(100) NOT NULL,
  `room` VARCHAR(50) NOT NULL,
  `department_id` INT NULL,
  `school_term` VARCHAR(50) NOT NULL,
  `school_year` VARCHAR(20) NOT NULL,
  INDEX `idx_tl_offer_code` (`offer_code`),
  INDEX `idx_tl_teacher` (`teacher_id`),
  INDEX `idx_tl_dept` (`department_id`),
  INDEX `idx_tl_term_year` (`school_year`, `school_term`),
  CONSTRAINT `fk_tl_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tl_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `load_id` INT NULL,
  `teacher_id` INT NOT NULL,
  `date` DATE NOT NULL,
  `school_year` VARCHAR(20) NOT NULL,
  `school_term` VARCHAR(50) NOT NULL,
  `scheduled_time_in` TIME NULL,
  `scheduled_time_out` TIME NULL,
  `actual_time_in` TIME NULL,
  `actual_time_out` TIME NULL,
  `source` ENUM('manual_operator', 'device_biometric') DEFAULT 'manual_operator',
  `late_minutes` INT DEFAULT 0,
  `undertime_minutes` INT DEFAULT 0,
  `status` ENUM('Present', 'Late', 'Absent', 'Excused', 'Holiday', 'Suspended', 'Partial Suspension') DEFAULT 'Present',
  `workflow_status` ENUM('draft', 'submitted_operator', 'confirmed_monitoring', 'submitted_teacher', 'submitted_chairperson', 'submitted_dean', 'returned_to_teacher', 'verified_monitoring', 'final_approved_vp') DEFAULT 'draft',
  `schedule_remarks` TEXT NULL,
  `logged_by` INT NULL,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_al_teacher` (`teacher_id`),
  INDEX `idx_al_load` (`load_id`),
  INDEX `idx_al_date` (`date`),
  INDEX `idx_al_term_year` (`school_year`, `school_term`),
  INDEX `idx_al_workflow` (`workflow_status`),
  CONSTRAINT `fk_al_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_al_load` FOREIGN KEY (`load_id`) REFERENCES `teacher_loads` (`load_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `calendar_events` (
  `event_id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_type` ENUM('holiday', 'emergency_closure', 'partial_suspension') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `start_time` TIME NULL,
  `end_time` TIME NULL,
  `scope_type` ENUM('institution', 'college', 'department', 'subject_load') NOT NULL,
  `scope_id` INT NULL,
  `require_event_checkin` TINYINT(1) DEFAULT 0,
  `created_by` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ce_dates` (`start_date`, `end_date`),
  INDEX `idx_ce_scope` (`scope_type`, `scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `teacher_report_summaries` (
  `summary_id` INT AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT NOT NULL,
  `school_year` VARCHAR(20) NOT NULL,
  `school_term` VARCHAR(50) NOT NULL,
  `overall_remarks` TEXT NULL,
  `is_locked_operator` TINYINT(1) DEFAULT 0,
  `is_locked_monitoring` TINYINT(1) DEFAULT 0,
  `is_locked_teacher` TINYINT(1) DEFAULT 0,
  `is_locked_chairperson` TINYINT(1) DEFAULT 0,
  `is_locked_dean` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_teacher_term_year` (`teacher_id`, `school_year`, `school_term`),
  INDEX `idx_trs_teacher` (`teacher_id`),
  CONSTRAINT `fk_trs_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_evidence` (
  `evidence_id` INT AUTO_INCREMENT PRIMARY KEY,
  `log_id` INT NOT NULL,
  `teacher_id` INT NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `remarks` TEXT NULL,
  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ae_log` (`log_id`),
  INDEX `idx_ae_teacher` (`teacher_id`),
  CONSTRAINT `fk_ae_log` FOREIGN KEY (`log_id`) REFERENCES `attendance_logs` (`log_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ae_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_penalties` (
  `penalty_id` INT AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT NOT NULL,
  `school_year` VARCHAR(20) NOT NULL,
  `school_term` VARCHAR(50) NOT NULL,
  `accumulated_lates_count` INT DEFAULT 0,
  `converted_absents_count` INT DEFAULT 0,
  UNIQUE KEY `uniq_penalty_teacher_term` (`teacher_id`, `school_year`, `school_term`),
  INDEX `idx_ap_teacher` (`teacher_id`),
  CONSTRAINT `fk_ap_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `audit_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  `action` VARCHAR(255) NOT NULL,
  `target_record_id` INT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_role` (`role`),
  INDEX `idx_audit_time` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `report_audit_trails` (
  `trail_id` INT AUTO_INCREMENT PRIMARY KEY,
  `summary_id` INT NULL,
  `teacher_id` INT NOT NULL,
  `school_year` VARCHAR(20) NOT NULL,
  `school_term` VARCHAR(50) NOT NULL,
  `actor_id` INT NOT NULL,
  `actor_role` VARCHAR(50) NOT NULL,
  `action_type` VARCHAR(100) NOT NULL,
  `previous_workflow_status` VARCHAR(50) NULL,
  `new_workflow_status` VARCHAR(50) NULL,
  `remarks_snapshot` TEXT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_rat_summary` (`summary_id`),
  INDEX `idx_rat_teacher` (`teacher_id`),
  INDEX `idx_rat_actor` (`actor_id`, `actor_role`),
  INDEX `idx_rat_time` (`timestamp`),
  CONSTRAINT `fk_rat_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rat_summary` FOREIGN KEY (`summary_id`) REFERENCES `teacher_report_summaries` (`summary_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
