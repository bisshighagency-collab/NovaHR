<?php
/**
 * NovaHR API — front controller / router.
 *
 * Point your web server's document root at this `backend/` folder (or use
 * the .htaccess included here under Apache) so that every request is routed
 * through this file, then matched against the table below.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak PHP errors as HTML into a JSON API

require __DIR__ . '/config/database.php';
require __DIR__ . '/config/jwt.php';
require __DIR__ . '/config/storage.php';
require __DIR__ . '/config/policy.php';
require __DIR__ . '/helpers/response.php';
require __DIR__ . '/middleware/auth.php';

foreach (glob(__DIR__ . '/models/*.php') as $file) {
    require $file;
}
foreach (glob(__DIR__ . '/controllers/*.php') as $file) {
    require $file;
}

// ---- CORS (the frontend is static files, possibly served from a different
// origin/port than the API during local development) ------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log($e->getMessage());
    json_error('Internal server error.', 500);
});

// ---- Routing -----------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip a leading /backend (or wherever this is mounted) and /api prefix.
$path = preg_replace('#^.*?/api#', '', $path);
$path = '/' . trim($path, '/');

$routes = [
    // Auth
    'POST /auth/login' => ['AuthController', 'login'],
    'POST /auth/register' => ['AuthController', 'register'],
    'GET /auth/me' => ['AuthController', 'me'],
    'POST /auth/candidate-login' => ['AuthController', 'candidateLogin'],

    // Employees (admin-managed)
    'POST /employees' => ['EmployeeController', 'create'],
    'GET /employees' => ['EmployeeController', 'list'],
    'GET /employees/{id}' => ['EmployeeController', 'show'],
    'PUT /employees/{id}' => ['EmployeeController', 'update'],
    'PUT /employees/{id}/compensation' => ['EmployeeController', 'updateCompensation'],
    'PUT /employees/{id}/schedule' => ['EmployeeController', 'updateSchedule'],
    'PATCH /employees/{id}/status' => ['EmployeeController', 'setStatus'],
    'GET /profile' => ['EmployeeController', 'myProfile'],
    'PUT /profile' => ['EmployeeController', 'updateMyProfile'],

    // Attendance (signed on the paper sheet; the admin records late/absent days, employees justify them)
    'GET /attendance/schedule' => ['AttendanceController', 'schedule'],
    'GET /attendance/history' => ['AttendanceController', 'myHistory'],
    'GET /attendance' => ['AttendanceController', 'listForAdmin'],
    'POST /attendance/record' => ['AttendanceController', 'record'],
    'POST /attendance/justify' => ['AttendanceController', 'justify'],
    'PATCH /attendance/{id}/justification' => ['AttendanceController', 'reviewJustification'],
    'GET /attendance/{id}/file' => ['AttendanceController', 'downloadFile'],

    // Payroll
    'POST /payroll/generate' => ['PayrollController', 'generate'],
    'GET /payroll' => ['PayrollController', 'listForAdmin'],
    'GET /payroll/mine' => ['PayrollController', 'mine'],
    'PATCH /payroll/{id}/approve' => ['PayrollController', 'approve'],
    'PATCH /payroll/{id}/reject' => ['PayrollController', 'reject'],
    'POST /payroll/{id}/disburse' => ['PayrollController', 'disburse'],

    // AI assistant + knowledge base
    'POST /ai/ask' => ['AIController', 'ask'],
    'GET /ai/onboarding' => ['AIController', 'onboarding'],
    'GET /ai/history' => ['AIController', 'myHistory'],
    'GET /ai/knowledge-base' => ['AIController', 'listKnowledgeBase'],
    'POST /ai/knowledge-base' => ['AIController', 'upsertKnowledgeBase'],
    'DELETE /ai/knowledge-base/{id}' => ['AIController', 'deleteKnowledgeBase'],

    // Recruitment: job postings
    'GET /jobs' => ['RecruitmentController', 'listOpenJobs'],
    'GET /jobs/admin' => ['RecruitmentController', 'listAllJobs'],
    'POST /jobs' => ['RecruitmentController', 'createJob'],
    'PATCH /jobs/{id}/close' => ['RecruitmentController', 'closeJob'],

    // Recruitment: candidates (employees who signed in to the Candidate Portal)
    'POST /candidates/apply' => ['RecruitmentController', 'apply'],
    'GET /candidates/me' => ['RecruitmentController', 'myApplication'],
    'GET /candidates/faq' => ['RecruitmentController', 'faq'],
    'GET /candidates' => ['RecruitmentController', 'listForAdmin'],
    'PATCH /candidates/{id}/status' => ['RecruitmentController', 'setStatus'],

    // Recruitment: AI interview
    'POST /interview/start' => ['RecruitmentController', 'startInterview'],
    'POST /interview/{id}/answer' => ['RecruitmentController', 'submitAnswer'],
    'POST /interview/{id}/complete' => ['RecruitmentController', 'completeInterview'],
    'GET /interview/job/{id}' => ['RecruitmentController', 'listSessionsForJob'],

    // Admin: departments + dashboard
    'GET /departments' => ['AdminController', 'listDepartments'],
    'POST /departments' => ['AdminController', 'createDepartment'],
    'PUT /departments/{id}' => ['AdminController', 'updateDepartment'],
    'GET /admin/dashboard' => ['AdminController', 'dashboard'],
    'GET /admin/late-today' => ['AdminController', 'lateToday'],

    // Announcements (Admin -> all employees)
    'GET /announcements' => ['AnnouncementController', 'list'],
    'POST /announcements' => ['AnnouncementController', 'create'],
    'DELETE /announcements/{id}' => ['AnnouncementController', 'delete'],

    // Notifications (e.g. internal job application updates)
    'GET /notifications' => ['NotificationController', 'list'],
    'POST /notifications/read' => ['NotificationController', 'markAllRead'],
];

foreach ($routes as $pattern => $handler) {
    [$routeMethod, $routePath] = explode(' ', $pattern, 2);
    if ($routeMethod !== $method) {
        continue;
    }

    $regex = '#^' . preg_replace('/\{[a-zA-Z_]+\}/', '([^/]+)', $routePath) . '$#';
    if (preg_match($regex, $path, $matches)) {
        array_shift($matches);
        $params = array_map(fn($m) => is_numeric($m) ? (int) $m : $m, $matches);
        [$controller, $action] = $handler;
        // Note: `$controller::$action()` (no braces) would be parsed as a static
        // PROPERTY access, not a method call — the braces are required to call a
        // static method whose name is held in a variable.
        $controller::{$action}(...$params);
        exit;
    }
}

json_error('Not found: ' . $method . ' ' . $path, 404);
