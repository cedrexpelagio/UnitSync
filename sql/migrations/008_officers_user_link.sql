-- Migration 008: link a Platoon Leader officer to a generated login account
-- Run ONCE in phpMyAdmin: select the `unitsync` database > SQL tab > paste > Go
-- (Run migration 007 first.)
--
-- officers.user_id points to the users row that is created automatically
-- for an active Platoon Leader. It stays NULL for Company Commanders.

ALTER TABLE `officers`
    ADD COLUMN `user_id` INT DEFAULT NULL AFTER `platoon_id`,
    ADD UNIQUE KEY `uq_officers_user` (`user_id`),
    ADD CONSTRAINT `fk_officers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
