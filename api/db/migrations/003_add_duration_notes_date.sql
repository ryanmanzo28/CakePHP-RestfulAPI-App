-- Migration: add duration, notes, and date columns to workouts
-- Some servers may already have these columns; run conditional ALTERs for compatibility.
SELECT COUNT(*) INTO @has_duration FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workouts' AND COLUMN_NAME = 'duration';
SET @stmt = IF(@has_duration = 0, 'ALTER TABLE `workouts` ADD COLUMN `duration` VARCHAR(64) NULL AFTER `data`', 'SELECT 1');
PREPARE _stmt FROM @stmt; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SELECT COUNT(*) INTO @has_notes FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workouts' AND COLUMN_NAME = 'notes';
SET @stmt = IF(@has_notes = 0, 'ALTER TABLE `workouts` ADD COLUMN `notes` TEXT NULL AFTER `duration`', 'SELECT 1');
PREPARE _stmt FROM @stmt; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SELECT COUNT(*) INTO @has_date FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workouts' AND COLUMN_NAME = 'date';
SET @stmt = IF(@has_date = 0, 'ALTER TABLE `workouts` ADD COLUMN `date` DATE NULL AFTER `notes`', 'SELECT 1');
PREPARE _stmt FROM @stmt; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
