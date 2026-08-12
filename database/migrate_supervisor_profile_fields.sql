-- ============================================================
-- Add supervisor profile fields to the shared users table.
-- Phone, Department, Position are stored on the existing
-- `users` table (supervisors have no separate profile table).
--
-- Idempotent: safe to run multiple times.
-- ============================================================

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone') = 0,
    'ALTER TABLE users ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER email',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'department') = 0,
    'ALTER TABLE users ADD COLUMN department VARCHAR(100) DEFAULT NULL AFTER phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'position') = 0,
    'ALTER TABLE users ADD COLUMN position VARCHAR(100) DEFAULT NULL AFTER department',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
