<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BounceEvent extends Model
{
    protected $fillable = [
        'sender_mailbox_id', 'warmup_event_id', 'thread_id',
        'recipient_email', 'bounce_type', 'bounce_code', 'bounce_message',
        'provider', 'is_suppressed', 'bounced_at',
    ];

    protected $casts = [
        'bounced_at' => 'datetime',
        'is_suppressed' => 'boolean',
    ];

    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class);
    }

    public function warmupEvent(): BelongsTo
    {
        return $this->belongsTo(WarmupEvent::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function isHard(): bool
    {
        return $this->bounce_type === 'hard';
    }
}
