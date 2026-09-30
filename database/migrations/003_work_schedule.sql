-- NovaHR schema v3 — work schedule + paper-sheet attendance.
-- Employees no longer clock in: attendance is signed on the paper sheet, the admin
-- records late/absent days from it, and the dashboard clock only displays the time
-- together with the employee's service hours and break, set by the admin.

ALTER TABLE users
    ADD COLUMN work_end_time    TIME NOT NULL DEFAULT '17:00:00' AFTER work_start_time,
    ADD COLUMN break_start_time TIME NOT NULL DEFAULT '12:00:00' AFTER work_end_time,
    ADD COLUMN break_end_time   TIME NOT NULL DEFAULT '13:00:00' AFTER break_start_time;

UPDATE company_info
   SET content = 'Standard service hours are Monday to Friday, 8:00 AM to 5:00 PM, with a one-hour break from 12:00 to 1:00 PM. Your own service and break times are set by the admin and shown under the clock on your dashboard. Attendance is taken on the paper sign-in sheet at the reception.'
 WHERE topic_key = 'working_hours';

INSERT INTO company_info (topic_key, title, content, tags, is_onboarding, visible_to_candidates)
SELECT 'justify_absence', 'Justifying an Absence or a Late Arrival',
       'Sign the attendance sheet every morning when you arrive. If you were absent or arrived late, open Attendance in NovaHR, choose the date, say whether it was a late arrival or an absence, write the reason and attach proof if you have one (PDF or image, up to 8 MB). The admin approves or rejects it; an approved day is not deducted from your salary.',
       'justify,justification,absent,absence,late,lateness,sheet,sign,excuse,proof', 1, 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM company_info WHERE topic_key = 'justify_absence');
