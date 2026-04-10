<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlannerRun extends Model
{
    protected $fillable = [
        'warmup_campaign_id', 'plan_date', 'warmup_day_number', 'warmup_stage',
        'total_action_budget', 'new_thread_target', 'reply_target',
        'actual_new_threads', 'actual_replies', 'actual_total_actions',
        'eligible_seed_ids', 'provider_distribution',
        'working_window_start', 'working_window_end', 'notes', 'status',
    ];

    protected $casts = [
        'plan_date' => 'date',
        'eligible_seed_ids' => 'array',
        'provider_distribution' => 'array',
        'notes' => 'array',
    ];

    public function warmupCampaign(): BelongsTo
    {
        return $this->belongsTo(WarmupCampaign::class);
    }
}
