-- 003_seed_users_and_workouts.sql
-- Inserts a sample user and a sample workout for local testing.
-- 003_seed_users_and_workouts.sql
-- Inserts a sample user and a sample workout for local testing.
-- Use an upsert so the seed is safe to run multiple times.
INSERT INTO `users` (`email`, `username`, `password`) VALUES
('test@example.com', 'testuser', '$2y$10$F5ElLqYRPkGVsVI8Vr1Hb.J2HeIBUmxUGquMBMGFPZIGSKoA03WO6')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

-- Ensure we use the inserted/existing user's id for the workout
SET @uid = LAST_INSERT_ID();
INSERT INTO `workouts` (`user_id`, `title`, `account_hash`, `data`) VALUES
(@uid, 'Sample Workout', 'samplehash', JSON_OBJECT())
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
