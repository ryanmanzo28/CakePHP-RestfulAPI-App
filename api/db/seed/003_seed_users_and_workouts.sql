-- 003_seed_users_and_workouts.sql
-- Inserts a sample user and a sample workout for local testing.
INSERT INTO `users` (`email`, `username`, `password`) VALUES
('test@example.com', 'testuser', '$2y$10$F5ElLqYRPkGVsVI8Vr1Hb.J2HeIBUmxUGquMBMGFPZIGSKoA03WO6');

-- If the users table's AUTO_INCREMENT starts at 1, this will associate the sample workout with the seeded user.
INSERT INTO `workouts` (`user_id`, `title`, `account_hash`, `data`) VALUES
(1, 'Sample Workout', 'samplehash', JSON_OBJECT());
