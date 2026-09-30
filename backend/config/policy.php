<?php
/**
 * Default work schedule given to a new employee when the admin doesn't set one.
 * Each employee then has their own service hours and break (users.work_start_time,
 * work_end_time, break_start_time, break_end_time), edited by the admin on the
 * Employees page and shown under the clock on the employee dashboard.
 *
 * Attendance itself is taken on the paper sign-in sheet: the admin records late or
 * absent days from the sheet, and employees justify those days in the app.
 */
define('WORK_START_TIME', '08:00:00');
define('WORK_END_TIME', '17:00:00');
define('BREAK_START_TIME', '12:00:00');
define('BREAK_END_TIME', '13:00:00');
