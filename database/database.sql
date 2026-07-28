-- ============================================================
-- InternReport System - Complete Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS intern_report_db;
USE intern_report_db;

-- ============================================================
-- CORE TABLES
-- ============================================================

-- Companies table
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

-- Users table (admins, students, supervisors)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'student', 'supervisor') NOT NULL DEFAULT 'student',
    is_first_login TINYINT(1) NOT NULL DEFAULT 1,
    academic_year VARCHAR(15) DEFAULT NULL,
    status ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Student profiles table
CREATE TABLE IF NOT EXISTS student_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    supervisor_id INT DEFAULT NULL,
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
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- LOGGING TABLES
-- ============================================================

-- Daily logs table
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
    calculated_duration VARCHAR(5) NOT NULL DEFAULT '08:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_log (internship_id, log_date)
);

-- Weekly reflections table
CREATE TABLE IF NOT EXISTS weekly_reflections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    week_number INT NOT NULL,
    what_done TEXT NOT NULL,
    how_done TEXT NOT NULL,
    why_done TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_week (internship_id, week_number)
);

-- ============================================================
-- LINK & EVALUATION TABLES
-- ============================================================

-- Magic links for students
CREATE TABLE magic_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    week_number INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_week_link (internship_id, week_number)
);

-- Instructor magic links
CREATE TABLE instructor_magic_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    week_number INT NOT NULL,
    magic_token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL
);

-- Report evaluations (instructor grading)
CREATE TABLE IF NOT EXISTS report_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    week_number INT NOT NULL,
    grade ENUM('excellent', 'good', 'average', 'needs_improvement') NOT NULL DEFAULT 'good',
    comment TEXT NOT NULL,
    instructor_comments TEXT DEFAULT NULL,
    signature_type ENUM('typed', 'uploaded') DEFAULT NULL,
    signature_value VARCHAR(500) DEFAULT NULL,
    report_status ENUM('pending', 'approved_by_instructor', 'approved_by_supervisor', 'rejected') NOT NULL DEFAULT 'pending',
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_eval (student_id, week_number),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Supervisor evaluations (university grading)
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
-- SYSTEM TABLES
-- ============================================================

-- Supervisor email alerts (to track sent notifications)
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
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- System settings
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default system settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('default_student_password', 'password123'),
('default_supervisor_password', 'password123');

-- Default test accounts (password for all: "password")
INSERT INTO users (username, email, password, role, is_first_login) VALUES
('admin',  'admin@example.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',      0),
('supervisor1', 'sup@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'supervisor', 1),
('john_doe',    'john@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student',    1);

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
    