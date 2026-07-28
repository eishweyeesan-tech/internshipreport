-- Migration: Add announcement_id column to notifications table
-- Links notification records to their source announcement for click-through navigation

ALTER TABLE notifications
    ADD COLUMN announcement_id INT DEFAULT NULL AFTER related_week;

ALTER TABLE notifications
    ADD CONSTRAINT fk_notifications_announcement
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE SET NULL;
