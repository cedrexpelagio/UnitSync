-- Migration 002: terms and training_sessions tables (Stage PL-1)
-- Target Database: unitsync

CREATE TABLE IF NOT EXISTS `terms` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_terms_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `training_sessions` (
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
