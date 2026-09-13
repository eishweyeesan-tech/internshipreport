# Internship Report Management System (InternReport)

A centralized digital system for **Polytechnic University (Faculty of Computing)** that connects undergraduate interns, university faculty supervisors, and company instructors for **daily work-log tracking, weekly reflections, and academic evaluation** — end to end, paperless.

![Tech](https://img.shields.io/badge/PHP-8.x-777BB4) ![DB](https://img.shields.io/badge/MySQL-8.x-4479A1) ![UI](https://img.shields.io/badge/Tailwind%20CSS-CDN-38B2AC)

---

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Workflow](#workflow)
- [User Roles](#user-roles)
- [Tech Stack](#tech-stack)
- [Requirements](#requirements)
- [Installation](#installation)
- [Default Accounts](#default-accounts)
- [Project Structure](#project-structure)
- [Configuration](#configuration)
- [Email & Notifications](#email--notifications)
- [Security](#security)
- [Database](#database)
- [Known Notes](#known-notes)

---

## Overview

InternReport replaces paper logbooks with a single web portal where:

- **Students** record daily work, attendance and hours, submit weekly reflections, and generate official printable reports.
- **Company instructors** review submitted reports through a secure, passwordless **magic link** and provide feedback, grades, and type-to-sign features.
- **University supervisors** monitor assigned students, review instructor feedback, record A–F university grades, and track attendance/progress.
- **Admins** manage academic years, companies, students, supervisors, and view full student history.

---

## Key Features

- **Role-based access control (RBAC)** with automatic directory-level enforcement
  (`admin/`, `supervisor/`, `student/`) via `auth.php`.
- **Daily logs** — attendance status (present / leave / absent), task title, tasks performed, tools used, skills learned, start/end times with automatic working-duration calculation.
- **Weekly reflections** — structured *What / How / Why* submissions per internship week.
- **Automatic week calculation** — Sun→Sat week convention derived from each student's internship start/end dates (`config/week_helper.php`, `config/internship_progress.php`).
- **Official A4 report generation** — printable per-week or all-weeks report with daily-log tables, reflections, evaluation/feedback, and three formal signature blocks (student, company instructor, university supervisor). Bilingual (English / မြန်မာ).
- **Instructor magic links** — one-click, no-login, time-limited review links emailed to company instructors.
- **Digital signatures** — instructor and student signatures can be typed or uploaded; instructor signatures via HTML5 canvas pad.
- **Notifications** — in-app notification system with role-aware deep links (`config/notify.php`), plus email dispatch.
- **Academic year management** — active-year selection, creation, archiving, and supervisor/student assignment filtering.
- **Supervisor dashboards** — attendance rates, progress %, grades, per-week review queues.
- **Default seeded demo data** — 12 students (2025-2026) and 10 students (2024-2025) with realistic Myanmar-language logs, reflections and evaluations.

---

## Workflow

```
1. Student records daily logs (tasks, hours, attendance)
        │
2. Student writes weekly reflection (What / How / Why)
        │
3. Student submits week → system emails a magic link to the
   company instructor
        │
4. Company instructor reviews + grades + signs via the link
   (report_status → approved_by_instructor / rejected)
        │
5. University supervisor reviews instructor feedback & daily logs,
   assigns official A–F weekly grade
```

For the **2025–2026** academic year the internship period runs **May 5 → July 31, 2026 (13 weeks)**.

---

## User Roles

| Role       | Scope                                                                   | Entry dashboard                     |
|------------|-------------------------------------------------------------------------|-------------------------------------|
| **Admin**  | Academic years, companies, students, supervisors, full history, reports | `admin/admin-dashboard.php`         |
| **Student**| Daily logs, weekly reflections, print report, history, notifications    | `student/student-dashboard.php`     |
| **Supervisor** | Assigned students, reports review, A–F grading, attendance & progress | `supervisor/supervisor-dashboard.php` |
| **Instructor** | No account — reviews via emailed magic-link token                     | `instructor/view-report.php?token=…` |

---

## Tech Stack

- **PHP 8.x** (procedural, MySQLi object-oriented)
- **MySQL** (`intern_report_db`, utf8mb4)
- **Tailwind CSS** (CDN) + custom CSS animations
- **Vanilla JavaScript** (scroll reveals, counters, canvas signatures, AJAX)
- **Pure-PHP socket SMTP mailer** (no Composer/PHPMailer dependency)

---

## Requirements

- AMP stack: **WAMP / XAMPP / Laragon** (Apache + PHP 8+ + MySQL)
- Any modern browser (Chrome, Edge, Firefox)
- Internet connection for CDN assets (Tailwind, Google Fonts, Font Awesome)

---

## Installation

1. **Copy the project** into your web root, e.g. for WAMP:
   ```
   C:\wamp64\www\internreportsystem
   ```

2. **Create the database.** Open phpMyAdmin (or the MySQL CLI) and import:
   ```
   database/schema.sql
   ```
   This creates the `intern_report_db` database, all tables, default academic years (2023–2024, 2024–2025), the default admin account and three faculty supervisors.

3. **Configure DB credentials** in `config/db.php`:
   ```php
   $host     = 'localhost';
   $port     = 3306;
   $dbname   = 'intern_report_db';
   $username = 'root';
   $password = 'root';   // WAMP default; change for your environment
   ```

4. **(Optional) Seed rich demo data.** Run either script from the CLI:
   ```
   php database/seed_2025_2026_data.php   # 12 students, 2025–2026 (13 weeks, Myanmar data)
   php database/seed_mock_data.php        # 10 students, 2024–2025 (13 weeks, Myanmar data)
   ```
   The 2025–2026 seeder also sets that year as the current active year and creates/assigns student accounts (password `password1234`), daily logs, reflections, evaluations and notifications.

5. **Open the app:**
   ```
   http://localhost/internreportsystem
   ```
   Sign in with one of the default accounts below.

---

## Default Accounts

| Role      | Username / Email                      | Password   | Notes                                        |
|-----------|---------------------------------------|------------|----------------------------------------------|
| Admin     | `admin` / `admin@gmail.com`           | `password` | Seeded in `schema.sql`                       |
| Supervisor| `umya.instructor@gmail.com` (U Mya)   | `password` | Faculty of Computer Science                  |
| Supervisor| `aungkyaw.supervisor@gmail.com` (Dr. Aung Kyaw) | `password` | Faculty of Computer Science       |
| Supervisor| `susu.supervisor@gmail.com` (Dr. Su Su Hlaing) | `password` | Faculty of Computer Systems and Tech |
| Student   | e.g. `nangeikhaing@gmail.com` (5CS-1) | `password1234` | Created by the 2025–2026 seeder    |

> Change all default passwords after first login — the system forces a password change on first login (`change-password.php`).

Student emails for the 2025–2026 seeder: `nangeikhaing@gmail.com`, `thinzar.2025@gmail.com`, `kyawzin.2025@gmail.com`, `maymyatnoe.2025@gmail.com`, `heinhtet.2025@gmail.com`, `hsumyat.2025@gmail.com`, `kaungkhant.2025@gmail.com`, `yoonnadi.2025@gmail.com`, `aungphone.2025@gmail.com`, `sumyatnoe.2025@gmail.com`, `minkhant.2025@gmail.com`, `thethtar.2025@gmail.com`.

---

## Project Structure

```
internreportsystem/
├── index.php                  # Public landing page
├── login.php                  # Sign-in page
├── change-password.php        # First-login / self-service password change
├── logout.php                 # Session destroy
├── auth.php                   # Shared session + role-based gate (require once in every page)
├── dashboard.php              # Generic redirector
├── view_student_history.php   # Full student history (admin view)
├── admin/                     # Admin area
│   ├── admin-dashboard.php    # Main admin dashboard (students / history / manage tabs)
│   ├── admin-profile.php
│   ├── manage-academic-years.php
│   ├── manage-companies.php
│   ├── manage-students.php    # Thin entry point into admin-dashboard.php
│   ├── manage-supervisors.php
│   └── api/                   # AJAX endpoints (archive_year, assign_supervisor, create_year, ...)
├── student/                   # Student area
│   ├── student-dashboard.php  # Unified dashboard (daily log + weekly report tabs)
│   ├── daily_log.php          # Redirect to dashboard daily-log tab
│   ├── daily_logs_table.php
│   ├── weekly_reflections_table.php
│   ├── log-history.php        # History + print report launcher
│   ├── print_report.php       # Official A4 printable report (per-week / all-weeks)
│   ├── instructions.php
│   ├── notifications.php
│   └── profile.php
├── supervisor/                # Supervisor area
│   ├── supervisor-dashboard.php
│   ├── my-students.php
│   ├── supervisor-reports.php
│   ├── supervisor-review.php  # Per-week review + A–F grading
│   ├── view-student-dashboard.php
│   ├── supervisor-companies.php
│   ├── supervisor-student-search-api.php
│   ├── notifications.php / profile.php / print_report.php
│   └── includes/              # Supervisor sidebar + topbar
├── instructor/
│   └── view-report.php        # Magic-link report review + signature (no login)
├── api/
│   └── notifications.php      # AJAX: mark read / mark all read
├── config/
│   ├── db.php                 # MySQLi connection
│   ├── week_helper.php        # Sun→Sat week range/number helpers (+ dev tester UI)
│   ├── internship_progress.php# Progress / attendance calculations
│   ├── notify.php             # Notification insert, dedup, deep-link routing
│   └── mailer.php             # Pure-PHP SMTP mailer + HTML templates
├── includes/                  # Shared helpers & partials
│   ├── academic_year_helper.php
│   ├── security_helper.php
│   ├── phone_validation.php
│   ├── ui_helpers.php / ui-helper.php
│   ├── notification_actions.php
│   └── admin-sidebar.php / student-topbar.php / topbar.php
├── database/
│   ├── schema.sql             # Full schema + default seeds
│   ├── seed_2025_2026_data.php
│   └── seed_mock_data.php
├── uploads/                   # Profile pics, signatures (gitignored mail previews)
├── logs/                      # mail.log etc. (gitignored)
└── assets/                    # Images, static assets
```

---

## Configuration

### Database — `config/db.php`
Single canonical MySQLi connection. Edit host / port / dbname / username / password for your environment.

### Email — `config/mailer.php`
- `MAIL_SMTP_ENABLED`, `MAIL_SMTP_HOST`, `MAIL_SMTP_PORT`, `MAIL_SMTP_USER`, `MAIL_SMTP_PASS` (Gmail App Password example included).
- `APP_URL` — `'auto'` auto-detects the PC's Wi‑Fi/LAN IP (or set a custom tunnel/domain URL). Used to build the instructor magic-link URL in emails.
- Every email is also saved as an HTML preview in `uploads/mail_previews/` and logged to `logs/mail.log` — handy for local testing even when SMTP is off.

### Academic year helpers — `includes/academic_year_helper.php`
Auto-creates/migrates the `academic_years` and `users.status ENUM` tables, ensures an active year exists, and provides lookup/assignment helpers.

---

## Email & Notifications

- **Magic-link email** — when a student submits a weekly report, a unique token is inserted into `magic_links` (expiry date) and `config/mailer.php:send_instructor_magic_link()` emails the company instructor a styled review button: `…/instructor/view-report.php?token=…`.
- **In-app notifications** — the `notifications` table stores role-aware items (`instructor_approved`, `instructor_rejected`, `new_report_submitted`, `student_behind_schedule`, `internship_completed`, …). `notif_action_url()` in `config/notify.php` generates the correct destination per recipient role. AJAX mark-as-read is in `api/notifications.php`.

---

## Security

- Passwords hashed with `password_hash()` / verified with `password_verify()`.
- First-login password change enforced; strong-password validation in `includes/security_helper.php`.
- Directory-level RBAC in `auth.php` — files under `admin/`, `supervisor/`, `student/` auto-redirect unauthenticated or unauthorized users.
- Authenticated pages emit `Cache-Control: no-store` headers.
- Instructors use expiring single-purpose magic-link tokens (validated against `magic_links.expires_at`), and students are blocked from self-evaluating on `instructor/view-report.php`.
- Inactive supervisors and archived/past-year student accounts are blocked from logging in.
- Prepared statements used for all database queries.

---

## Database

Database: `intern_report_db` (utf8mb4). Core tables (see `database/schema.sql`):

- `users` — unified accounts (admin / student / supervisor / instructor), academic year, status
- `student_profiles` — full_name, roll, major, company, instructors, internship dates
- `companies` — host-company registry
- `academic_years` — year periods, active/current flag
- `daily_logs` — per-day attendance + task + hours (unique per internship & date)
- `weekly_reflections` — What / How / Why (unique per internship & week)
- `report_evaluations` — instructor grade, comments, signatures, approval status (unique per student & week)
- `supervisor_weekly_evaluations` — university A–F weekly grades
- `magic_links` — instructor review tokens with expiry (unique per internship & week)
- `notifications` — role-aware in-app notices

Week numbering uses a **Sun → Sat** convention: week 1 starts on the internship start date and ends on the following Saturday; subsequent weeks are 7-day blocks (`config/week_helper.php`).

---

## Known Notes

- The default student password from the seeders is `password1234`; the seeders delete and rebuild their own students each run.
- Tailwind CSS, Google Fonts, and Font Awesome are loaded from CDNs — the styling depends on network access.
- SMTP credentials in `config/mailer.php` are placeholders/examples — replace with your own (e.g. a Gmail App Password) for production delivery, or leave enabled to keep the preview/log workflow.

---

© Polytechnic University (Faculty of Computing). All rights reserved.