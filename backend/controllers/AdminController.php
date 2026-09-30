<?php
class AdminController
{
    /** GET /api/departments */
    public static function listDepartments(): void
    {
        require_auth();
        json_response(['departments' => DepartmentModel::all()]);
    }

    /** POST /api/departments — use case: Manage Departments (Add Department). */
    public static function createDepartment(): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['name']);
        $id = DepartmentModel::create($body['name'], $body['description'] ?? null);
        json_response(['id' => $id, 'departments' => DepartmentModel::all()], 201);
    }

    /** PUT /api/departments/{id} — use case: Manage Departments (Edit Department). */
    public static function updateDepartment(int $id): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['name']);
        DepartmentModel::update($id, $body['name'], $body['description'] ?? null);
        json_response(['departments' => DepartmentModel::all()]);
    }

    /** GET /api/admin/dashboard — summary counters for the admin dashboard. */
    public static function dashboard(): void
    {
        require_role('admin');
        $pdo = db();
        $employees = (int) $pdo->query("SELECT COUNT(*) c FROM users WHERE role='employee' AND status='active'")->fetch()['c'];
        // attendance is signed on paper: everyone active is present unless the admin recorded them absent
        $absentToday = (int) $pdo->query("SELECT COUNT(*) c FROM attendance a JOIN users u ON u.id = a.employee_id
                                          WHERE a.work_date = CURDATE() AND a.status = 'absent' AND u.status = 'active'")->fetch()['c'];
        $presentToday = max(0, $employees - $absentToday);
        $pendingJustifications = (int) $pdo->query("SELECT COUNT(*) c FROM attendance WHERE justification_status = 'pending'")->fetch()['c'];
        $openJobs = (int) $pdo->query("SELECT COUNT(*) c FROM job_postings WHERE status='open'")->fetch()['c'];
        $pendingCandidates = (int) $pdo->query("SELECT COUNT(*) c FROM candidates WHERE application_status='applied'")->fetch()['c'];
        $shortlisted = (int) $pdo->query("SELECT COUNT(*) c FROM candidates WHERE application_status='shortlisted'")->fetch()['c'];
        $pendingPayroll = (int) $pdo->query("SELECT COUNT(*) c FROM payroll WHERE status='pending_review'")->fetch()['c'];

        json_response([
            'active_employees' => $employees,
            'present_today' => $presentToday,
            'justifications_pending' => $pendingJustifications,
            'open_job_postings' => $openJobs,
            'candidates_awaiting_review' => $pendingCandidates,
            'shortlisted_candidates' => $shortlisted,
            'payroll_pending_review' => $pendingPayroll,
        ]);
    }

    /**
     * GET /admin/late-today — the late or absent days the admin has recorded today from
     * the paper sign-in sheet, and justifications waiting for review.
     */
    public static function lateToday(): void
    {
        require_role('admin');
        $today = db()->query(
            "SELECT a.id, a.employee_id, a.status, a.clock_in, a.justification_status, u.name AS employee_name
             FROM attendance a JOIN users u ON u.id = a.employee_id
             WHERE a.work_date = CURDATE() AND a.status IN ('late', 'absent')
             ORDER BY u.name"
        )->fetchAll();
        $pending = db()->query(
            "SELECT a.id, a.work_date, a.status, a.justification_text, u.name AS employee_name
             FROM attendance a JOIN users u ON u.id = a.employee_id
             WHERE a.justification_status = 'pending'
             ORDER BY a.work_date DESC LIMIT 8"
        )->fetchAll();
        json_response(['late' => $today, 'pending_justifications' => $pending]);
    }
}
