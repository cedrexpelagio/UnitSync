-- Migration 001: cadets table (used by s1/import_cadets.php)
-- Run in phpMyAdmin: select the `unitsync` database > SQL tab > paste > Go

CREATE TABLE IF NOT EXISTS `cadets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `gender` ENUM('Male', 'Female') NOT NULL,
    `designation` VARCHAR(100) NOT NULL,
    `program` VARCHAR(100) NOT NULL,
    `status` ENUM('active', 'removed') NOT NULL DEFAULT 'active',
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cadets_name_program` (`full_name`, `program`),
    CONSTRAINT `fk_cadets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
