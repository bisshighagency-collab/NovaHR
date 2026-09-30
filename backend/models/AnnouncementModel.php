<?php
/** Data access for the `announcements` table — Admin-to-all-employees broadcasts. */
class AnnouncementModel
{
    public static function create(string $title, string $body, int $createdBy): int
    {
        $stmt = db()->prepare('INSERT INTO announcements (title, body, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$title, $body, $createdBy]);
        return (int) db()->lastInsertId();
    }

    public static function all(int $limit = 30): array
    {
        $stmt = db()->prepare(
            'SELECT a.*, u.name AS created_by_name FROM announcements a
             LEFT JOIN users u ON u.id = a.created_by
             ORDER BY a.created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function delete(int $id): void
    {
        db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
    }
}
