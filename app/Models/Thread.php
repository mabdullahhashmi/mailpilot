<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Thread extends Model
{
    protected $fillable = [
        'warmup_campaign_id', 'sender_mailbox_id', 'seed_mailbox_id', 'domain_id',
        'initiator_type', 'thread_status', 'planned_message_count', 'actual_message_count',
        'current_step_number', 'next_actor_type', 'next_scheduled_at',
        'close_condition_type', 'template_group_id', 'subject_line',
    ];

    protected $casts = [
        'next_scheduled_at' => 'datetime',
    ];

    public function warmupCampaign(): BelongsTo
    {
        return $this->belongsTo(WarmupCampaign::class);
    }

    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class);
    }

    public function seedMailbox(): BelongsTo
    {
        return $this->belongsTo(SeedMailbox::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ThreadMessage::class)->orderBy('message_step_number');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WarmupEvent::class);
    }

    public function isComplete(): bool
    {
        return in_array($this->thread_status, ['closed', 'failed']);
    }

    public function shouldClose(): bool
    {
        return $this->actual_message_count >= $this->planned_message_count;
    }
}
