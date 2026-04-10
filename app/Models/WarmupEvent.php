<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarmupEvent extends Model
{
    protected $fillable = [
        'event_type', 'actor_type', 'actor_mailbox_id',
        'recipient_type', 'recipient_mailbox_id',
        'thread_id', 'warmup_campaign_id',
        'scheduled_at', 'executed_at', 'status',
        'retry_count', 'max_retries', 'priority',
        'payload', 'failure_reason',
        'lock_token', 'lock_expires_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'executed_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'payload' => 'array',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WarmupCampaign::class, 'warmup_campaign_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WarmupEventLog::class);
    }

    public function isRetryable(): bool
    {
        return $this->status === 'failed' && $this->retry_count < $this->max_retries;
    }

    public function isDue(): bool
    {
        return $this->status === 'pending' && $this->scheduled_at <= now();
    }

    public function acquireLock(string $token, int $ttlSeconds = 300): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $affected = static::where('id', $this->id)
            ->where('status', 'pending')
            ->whereNull('lock_token')
            ->update([
                'status' => 'locked',
                'lock_token' => $token,
                'lock_expires_at' => now()->addSeconds($ttlSeconds),
            ]);

        return $affected > 0;
    }

    public function releaseLock(): void
    {
        $this->update([
            'lock_token' => null,
            'lock_expires_at' => null,
            'status' => 'pending',
        ]);
    }
}
