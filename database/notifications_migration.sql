-- Migration: extend notifications table for supervisor notification types
-- Run once. Idempotent for enum/columns (re-running MODIFY/ADD is safe-ish),
-- so check information_schema before ADD COLUMN.

-- 1. Widen the type enum with the new supervisor notification types.
ALTER TABLE notifications
    MODIFY type ENUM(
        'instructor_approved',
        'instructor_rejected',
        'supervisor_approved',
        'new_report_submitted',
        'report_needs_review',
        'student_behind_schedule',
        'internship_completed',
        'system_notice',
        'info'
    ) NOT NULL DEFAULT 'info';

-- 2. Add the student/report target columns (for action links).
SET @has_student_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'student_id'
);
SET @has_report_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'report_id'
);

SET @ddl_student := IF(@has_student_id = 0,
    'ALTER TABLE notifications ADD COLUMN student_id INT NULL AFTER related_week',
    'SELECT 1');
PREPARE stmt1 FROM @ddl_student; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @ddl_report := IF(@has_report_id = 0,
    'ALTER TABLE notifications ADD COLUMN report_id INT NULL AFTER student_id',
    'SELECT 1');
PREPARE stmt1 FROM @ddl_report; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

-- 3. Speed up unread badge queries.
ALTER TABLE notifications
    ADD INDEX idx_notif_user_read (user_id, is_read);
