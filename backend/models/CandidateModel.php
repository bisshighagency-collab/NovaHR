<?php
/**
 * A candidacy = one employee applying for one internal job opening.
 * The applicant's identity (name, email, department) lives in `users`;
 * this table only records the application itself.
 */
class CandidateModel
{
    private const SELECT_WITH_PERSON =
        'SELECT c.*, u.name, u.email, u.phone, u.position, d.name AS department_name, jp.title AS job_title
         FROM candidates c
         JOIN users u ON u.id = c.user_id
         JOIN job_postings jp ON jp.id = c.job_posting_id
         LEFT JOIN departments d ON d.id = u.department_id';

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare(self::SELECT_WITH_PERSON . ' WHERE c.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByUserAndJob(int $userId, int $jobPostingId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM candidates WHERE user_id = ? AND job_posting_id = ?');
        $stmt->execute([$userId, $jobPostingId]);
        return $stmt->fetch() ?: null;
    }

    public static function apply(int $userId, int $jobPostingId, ?string $motivation): int
    {
        $stmt = db()->prepare('INSERT INTO candidates (user_id, job_posting_id, motivation) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $jobPostingId, $motivation]);
        return (int) db()->lastInsertId();
    }

    public static function setStatus(int $id, string $status): void
    {
        db()->prepare('UPDATE candidates SET application_status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** All applications of one employee, newest first. */
    public static function listForUser(int $userId): array
    {
        $stmt = db()->prepare(self::SELECT_WITH_PERSON . ' WHERE c.user_id = ? ORDER BY c.created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function listForJob(int $jobPostingId): array
    {
        $stmt = db()->prepare(self::SELECT_WITH_PERSON . ' WHERE c.job_posting_id = ? ORDER BY c.created_at DESC');
        $stmt->execute([$jobPostingId]);
        return $stmt->fetchAll();
    }

    public static function listAll(): array
    {
        return db()->query(self::SELECT_WITH_PERSON . ' ORDER BY c.created_at DESC')->fetchAll();
    }
}
