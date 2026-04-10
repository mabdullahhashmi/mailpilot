<?php

namespace App\Services;

use App\Models\WarmupCampaign;
use App\Models\SenderMailbox;
use App\Models\WarmupProfile;

class WarmupCampaignService
{
    public function start(SenderMailbox $mailbox, ?int $profileId = null): WarmupCampaign
    {
        $profile = $profileId
            ? WarmupProfile::findOrFail($profileId)
            : WarmupProfile::where('profile_type', 'default')->firstOrFail();

        $campaign = WarmupCampaign::create([
            'sender_mailbox_id' => $mailbox->id,
            'domain_id' => $mailbox->domain_id,
            'warmup_profile_id' => $profile->id,
            'start_date' => today(),
            'planned_duration_days' => $mailbox->target_warmup_duration_days,
            'current_day_number' => 1,
            'current_stage' => 'initial_trust',
            'status' => 'active',
        ]);

        $mailbox->update([
            'is_warmup_enabled' => true,
            'warmup_start_date' => today(),
            'current_warmup_day' => 1,
        ]);

        return $campaign;
    }

    public function stop(WarmupCampaign $campaign): void
    {
        $campaign->update(['status' => 'stopped']);
        $campaign->senderMailbox->update(['is_warmup_enabled' => false]);

        // Cancel all pending events
        $campaign->events()
            ->whereIn('status', ['pending', 'locked'])
            ->update(['status' => 'cancelled']);
    }

    public function pause(WarmupCampaign $campaign): void
    {
        $campaign->update([
            'status' => 'paused',
            'paused_at' => now(),
        ]);

        // Cancel pending events
        $campaign->events()
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }

    public function resume(WarmupCampaign $campaign): void
    {
        $campaign->update([
            'status' => 'active',
            'paused_at' => null,
        ]);
    }

    public function restart(WarmupCampaign $campaign): WarmupCampaign
    {
        $this->stop($campaign);

        return $this->start(
            $campaign->senderMailbox,
            $campaign->warmup_profile_id
        );
    }

    public function markCompleted(WarmupCampaign $campaign): void
    {
        $campaign->update([
            'status' => 'completed',
            'current_stage' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function switchToMaintenance(WarmupCampaign $campaign): void
    {
        $campaign->update([
            'current_stage' => 'maintenance',
            'maintenance_mode_enabled' => true,
        ]);

        $campaign->senderMailbox->update(['maintenance_mode' => true]);
    }

    public function advanceDay(WarmupCampaign $campaign): void
    {
        $newDay = $campaign->current_day_number + 1;
        $stage = $campaign->calculateStage();

        $campaign->update([
            'current_day_number' => $newDay,
            'current_stage' => $stage,
        ]);

        $campaign->senderMailbox->update([
            'current_warmup_day' => $newDay,
        ]);

        // Check if warmup period is complete
        if ($newDay > $campaign->planned_duration_days) {
            $this->switchToMaintenance($campaign);
        }
    }

    public function getActiveCampaigns(): \Illuminate\Database\Eloquent\Collection
    {
        return WarmupCampaign::where('status', 'active')
            ->with(['senderMailbox', 'domain', 'profile'])
            ->get();
    }
}
