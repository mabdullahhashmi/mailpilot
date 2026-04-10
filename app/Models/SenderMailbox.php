<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SenderMailbox extends Model
{
    protected $fillable = [
        'domain_id', 'email_address', 'provider_type',
        'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
        'imap_host', 'imap_port', 'imap_username', 'imap_password', 'imap_encryption',
        'status', 'is_warmup_enabled', 'warmup_start_date', 'current_warmup_day',
        'target_warmup_duration_days', 'daily_send_cap', 'daily_reply_cap',
        'timezone', 'working_hours_start', 'working_hours_end',
        'health_score', 'readiness_score', 'is_paused', 'maintenance_mode',
        'last_smtp_test_at', 'last_imap_test_at',
        'last_smtp_test_result', 'last_imap_test_result',
    ];

    protected $casts = [
        'warmup_start_date' => 'date',
        'is_warmup_enabled' => 'boolean',
        'is_paused' => 'boolean',
        'maintenance_mode' => 'boolean',
        'last_smtp_test_at' => 'datetime',
        'last_imap_test_at' => 'datetime',
    ];

    protected $hidden = [
        'smtp_password', 'imap_password',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function warmupCampaign(): HasOne
    {
        return $this->hasOne(WarmupCampaign::class)->latestOfMany();
    }

    public function warmupCampaigns(): HasMany
    {
        return $this->hasMany(WarmupCampaign::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function healthLogs(): HasMany
    {
        return $this->hasMany(MailboxHealthLog::class);
    }

    public function pauseRules(): HasMany
    {
        return $this->morphMany(PauseRule::class, 'pausable');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active' && !$this->is_paused && $this->is_warmup_enabled;
    }
}
