-- Migration 003: Cadets, Enrollments, Attendance Records, and Attendance Submissions (Stage PL-2)
-- Target Database: unitsync

SET FOREIGN_KEY_CHECKS = 0;

-- Drop temporary prototype table if it exists
DROP TABLE IF EXISTS `attendance_records`;
DROP TABLE IF EXISTS `attendance_submissions`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `cadets`;

SET FOREIGN_KEY_CHECKS = 1;

-- Cadets Table
CREATE TABLE `cadets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cadet_code` VARCHAR(50) NOT NULL UNIQUE,
    `last_name` VARCHAR(100) NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `birthday` DATE NOT NULL,
    `gender` ENUM('Male', 'Female') NOT NULL,
    `program_id` INT NOT NULL,
    `student_number` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `contact_number` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('active', 'dropped', 'transferred', 'graduated') NOT NULL DEFAULT 'active',
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cadets_name` (`last_name`, `first_name`),
    INDEX `idx_cadets_status` (`status`),
    CONSTRAINT `fk_cadets_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cadets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollments Table (connects a cadet to a term and platoon/company; NULL platoon/company means Unassigned)
CREATE TABLE `enrollments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cadet_id` INT NOT NULL,
    `term_id` INT NOT NULL,
    `company_id` INT DEFAULT NULL,
    `platoon_id` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_cadet_term` (`cadet_id`, `term_id`),
    INDEX `idx_enrollments_company_platoon` (`company_id`, `platoon_id`),
    CONSTRAINT `fk_enrollments_cadet` FOREIGN KEY (`cadet_id`) REFERENCES `cadets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_enrollments_term` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_enrollments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_enrollments_platoon` FOREIGN KEY (`platoon_id`) REFERENCES `platoons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance Records Table
CREATE TABLE `attendance_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cadet_id` INT NOT NULL,
    `session_id` INT NOT NULL,
    `status` ENUM('P', 'A', 'L', 'E') NOT NULL,
    `minutes_late` INT DEFAULT NULL,
    `excuse_reason` TEXT DEFAULT NULL,
    `document_path` VARCHAR(255) DEFAULT NULL,
    `marked_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_cadet_session` (`cadet_id`, `session_id`),
    INDEX `idx_attendance_status` (`status`),
    CONSTRAINT `fk_attendance_cadet` FOREIGN KEY (`cadet_id`) REFERENCES `cadets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_session` FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_marked_by` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance Submissions Table
CREATE TABLE `attendance_submissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `platoon_id` INT NOT NULL,
    `session_id` INT NOT NULL,
    `state` ENUM('draft', 'submitted', 'returned', 'approved') NOT NULL DEFAULT 'draft',
    `submitted_by` INT DEFAULT NULL,
    `submitted_at` DATETIME DEFAULT NULL,
    `battalion_approved_by` INT DEFAULT NULL,
    `battalion_approved_at` DATETIME DEFAULT NULL,
    `brigade_approved_by` INT DEFAULT NULL,
    `brigade_approved_at` DATETIME DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_platoon_session` (`platoon_id`, `session_id`),
    INDEX `idx_submissions_state` (`state`),
    CONSTRAINT `fk_submissions_platoon` FOREIGN KEY (`platoon_id`) REFERENCES `platoons` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_submissions_session` FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_submissions_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_submissions_battalion_approved_by` FOREIGN KEY (`battalion_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_submissions_brigade_approved_by` FOREIGN KEY (`brigade_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
