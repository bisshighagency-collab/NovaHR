<?php
/**
 * Where uploaded justification files (e.g. doctor's notes) are kept.
 *
 * This lives OUTSIDE both `frontend/` and `backend/` — the two folders that
 * ever get pointed to by a web server's document root — so the files are
 * never directly reachable by URL. They can only be read through
 * AttendanceController::downloadFile(), which checks that the requester is
 * either the employee who submitted the justification or an admin.
 */
define('JUSTIFICATION_STORAGE_DIR', __DIR__ . '/../../storage/justifications');

if (!is_dir(JUSTIFICATION_STORAGE_DIR)) {
    mkdir(JUSTIFICATION_STORAGE_DIR, 0755, true);
}
