<?php
class AuthController
{
    /** POST /api/auth/login — Admin or Employee login (use case: Authenticate). */
    public static function login(): void
    {
        $body = request_body();
        require_fields($body, ['email', 'password']);

        $user = UserModel::findByEmail($body['email']);
        if (!$user || !password_verify($body['password'], $user['password_hash'])) {
            json_error('Invalid email or password.', 401);
        }
        if ($user['status'] !== 'active') {
            json_error('This account is not active. Contact an administrator.', 403);
        }

        $token = jwt_encode(['sub' => (int) $user['id'], 'role' => $user['role'], 'name' => $user['name']]);
        json_response(['token' => $token, 'user' => UserModel::sanitize($user)]);
    }

    /** POST /api/auth/register — Employee self-registration (use case: Register Employee, employee-initiated variant). */
    public static function register(): void
    {
        $body = request_body();
        require_fields($body, ['name', 'email', 'password']);

        if (UserModel::findByEmail($body['email'])) {
            json_error('An account with this email already exists.', 409);
        }
        if (!empty($body['phone']) && UserModel::findByPhone($body['phone'])) {
            json_error('An account with this phone number already exists.', 409);
        }
        if (strlen($body['password']) < 6) {
            json_error('Password must be at least 6 characters.', 422);
        }

        $id = UserModel::create([
            'name' => $body['name'],
            'email' => $body['email'],
            'phone' => $body['phone'] ?? null,
            'password' => $body['password'],
            'role' => 'employee',
            'status' => 'active',
            'department_id' => $body['department_id'] ?? null,
        ]);

        $user = UserModel::findById($id);
        $token = jwt_encode(['sub' => $id, 'role' => 'employee', 'name' => $user['name']]);
        json_response(['token' => $token, 'user' => UserModel::sanitize($user)], 201);
    }

    /** GET /api/auth/me — resolve the current session to a full user record. */
    public static function me(): void
    {
        $claims = require_auth();
        $user = UserModel::findById((int) $claims['sub']);
        if (!$user) {
            json_error('Session user no longer exists.', 401);
        }
        json_response(['user' => UserModel::sanitize($user)]);
    }

    /**
     * POST /api/auth/candidate-login — Candidate Portal login.
     * Job openings are internal, so a candidate IS an employee: they sign in here with
     * the same email/password as their employee account and get a separate session
     * with the 'candidate' role (sub = their users.id). That session can only reach
     * the recruitment features; to go back to attendance, payroll, etc. they log out
     * and sign in again on the employee login page.
     */
    public static function candidateLogin(): void
    {
        $body = request_body();
        require_fields($body, ['email', 'password']);

        $user = UserModel::findByEmail($body['email']);
        if (!$user || !password_verify($body['password'], $user['password_hash'])) {
            json_error('Invalid email or password. Use your employee account credentials.', 401);
        }
        if ($user['role'] !== 'employee') {
            json_error('Only employees can apply for internal job openings.', 403);
        }
        if ($user['status'] !== 'active') {
            json_error('This account is not active. Contact an administrator.', 403);
        }

        $token = jwt_encode(['sub' => (int) $user['id'], 'role' => 'candidate', 'name' => $user['name']]);
        json_response(['token' => $token, 'candidate' => UserModel::sanitize($user)]);
    }
}
