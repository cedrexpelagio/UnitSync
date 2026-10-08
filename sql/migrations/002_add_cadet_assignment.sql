-- Migration 002: cadet company/platoon assignment + status list (S1 roster)
-- Requires 001_create_cadets.sql to have been run first.
-- Run ONCE in phpMyAdmin: select the `unitsync` database > SQL tab > paste > Go

ALTER TABLE `cadets`
    ADD COLUMN `company_id` INT DEFAULT NULL AFTER `program`,
    ADD COLUMN `platoon_id` INT DEFAULT NULL AFTER `company_id`,
    MODIFY COLUMN `status` ENUM('active', 'unassigned', 'dropped', 'transferred', 'graduated') NOT NULL DEFAULT 'unassigned',
    ADD INDEX `idx_cadets_company_platoon` (`company_id`, `platoon_id`),
    ADD CONSTRAINT `fk_cadets_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_cadets_platoon` FOREIGN KEY (`platoon_id`) REFERENCES `platoons` (`id`) ON DELETE SET NULL;

-- Cadets imported before this migration have no assignment yet
UPDATE `cadets` SET `status` = 'unassigned' WHERE `status` = 'active' AND `company_id` IS NULL;
