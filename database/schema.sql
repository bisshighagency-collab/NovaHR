-- =============================================================================
-- NovaHR — Employee Management System with an integrated AI Assistant
-- Database schema (MySQL 5.7+ / MariaDB 10.3+)
--
-- Mirrors the class diagram in the project report: AbstractUser -> Admin /
-- Employee (single-table, discriminated by `role`), Department, Attendance,
-- Payroll, CompanyInfo (AI knowledge base), AIQuery, JobPosting, Candidate,
-- InterviewSession.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS novahr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE novahr;

-- -----------------------------------------------------------------------------
-- Departments
-- -----------------------------------------------------------------------------
CREATE TABLE departments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Users (AbstractUser -> Admin / Employee, discriminated by `role`)
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(190) NOT NULL UNIQUE,
    phone           VARCHAR(30)  DEFAULT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','employee') NOT NULL DEFAULT 'employee',
    status          ENUM('pending','active','suspended','deleted') NOT NULL DEFAULT 'active',
    department_id   INT UNSIGNED DEFAULT NULL,
    hire_date       DATE DEFAULT NULL,
    work_start_time TIME NOT NULL DEFAULT '08:00:00', -- this employee's service hours and break, set by the admin and
    work_end_time   TIME NOT NULL DEFAULT '17:00:00', -- shown under the clock on the employee dashboard (attendance itself
    break_start_time TIME NOT NULL DEFAULT '12:00:00', -- is signed on the paper sheet; the admin records late/absent days)
    break_end_time  TIME NOT NULL DEFAULT '13:00:00',
    position        VARCHAR(120) DEFAULT NULL,        -- job title/position in the company, editable by the employee
    location        VARCHAR(150) DEFAULT NULL,        -- work site / city, editable by the employee
    address         VARCHAR(255) DEFAULT NULL,        -- home/mailing address, editable by the employee
    base_salary     DECIMAL(12,2) DEFAULT NULL,       -- set individually per employee by the admin; no standard/default salary
    late_deduction  DECIMAL(12,2) NOT NULL DEFAULT 0, -- FCFA deducted per unexcused late day, set by the admin per employee
    absence_deduction DECIMAL(12,2) NOT NULL DEFAULT 0, -- FCFA deducted per unexcused absence day, set by the admin per employee
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Attendance
-- -----------------------------------------------------------------------------
CREATE TABLE attendance (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT UNSIGNED NOT NULL,
    work_date    DATE NOT NULL,
    clock_in     DATETIME DEFAULT NULL,
    clock_out    DATETIME DEFAULT NULL,
    latitude     DECIMAL(10,7) DEFAULT NULL,
    longitude    DECIMAL(10,7) DEFAULT NULL,
    status       ENUM('present','late','absent','corrected') NOT NULL DEFAULT 'present',
    justification_text   TEXT DEFAULT NULL,           -- the employee's explanation for a late/absent day
    justification_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    justification_file      VARCHAR(64)  DEFAULT NULL, -- randomly-generated stored filename (see app/storage/justifications/)
    justification_file_name VARCHAR(255) DEFAULT NULL, -- original filename, for display only
    justification_file_type VARCHAR(100) DEFAULT NULL, -- MIME type, used for Content-Type when serving the file
    justification_reviewed_by INT UNSIGNED DEFAULT NULL,
    justification_reviewed_at DATETIME DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_reviewer FOREIGN KEY (justification_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_employee_day (employee_id, work_date)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Payroll
-- -----------------------------------------------------------------------------
CREATE TABLE payroll (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT UNSIGNED NOT NULL,
    period_month  CHAR(7) NOT NULL,                 -- e.g. '2026-09'
    base_salary   DECIMAL(12,2) NOT NULL DEFAULT 0,
    deductions    DECIMAL(12,2) NOT NULL DEFAULT 0,
    bonuses       DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_salary    DECIMAL(12,2) NOT NULL DEFAULT 0,
    days_present  INT UNSIGNED NOT NULL DEFAULT 0,
    late_count    INT UNSIGNED NOT NULL DEFAULT 0,     -- unexcused late days that were deducted
    absent_count  INT UNSIGNED NOT NULL DEFAULT 0,     -- unexcused absence days that were deducted
    status        ENUM('draft','pending_review','approved','rejected','paid') NOT NULL DEFAULT 'draft',
    transaction_ref VARCHAR(64) DEFAULT NULL,
    generated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at       DATETIME DEFAULT NULL,
    CONSTRAINT fk_payroll_employee FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_employee_period (employee_id, period_month)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Company Info (also serves as the AI assistant's knowledge base)
-- -----------------------------------------------------------------------------
CREATE TABLE company_info (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    topic_key   VARCHAR(100) NOT NULL UNIQUE,
    title       VARCHAR(200) NOT NULL,
    content     TEXT NOT NULL,
    tags        VARCHAR(255) DEFAULT NULL,          -- comma-separated keywords to aid AI search
    is_onboarding BOOLEAN NOT NULL DEFAULT 0,        -- shown as part of the new-hire walkthrough
    visible_to_candidates BOOLEAN NOT NULL DEFAULT 0, -- shown in the candidate dashboard's FAQ section
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- AI Queries (log of Ask-AI-Assistant interactions)
-- -----------------------------------------------------------------------------
CREATE TABLE ai_queries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED DEFAULT NULL,
    question    TEXT NOT NULL,
    answer      TEXT NOT NULL,
    matched_topic VARCHAR(100) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ai_queries_employee FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Job Postings
-- -----------------------------------------------------------------------------
CREATE TABLE job_postings (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(150) NOT NULL,
    description  TEXT NOT NULL,
    requirements TEXT DEFAULT NULL,
    department_id INT UNSIGNED DEFAULT NULL,
    status       ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_by   INT UNSIGNED DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_job_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_job_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Interview question bank (per job posting; a NULL job_posting_id is a
-- generic question usable for any posting)
-- -----------------------------------------------------------------------------
CREATE TABLE interview_questions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_posting_id  INT UNSIGNED DEFAULT NULL,
    question_text   VARCHAR(500) NOT NULL,
    expected_keywords VARCHAR(255) DEFAULT NULL,   -- comma-separated, used by the scoring heuristic
    order_index     INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_question_job FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Candidates — internal applications. Job openings are posted for the company's
-- own employees only; an employee applies by signing in to the Candidate Portal
-- with the SAME email/password as their employee account, so a candidacy points
-- at the employee's users row instead of holding separate login details.
-- -----------------------------------------------------------------------------
CREATE TABLE candidates (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    job_posting_id      INT UNSIGNED NOT NULL,
    motivation          TEXT DEFAULT NULL,
    application_status  ENUM('applied','interview_scheduled','interviewed','shortlisted','rejected','hired')
                         NOT NULL DEFAULT 'applied',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_candidate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_candidate_job FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE CASCADE,
    UNIQUE KEY uq_candidate_job (user_id, job_posting_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Interview Sessions (AI-conducted interview + score)
-- -----------------------------------------------------------------------------
CREATE TABLE interview_sessions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidate_id   INT UNSIGNED NOT NULL,
    job_posting_id INT UNSIGNED NOT NULL,
    transcript_json LONGTEXT DEFAULT NULL,   -- [{question, answer}, ...]
    score          DECIMAL(5,2) DEFAULT NULL,
    status         ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
    started_at     DATETIME DEFAULT NULL,
    completed_at   DATETIME DEFAULT NULL,
    CONSTRAINT fk_session_candidate FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
    CONSTRAINT fk_session_job FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Announcements (Admin -> all employees company-wide broadcasts)
-- -----------------------------------------------------------------------------
CREATE TABLE announcements (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200) NOT NULL,
    body        TEXT NOT NULL,
    created_by  INT UNSIGNED DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_announcement_admin FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Notifications (e.g. "your application was shortlisted") for one user
-- -----------------------------------------------------------------------------
CREATE TABLE notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    body        TEXT NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Helpful indexes
-- -----------------------------------------------------------------------------
CREATE INDEX idx_attendance_date ON attendance(work_date);
CREATE INDEX idx_payroll_period ON payroll(period_month);
CREATE INDEX idx_candidates_status ON candidates(application_status);
CREATE INDEX idx_users_role ON users(role);
