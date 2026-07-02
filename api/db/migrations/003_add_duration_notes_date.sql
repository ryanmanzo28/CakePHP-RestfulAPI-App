-- Migration: add duration, notes, and date columns to workouts
ALTER TABLE `workouts`
  ADD COLUMN `duration` VARCHAR(64) NULL AFTER `data`,
  ADD COLUMN `notes` TEXT NULL AFTER `duration`,
  ADD COLUMN `date` DATE NULL AFTER `notes`;
