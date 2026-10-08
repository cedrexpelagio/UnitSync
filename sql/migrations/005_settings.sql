-- Migration 005: Create settings table and seed attendance at-risk threshold
-- Run in phpMyAdmin: select `unitsync` database > SQL tab > paste > Go

CREATE TABLE IF NOT EXISTS `settings` (
    `key_name` VARCHAR(50) NOT NULL PRIMARY KEY,
    `value` TEXT DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key_name`, `value`, `description`)
VALUES ('attendance_at_risk_threshold', '80', 'Minimum attendance percentage threshold (cadets below this are flagged as at-risk)')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;
