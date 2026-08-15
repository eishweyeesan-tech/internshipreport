-- ============================================================
-- InternReport Management System — Clean Core Database Schema
-- File: database/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS intern_report_db;
USE intern_report_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. INDEPENDENT TABLES
-- ============================================================

-- Unified Users table (admins, students, supervisors, instructors)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(30) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    position VARCHAR(100) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'student', 'supervisor', 'instructor') NOT NULL DEFAULT 'student',
    is_first_login TINYINT(1) NOT NULL DEFAULT 1,
    academic_year VARCHAR(15) DEFAULT NULL,
    academic_year_id INT DEFAULT NULL,
    status ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
    profile_pic VARCHAR(255) DEFAULT NULL,
    github_link VARCHAR(255) DEFAULT NULL,
    linkedin_link VARCHAR(255) DEFAULT NULL,
    portfolio_link VARCHAR(255) DEFAULT NULL,
    last_login_at DATETIME DEFAULT NULL,
    is_warned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_username_year (username, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- System settings
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. STUDENT & SUPERVISOR PROFILE TABLES
-- ============================================================

-- Student profiles table
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. INTERNSHIP LOGGING & REFLECTION TABLES
-- ============================================================

-- Daily logs table
CREATE TABLE IF NOT EXISTS daily_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    log_date DATE NOT NULL,
    attendance_status ENUM('present', 'leave', 'absent') NOT NULL DEFAULT 'present',
    reason_for_absence TEXT DEFAULT NULL,
    task_title VARCHAR(255) NOT NULL DEFAULT '',
    task_detail TEXT DEFAULT NULL,
    tasks_performed TEXT NOT NULL,
    actual_tasks TEXT DEFAULT NULL,
    tools_used VARCHAR(255) DEFAULT NULL,
    learnt_skills VARCHAR(255) DEFAULT NULL,
    challenges VARCHAR(255) DEFAULT NULL,
    start_time VARCHAR(5) NOT NULL DEFAULT '09:00',
    end_time VARCHAR(5) NOT NULL DEFAULT '17:00',
    calculated_duration VARCHAR(20) NOT NULL DEFAULT '00:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_log (internship_id, log_date),
    FOREIGN KEY (internship_id) REFERENCES student_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weekly reflections table
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. LINK & EVALUATION TABLES
-- ============================================================

-- Magic links for students
CREATE TABLE IF NOT EXISTS magic_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internship_id INT NOT NULL,
    week_number INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_week_link (internship_id, week_number),
    FOREIGN KEY (internship_id) REFERENCES student_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Report evaluations (instructor grading & approval)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Supervisor weekly evaluations (university grading)
CREATE TABLE IF NOT EXISTS supervisor_weekly_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    week_number INT NOT NULL,
    supervisor_id INT NOT NULL,
    weekly_grade ENUM('A', 'B', 'C', 'D', 'F') NOT NULL,
    supervisor_comments TEXT DEFAULT NULL,
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_weekly_eval (student_id, week_number),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. NOTIFICATION TABLES
-- ============================================================

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM(
        'instructor_approved',
        'instructor_rejected',
        'supervisor_approved',
        'new_report_submitted',
        'report_needs_review',
        'student_behind_schedule',
        'internship_completed',
        'system_notice',
        'info'
    ) NOT NULL DEFAULT 'info',
    related_week INT DEFAULT NULL,
    student_id INT DEFAULT NULL,
    report_id INT DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user_read (user_id, is_read),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default system settings
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('default_student_password', 'password1234'),
('default_supervisor_password', 'password1234');

-- Default admin account (password: "password")
INSERT IGNORE INTO users (username, email, password, role, is_first_login) VALUES
('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 0);

SET FOREIGN_KEY_CHECKS = 1;
