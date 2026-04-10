<?php

namespace App\Services;

/**
 * Detects the intent/category of an incoming warmup email message
 * so seed replies can be contextually matched.
 */
class IntentDetector
{
    /**
     * Intent keyword patterns mapped to categories.
     * Order matters: first match wins.
     */
    private array $patterns = [
        'question' => [
            'can you', 'could you', 'would you', 'do you', 'are you', 'is it',
            'how do', 'how can', 'how does', 'how much', 'how long', 'how would',
            'what is', 'what are', 'what do', 'what would', 'what about',
            'when can', 'when do', 'when will', 'when would',
            'where can', 'where do',
            'who can', 'who do', 'who would',
            'which one', 'which is',
            'any idea', 'any thoughts', 'any suggestions',
            'wondering if', 'curious about', 'quick question',
            'let me know', 'thoughts on',
        ],
        'pricing' => [
            'pricing', 'price', 'cost', 'budget', 'investment', 'quote',
            'how much', 'rate', 'rates', 'package', 'packages',
            'affordable', 'expensive', 'charge', 'fee', 'fees',
            'estimate', 'proposal', 'billing', 'payment',
        ],
        'timeline' => [
            'timeline', 'deadline', 'timeframe', 'how long', 'how soon',
            'when can you', 'turnaround', 'delivery', 'deliver',
            'start date', 'launch date', 'eta', 'estimated time',
            'available when', 'availability', 'schedule', 'scheduling',
            'this week', 'next week', 'this month', 'asap', 'urgent',
        ],
        'technical' => [
            'landing page', 'conversion', 'optimization', 'optimize',
            'website', 'web design', 'responsive', 'mobile',
            'a/b test', 'split test', 'page speed', 'loading',
            'seo', 'html', 'css', 'javascript', 'wordpress',
            'shopify', 'funnel', 'cta', 'call to action',
            'bounce rate', 'conversion rate', 'ux', 'ui',
            'design', 'redesign', 'rebuild', 'development',
            'hosting', 'domain', 'ssl', 'analytics', 'tracking',
            'google analytics', 'heatmap', 'performance',
        ],
        'thanks' => [
            'thank you', 'thanks', 'appreciate', 'grateful',
            'that helps', 'that\'s helpful', 'perfect', 'great',
            'awesome', 'excellent', 'wonderful', 'fantastic',
            'sounds good', 'sounds great', 'love it', 'looks good',
            'well done', 'good job', 'nice work', 'impressive',
        ],
        'follow_up' => [
            'following up', 'follow up', 'checking in', 'just checking',
            'circling back', 'touching base', 'wanted to reconnect',
            'haven\'t heard', 'any update', 'any progress',
            'still interested', 'are we still', 'moving forward',
            'next steps', 'what\'s next', 'going forward',
        ],
        'availability' => [
            'available', 'free to', 'open to', 'have time',
            'calendar', 'meeting', 'call', 'chat', 'zoom',
            'connect', 'catch up', 'sync up', 'discuss',
            'slot', 'book', 'appointment',
        ],
        'agreement' => [
            'sounds good', 'agree', 'absolutely', 'definitely',
            'let\'s do it', 'let\'s go', 'i\'m in', 'count me in',
            'makes sense', 'works for me', 'on board', 'ready',
            'let\'s proceed', 'approved', 'confirmed', 'deal',
        ],
        'objection' => [
            'not sure', 'not interested', 'not right now', 'maybe later',
            'too busy', 'no budget', 'already have', 'not a priority',
            'need to think', 'give me time', 'hold off',
            'concerns', 'hesitant', 'not convinced',
        ],
    ];

    /**
     * Detect intent from email body text.
     * Returns the category name or 'generic' if no match.
     */
    public function detect(?string $messageBody): string
    {
        if (!$messageBody) {
            return 'generic';
        }

        // Strip HTML and normalize
        $text = strtolower(strip_tags($messageBody));
        $text = preg_replace('/\s+/', ' ', $text);

        // Score each category
        $scores = [];
        foreach ($this->patterns as $category => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$category] = $score;
            }
        }

        if (empty($scores)) {
            return 'generic';
        }

        // Return highest-scoring category
        arsort($scores);
        return array_key_first($scores);
    }

    /**
     * Detect multiple intents with confidence scores.
     * Returns array of ['category' => score] sorted by score descending.
     */
    public function detectMultiple(?string $messageBody): array
    {
        if (!$messageBody) {
            return ['generic' => 1];
        }

        $text = strtolower(strip_tags($messageBody));
        $text = preg_replace('/\s+/', ' ', $text);

        $scores = [];
        foreach ($this->patterns as $category => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$category] = $score;
            }
        }

        if (empty($scores)) {
            return ['generic' => 1];
        }

        arsort($scores);
        return $scores;
    }
}
