<?php

namespace App\Services;

/**
 * Processes spintax syntax in template content.
 * Converts {option1|option2|option3} into one random selection.
 * Supports nested spintax: {Hi|{Hello|Hey}} there
 */
class SpintaxProcessor
{
    /**
     * Process all spintax in text and return a randomized version.
     */
    public function process(string $text): string
    {
        // Process nested spintax from inside out
        $maxIterations = 10; // Safety limit for nesting depth
        $iteration = 0;

        while (preg_match('/\{[^{}]+\}/', $text) && $iteration < $maxIterations) {
            $text = preg_replace_callback('/\{([^{}]+)\}/', function ($matches) {
                $options = explode('|', $matches[1]);
                return trim($options[array_rand($options)]);
            }, $text);
            $iteration++;
        }

        // Clean up extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\s([.,!?;:])/', '$1', $text);

        return trim($text);
    }

    /**
     * Count the number of possible unique variations in a spintax template.
     */
    public function countVariations(string $text): int
    {
        $count = 1;

        preg_match_all('/\{([^{}]+)\}/', $text, $matches);
        foreach ($matches[1] as $match) {
            $options = explode('|', $match);
            $count *= count($options);
        }

        return $count;
    }

    /**
     * Validate spintax syntax — check for balanced braces.
     */
    public function validate(string $text): bool
    {
        $depth = 0;
        for ($i = 0; $i < strlen($text); $i++) {
            if ($text[$i] === '{') $depth++;
            if ($text[$i] === '}') $depth--;
            if ($depth < 0) return false;
        }
        return $depth === 0;
    }

    /**
     * Generate multiple unique variations from a single template.
     */
    public function generateBatch(string $text, int $count): array
    {
        $results = [];
        $attempts = 0;
        $maxAttempts = $count * 5;

        while (count($results) < $count && $attempts < $maxAttempts) {
            $result = $this->process($text);
            if (!in_array($result, $results)) {
                $results[] = $result;
            }
            $attempts++;
        }

        return $results;
    }
}
