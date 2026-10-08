-- Migration 004: support for the S1 roster and CSV import (works on top of migration 003)
-- Run ONCE in phpMyAdmin: select the `unitsync` database > SQL tab > paste > Go
--
-- * designation: S1 import records a designation (for example "Cadet")
-- * birthday, student_number, email become optional so S1 can bulk-import
--   cadets from a CSV that has only name, gender, designation and program.
--   (The Platoon Leader "Add Cadet" form still requires them. UNIQUE keys stay,
--   and MySQL allows many NULLs in a UNIQUE column.)

ALTER TABLE `cadets`
    ADD COLUMN `designation` VARCHAR(100) DEFAULT NULL AFTER `program_id`,
    MODIFY COLUMN `birthday` DATE DEFAULT NULL,
    MODIFY COLUMN `student_number` VARCHAR(50) DEFAULT NULL,
    MODIFY COLUMN `email` VARCHAR(150) DEFAULT NULL;
