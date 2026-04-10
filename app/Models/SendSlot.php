<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SendSlot extends Model
{
    protected $fillable = [
        'warmup_campaign_id', 'sender_mailbox_id', 'seed_mailbox_id',
        'thread_id', 'warmup_event_id', 'slot_type',
        'planned_at', 'executed_at', 'status', 'skip_reason', 'slot_date',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'executed_at' => 'datetime',
        'slot_date' => 'date',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WarmupCampaign::class, 'warmup_campaign_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class, 'sender_mailbox_id');
    }

    public function seed(): BelongsTo
    {
        return $this->belongsTo(SeedMailbox::class, 'seed_mailbox_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WarmupEvent::class, 'warmup_event_id');
    }
}
