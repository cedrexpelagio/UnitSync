-- UnitSync Schema for MVP (Stage 0)
-- Target Database: unitsync (Already created in phpMyAdmin)
-- Engine: InnoDB, Charset: utf8mb4

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `attendance_records`;
DROP TABLE IF EXISTS `attendance_submissions`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `cadets`;
DROP TABLE IF EXISTS `training_sessions`;
DROP TABLE IF EXISTS `terms`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `user_assignments`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `platoons`;
DROP TABLE IF EXISTS `companies`;
DROP TABLE IF EXISTS `programs`;

SET FOREIGN_KEY_CHECKS = 1;

-- Controlled list of Academic Programs (sections for Class Presidents)
CREATE TABLE `programs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controlled list of Companies
CREATE TABLE `companies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controlled list of Platoons belonging to Companies
CREATE TABLE `platoons` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_company_platoon` (`company_id`, `name`),
    CONSTRAINT `fk_platoons_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table for all system accounts
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `student_number` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `role` ENUM('admin', 'class_president', 'platoon_leader', 'battalion_s1', 'brigade_s1') NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'deactivated') NOT NULL DEFAULT 'pending',
    `rejection_reason` TEXT DEFAULT NULL,
    `deactivation_reason` TEXT DEFAULT NULL,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `reviewed_by` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    CONSTRAINT `fk_users_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Specific assignments for Class Presidents and Platoon Leaders
CREATE TABLE `user_assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `program_id` INT DEFAULT NULL,
    `company_id` INT DEFAULT NULL,
    `platoon_id` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assignments_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_assignments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_assignments_platoon` FOREIGN KEY (`platoon_id`) REFERENCES `platoons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login attempts for rate limiting and temporary lockout
CREATE TABLE `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `attempted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_username_attempt` (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log for tracking actions
CREATE TABLE `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `actor_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity` VARCHAR(100) NOT NULL,
    `entity_id` INT DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Terms / Semesters (only one active at a time)
CREATE TABLE `terms` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_terms_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Training sessions per term
CREATE TABLE `training_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `term_id` INT NOT NULL,
    `session_date` DATE NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `status` ENUM('scheduled', 'held', 'cancelled') NOT NULL DEFAULT 'scheduled',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sessions_term_date` (`term_id`, `session_date`),
    CONSTRAINT `fk_sessions_term` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Enrollments Table
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


