<?php

namespace App\Services;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\WarmupCampaign;
use App\Models\SenderMailbox;
use App\Models\SeedMailbox;

class ThreadService
{
    public function __construct(
        private RandomizationService $randomizer,
        private ContentService $content,
    ) {}

    public function createThread(
        WarmupCampaign $campaign,
        SenderMailbox $sender,
        SeedMailbox $seed
    ): Thread {
        $plannedLength = $this->randomizer->threadLength($campaign->profile);

        $template = $this->content->selectInitialTemplate($campaign->current_stage);

        $thread = Thread::create([
            'warmup_campaign_id' => $campaign->id,
            'sender_mailbox_id' => $sender->id,
            'seed_mailbox_id' => $seed->id,
            'domain_id' => $campaign->domain_id,
            'initiator_type' => 'sender',
            'thread_status' => 'planned',
            'planned_message_count' => $plannedLength,
            'actual_message_count' => 0,
            'current_step_number' => 0,
            'next_actor_type' => 'sender',
            'close_condition_type' => 'step_limit',
            'template_group_id' => $template?->id,
            'subject_line' => $this->content->generateSubject($template),
        ]);

        return $thread;
    }

    public function advanceThread(Thread $thread, ThreadMessage $message): void
    {
        $newStep = $thread->current_step_number + 1;
        $newActualCount = $thread->actual_message_count + 1;

        $updates = [
            'current_step_number' => $newStep,
            'actual_message_count' => $newActualCount,
            'thread_status' => 'active',
        ];

        // Determine next actor
        if ($newActualCount >= $thread->planned_message_count) {
            $updates['thread_status'] = 'closing';
            $updates['next_actor_type'] = 'none';
        } else {
            // Alternate between sender and seed
            $updates['next_actor_type'] = $message->actor_type === 'sender' ? 'seed' : 'sender';
        }

        $thread->update($updates);
    }

    public function closeThread(Thread $thread, string $reason = 'step_limit'): void
    {
        $thread->update([
            'thread_status' => 'closed',
            'next_actor_type' => 'none',
            'close_condition_type' => $reason,
        ]);
    }

    public function failThread(Thread $thread, string $reason): void
    {
        $thread->update([
            'thread_status' => 'failed',
            'next_actor_type' => 'none',
            'close_condition_type' => 'error',
        ]);
    }

    public function getOpenThreadsForCampaign(WarmupCampaign $campaign): \Illuminate\Database\Eloquent\Collection
    {
        return Thread::where('warmup_campaign_id', $campaign->id)
            ->whereIn('thread_status', ['active', 'awaiting_reply'])
            ->where('next_actor_type', '!=', 'none')
            ->get();
    }

    public function getContinuationThreads(WarmupCampaign $campaign, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return Thread::where('warmup_campaign_id', $campaign->id)
            ->whereIn('thread_status', ['active', 'awaiting_reply'])
            ->where('next_actor_type', '!=', 'none')
            ->where('actual_message_count', '<', \Illuminate\Support\Facades\DB::raw('planned_message_count'))
            ->orderBy('updated_at', 'asc')
            ->limit($limit)
            ->get();
    }
}
