-- Migration 007: officers table for the S1 officer CSV import
-- Run ONCE in phpMyAdmin: select the `unitsync` database > SQL tab > paste > Go
--
-- Officers are a roster record (like cadets), not login accounts.
-- * company_commander: belongs to a company, platoon_id stays NULL
-- * platoon_leader:    belongs to a company AND a platoon
-- One active company commander per company and one active platoon leader
-- per platoon is enforced by the import page.

CREATE TABLE IF NOT EXISTS `officers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `last_name` VARCHAR(100) NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `role` ENUM('company_commander', 'platoon_leader') NOT NULL,
    `company_id` INT NOT NULL,
    `platoon_id` INT DEFAULT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_officers_name` (`last_name`, `first_name`),
    INDEX `idx_officers_slot` (`role`, `company_id`, `platoon_id`, `status`),
    CONSTRAINT `fk_officers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_officers_platoon` FOREIGN KEY (`platoon_id`) REFERENCES `platoons` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_officers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
