-- ============================================================
-- InternReport System - Complete Database Schema
-- ============================================================
-- ER DIAGRAM RELATIONSHIPS (read top-to-bottom for FK order):
--
--   users ──1:1──> student_profiles
--   users ──1:N──> student_profiles         (as supervisor)
--   users ──1:N──> student_profiles         (as instructor)
--   companies ──1:N──> student_profiles     (FK: company_id)
--   users ──1:N──> daily_logs               (FK: student_profiles.internship_id)
--   users ──1:N──> weekly_reflections       (FK: student_profiles.internship_id)
--   users ──1:N──> magic_links              (FK: student_profiles.internship_id)
--   users ──1:N──> instructor_magic_links   (FK: student_id)
--   users ──1:N──> report_evaluations       (FK: student_id)
--   users ──1:N──> supervisor_evaluations   (FK: student_id)
--   users ──1:N──> supervisor_alerts        (FK: supervisor_id, student_id)
--   users ──1:N──> supervisor_weekly_evaluations (FK: student_id, supervisor_id)
--   users ──1:N──> announcements            (FK: created_by)
--   users ──1:N──> notifications            (FK: user_id)
-- ============================================================

CREATE DATABASE IF NOT EXISTS intern_report_db;
USE intern_report_db;

-- ============================================================
-- INDEPENDENT TABLES (no foreign keys)
-- ============================================================

-- Users table (admins, students, supervisors, instructors)
-- PK: id
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'student', 'supervisor', 'instructor') NOT NULL DEFAULT 'student',
    is_first_login TINYINT(1) NOT NULL DEFAULT 1,
    academic_year VARCHAR(15) DEFAULT NULL,
    status ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Companies table
-- PK: id
CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL UNIQUE,
    address TEXT DEFAULT NULL,
    contact_person VARCHAR(150) DEFAULT NULL,
    contact_email VARCHAR(100) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System settings
-- PK: id
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- STUDENT PROFILE TABLES (depends on: users, companies)
-- ============================================================

-- Student profiles table
-- PK: id
-- FK: user_id      -> users.id          (CASCADE)
-- FK: supervisor_id -> users.id          (SET NULL)
-- FK: instructor_id -> users.id          (SET NULL)
-- FK: company_id   -> companies.id       (SET NULL)
CREATE TABLE IF NOT EXISTS student_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    supervisor_id INT DEFAULT NULL,
    instructor_id INT DEFAULT NULL,
    company_id INT DEFAULT NULL,
    full_name VARCHAR(150) DEFAULT '',
    student_roll VARCHAR(50) DEFAULT '',
    major VARCHAR(100) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    company_name VARCHAR(150) DEFAULT '',
    job_role VARCHAR(100) DEFAULT '',
    instructor_name VARCHAR(150) DEFAULT '',
    instructor_email VARCHAR(100) DEFAULT '',
    instructor_phone VARCHAR(30) DEFAULT '',
    internship_start_date DATE DEFAULT NULL,
    internship_end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

-- ============================================================
-- LOGGING TABLES (depends on: student_profiles via internship_id)
-- ============================================================

-- Daily logs table
-- PK: id
-- FK: internship_id -> student_profiles.id  (CASCADE)
CREATE TABLE daily_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    log_date DATE NOT NULL,
    attendance_status ENUM('present', 'leave', 'absent') NOT NULL DEFAULT 'present',
    reason_for_absence TEXT DEFAULT NULL,
    task_title VARCHAR(255) NOT NULL DEFAULT '',
    task_detail TEXT,
    tasks_performed TEXT NOT NULL,
    actual_tasks TEXT,
    tools_used VARCHAR(255),
    learnt_skills VARCHAR(255),
    challenges VARCHAR(255),
    start_time VARCHAR(5) NOT NULL DEFAULT '09:00',
    end_time VARCHAR(5) NOT NULL DEFAULT '17:00',
    calculated_duration VARCHAR(20) NOT NULL DEFAULT '00:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_log (internship_id, log_date),
    FOREIGN KEY (internship_id) REFERENCES student_profiles(id) ON DELETE CASCADE
);

-- Weekly reflections table
-- PK: id
-- FK: internship_id -> student_profiles.id  (CASCADE)
CREATE TABLE IF NOT EXISTS weekly_reflections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    week_number INT NOT NULL,
    what_done TEXT NOT NULL,
    how_done TEXT NOT NULL,
    why_done TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_week (internship_id, week_number),
    FOREIGN KEY (internship_id) REFERENCES student_profiles(id) ON DELETE CASCADE
);

-- ============================================================
-- LINK & EVALUATION TABLES (depends on: users, student_profiles)
-- ============================================================

-- Magic links for students
-- PK: id
-- FK: internship_id -> student_profiles.id  (CASCADE)
CREATE TABLE magic_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    week_number INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_week_link (internship_id, week_number),
    FOREIGN KEY (internship_id) REFERENCES student_profiles(id) ON DELETE CASCADE
);

-- Instructor magic links
-- PK: id
-- FK: student_id -> users.id  (CASCADE)
CREATE TABLE instructor_magic_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    week_number INT NOT NULL,
    magic_token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Report evaluations (instructor grading)
-- PK: id
-- FK: student_id -> users.id  (CASCADE)
CREATE TABLE IF NOT EXISTS report_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    week_number INT NOT NULL,
    grade ENUM('excellent', 'good', 'average', 'needs_improvement') NOT NULL DEFAULT 'good',
    comment TEXT NOT NULL,
    instructor_comments TEXT DEFAULT NULL,
    signature_type ENUM('typed', 'uploaded') DEFAULT NULL,
    signature_value VARCHAR(500) DEFAULT NULL,
    student_signature_type ENUM('typed', 'uploaded') DEFAULT NULL,
    student_signature_value VARCHAR(500) DEFAULT NULL,
    report_status ENUM('pending', 'approved_by_instructor', 'approved_by_supervisor', 'rejected') NOT NULL DEFAULT 'pending',
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_eval (student_id, week_number),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Supervisor evaluations (university grading)
-- PK: id
-- FK: student_id -> users.id  (CASCADE)
CREATE TABLE IF NOT EXISTS supervisor_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    week_number INT NOT NULL,
    university_grade ENUM('A', 'B', 'C', 'D', 'F') NOT NULL DEFAULT 'C',
    supervisor_remarks TEXT,
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_sup_eval (student_id, week_number),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- SYSTEM & NOTIFICATION TABLES (depends on: users)
-- ============================================================

-- Supervisor email alerts (to track sent notifications)
-- PK: id
-- FK: supervisor_id -> users.id  (CASCADE)
-- FK: student_id    -> users.id  (CASCADE)
CREATE TABLE IF NOT EXISTS supervisor_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supervisor_id INT NOT NULL,
    student_id INT NOT NULL,
    alert_type ENUM('red_badge', 'amber_warning', 'weekly_summary') NOT NULL DEFAULT 'red_badge',
    alert_date DATE NOT NULL,
    week_number INT DEFAULT NULL,
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_alert (supervisor_id, student_id, alert_type, alert_date),
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Weekly grading evaluations by supervisor
-- PK: id
-- FK: student_id    -> users.id  (CASCADE)
-- FK: supervisor_id -> users.id  (CASCADE)
CREATE TABLE IF NOT EXISTS supervisor_weekly_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    week_number INT NOT NULL,
    supervisor_id INT NOT NULL,
    weekly_grade ENUM('A', 'B', 'C', 'D', 'F') NOT NULL,
    supervisor_comments TEXT,
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_weekly_eval (student_id, week_number),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Announcements
-- PK: id
-- FK: created_by -> users.id  (SET NULL)
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Notifications table (in-app notifications for students)
-- PK: id
-- FK: user_id -> users.id  (CASCADE)
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('instructor_approved', 'instructor_rejected', 'supervisor_approved', 'info') NOT NULL DEFAULT 'info',
    related_week INT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default system settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('default_student_password', 'password123'),
('default_supervisor_password', 'password123');

-- Default test accounts (password for all: "password")
INSERT INTO users (username, email, password, role, is_first_login) VALUES
('admin',  'admin@example.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',      0);

-- ============================================================
-- MIGRATION for existing databases (run if tables already exist)
-- ============================================================
-- ALTER TABLE student_profiles ADD COLUMN company_id INT DEFAULT NULL AFTER supervisor_id;
-- ALTER TABLE student_profiles ADD COLUMN internship_end_date DATE DEFAULT NULL AFTER internship_start_date;
-- CREATE TABLE IF NOT EXISTS companies LIKE intern_report_db.companies;
--
-- 2026-07-08: Add company profile fields
-- ALTER TABLE companies ADD COLUMN address TEXT DEFAULT NULL AFTER company_name;
-- ALTER TABLE companies ADD COLUMN contact_person VARCHAR(150) DEFAULT NULL AFTER address;
-- ALTER TABLE companies ADD COLUMN contact_email VARCHAR(100) DEFAULT NULL AFTER contact_person;
-- ALTER TABLE companies ADD COLUMN contact_phone VARCHAR(30) DEFAULT NULL AFTER contact_email;
-- ALTER TABLE companies ADD COLUMN website VARCHAR(255) DEFAULT NULL AFTER contact_phone;
-- ALTER TABLE companies ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- ============================================================
-- MIGRATION: Profile Features & Login Tracking
-- Run these on existing databases to add new columns
-- ============================================================
ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT NULL AFTER status;
ALTER TABLE users ADD COLUMN github_link VARCHAR(255) DEFAULT NULL AFTER profile_pic;
ALTER TABLE users ADD COLUMN linkedin_link VARCHAR(255) DEFAULT NULL AFTER github_link;
ALTER TABLE users ADD COLUMN portfolio_link VARCHAR(255) DEFAULT NULL AFTER linkedin_link;
ALTER TABLE users ADD COLUMN last_login_at DATETIME DEFAULT NULL AFTER portfolio_link;
ALTER TABLE users ADD COLUMN is_warned TINYINT(1) DEFAULT 0;


-- ============================================================
-- MIGRATION: Instructor Role & Linking
-- Run on existing databases to add instructor support
-- ============================================================
-- ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'student', 'supervisor', 'instructor') NOT NULL DEFAULT 'student';
-- ALTER TABLE student_profiles ADD COLUMN instructor_id INT DEFAULT NULL AFTER supervisor_id;
-- ALTER TABLE student_profiles ADD FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================================
-- MIGRATION: Public Holidays (Myanmar Calendar)
-- Run on existing databases to add holiday support
-- ============================================================
CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(200) NOT NULL,
    holiday_name_mm VARCHAR(200) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_holiday_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;