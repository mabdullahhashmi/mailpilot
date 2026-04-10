<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\MailboxHealthLog;
use App\Models\Domain;

class ReadinessScoringService
{
    /**
     * Readiness states: not_ready, warming, nearly_ready, ready, maintenance.
     * Calculated from health history, send volume, and age.
     */
    public function calculateReadiness(SenderMailbox $sender): string
    {
        $campaign = $sender->warmupCampaigns()
            ->whereIn('status', ['active', 'maintenance'])
            ->latest()
            ->first();

        if (!$campaign) {
            return 'not_ready';
        }

        $dayNumber = $campaign->current_day_number;
        $stage = $campaign->current_stage;
        $avgHealth = $this->getAverageHealthScore($sender, 7);

        // Not started or just started
        if ($dayNumber <= 3) {
            return 'not_ready';
        }

        // Warming phase
        if ($stage === 'ramp_up' || $dayNumber <= 7) {
            if ($avgHealth >= 60) {
                return 'warming';
            }
            return 'not_ready';
        }

        // Mid warmup
        if ($stage === 'building' || ($dayNumber > 7 && $dayNumber <= 14)) {
            if ($avgHealth >= 70) {
                return 'nearly_ready';
            }
            return 'warming';
        }

        // Late warmup or stabilizing
        if ($stage === 'stabilizing' || $dayNumber > 14) {
            if ($avgHealth >= 80) {
                return 'ready';
            }
            if ($avgHealth >= 65) {
                return 'nearly_ready';
            }
            return 'warming';
        }

        // Maintenance
        if ($campaign->status === 'maintenance') {
            return 'maintenance';
        }

        return 'warming';
    }

    /**
     * Get readiness data for dashboard display.
     */
    public function getReadinessSummary(SenderMailbox $sender): array
    {
        $readiness = $this->calculateReadiness($sender);
        $avgHealth7 = $this->getAverageHealthScore($sender, 7);
        $avgHealth30 = $this->getAverageHealthScore($sender, 30);

        $campaign = $sender->warmupCampaigns()
            ->whereIn('status', ['active', 'maintenance'])
            ->latest()
            ->first();

        return [
            'readiness' => $readiness,
            'readiness_label' => $this->readinessLabel($readiness),
            'readiness_color' => $this->readinessColor($readiness),
            'health_score_7d' => $avgHealth7,
            'health_score_30d' => $avgHealth30,
            'warmup_day' => $campaign?->current_day_number ?? 0,
            'planned_duration' => $campaign?->planned_duration_days ?? 0,
            'progress_percent' => $campaign
                ? min(100, (int)(($campaign->current_day_number / max(1, $campaign->planned_duration_days)) * 100))
                : 0,
        ];
    }

    /**
     * Check if a domain is ready based on all its sender mailboxes.
     */
    public function isDomainReady(Domain $domain): bool
    {
        $senders = $domain->senderMailboxes;

        if ($senders->isEmpty()) return false;

        $readyCount = $senders->filter(function ($sender) {
            return in_array($this->calculateReadiness($sender), ['ready', 'maintenance']);
        })->count();

        return ($readyCount / $senders->count()) >= 0.7; // 70% of senders must be ready
    }

    private function getAverageHealthScore(SenderMailbox $sender, int $days): int
    {
        $avg = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->where('log_date', '>=', today()->subDays($days))
            ->avg('health_score');

        return (int) ($avg ?? 0);
    }

    private function readinessLabel(string $readiness): string
    {
        return match ($readiness) {
            'not_ready' => 'Not Ready',
            'warming' => 'Warming Up',
            'nearly_ready' => 'Nearly Ready',
            'ready' => 'Ready',
            'maintenance' => 'Maintenance',
            default => 'Unknown',
        };
    }

    private function readinessColor(string $readiness): string
    {
        return match ($readiness) {
            'not_ready' => '#ef4444',
            'warming' => '#f59e0b',
            'nearly_ready' => '#3b82f6',
            'ready' => '#22c55e',
            'maintenance' => '#8b5cf6',
            default => '#6b7280',
        };
    }
}
