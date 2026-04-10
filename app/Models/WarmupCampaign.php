<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarmupCampaign extends Model
{
    protected $fillable = [
        'campaign_name', 'sender_mailbox_id', 'domain_id', 'warmup_profile_id',
        'start_date', 'planned_duration_days', 'current_day_number',
        'current_stage', 'status', 'maintenance_mode_enabled',
        'time_window_start', 'time_window_end',
        'completed_at', 'paused_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'maintenance_mode_enabled' => 'boolean',
        'completed_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WarmupProfile::class, 'warmup_profile_id');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WarmupEvent::class);
    }

    public function plannerRuns(): HasMany
    {
        return $this->hasMany(PlannerRun::class);
    }

    public function pauseRules(): HasMany
    {
        return $this->morphMany(PauseRule::class, 'pausable');
    }

    public function calculateStage(): string
    {
        $day = $this->current_day_number;

        if ($day <= 4) return 'initial_trust';
        if ($day <= 9) return 'controlled_expansion';
        if ($day <= 14) return 'behavioral_maturity';
        if ($day <= $this->planned_duration_days) return 'readiness';
        return 'maintenance';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
