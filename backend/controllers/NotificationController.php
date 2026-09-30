<?php
/**
 * Notifications for the signed-in person. A candidate session and an employee session
 * belong to the same person (same users.id), so both see the same notifications.
 */
class NotificationController
{
    /** GET /api/notifications */
    public static function list(): void
    {
        $claims = require_role('candidate', 'employee');
        $userId = (int) $claims['sub'];
        json_response([
            'notifications' => NotificationModel::listForUser($userId),
            'unread' => NotificationModel::unreadCount($userId),
        ]);
    }

    /** POST /api/notifications/read — mark all as read. */
    public static function markAllRead(): void
    {
        $claims = require_role('candidate', 'employee');
        NotificationModel::markAllRead((int) $claims['sub']);
        json_response(['ok' => true]);
    }
}
