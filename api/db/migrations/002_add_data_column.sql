-- Migration: add JSON data column to workouts
-- Some MySQL versions (and MariaDB) don't support `ADD COLUMN IF NOT EXISTS`.
-- Use an INFORMATION_SCHEMA check and a prepared statement so the migration is safe to run repeatedly.
SET @has_col = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workouts' AND COLUMN_NAME = 'data'
);
SET @stmt = IF(@has_col = 0,
  'ALTER TABLE `workouts` ADD COLUMN `data` JSON NULL AFTER `duration`',
  'SELECT "column already exists"'
);
PREPARE _stmt FROM @stmt;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;
