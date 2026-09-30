<?php
/**
 * Data access for `company_info` — the AI assistant's knowledge base.
 *
 * NOTE on the "AI Engine": the project report models a hosted AI Engine API
 * (for the assistant, onboarding walkthrough, and interview scoring). This
 * build ships a transparent, dependency-free keyword-matching engine here
 * so the whole system runs standalone with no external API key. It
 * implements the exact same contract (question in, answer out) described in
 * the sequence diagrams, so it is a drop-in seam: replace
 * AIController::searchKnowledgeBase() with a real LLM API call later without
 * touching any other part of the app.
 */
class CompanyInfoModel
{
    public static function all(): array
    {
        return db()->query('SELECT * FROM company_info ORDER BY title')->fetchAll();
    }

    public static function onboardingEntries(): array
    {
        return db()->query('SELECT * FROM company_info WHERE is_onboarding = 1 ORDER BY id')->fetchAll();
    }

    /** Entries the admin has marked visible to job candidates — the dashboard's FAQ section. */
    public static function candidateFaqEntries(): array
    {
        return db()->query('SELECT * FROM company_info WHERE visible_to_candidates = 1 ORDER BY id')->fetchAll();
    }

    public static function upsert(array $d): void
    {
        // Each named placeholder must be unique: with PDO::ATTR_EMULATE_PREPARES
        // off (native prepares), MySQL's binary protocol does not support
        // re-using the same named parameter twice in one statement, so the
        // ON DUPLICATE KEY UPDATE clause gets its own :new_* set below.
        $stmt = db()->prepare(
            'INSERT INTO company_info (topic_key, title, content, tags, is_onboarding, visible_to_candidates)
             VALUES (:key, :title, :content, :tags, :onboarding, :candidate_faq)
             ON DUPLICATE KEY UPDATE title = :new_title, content = :new_content, tags = :new_tags,
                is_onboarding = :new_onboarding, visible_to_candidates = :new_candidate_faq'
        );
        $onboarding = !empty($d['is_onboarding']) ? 1 : 0;
        $candidateFaq = !empty($d['visible_to_candidates']) ? 1 : 0;
        $stmt->execute([
            'key' => $d['topic_key'],
            'title' => $d['title'],
            'content' => $d['content'],
            'tags' => $d['tags'] ?? '',
            'onboarding' => $onboarding,
            'candidate_faq' => $candidateFaq,
            'new_title' => $d['title'],
            'new_content' => $d['content'],
            'new_tags' => $d['tags'] ?? '',
            'new_onboarding' => $onboarding,
            'new_candidate_faq' => $candidateFaq,
        ]);
    }

    public static function delete(int $id): void
    {
        db()->prepare('DELETE FROM company_info WHERE id = ?')->execute([$id]);
    }

    /**
     * Keyword-overlap search: scores every knowledge-base entry by how many
     * significant words it shares with the question (title + tags count
     * double), and returns the best match if it clears a minimum score.
     */
    public static function search(string $question): ?array
    {
        $stopWords = ['the','a','an','is','are','was','were','do','does','did','what','when','where','how',
            'why','who','which','to','of','in','on','for','and','or','my','me','i','can','could','will',
            'would','should','please','tell','about','it','this','that'];

        $words = preg_split('/[^a-z0-9]+/i', strtolower($question));
        $words = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopWords, true));
        if (!$words) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach (self::all() as $entry) {
            $haystack = strtolower($entry['title'] . ' ' . $entry['content'] . ' ' . $entry['tags']);
            $tagWords = strtolower($entry['tags']);
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($haystack, $w)) {
                    $score += str_contains($tagWords, $w) ? 2 : 1;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        return ($best && $bestScore >= 1) ? $best : null;
    }
}
