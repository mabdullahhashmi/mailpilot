<?php

namespace App\Services;

use App\Models\SendSlot;
use App\Models\WarmupCampaign;
use App\Models\WarmupEvent;

class SlotSchedulerService
{
    public function __construct(
        private RandomizationService $randomizer,
    ) {}

    /**
     * Create a visible send slot and link it to a warmup event.
     */
    public function createSlot(
        WarmupCampaign $campaign,
        int $senderMailboxId,
        ?int $seedMailboxId,
        ?int $threadId,
        string $slotType,
        \DateTimeInterface $plannedAt,
        ?int $eventId = null,
    ): SendSlot {
        return SendSlot::create([
            'warmup_campaign_id' => $campaign->id,
            'sender_mailbox_id' => $senderMailboxId,
            'seed_mailbox_id' => $seedMailboxId,
            'thread_id' => $threadId,
            'warmup_event_id' => $eventId,
            'slot_type' => $slotType,
            'planned_at' => $plannedAt,
            'status' => 'planned',
            'slot_date' => $plannedAt->format('Y-m-d'),
        ]);
    }

    /**
     * Link a slot to a warmup event once created.
     */
    public function linkEventToSlot(SendSlot $slot, WarmupEvent $event): void
    {
        $slot->update(['warmup_event_id' => $event->id]);
    }

    /**
     * Mark a slot as completed when its event finishes.
     */
    public function markSlotCompleted(SendSlot $slot): void
    {
        $slot->update([
            'status' => 'completed',
            'executed_at' => now(),
        ]);
    }

    /**
     * Mark a slot as skipped.
     */
    public function markSlotSkipped(SendSlot $slot, string $reason): void
    {
        $slot->update([
            'status' => 'skipped',
            'skip_reason' => $reason,
        ]);
    }

    /**
     * Mark a slot as failed.
     */
    public function markSlotFailed(SendSlot $slot): void
    {
        $slot->update(['status' => 'failed']);
    }

    /**
     * Get today's slot schedule for a campaign — the visible timeline.
     */
    public function getTodaySlots(int $campaignId): array
    {
        return SendSlot::where('warmup_campaign_id', $campaignId)
            ->where('slot_date', today())
            ->orderBy('planned_at')
            ->with(['sender:id,email_address', 'seed:id,email_address'])
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'type' => $s->slot_type,
                'planned_at' => $s->planned_at->format('H:i:s'),
                'executed_at' => $s->executed_at?->format('H:i:s'),
                'status' => $s->status,
                'sender' => $s->sender?->email_address,
                'seed' => $s->seed?->email_address,
                'skip_reason' => $s->skip_reason,
            ])
            ->toArray();
    }

    /**
     * Get slot stats for a campaign and date range.
     */
    public function getSlotStats(int $campaignId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $query = SendSlot::where('warmup_campaign_id', $campaignId);

        if ($fromDate) $query->where('slot_date', '>=', $fromDate);
        if ($toDate) $query->where('slot_date', '<=', $toDate);

        $slots = $query->get();

        return [
            'total' => $slots->count(),
            'planned' => $slots->where('status', 'planned')->count(),
            'completed' => $slots->where('status', 'completed')->count(),
            'skipped' => $slots->where('status', 'skipped')->count(),
            'failed' => $slots->where('status', 'failed')->count(),
            'completion_rate' => $slots->count() > 0
                ? round($slots->where('status', 'completed')->count() / $slots->count() * 100, 1)
                : 0,
            'by_type' => [
                'initial_send' => $slots->where('slot_type', 'initial_send')->count(),
                'reply' => $slots->where('slot_type', 'reply')->count(),
                'auxiliary' => $slots->where('slot_type', 'auxiliary')->count(),
            ],
        ];
    }

    /**
     * Clean up old executed slots (older than 30 days).
     */
    public function cleanOldSlots(int $daysToKeep = 30): int
    {
        return SendSlot::where('slot_date', '<', today()->subDays($daysToKeep))
            ->whereIn('status', ['completed', 'skipped', 'failed'])
            ->delete();
    }

    /**
     * Auto-complete orphaned slots (event completed but slot not updated).
     */
    public function syncSlotStatuses(): int
    {
        $orphaned = SendSlot::where('status', 'planned')
            ->whereNotNull('warmup_event_id')
            ->whereHas('event', fn ($q) => $q->where('status', 'completed'))
            ->get();

        foreach ($orphaned as $slot) {
            $this->markSlotCompleted($slot);
        }

        return $orphaned->count();
    }
}
