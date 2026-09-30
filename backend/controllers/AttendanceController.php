<?php
class AttendanceController
{
    /**
     * GET /api/attendance/schedule — the employee's service hours and break (set by the
     * admin) and the server's clock, for the dashboard clock. The clock only displays
     * the time: attendance itself is signed on the paper sheet. server_time comes from
     * MySQL's NOW() so the dashboard clock follows the same clock as the rest of the app.
     */
    public static function schedule(): void
    {
        $claims = require_role('employee');
        $now = db()->query('SELECT NOW() AS now')->fetch()['now'];
        json_response(UserModel::scheduleFor((int) $claims['sub']) + ['server_time' => $now]);
    }

    /** GET /api/attendance/history — the employee's own attendance history. */
    public static function myHistory(): void
    {
        $claims = require_role('employee');
        json_response(['history' => AttendanceModel::history((int) $claims['sub'])]);
    }

    /** GET /api/attendance?period=YYYY-MM — Admin: review attendance records. */
    public static function listForAdmin(): void
    {
        require_role('admin');
        $period = $_GET['period'] ?? date('Y-m');
        json_response(['period' => $period, 'records' => AttendanceModel::allForPeriod($period)]);
    }

    /**
     * POST /api/attendance/record — Admin copies a day from the paper sign-in sheet:
     * {employee_id, date, status: late|absent|present, arrival_time?: "HH:MM"}.
     * Creates the day or updates it (the same endpoint corrects a mistake).
     */
    public static function record(): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['employee_id', 'date', 'status']);
        $status = (string) $body['status'];
        if (!in_array($status, ['late', 'absent', 'present'], true)) {
            json_error('Status must be late, absent or present.', 422);
        }
        $date = (string) $body['date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            json_error('Choose a valid date that is not in the future.', 422);
        }
        $arrival = trim((string) ($body['arrival_time'] ?? ''));
        if ($arrival !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $arrival)) {
            json_error('Arrival time must be in HH:MM format.', 422);
        }
        $employee = UserModel::findById((int) $body['employee_id']);
        if (!$employee || $employee['role'] !== 'employee') {
            json_error('Employee not found.', 404);
        }
        $entry = AttendanceModel::record((int) $employee['id'], $date, $status, $arrival === '' ? null : $arrival);
        json_response(['message' => 'Attendance recorded.', 'entry' => $entry], 201);
    }

    /**
     * POST /api/attendance/justify — employee justifies a late arrival or an
     * absence, optionally attaching proof (e.g. a doctor's note). Sent as
     * multipart/form-data (fields: date, type = late|absent, reason, and an
     * optional file) so the file rides alongside the text in one request.
     */
    public static function justify(): void
    {
        $claims = require_role('employee');
        $date = trim((string) ($_POST['date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $type = ($_POST['type'] ?? 'absent') === 'late' ? 'late' : 'absent';

        if ($date === '' || $reason === '') {
            json_error('Missing required field(s): date, reason', 422);
        }
        if ($date > date('Y-m-d')) {
            json_error('You cannot justify a future date.', 422);
        }

        $file = null;
        if (!empty($_FILES['file']['name'])) {
            $file = self::storeJustificationFile($_FILES['file']);
        }

        try {
            $entry = AttendanceModel::justify((int) $claims['sub'], $date, $reason, $file, $type);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 422);
        }
        json_response(['message' => 'Justification submitted for admin review.', 'entry' => $entry]);
    }

    /** Validates and saves an uploaded proof document under a random name; never trusts the client's filename. */
    private static function storeJustificationFile(array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            json_error('The file upload failed. Please try again.', 422);
        }
        if ($file['size'] > 8 * 1024 * 1024) {
            json_error('The attached file is too large (max 8MB).', 422);
        }

        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            json_error('Only PDF, JPG, PNG, or WEBP files are accepted as proof.', 422);
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], JUSTIFICATION_STORAGE_DIR . '/' . $stored)) {
            json_error('Could not save the uploaded file.', 500);
        }

        return ['stored' => $stored, 'original' => basename($file['name']), 'type' => $mime];
    }

    /** GET /api/attendance/{id}/file — stream a justification's attached proof (the owning employee or an admin only). */
    public static function downloadFile(int $id): void
    {
        $claims = require_auth();
        $entry = AttendanceModel::findById($id);
        if (!$entry || !$entry['justification_file']) {
            json_error('No file attached to this record.', 404);
        }

        $isOwner = $claims['role'] === 'employee' && (int) $claims['sub'] === (int) $entry['employee_id'];
        $isAdmin = $claims['role'] === 'admin';
        if (!$isOwner && !$isAdmin) {
            json_error('You are not authorized to view this file.', 403);
        }

        $path = JUSTIFICATION_STORAGE_DIR . '/' . $entry['justification_file'];
        if (!is_file($path)) {
            json_error('File not found.', 404);
        }

        header('Content-Type: ' . ($entry['justification_file_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . basename($entry['justification_file_name']) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    /** PATCH /api/attendance/{id}/justification — Admin approves or rejects a justification. */
    public static function reviewJustification(int $id): void
    {
        $claims = require_role('admin');
        $body = request_body();
        require_fields($body, ['decision']);
        if (!in_array($body['decision'], ['approved', 'rejected'], true)) {
            json_error('Decision must be "approved" or "rejected".', 422);
        }

        try {
            $entry = AttendanceModel::reviewJustification($id, $body['decision'], (int) $claims['sub']);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 422);
        }
        json_response(['message' => 'Justification ' . $body['decision'] . '.', 'entry' => $entry]);
    }
}
