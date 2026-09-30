<?php
/**
 * Internal recruitment. Job openings are posted by the Admin for the company's own
 * employees. Employees can SEE the openings from their employee account, but to APPLY
 * they sign in to the Candidate Portal (role 'candidate') with the same credentials,
 * apply, take the AI interview, and are notified when their application is reviewed.
 */
class RecruitmentController
{
    // ---- Job postings ------------------------------------------------------

    /** GET /api/jobs — open internal job openings (any signed-in employee, candidate or admin). */
    public static function listOpenJobs(): void
    {
        $claims = require_role('employee', 'candidate', 'admin');
        $jobs = JobPostingModel::allOpen();
        if ($claims['role'] === 'candidate') {
            // Mark the openings this candidate already applied for.
            $applied = [];
            foreach (CandidateModel::listForUser((int) $claims['sub']) as $c) {
                $applied[(int) $c['job_posting_id']] = $c['application_status'];
            }
            foreach ($jobs as &$j) {
                $j['my_application_status'] = $applied[(int) $j['id']] ?? null;
            }
            unset($j);
        }
        json_response(['jobs' => $jobs]);
    }

    /** GET /api/jobs/admin — Admin: manage job postings. */
    public static function listAllJobs(): void
    {
        require_role('admin');
        json_response(['jobs' => JobPostingModel::all()]);
    }

    /** POST /api/jobs — use case: Manage Job Postings (Post Job Opening). */
    public static function createJob(): void
    {
        $claims = require_role('admin');
        $body = request_body();
        require_fields($body, ['title', 'description']);
        $id = JobPostingModel::create($body, (int) $claims['sub']);
        json_response(['job' => JobPostingModel::find($id)], 201);
    }

    /** PATCH /api/jobs/{id}/close — use case: Manage Job Postings (Close Job Posting). */
    public static function closeJob(int $id): void
    {
        require_role('admin');
        JobPostingModel::close($id);
        json_response(['job' => JobPostingModel::find($id)]);
    }

    // ---- Candidates (employees applying internally) ------------------------

    /** POST /api/candidates/apply — use case: Apply for Job Opening (Candidate Portal). */
    public static function apply(): void
    {
        $claims = require_role('candidate');
        $body = request_body();
        require_fields($body, ['job_posting_id']);
        $userId = (int) $claims['sub'];
        $jobId = (int) $body['job_posting_id'];

        $job = JobPostingModel::find($jobId);
        if (!$job || $job['status'] !== 'open') {
            json_error('This job opening is closed or does not exist.', 404);
        }
        if (CandidateModel::findByUserAndJob($userId, $jobId)) {
            json_error('You have already applied for this job opening.', 409);
        }

        $motivation = trim((string) ($body['motivation'] ?? ''));
        $id = CandidateModel::apply($userId, $jobId, $motivation === '' ? null : $motivation);
        NotificationModel::create($userId, 'Application received',
            "Your application for \"{$job['title']}\" was received. You can now take the AI interview.");
        json_response(['application' => CandidateModel::findById($id)], 201);
    }

    /** GET /api/candidates/me — use case: View Application Status (all my applications). */
    public static function myApplication(): void
    {
        $claims = require_role('candidate');
        $userId = (int) $claims['sub'];
        $applications = [];
        foreach (CandidateModel::listForUser($userId) as $c) {
            $applications[] = [
                'application' => $c,
                'job' => JobPostingModel::find((int) $c['job_posting_id']),
                'interview' => InterviewModel::findForCandidateAndJob((int) $c['id'], (int) $c['job_posting_id']),
            ];
        }
        json_response([
            'candidate' => UserModel::sanitize(UserModel::findById($userId)),
            'applications' => $applications,
        ]);
    }

    /** GET /api/candidates/faq — company-info entries the admin marked visible to candidates. */
    public static function faq(): void
    {
        require_role('candidate', 'employee', 'admin');
        json_response(['entries' => CompanyInfoModel::candidateFaqEntries()]);
    }

    /** GET /api/candidates?job_posting_id= — Admin: Review Candidates. */
    public static function listForAdmin(): void
    {
        require_role('admin');
        $rows = isset($_GET['job_posting_id'])
            ? CandidateModel::listForJob((int) $_GET['job_posting_id'])
            : CandidateModel::listAll();
        json_response(['candidates' => $rows]);
    }

    /** PATCH /api/candidates/{id}/status — Admin: Review Candidates (Shortlist/Reject/Hire). Notifies the employee. */
    public static function setStatus(int $id): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['status']);
        if (!in_array($body['status'], ['shortlisted', 'rejected', 'hired', 'interviewed'], true)) {
            json_error('Invalid status.', 422);
        }
        $candidate = CandidateModel::findById($id);
        if (!$candidate) {
            json_error('Application not found.', 404);
        }
        CandidateModel::setStatus($id, $body['status']);
        NotificationModel::notifyApplicationStatus($candidate, $body['status']);
        json_response(['candidate' => CandidateModel::findById($id)]);
    }

    // ---- AI interview --------------------------------------------------------

    /** The application must belong to the signed-in candidate (an employee's users.id). */
    private static function ownedApplication(int $applicationId, int $userId): array
    {
        $application = CandidateModel::findById($applicationId);
        if (!$application || (int) $application['user_id'] !== $userId) {
            json_error('Application not found.', 404);
        }
        return $application;
    }

    /** Verifies the interview session belongs to one of the signed-in candidate's applications. */
    private static function ownedSession(int $sessionId, int $userId): array
    {
        $session = InterviewModel::find($sessionId);
        if (!$session) {
            json_error('Interview session not found.', 404);
        }
        self::ownedApplication((int) $session['candidate_id'], $userId);
        return $session;
    }

    /** POST /api/interview/start {application_id} — use case: Take AI Interview. */
    public static function startInterview(): void
    {
        $claims = require_role('candidate');
        $body = request_body();
        require_fields($body, ['application_id']);
        $application = self::ownedApplication((int) $body['application_id'], (int) $claims['sub']);
        $session = InterviewModel::start((int) $application['id'], (int) $application['job_posting_id']);
        $questions = JobPostingModel::questionsFor((int) $application['job_posting_id']);
        json_response(['session' => $session, 'questions' => $questions, 'job_title' => $application['job_title']]);
    }

    /** POST /api/interview/{sessionId}/answer — one question/answer turn. */
    public static function submitAnswer(int $sessionId): void
    {
        $claims = require_role('candidate');
        self::ownedSession($sessionId, (int) $claims['sub']);
        $body = request_body();
        require_fields($body, ['question', 'answer']);
        try {
            $result = InterviewModel::submitAnswer($sessionId, $body['question'], $body['answer'], $body['expected_keywords'] ?? null);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 409);
        }
        json_response($result);
    }

    /** POST /api/interview/{sessionId}/complete — AI evaluates and scores the full interview. */
    public static function completeInterview(int $sessionId): void
    {
        $claims = require_role('candidate');
        self::ownedSession($sessionId, (int) $claims['sub']);
        $session = InterviewModel::complete($sessionId);
        json_response(['session' => $session]);
    }

    /** GET /api/interview/job/{jobId} — Admin: view interview scores for a posting. */
    public static function listSessionsForJob(int $jobId): void
    {
        require_role('admin');
        json_response(['sessions' => InterviewModel::listForJob($jobId)]);
    }
}
