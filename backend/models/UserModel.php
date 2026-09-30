<?php
/** Data access for the `users` table (Admin + Employee, per the class diagram's AbstractUser). */
class UserModel
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Like findById(), but also resolves department_name — used for the admin's single-employee profile view. */
    public static function findByIdWithDepartment(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT u.*, d.name AS department_name FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Used to detect a repeated phone number at registration time (email is already UNIQUE). */
    public static function findByPhone(string $phone): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE phone = ? LIMIT 1');
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $d): int
    {
        $stmt = db()->prepare(
            'INSERT INTO users (name, email, phone, password_hash, role, status, department_id, hire_date, work_start_time, work_end_time,
                                break_start_time, break_end_time, base_salary, late_deduction, absence_deduction)
             VALUES (:name, :email, :phone, :password_hash, :role, :status, :department_id, :hire_date, :work_start_time, :work_end_time,
                     :break_start_time, :break_end_time, :base_salary, :late_deduction, :absence_deduction)'
        );
        $stmt->execute([
            'name' => $d['name'],
            'email' => $d['email'],
            'phone' => $d['phone'] ?? null,
            'password_hash' => password_hash($d['password'], PASSWORD_BCRYPT),
            'role' => $d['role'] ?? 'employee',
            'status' => $d['status'] ?? 'active',
            'department_id' => $d['department_id'] ?? null,
            'hire_date' => $d['hire_date'] ?? date('Y-m-d'),
            'work_start_time' => $d['work_start_time'] ?? WORK_START_TIME,
            'work_end_time' => $d['work_end_time'] ?? WORK_END_TIME,
            'break_start_time' => $d['break_start_time'] ?? BREAK_START_TIME,
            'break_end_time' => $d['break_end_time'] ?? BREAK_END_TIME,
            'base_salary' => $d['base_salary'] ?? null,
            'late_deduction' => $d['late_deduction'] ?? 0,
            'absence_deduction' => $d['absence_deduction'] ?? 0,
        ]);
        return (int) db()->lastInsertId();
    }

    /**
     * Used both by an employee editing their own profile and by an admin
     * editing/reviewing it (PUT /profile and PUT /employees/{id} both call
     * this) — position/location/address are the fields the employee fills
     * in themselves; since both read from the same `users` row, any change
     * is visible to the admin immediately, with no separate sync step.
     *
     * department_id is only touched when the caller actually supplies the
     * key — the employee's own profile form has no department field, so
     * leaving it out here would otherwise silently unassign their
     * department on every self-save.
     */
    public static function updateProfile(int $id, array $d): void
    {
        $sets = ['name = :name', 'phone = :phone', 'position = :position', 'location = :location', 'address = :address'];
        $params = [
            'name' => $d['name'],
            'phone' => $d['phone'] ?? null,
            'position' => $d['position'] ?? null,
            'location' => $d['location'] ?? null,
            'address' => $d['address'] ?? null,
            'id' => $id,
        ];
        if (array_key_exists('department_id', $d)) {
            $sets[] = 'department_id = :department_id';
            $params['department_id'] = $d['department_id'] ?: null;
        }
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /** Admin-only: sets an employee's individual pay — there is no standard/default salary. */
    public static function updateCompensation(int $id, array $d): void
    {
        $stmt = db()->prepare(
            'UPDATE users SET base_salary = :base_salary, late_deduction = :late_deduction, absence_deduction = :absence_deduction WHERE id = :id'
        );
        $stmt->execute([
            'base_salary' => $d['base_salary'],
            'late_deduction' => $d['late_deduction'] ?? 0,
            'absence_deduction' => $d['absence_deduction'] ?? 0,
            'id' => $id,
        ]);
    }

    /** Admin-only: this employee's service hours and break, shown under the clock on their dashboard. */
    public static function updateSchedule(int $id, array $s): void
    {
        $stmt = db()->prepare(
            'UPDATE users SET work_start_time = :start, work_end_time = :end, break_start_time = :break_start, break_end_time = :break_end
             WHERE id = :id'
        );
        $stmt->execute([
            'start' => $s['work_start_time'], 'end' => $s['work_end_time'],
            'break_start' => $s['break_start_time'], 'break_end' => $s['break_end_time'], 'id' => $id,
        ]);
    }

    /** The schedule shown to an employee under their dashboard clock. */
    public static function scheduleFor(int $id): array
    {
        $stmt = db()->prepare('SELECT work_start_time, work_end_time, break_start_time, break_end_time FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [
            'work_start_time' => WORK_START_TIME, 'work_end_time' => WORK_END_TIME,
            'break_start_time' => BREAK_START_TIME, 'break_end_time' => BREAK_END_TIME,
        ];
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public static function delete(int $id): void
    {
        self::setStatus($id, 'deleted');
    }

    /** @return array<int,array<string,mixed>> */
    public static function listEmployees(?int $departmentId = null): array
    {
        if ($departmentId) {
            $stmt = db()->prepare(
                "SELECT u.*, d.name AS department_name FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.role = 'employee' AND u.department_id = ? ORDER BY u.name"
            );
            $stmt->execute([$departmentId]);
        } else {
            $stmt = db()->query(
                "SELECT u.*, d.name AS department_name FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.role = 'employee' ORDER BY u.name"
            );
        }
        return $stmt->fetchAll();
    }

    public static function sanitize(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
}
