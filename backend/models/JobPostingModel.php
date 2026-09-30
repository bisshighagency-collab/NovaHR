<?php
class JobPostingModel
{
    public static function allOpen(): array
    {
        return db()->query("SELECT jp.*, d.name AS department_name FROM job_postings jp
                             LEFT JOIN departments d ON d.id = jp.department_id
                             WHERE jp.status = 'open' ORDER BY jp.created_at DESC")->fetchAll();
    }

    public static function all(): array
    {
        return db()->query("SELECT jp.*, d.name AS department_name,
                                    (SELECT COUNT(*) FROM candidates c WHERE c.job_posting_id = jp.id) AS candidate_count
                             FROM job_postings jp
                             LEFT JOIN departments d ON d.id = jp.department_id
                             ORDER BY jp.created_at DESC")->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM job_postings WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $d, int $createdBy): int
    {
        $stmt = db()->prepare(
            'INSERT INTO job_postings (title, description, requirements, department_id, created_by)
             VALUES (:title, :description, :requirements, :department_id, :created_by)'
        );
        $stmt->execute([
            'title' => $d['title'],
            'description' => $d['description'],
            'requirements' => $d['requirements'] ?? null,
            'department_id' => $d['department_id'] ?? null,
            'created_by' => $createdBy,
        ]);
        $id = (int) db()->lastInsertId();

        if (!empty($d['questions']) && is_array($d['questions'])) {
            $qStmt = db()->prepare(
                'INSERT INTO interview_questions (job_posting_id, question_text, expected_keywords, order_index)
                 VALUES (?, ?, ?, ?)'
            );
            foreach (array_values($d['questions']) as $i => $q) {
                $qStmt->execute([$id, $q['text'], $q['keywords'] ?? '', $i]);
            }
        }
        return $id;
    }

    public static function close(int $id): void
    {
        db()->prepare("UPDATE job_postings SET status = 'closed' WHERE id = ?")->execute([$id]);
    }

    /** Job-specific questions if any were set for this posting, otherwise the
     *  generic fallback bank (job_posting_id IS NULL) — never both at once. */
    public static function questionsFor(int $jobPostingId): array
    {
        $stmt = db()->prepare('SELECT * FROM interview_questions WHERE job_posting_id = ? ORDER BY order_index');
        $stmt->execute([$jobPostingId]);
        $rows = $stmt->fetchAll();
        return $rows ?: db()->query('SELECT * FROM interview_questions WHERE job_posting_id IS NULL ORDER BY order_index')->fetchAll();
    }
}
