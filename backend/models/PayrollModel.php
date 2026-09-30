<?php
/** Data access + calculation for the `payroll` table. */
class PayrollModel
{
    /**
     * Salary rule: each employee has their own base salary and their own
     * late/absence deduction rates, set individually by the admin (there is
     * no standard/default salary — see UserModel::updateCompensation()).
     * Attendance is signed on the paper sheet, and the admin records the
     * exceptions (late / absent days) in NovaHR. Every weekday of the period up
     * to today is checked: a day with no record, or recorded present, counts as
     * present; a day recorded "late" or "absent" is deducted at that employee's
     * rate UNLESS the employee justified it and the admin approved the
     * justification, in which case that day is excused.
     */
    public static function generate(int $employeeId, string $period, array $employee): array
    {
        $existing = self::findForEmployeeAndPeriod($employeeId, $period);
        if ($existing) {
            return $existing;
        }

        $baseSalary = (float) $employee['base_salary'];
        $lateRate = (float) ($employee['late_deduction'] ?? 0);
        $absenceRate = (float) ($employee['absence_deduction'] ?? 0);

        $rows = AttendanceModel::forEmployeeInMonth($employeeId, $period);
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['work_date']] = $r;
        }

        $daysPresent = 0;
        $lateCount = 0;
        $absentCount = 0;
        $today = date('Y-m-d');
        foreach (self::workingDatesInPeriod($period) as $date) {
            if ($date > $today) {
                break; // days not worked yet
            }
            $row = $byDate[$date] ?? null;
            $status = $row['status'] ?? 'present';
            $excused = $row && $row['justification_status'] === 'approved';

            if ($status === 'absent') {
                if (!$excused) {
                    $absentCount++;
                }
                continue;
            }
            $daysPresent++;
            if ($status === 'late' && !$excused) {
                $lateCount++;
            }
        }

        $deductions = round($lateCount * $lateRate + $absentCount * $absenceRate, 2);
        $bonuses = 0.0;
        $net = max(0, $baseSalary - $deductions + $bonuses);

        $stmt = db()->prepare(
            'INSERT INTO payroll (employee_id, period_month, base_salary, deductions, bonuses, net_salary, days_present, late_count, absent_count, status)
             VALUES (:employee_id, :period, :base, :ded, :bon, :net, :days, :late, :absent, "pending_review")'
        );
        $stmt->execute([
            'employee_id' => $employeeId,
            'period' => $period,
            'base' => $baseSalary,
            'ded' => $deductions,
            'bon' => $bonuses,
            'net' => $net,
            'days' => $daysPresent,
            'late' => $lateCount,
            'absent' => $absentCount,
        ]);
        return self::findById((int) db()->lastInsertId());
    }

    /** Monday–Friday dates within a 'YYYY-MM' period, as 'YYYY-MM-DD' strings. */
    private static function workingDatesInPeriod(string $period): array
    {
        $start = new DateTime($period . '-01');
        $end = (clone $start)->modify('last day of this month');
        $dates = [];
        for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
            if ((int) $d->format('N') <= 5) { // 1 (Mon) .. 5 (Fri)
                $dates[] = $d->format('Y-m-d');
            }
        }
        return $dates;
    }

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM payroll WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findForEmployeeAndPeriod(int $employeeId, string $period): ?array
    {
        $stmt = db()->prepare('SELECT * FROM payroll WHERE employee_id = ? AND period_month = ?');
        $stmt->execute([$employeeId, $period]);
        return $stmt->fetch() ?: null;
    }

    public static function listForEmployee(int $employeeId): array
    {
        $stmt = db()->prepare('SELECT * FROM payroll WHERE employee_id = ? ORDER BY period_month DESC');
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll();
    }

    public static function listForPeriod(string $period): array
    {
        $stmt = db()->prepare(
            'SELECT p.*, u.name AS employee_name FROM payroll p
             JOIN users u ON u.id = p.employee_id
             WHERE p.period_month = ? ORDER BY u.name'
        );
        $stmt->execute([$period]);
        return $stmt->fetchAll();
    }

    public static function approve(int $id): void
    {
        db()->prepare('UPDATE payroll SET status = "approved" WHERE id = ?')->execute([$id]);
    }

    public static function reject(int $id): void
    {
        db()->prepare('UPDATE payroll SET status = "rejected" WHERE id = ?')->execute([$id]);
    }

    /** Simulates the Payroll/Bank API disbursement call described in the report. */
    public static function disburse(int $id): array
    {
        $ref = 'TXN-' . strtoupper(bin2hex(random_bytes(5)));
        $stmt = db()->prepare('UPDATE payroll SET status = "paid", transaction_ref = ?, paid_at = NOW() WHERE id = ? AND status = "approved"');
        $stmt->execute([$ref, $id]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'message' => 'Payroll must be approved before it can be disbursed.'];
        }
        return ['ok' => true, 'transaction_ref' => $ref];
    }
}
