-- =============================================================================
-- NovaHR — demo seed data. Run after schema.sql.
-- Demo passwords (bcrypt-hashed below): Admin@123 / Employee@123
-- (Employees sign in to the Candidate Portal with these same employee credentials.)
-- =============================================================================
USE novahr;

INSERT INTO departments (name, description) VALUES
  ('Software Engineering', 'Builds and maintains NovaHR and client products.'),
  ('Human Resources', 'Handles recruitment, payroll policy, and staff welfare.'),
  ('Finance', 'Manages budgets, invoicing, and financial reporting.');

-- Admin: admin@novahr.local / Admin@123
INSERT INTO users (name, email, phone, password_hash, role, status, department_id, hire_date) VALUES
  ('Ngo Patricia', 'admin@novahr.local', '+237670000001',
   '$2b$10$H.b5me384N1CHbdpAC/e7.jVLOYEdklF4MWo..v4eNb4huYM17MqS',
   'admin', 'active', 2, '2024-01-10');

-- Employees: employee1@novahr.local / employee2@novahr.local — Employee@123
-- Compensation (base_salary, late_deduction, absence_deduction) and work_start_time
-- (service hours + break) are both set individually per employee by the admin — there is no
-- standard/default salary or shift time.
INSERT INTO users (name, email, phone, password_hash, role, status, department_id, hire_date, work_start_time, position, location, address, base_salary, late_deduction, absence_deduction) VALUES
  ('Fotso Alain', 'employee1@novahr.local', '+237670000002',
   '$2b$10$lMpGN4sjXxm8W2nQaSiB/eIv1P80pT7afe.ECoRKLLTGvK.WKSoeC',
   'employee', 'active', 1, '2025-03-01', '09:00:00', 'Frontend Developer', 'Yaoundé HQ', 'Quartier Bastos, Yaoundé', 150000.00, 3000.00, 8000.00),
  ('Mballa Sonia', 'employee2@novahr.local', '+237670000003',
   '$2b$10$lMpGN4sjXxm8W2nQaSiB/eIv1P80pT7afe.ECoRKLLTGvK.WKSoeC',
   'employee', 'active', 3, '2025-06-15', '08:30:00', 'Financial Analyst', 'Yaoundé HQ', 'Quartier Mvan, Yaoundé', 175000.00, 3500.00, 9000.00);

-- AI assistant knowledge base (Manage AI Knowledge Base / Ask AI Assistant).
-- visible_to_candidates marks entries shown in the candidate dashboard's FAQ section —
-- distinct from is_onboarding (new-hire walkthrough, employees only).
INSERT INTO company_info (topic_key, title, content, tags, is_onboarding, visible_to_candidates) VALUES
  ('leave_policy', 'Leave Policy',
   'Employees accrue 1.5 days of paid leave per month worked (18 days/year). Submit leave requests to your department head at least 5 working days in advance.',
   'leave, vacation, holiday, time off, pto', 0, 0),
  ('working_hours', 'Working Hours',
   'Standard service hours are Monday to Friday, 8:00 AM to 5:00 PM, with a one-hour break from 12:00 to 1:00 PM. Your own service and break times are set by the admin and shown under the clock on your dashboard. Attendance is taken on the paper sign-in sheet at the reception.',
   'hours, schedule, time, late, shift', 0, 0),
  ('justify_absence', 'Justifying an Absence or a Late Arrival',
   'Sign the attendance sheet every morning when you arrive. If you were absent or arrived late, open Attendance in NovaHR, choose the date, say whether it was a late arrival or an absence, write the reason and attach proof if you have one (PDF or image, up to 8 MB). The admin approves or rejects it; an approved day is not deducted from your salary.',
   'justify, justification, absent, absence, late, lateness, sheet, sign, excuse, proof', 1, 0),
  ('payroll_dates', 'Payroll Schedule',
   'Payroll is calculated at the end of each calendar month and disbursed to your registered mobile money or bank account by the 5th of the following month.',
   'payroll, salary, pay, paid, payment, payday, wages, disbursed, disbursement', 0, 0),
  ('company_history', 'About the Company',
   'NovaHR is developed and operated by INTERLINK, a Cameroon-based technology company founded to help local enterprises digitize their operations, starting with human-resource management.',
   'history, about, company, interlink, founded', 1, 1),
  ('workspace_tools', 'Tools You Will Use',
   'Day to day you will use the NovaHR employee portal for attendance and payslips, your company email, and the internal chat for team communication. IT will provision your accounts on your first day.',
   'tools, software, accounts, setup, laptop, email', 1, 0),
  ('contacts', 'Who To Contact',
   'For HR questions, contact the Human Resources department. For technical issues with the platform, contact the Software Engineering department. For payroll disputes, contact Finance.',
   'contact, help, support, hr, it, finance', 1, 0),
  ('candidate_after_apply', 'What happens after I apply?',
   'Job openings on NovaHR are internal: they are posted for INTERLINK employees only. After you apply, you can take a short AI-conducted interview right here in the Candidate Portal. An administrator then reviews every application together with its interview score.',
   'apply, application, next steps, review, process', 0, 1),
  ('candidate_ai_interview', 'How does the AI interview work?',
   'The interview asks a short series of questions about the role. Answer in your own words — there is no time limit per question. Your answers are scored on relevance and depth, and the results are reviewed by our team alongside your application.',
   'ai interview, questions, how does it work, score, evaluation', 0, 1),
  ('candidate_shortlist_timeline', 'How will I know if I am shortlisted?',
   'You receive a notification here in the Candidate Portal as soon as your interview is scored or an administrator reviews your application, and the status on each application updates automatically. To get back to your normal work, log out of the Candidate Portal and sign in to your employee account with the same email and password.',
   'shortlist, timeline, status, update, contact, results', 0, 1);

-- Sample internal job openings (visible to employees only). Job #1 has its own
-- interview question bank; job #2 uses the generic fallback questions below.
INSERT INTO job_postings (title, description, requirements, department_id, status, created_by) VALUES
  ('Senior Frontend Developer', 'Lead the development of the NovaHR employee and admin web interfaces and mentor junior developers.',
   'Solid HTML/CSS/JavaScript; experience with REST APIs; at least one year at INTERLINK.',
   1, 'open', 1),
  ('HR Officer', 'Support recruitment, onboarding and staff welfare in the Human Resources department.',
   'Good communication and organisation skills; interest in people management.',
   2, 'open', 1);

INSERT INTO interview_questions (job_posting_id, question_text, expected_keywords, order_index) VALUES
  (1, 'Tell us about a project where you used HTML, CSS, and JavaScript together.', 'html, css, javascript, project', 0),
  (1, 'How do you call a REST API from the browser and handle its response?', 'fetch, api, json, response, async', 1),
  (1, 'How would you debug a page that is not displaying data from the server?', 'console, network, debug, error, devtools', 2);

-- Generic fallback questions (job_posting_id NULL) used when a posting has none of its own
INSERT INTO interview_questions (job_posting_id, question_text, expected_keywords, order_index) VALUES
  (NULL, 'Why are you interested in this position?', '', 0),
  (NULL, 'Describe a challenge you overcame in a previous role or project.', '', 1),
  (NULL, 'Where do you see yourself professionally in two years?', '', 2);

-- Welcome announcement (Admin -> all employees)
INSERT INTO announcements (title, body, created_by) VALUES
  ('Welcome to NovaHR', 'This is where INTERLINK will share company-wide updates and news. Check back here on your dashboard for the latest announcements.', 1);

-- No candidates are seeded: job openings are internal, so any employee can sign in to
-- the Candidate Portal with their own employee email/password and apply themselves.
