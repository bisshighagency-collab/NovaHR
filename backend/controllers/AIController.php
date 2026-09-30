<?php
class AIController
{
    /** POST /api/ai/ask — use case: Ask AI Assistant. Body: { question, is_new_hire? } */
    public static function ask(): void
    {
        $claims = require_role('employee');
        $body = request_body();
        require_fields($body, ['question']);

        $match = CompanyInfoModel::search($body['question']);
        if ($match) {
            $answer = $match['content'];
        } else {
            $answer = "I don't have information about that yet. Please contact HR, or try rephrasing your question "
                . "(e.g. ask about leave policy, working hours, payroll dates, or company history).";
        }

        AIQueryModel::log((int) $claims['sub'], $body['question'], $answer, $match['topic_key'] ?? null);
        json_response(['answer' => $answer, 'matched_topic' => $match['title'] ?? null]);
    }

    /** GET /api/ai/onboarding — use case: Ask AI Assistant, new-hire walkthrough branch. */
    public static function onboarding(): void
    {
        require_role('employee');
        json_response(['steps' => CompanyInfoModel::onboardingEntries()]);
    }

    /** GET /api/ai/history — the employee's own past questions. */
    public static function myHistory(): void
    {
        $claims = require_role('employee');
        json_response(['history' => AIQueryModel::historyForEmployee((int) $claims['sub'])]);
    }

    /** Company-info / knowledge-base management (Admin: Manage AI Knowledge Base). */
    public static function listKnowledgeBase(): void
    {
        require_role('admin');
        json_response(['entries' => CompanyInfoModel::all()]);
    }

    public static function upsertKnowledgeBase(): void
    {
        require_role('admin');
        $body = request_body();
        require_fields($body, ['topic_key', 'title', 'content']);
        CompanyInfoModel::upsert($body);
        json_response(['entries' => CompanyInfoModel::all()], 201);
    }

    public static function deleteKnowledgeBase(int $id): void
    {
        require_role('admin');
        CompanyInfoModel::delete($id);
        json_response(['entries' => CompanyInfoModel::all()]);
    }
}
