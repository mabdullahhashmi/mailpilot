<?php

namespace App\Services;

use App\Models\WarmupProfile;
use Carbon\Carbon;

class RandomizationService
{
    /**
     * Determine thread length based on profile distribution.
     * E.g., {"2":40,"3":30,"4":20,"5":10} means 40% chance of 2 messages, etc.
     */
    public function threadLength(WarmupProfile $profile): int
    {
        $distribution = $profile->thread_length_distribution ?? ['2' => 40, '3' => 30, '4' => 20, '5' => 10];

        return $this->weightedRandom($distribution);
    }

    /**
     * Generate a random reply delay in minutes based on profile.
     */
    public function replyDelay(WarmupProfile $profile): int
    {
        $config = $profile->reply_delay_distribution ?? [
            'min_minutes' => 15,
            'max_minutes' => 180,
            'peak_minutes' => 60,
        ];

        // Use a bell-curve-like distribution around the peak
        $min = $config['min_minutes'];
        $max = $config['max_minutes'];
        $peak = $config['peak_minutes'];

        // Box-Muller approximation centered on peak
        $u1 = max(0.0001, mt_rand() / mt_getrandmax());
        $u2 = max(0.0001, mt_rand() / mt_getrandmax());
        $normal = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        $stdDev = ($max - $min) / 4;
        $value = $peak + ($normal * $stdDev);

        return (int) max($min, min($max, round($value)));
    }

    /**
     * Generate a scheduled time within a working window with natural randomization.
     * Avoids equal spacing and fixed patterns.
     */
    public function scheduledTime(string $windowStart, string $windowEnd, string $timezone = 'UTC'): Carbon
    {
        $start = Carbon::parse(today()->format('Y-m-d') . ' ' . $windowStart, $timezone);
        $end = Carbon::parse(today()->format('Y-m-d') . ' ' . $windowEnd, $timezone);

        // If the window has passed for today, use the window but set to now + random offset
        if ($end->isPast()) {
            return now()->addMinutes(rand(5, 30));
        }

        if ($start->isFuture()) {
            $totalMinutes = $start->diffInMinutes($end);
        } else {
            $start = now();
            $totalMinutes = $start->diffInMinutes($end);
        }

        // Generate non-uniform random offset (prefer mid-window)
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $biased = ($u1 + $u2) / 2; // Triangle distribution - peaks in middle

        $offsetMinutes = (int) ($biased * $totalMinutes);
        $offsetSeconds = rand(0, 59);

        return $start->copy()->addMinutes($offsetMinutes)->addSeconds($offsetSeconds);
    }

    /**
     * Randomize working window with slight variation each day.
     * For tight windows (< 30 min), apply minimal or no jitter.
     */
    public function workingWindow(string $baseStart, string $baseEnd): array
    {
        $start = Carbon::parse($baseStart);
        $end = Carbon::parse($baseEnd);

        // Calculate window size in minutes
        $windowSize = $start->diffInMinutes($end);

        if ($windowSize <= 30) {
            // Tight window: no jitter, use exact times
            return [
                'start' => $start->format('H:i:s'),
                'end' => $end->format('H:i:s'),
            ];
        }

        // For larger windows, apply proportional jitter (max 5% of window)
        $maxJitter = max(1, (int)($windowSize * 0.05));
        $startOffset = rand(-$maxJitter, $maxJitter);
        $endOffset = rand(-$maxJitter, $maxJitter);

        $start = $start->addMinutes($startOffset);
        $end = $end->addMinutes($endOffset);

        // Ensure valid window
        if ($start->gte($end)) {
            $start = Carbon::parse($baseStart);
            $end = Carbon::parse($baseEnd);
        }

        return [
            'start' => $start->format('H:i:s'),
            'end' => $end->format('H:i:s'),
        ];
    }

    /**
     * Select from a weighted distribution.
     * Input: ["2" => 40, "3" => 30, "4" => 20, "5" => 10]
     */
    public function weightedRandom(array $distribution): int
    {
        $total = array_sum($distribution);
        $roll = rand(1, $total);
        $cumulative = 0;

        foreach ($distribution as $value => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return (int) $value;
            }
        }

        return (int) array_key_first($distribution);
    }

    /**
     * Decide if today should be a light or heavy day (within safe bounds).
     * Returns a multiplier: 0.7 (light) to 1.3 (heavy).
     */
    public function dailyIntensityMultiplier(): float
    {
        $options = [0.7, 0.8, 0.9, 1.0, 1.0, 1.1, 1.2, 1.3];
        return $options[array_rand($options)];
    }
}
