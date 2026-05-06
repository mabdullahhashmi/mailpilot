<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowTestRun extends Model
{
    protected $fillable = [
        'sender_mailbox_id',
        'phase_count',
        'open_delay_seconds',
        'star_delay_seconds',
        'reply_delay_seconds',
        'status',
        'created_by',
        'started_at',
        'finished_at',
        'summary',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary' => 'array',
    ];

    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(FlowTestStep::class)->orderBy('seed_mailbox_id')->orderBy('step_index');
    }
}
