<?php
class PayrollController
{
    /** POST /api/payroll/generate — use case: Generate Payroll. Body: { period: 'YYYY-MM' }. */
    public static function generate(): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['period']);

        $results = [];
        $skipped = [];
        foreach (UserModel::listEmployees() as $emp) {
            if ($emp['status'] !== 'active') {
                continue; // suspended/deleted employees are not paid
            }
            if ($emp['base_salary'] === null) {
                $skipped[] = $emp['name']; // admin hasn't set a salary for this employee yet
                continue;
            }
            $results[] = PayrollModel::generate((int) $emp['id'], $body['period'], $emp);
        }
        json_response(['period' => $body['period'], 'payroll' => $results, 'skipped' => $skipped], 201);
    }

    /** GET /api/payroll?period=YYYY-MM — Admin: review a payroll run. */
    public static function listForAdmin(): void
    {
        require_role('admin');
        $period = $_GET['period'] ?? date('Y-m');
        json_response(['period' => $period, 'payroll' => PayrollModel::listForPeriod($period)]);
    }

    /** PATCH /api/payroll/{id}/approve */
    public static function approve(int $id): void
    {
        require_role('admin');
        PayrollModel::approve($id);
        json_response(['payroll' => PayrollModel::findById($id)]);
    }

    /** PATCH /api/payroll/{id}/reject */
    public static function reject(int $id): void
    {
        require_role('admin');
        PayrollModel::reject($id);
        json_response(['payroll' => PayrollModel::findById($id)]);
    }

    /** POST /api/payroll/{id}/disburse — simulated Payroll/Bank API call. */
    public static function disburse(int $id): void
    {
        require_role('admin');
        $result = PayrollModel::disburse($id);
        if (!$result['ok']) {
            json_error($result['message'], 409);
        }
        json_response(['payroll' => PayrollModel::findById($id), 'transaction_ref' => $result['transaction_ref']]);
    }

    /** GET /api/payroll/mine — the employee's own payslips. */
    public static function mine(): void
    {
        $claims = require_role('employee');
        json_response(['payroll' => PayrollModel::listForEmployee((int) $claims['sub'])]);
    }
}
