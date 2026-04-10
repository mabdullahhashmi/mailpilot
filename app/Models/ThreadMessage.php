<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreadMessage extends Model
{
    protected $fillable = [
        'thread_id', 'actor_type', 'actor_mailbox_id', 'recipient_mailbox_id',
        'direction', 'subject', 'body', 'provider_message_id', 'in_reply_to_message_id',
        'message_step_number', 'sent_at', 'delivery_state',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
