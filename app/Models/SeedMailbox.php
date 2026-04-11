<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SeedMailbox extends Model
{
    protected $fillable = [
        'email_address', 'provider_type',
        'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
        'imap_host', 'imap_port', 'imap_username', 'imap_password', 'imap_encryption',
        'status', 'working_hours_start', 'working_hours_end',
        'daily_total_interaction_cap', 'per_domain_interaction_cap', 'per_sender_interaction_cap',
        'concurrent_thread_cap', 'cooldown_minutes_between_threads',
        'trust_tier', 'health_score', 'last_used_at', 'is_paused',
        'seed_health_score', 'reply_quality_score', 'total_replies_sent',
        'total_opens', 'failed_interactions',
        'last_health_check_at', 'auto_disabled_at', 'auto_disable_reason',
    ];

    protected $casts = [
        'is_paused' => 'boolean',
        'last_used_at' => 'datetime',
        'last_health_check_at' => 'datetime',
        'auto_disabled_at' => 'datetime',
    ];

    protected $hidden = [
        'smtp_password', 'imap_password',
    ];

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(SeedUsageLog::class);
    }

    public function pauseRules(): MorphMany
    {
        return $this->morphMany(PauseRule::class, 'pausable');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active' && !$this->is_paused;
    }
}
