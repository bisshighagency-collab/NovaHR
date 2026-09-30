<?php
/** Per-user notifications (e.g. an update on an internal job application). */
class NotificationModel
{
    public static function create(int $userId, string $title, string $body): void
    {
        db()->prepare('INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)')
            ->execute([$userId, $title, $body]);
    }

    public static function listForUser(int $userId): array
    {
        $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function unreadCount(int $userId): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetch()['c'];
    }

    public static function markAllRead(int $userId): void
    {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
    }

    /** Tells the applicant their candidacy changed status. */
    public static function notifyApplicationStatus(array $candidate, string $status): void
    {
        $job = $candidate['job_title'] ?? 'the job opening';
        $messages = [
            'shortlisted' => ['You have been shortlisted', "Good news — you have been shortlisted for \"$job\". HR will contact you about the next steps."],
            'rejected'    => ['Application update', "Thank you for applying for \"$job\". Your application was not retained this time."],
            'hired'       => ['Congratulations!', "You have been selected for \"$job\". HR will contact you about your new role."],
            'interviewed' => ['Interview received', "Your AI interview for \"$job\" was scored and is now waiting for an administrator's review."],
        ];
        if (!isset($messages[$status])) {
            return;
        }
        [$title, $body] = $messages[$status];
        self::create((int) $candidate['user_id'], $title, $body);
    }
}
