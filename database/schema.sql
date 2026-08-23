-- ============================================================
-- InternReport Management System — Clean Core Database Schema (8 Core Tables)
-- File: database/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS intern_report_db;
USE intern_report_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Academic Years
CREATE TABLE IF NOT EXISTS academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_label VARCHAR(50) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    default_student_password VARCHAR(255) NOT NULL DEFAULT 'password1234',
    default_supervisor_password VARCHAR(255) NOT NULL DEFAULT 'password1234',
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Active', 'Upcoming', 'Archived') NOT NULL DEFAULT 'Upcoming',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Companies
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

-- 3. Users (Unified)
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
    academic_year_id INT DEFAULT NULL,
    status ENUM('Active', 'Inactive', 'Archived') NOT NULL DEFAULT 'Active',
    profile_pic VARCHAR(255) DEFAULT NULL,
    last_login_at DATETIME DEFAULT NULL,
    is_warned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Supervisor Academic Assignments
CREATE TABLE IF NOT EXISTS supervisor_academic_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supervisor_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT DEFAULT NULL,
    UNIQUE KEY unique_sup_year (supervisor_id, academic_year_id),
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Student Profiles
CREATE TABLE IF NOT EXISTS student_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    supervisor_id INT DEFAULT NULL,
    company_id INT DEFAULT NULL,
    student_roll VARCHAR(50) DEFAULT '',
    major VARCHAR(100) DEFAULT '',
    internship_start_date DATE DEFAULT NULL,
    internship_end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Daily Logs
CREATE TABLE IF NOT EXISTS daily_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
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
    UNIQUE KEY unique_log (student_id, log_date),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Weekly Reports & Evaluations (Consolidated)
CREATE TABLE IF NOT EXISTS weekly_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    week_number INT NOT NULL,
    what_done TEXT NOT NULL,
    how_done TEXT NOT NULL,
    why_done TEXT NOT NULL,
    instructor_review_code VARCHAR(64) DEFAULT NULL UNIQUE,
    instructor_grade ENUM('excellent', 'good', 'average', 'needs_improvement') DEFAULT NULL,
    instructor_comments TEXT DEFAULT NULL,
    supervisor_grade ENUM('A', 'B', 'C', 'D', 'F') DEFAULT NULL,
    supervisor_comments TEXT DEFAULT NULL,
    status ENUM('pending', 'approved_by_instructor', 'graded', 'rejected') NOT NULL DEFAULT 'pending',
    student_signature_type VARCHAR(20) DEFAULT NULL,
    student_signature_value TEXT DEFAULT NULL,
    student_signed_at DATETIME DEFAULT NULL,
    instructor_signature_type VARCHAR(20) DEFAULT NULL,
    instructor_signature_value TEXT DEFAULT NULL,
    instructor_signed_at DATETIME DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_week (student_id, week_number),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'system_notice',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default academic years
INSERT IGNORE INTO academic_years (year_label, start_date, end_date, default_student_password, default_supervisor_password, is_current, status) VALUES
('2023-2024', '2023-09-01', '2024-08-31', 'password1234', 'password1234', 1, 'Active'),
('2024-2025', '2024-09-01', '2025-08-31', 'password1234', 'password1234', 0, 'Upcoming');

-- Default admin account (password: "password")
INSERT IGNORE INTO users (username, email, password, role, is_first_login) VALUES
('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 0);

SET FOREIGN_KEY_CHECKS = 1;
