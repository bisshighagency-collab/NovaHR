<?php
class EmployeeController
{
    /** POST /api/employees — Admin creates an employee account (use case: Register Employee). */
    public static function create(): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['name', 'email', 'password']);

        if (UserModel::findByEmail($body['email'])) {
            json_error('An account with this email already exists.', 409);
        }
        if (!empty($body['phone']) && UserModel::findByPhone($body['phone'])) {
            json_error('An account with this phone number already exists.', 409);
        }

        $schedule = self::validSchedule($body);

        $id = UserModel::create($schedule + [
            'name' => $body['name'],
            'email' => $body['email'],
            'phone' => $body['phone'] ?? null,
            'password' => $body['password'],
            'role' => 'employee',
            'department_id' => $body['department_id'] ?? null,
            'hire_date' => $body['hire_date'] ?? date('Y-m-d'),
            'base_salary' => $body['base_salary'] ?? null,
            'late_deduction' => $body['late_deduction'] ?? 0,
            'absence_deduction' => $body['absence_deduction'] ?? 0,
        ]);
        json_response(['user' => UserModel::sanitize(UserModel::findById($id))], 201);
    }

    private static function isValidTime(string $t): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t);
    }

    /**
     * Service hours + break from the request (defaults for any field left out), validated:
     * HH:MM times, and service start < break start < break end < service end.
     */
    private static function validSchedule(array $body): array
    {
        $defaults = [
            'work_start_time' => WORK_START_TIME, 'work_end_time' => WORK_END_TIME,
            'break_start_time' => BREAK_START_TIME, 'break_end_time' => BREAK_END_TIME,
        ];
        $s = [];
        foreach ($defaults as $field => $default) {
            $value = trim((string) ($body[$field] ?? ''));
            if ($value === '') {
                $value = $default;
            }
            if (!self::isValidTime($value)) {
                json_error('Service and break times must be in HH:MM format.', 422);
            }
            $s[$field] = strlen($value) === 5 ? $value . ':00' : $value;
        }
        if (!($s['work_start_time'] < $s['break_start_time'] && $s['break_start_time'] < $s['break_end_time']
              && $s['break_end_time'] < $s['work_end_time'])) {
            json_error('The break must fall inside the service hours (service start < break start < break end < service end).', 422);
        }
        return $s;
    }

    /** GET /api/employees — Admin: list/manage employees. */
    public static function list(): void
    {
        require_role('admin');
        $departmentId = isset($_GET['department_id']) ? (int) $_GET['department_id'] : null;
        $rows = array_map(fn($u) => UserModel::sanitize($u), UserModel::listEmployees($departmentId));
        json_response(['employees' => $rows]);
    }

    /** GET /api/employees/{id} — Admin views one employee's full profile. */
    public static function show(int $id): void
    {
        require_role('admin');
        $user = UserModel::findByIdWithDepartment($id);
        if (!$user || $user['role'] !== 'employee') {
            json_error('Employee not found.', 404);
        }
        json_response(['user' => UserModel::sanitize($user)]);
    }

    /** PUT /api/employees/{id} — Admin updates an employee's profile. */
    public static function update(int $id): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['name']);
        UserModel::updateProfile($id, $body);
        json_response(['user' => UserModel::sanitize(UserModel::findById($id))]);
    }

    /** PUT /api/employees/{id}/compensation — Admin sets this employee's individual pay (no standard salary). */
    public static function updateCompensation(int $id): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['base_salary']);
        if (!is_numeric($body['base_salary']) || (float) $body['base_salary'] < 0) {
            json_error('Base salary must be a positive number.', 422);
        }
        foreach (['late_deduction', 'absence_deduction'] as $field) {
            if (isset($body[$field]) && (!is_numeric($body[$field]) || (float) $body[$field] < 0)) {
                json_error('Deduction amounts must be positive numbers.', 422);
            }
        }
        UserModel::updateCompensation($id, [
            'base_salary' => (float) $body['base_salary'],
            'late_deduction' => (float) ($body['late_deduction'] ?? 0),
            'absence_deduction' => (float) ($body['absence_deduction'] ?? 0),
        ]);
        json_response(['user' => UserModel::sanitize(UserModel::findById($id))]);
    }

    /** PUT /api/employees/{id}/schedule — Admin sets this employee's service hours and break time. */
    public static function updateSchedule(int $id): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['work_start_time', 'work_end_time', 'break_start_time', 'break_end_time']);
        UserModel::updateSchedule($id, self::validSchedule($body));
        json_response(['user' => UserModel::sanitize(UserModel::findById($id))]);
    }

    /** PATCH /api/employees/{id}/status — Admin suspends/reactivates/deletes an employee. */
    public static function setStatus(int $id): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['status']);
        if (!in_array($body['status'], ['active', 'suspended', 'deleted'], true)) {
            json_error('Invalid status.', 422);
        }
        UserModel::setStatus($id, $body['status']);
        json_response(['user' => UserModel::sanitize(UserModel::findById($id))]);
    }

    /** GET /api/profile — the logged-in employee views their own profile. */
    public static function myProfile(): void
    {
        $claims = require_role('employee');
        json_response(['user' => UserModel::sanitize(UserModel::findById((int) $claims['sub']))]);
    }

    /** PUT /api/profile — the logged-in employee updates their own profile. */
    public static function updateMyProfile(): void
    {
        $claims = require_role('employee');
        $body = request_body();
        require_fields($body, ['name']);
        UserModel::updateProfile((int) $claims['sub'], $body);
        json_response(['user' => UserModel::sanitize(UserModel::findById((int) $claims['sub']))]);
    }
}
