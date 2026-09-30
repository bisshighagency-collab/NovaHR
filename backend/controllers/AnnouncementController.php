<?php
class AnnouncementController
{
    /** GET /api/announcements — any signed-in employee or admin reads the feed. */
    public static function list(): void
    {
        require_auth();
        json_response(['announcements' => AnnouncementModel::all()]);
    }

    /** POST /api/announcements — Admin broadcasts a company-wide announcement to every employee. */
    public static function create(): void
    {
        $claims = require_role('admin');
        $body = request_body();
        require_fields($body, ['title', 'body']);
        AnnouncementModel::create(trim($body['title']), trim($body['body']), (int) $claims['sub']);
        json_response(['announcements' => AnnouncementModel::all()], 201);
    }

    /** DELETE /api/announcements/{id} — Admin removes an announcement. */
    public static function delete(int $id): void
    {
        require_role('admin');
        AnnouncementModel::delete($id);
        json_response(['announcements' => AnnouncementModel::all()]);
    }
}
