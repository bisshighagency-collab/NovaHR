<?php
class AIQueryModel
{
    public static function log(?int $employeeId, string $question, string $answer, ?string $topic): int
    {
        $stmt = db()->prepare(
            'INSERT INTO ai_queries (employee_id, question, answer, matched_topic) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$employeeId, $question, $answer, $topic]);
        return (int) db()->lastInsertId();
    }

    public static function historyForEmployee(int $employeeId, int $limit = 20): array
    {
        $stmt = db()->prepare('SELECT * FROM ai_queries WHERE employee_id = ? ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $employeeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
