<?php
/** Data access for the `attendance` table. */
class AttendanceModel
{
    /**
     * Attendance is signed on the paper sheet; the admin copies the exceptions into
     * NovaHR. Creates or updates the employee's row for that date. A day with no row is
     * treated as present (the employee signed the sheet). An existing justification is
     * kept as it is.
     */
    public static function record(int $employeeId, string $date, string $status, ?string $arrivalTime): array
    {
        $arrival = ($status === 'absent' || !$arrivalTime) ? null : $date . ' ' . (strlen($arrivalTime) === 5 ? $arrivalTime . ':00' : $arrivalTime);
        $existing = self::findByEmployeeAndDate($employeeId, $date);
        if ($existing) {
            $stmt = db()->prepare('UPDATE attendance SET status = :status, clock_in = :arrival WHERE id = :id');
            $stmt->execute(['status' => $status, 'arrival' => $arrival, 'id' => $existing['id']]);
            return self::findById((int) $existing['id']);
        }
        $stmt = db()->prepare('INSERT INTO attendance (employee_id, work_date, status, clock_in) VALUES (:e, :d, :s, :a)');
        $stmt->execute(['e' => $employeeId, 'd' => $date, 's' => $status, 'a' => $arrival]);
        return self::findById((int) db()->lastInsertId());
    }

    public static function history(int $employeeId, int $limit = 60): array
    {
        $stmt = db()->prepare('SELECT * FROM attendance WHERE employee_id = ? ORDER BY work_date DESC LIMIT ?');
        $stmt->bindValue(1, $employeeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Attendance rows for one employee within a YYYY-MM period, used by payroll generation. */
    public static function forEmployeeInMonth(int $employeeId, string $period): array
    {
        $stmt = db()->prepare(
            "SELECT * FROM attendance WHERE employee_id = ? AND DATE_FORMAT(work_date, '%Y-%m') = ? ORDER BY work_date"
        );
        $stmt->execute([$employeeId, $period]);
        return $stmt->fetchAll();
    }

    public static function allForPeriod(string $period): array
    {
        $stmt = db()->prepare(
            "SELECT a.*, u.name AS employee_name FROM attendance a
             JOIN users u ON u.id = a.employee_id
             WHERE DATE_FORMAT(a.work_date, '%Y-%m') = ? ORDER BY a.work_date"
        );
        $stmt->execute([$period]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM attendance WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByEmployeeAndDate(int $employeeId, string $date): ?array
    {
        $stmt = db()->prepare('SELECT * FROM attendance WHERE employee_id = ? AND work_date = ? LIMIT 1');
        $stmt->execute([$employeeId, $date]);
        return $stmt->fetch() ?: null;
    }

    /**
     * An employee justifies a late arrival or an absence. The day may already be
     * recorded by the admin from the paper sheet (status 'late' or 'absent'), or not
     * yet — then a row is created with the type the employee chose ($type:
     * 'late' or 'absent') so the day carries a reviewable justification. Resubmission after a rejection
     * is allowed; a day already approved/pending cannot be re-submitted.
     * $file, when provided, is ['stored' => <random filename on disk>,
     * 'original' => <filename to display>, 'type' => <MIME type>] — see
     * AttendanceController::storeJustificationFile().
     */
    public static function justify(int $employeeId, string $date, string $reason, ?array $file = null, string $type = 'absent'): array
    {
        $existing = self::findByEmployeeAndDate($employeeId, $date);

        if ($existing) {
            if (!in_array($existing['status'], ['late', 'absent'], true)) {
                throw new InvalidArgumentException('This day is recorded as present, so there is nothing to justify.');
            }
            if (in_array($existing['justification_status'], ['pending', 'approved'], true)) {
                throw new InvalidArgumentException('This day already has a justification on file.');
            }

            $sets = [
                'justification_text = :reason', 'justification_status = "pending"',
                'justification_reviewed_by = NULL', 'justification_reviewed_at = NULL',
            ];
            $params = ['reason' => $reason, 'id' => $existing['id']];
            if ($file) {
                if ($existing['justification_file']) {
                    self::deleteJustificationFile($existing['justification_file']);
                }
                $sets[] = 'justification_file = :file';
                $sets[] = 'justification_file_name = :file_name';
                $sets[] = 'justification_file_type = :file_type';
                $params['file'] = $file['stored'];
                $params['file_name'] = $file['original'];
                $params['file_type'] = $file['type'];
            }
            $stmt = db()->prepare('UPDATE attendance SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stmt->execute($params);
            return self::findById((int) $existing['id']);
        }

        $columns = ['employee_id', 'work_date', 'status', 'justification_text', 'justification_status'];
        $placeholders = [':employee_id', ':date', ':type', ':reason', '"pending"'];
        $params = ['employee_id' => $employeeId, 'date' => $date, 'type' => $type === 'late' ? 'late' : 'absent', 'reason' => $reason];
        if ($file) {
            $columns = array_merge($columns, ['justification_file', 'justification_file_name', 'justification_file_type']);
            $placeholders = array_merge($placeholders, [':file', ':file_name', ':file_type']);
            $params['file'] = $file['stored'];
            $params['file_name'] = $file['original'];
            $params['file_type'] = $file['type'];
        }
        $stmt = db()->prepare(
            'INSERT INTO attendance (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        return self::findById((int) db()->lastInsertId());
    }

    private static function deleteJustificationFile(string $storedName): void
    {
        $path = JUSTIFICATION_STORAGE_DIR . '/' . $storedName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Admin approves or rejects a pending justification. Approval clears the payroll deduction for that day. */
    public static function reviewJustification(int $id, string $decision, int $reviewerId): array
    {
        $stmt = db()->prepare(
            'UPDATE attendance SET justification_status = :decision, justification_reviewed_by = :reviewer, justification_reviewed_at = NOW()
             WHERE id = :id AND justification_status = "pending"'
        );
        $stmt->execute(['decision' => $decision, 'reviewer' => $reviewerId, 'id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('No pending justification found for this record.');
        }
        return self::findById($id);
    }
}
