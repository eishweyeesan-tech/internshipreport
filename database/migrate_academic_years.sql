-- ============================================================
-- ACADEMIC YEAR MIGRATION (for existing databases)
-- ============================================================
-- Run this ONCE if your database was created BEFORE the
-- academic year management feature was added.
--
-- Usage:
--   mysql -u root -p intern_report_db < migrate_academic_years.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Create academic_years table
CREATE TABLE IF NOT EXISTS academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_label VARCHAR(15) NOT NULL COMMENT 'e.g. 2025-2026',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('UPCOMING', 'ACTIVE', 'ARCHIVED') NOT NULL DEFAULT 'UPCOMING',
    is_current TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Only one row should have 1',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_year_label (year_label),
    INDEX idx_status (status),
    INDEX idx_is_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add academic_year_id to users (if missing)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'academic_year_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN academic_year_id INT DEFAULT NULL AFTER academic_year',
    'SELECT "users.academic_year_id already exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK constraint to users (if missing)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND CONSTRAINT_NAME = 'fk_users_academic_year' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "fk_users_academic_year already exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add academic_year_id to supervisor_assignments (if missing)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_assignments' AND COLUMN_NAME = 'academic_year_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE supervisor_assignments ADD COLUMN academic_year_id INT DEFAULT NULL AFTER academic_year',
    'SELECT "supervisor_assignments.academic_year_id already exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK constraint to supervisor_assignments (if missing)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_assignments'
      AND CONSTRAINT_NAME = 'fk_supassign_academic_year' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE supervisor_assignments ADD CONSTRAINT fk_supassign_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "fk_supassign_academic_year already exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed academic years from existing data
INSERT IGNORE INTO academic_years (year_label, start_date, end_date, status, is_current)
SELECT
    y.year_label,
    STR_TO_DATE(CONCAT(SUBSTRING_INDEX(y.year_label, '-', 1), '-09-01'), '%Y-%m-%d') AS start_date,
    STR_TO_DATE(CONCAT(SUBSTRING_INDEX(y.year_label, '-', -1), '-08-31'), '%Y-%m-%d') AS end_date,
    'ARCHIVED' AS status,
    0 AS is_current
FROM (
    SELECT DISTINCT academic_year AS year_label
    FROM users
    WHERE academic_year IS NOT NULL AND academic_year REGEXP '^[0-9]{4}-[0-9]{4}$'
    UNION
    SELECT DISTINCT academic_year AS year_label
    FROM supervisor_assignments
    WHERE academic_year IS NOT NULL AND academic_year REGEXP '^[0-9]{4}-[0-9]{4}$'
) y
WHERE NOT EXISTS (SELECT 1 FROM academic_years ay WHERE ay.year_label = y.year_label);

-- Set the most recent year as ACTIVE/current
UPDATE academic_years SET is_current = 0, status = 'ARCHIVED' WHERE is_current = 1;
UPDATE academic_years SET status = 'ACTIVE', is_current = 1
WHERE id = (SELECT id FROM (SELECT id FROM academic_years ORDER BY start_date DESC LIMIT 1) t);

-- Backfill academic_year_id in users
UPDATE users u
INNER JOIN academic_years ay ON ay.year_label = u.academic_year
SET u.academic_year_id = ay.id
WHERE u.academic_year IS NOT NULL AND u.academic_year_id IS NULL;

-- Backfill academic_year_id in supervisor_assignments
UPDATE supervisor_assignments sa
INNER JOIN academic_years ay ON ay.year_label = sa.academic_year
SET sa.academic_year_id = ay.id
WHERE sa.academic_year IS NOT NULL AND sa.academic_year_id IS NULL;

-- Create trigger to enforce single current year.
-- NOTE: a trigger cannot issue UPDATE/INSERT/DELETE against the same table it
-- fires on (MySQL error 1442), so this trigger only *guards* (SIGNAL) instead
-- of rewriting sibling rows. The application's transition code
-- (admin/transition_year.php) is responsible for the actual flip, using
-- row-level locks and explicit clearing of the previous current year.
DROP TRIGGER IF EXISTS trg_enforce_single_current_year;
DELIMITER //
CREATE TRIGGER trg_enforce_single_current_year
BEFORE UPDATE ON academic_years
FOR EACH ROW
BEGIN
    IF NEW.is_current = 1 THEN
        IF (SELECT COUNT(*) FROM academic_years WHERE is_current = 1 AND id <> NEW.id) > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only one academic year can be marked as current';
        END IF;
    END IF;
END //
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
-- Verify: should show exactly 1 active year
SELECT year_label, status, is_current FROM academic_years ORDER BY start_date DESC;
