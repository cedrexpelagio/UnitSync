-- Migration 006: Add submission_id to attendance_records, add indexes, and backfill
-- Run in phpMyAdmin: select `unitsync` database > SQL tab > paste > Go

-- 1. Add submission_id column, index, and foreign key
ALTER TABLE `attendance_records`
    ADD COLUMN `submission_id` INT DEFAULT NULL AFTER `session_id`,
    ADD INDEX `idx_attendance_submission` (`submission_id`),
    ADD CONSTRAINT `fk_attendance_submission` FOREIGN KEY (`submission_id`) REFERENCES `attendance_submissions` (`id`) ON DELETE SET NULL;

-- 2. Add composite indexes
ALTER TABLE `attendance_submissions`
    ADD INDEX `idx_submissions_session_state` (`session_id`, `state`);

ALTER TABLE `enrollments`
    ADD INDEX `idx_enrollments_term_platoon` (`term_id`, `platoon_id`);

-- 3. Backfill submission_id on existing attendance_records
UPDATE `attendance_records` ar
JOIN `training_sessions` ts ON ts.id = ar.session_id
JOIN `enrollments` e ON e.cadet_id = ar.cadet_id AND e.term_id = ts.term_id
JOIN `attendance_submissions` sub ON sub.session_id = ar.session_id AND sub.platoon_id = e.platoon_id
SET ar.submission_id = sub.id
WHERE ar.submission_id IS NULL;
