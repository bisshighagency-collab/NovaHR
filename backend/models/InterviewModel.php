<?php
/**
 * AI-conducted interview sessions.
 *
 * Scoring is a transparent keyword-overlap heuristic against each
 * question's `expected_keywords` (see the note in CompanyInfoModel.php about
 * the AI Engine seam — `scoreAnswer()` is the single place to swap in a real
 * LLM-based evaluator later).
 */
class InterviewModel
{
    public static function start(int $candidateId, int $jobPostingId): array
    {
        $stmt = db()->prepare('SELECT * FROM interview_sessions WHERE candidate_id = ? AND job_posting_id = ?');
        $stmt->execute([$candidateId, $jobPostingId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing;
        }

        $stmt = db()->prepare(
            'INSERT INTO interview_sessions (candidate_id, job_posting_id, status, started_at, transcript_json)
             VALUES (?, ?, "in_progress", NOW(), "[]")'
        );
        $stmt->execute([$candidateId, $jobPostingId]);
        // Capture the id immediately: lastInsertId() reflects only the most
        // recent statement, so it must be read before any other query runs
        // (the status UPDATE right below would otherwise clear it back to 0).
        $newId = (int) db()->lastInsertId();
        CandidateModel::setStatus($candidateId, 'interview_scheduled');
        return self::find($newId);
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM interview_sessions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findForCandidateAndJob(int $candidateId, int $jobPostingId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM interview_sessions WHERE candidate_id = ? AND job_posting_id = ?');
        $stmt->execute([$candidateId, $jobPostingId]);
        return $stmt->fetch() ?: null;
    }

    private static function scoreAnswer(string $answer, ?string $expectedKeywords): float
    {
        $answer = strtolower(trim($answer));
        if ($answer === '') {
            return 0.0;
        }
        // Baseline credit for a substantive answer, plus keyword coverage.
        $lengthScore = min(40, strlen($answer) / 2);

        $keywordScore = 0;
        $keywords = array_filter(array_map('trim', explode(',', strtolower((string) $expectedKeywords))));
        if ($keywords) {
            $hits = 0;
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($answer, $kw)) {
                    $hits++;
                }
            }
            $keywordScore = 60 * ($hits / count($keywords));
        } else {
            $keywordScore = 60; // no keyword rubric defined -> full credit for a substantive answer
        }

        return round(min(100, $lengthScore + $keywordScore), 2);
    }

    /**
     * Records one answer, appends it to the transcript, and returns the
     * running score. A candidate gets exactly one pass through the
     * interview: once the session is 'completed', this is rejected rather
     * than silently accepted — without this check, a candidate could call
     * the API directly (bypassing interview.html's "already completed" UI,
     * which is a client-side nicety, not a security boundary) and resubmit
     * better answers to inflate an already-scored, already-decided session.
     */
    public static function submitAnswer(int $sessionId, string $question, string $answer, ?string $expectedKeywords): array
    {
        $session = self::find($sessionId);
        if ($session['status'] === 'completed') {
            throw new InvalidArgumentException('This interview has already been completed and cannot be resubmitted.');
        }

        $transcript = json_decode($session['transcript_json'] ?? '[]', true) ?: [];
        $qScore = self::scoreAnswer($answer, $expectedKeywords);
        $transcript[] = ['question' => $question, 'answer' => $answer, 'score' => $qScore];

        $stmt = db()->prepare('UPDATE interview_sessions SET transcript_json = ? WHERE id = ?');
        $stmt->execute([json_encode($transcript), $sessionId]);

        return ['transcript' => $transcript, 'question_score' => $qScore];
    }

    /**
     * Scores and closes out a session. Idempotent by design rather than an
     * error: if the session is already 'completed' (e.g. a duplicate
     * request, or a direct API replay), the existing result is returned
     * unchanged instead of being recomputed — the score and shortlist/reject
     * decision, once made, are final.
     */
    public static function complete(int $sessionId): array
    {
        $session = self::find($sessionId);
        if ($session['status'] === 'completed') {
            return $session;
        }

        $transcript = json_decode($session['transcript_json'] ?? '[]', true) ?: [];
        $avg = $transcript ? array_sum(array_column($transcript, 'score')) / count($transcript) : 0;
        $avg = round($avg, 2);

        $stmt = db()->prepare(
            'UPDATE interview_sessions SET status = "completed", score = ?, completed_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$avg, $sessionId]);

        $newStatus = $avg >= 60 ? 'shortlisted' : 'interviewed';
        CandidateModel::setStatus((int) $session['candidate_id'], $newStatus);
        $candidate = CandidateModel::findById((int) $session['candidate_id']);
        if ($candidate) {
            NotificationModel::notifyApplicationStatus($candidate, $newStatus);
        }

        return self::find($sessionId);
    }

    public static function listForJob(int $jobPostingId): array
    {
        $stmt = db()->prepare(
            'SELECT s.*, u.name AS candidate_name, u.email AS candidate_email FROM interview_sessions s
             JOIN candidates c ON c.id = s.candidate_id
             JOIN users u ON u.id = c.user_id
             WHERE s.job_posting_id = ? ORDER BY s.score DESC'
        );
        $stmt->execute([$jobPostingId]);
        return $stmt->fetchAll();
    }
}
