-- =============================================================================
-- Migration 002 — internal job openings.
--
-- Job openings are now for the company's own employees. An employee applies by
-- signing in to the Candidate Portal with the same email/password as their
-- employee account, so a candidacy now points at the employee's `users` row
-- instead of storing its own name/email/password.
--
-- Only the recruitment tables are rebuilt (their old rows belonged to external
-- candidates, which no longer exist). Employees, departments, attendance,
-- justifications, payroll, announcements and the AI knowledge base are kept.
-- Run on an existing database created from the previous schema.sql.
-- =============================================================================
USE novahr;

DROP TABLE IF EXISTS interview_sessions;
DROP TABLE IF EXISTS candidates;

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

CREATE INDEX idx_candidates_status ON candidates(application_status);

CREATE TABLE interview_sessions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidate_id   INT UNSIGNED NOT NULL,
    job_posting_id INT UNSIGNED NOT NULL,
    transcript_json LONGTEXT DEFAULT NULL,
    score          DECIMAL(5,2) DEFAULT NULL,
    status         ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
    started_at     DATETIME DEFAULT NULL,
    completed_at   DATETIME DEFAULT NULL,
    CONSTRAINT fk_session_candidate FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
    CONSTRAINT fk_session_job FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    body        TEXT NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Reword the candidate FAQ for internal applications (only if still the seeded text).
UPDATE company_info SET content =
  'Job openings on NovaHR are internal: they are posted for INTERLINK employees only. After you apply, you can take a short AI-conducted interview right here in the Candidate Portal. An administrator then reviews every application together with its interview score.'
  WHERE topic_key = 'candidate_after_apply';
UPDATE company_info SET content =
  'You receive a notification here in the Candidate Portal as soon as your interview is scored or an administrator reviews your application, and the status on each application updates automatically. To get back to your normal work, log out of the Candidate Portal and sign in to your employee account with the same email and password.'
  WHERE topic_key = 'candidate_shortlist_timeline';
