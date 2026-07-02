-- Migration: add JSON data column to workouts
ALTER TABLE `workouts`
  ADD COLUMN `data` JSON NULL AFTER `duration`;
