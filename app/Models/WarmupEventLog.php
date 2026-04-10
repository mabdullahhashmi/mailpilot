<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarmupEventLog extends Model
{
    protected $fillable = [
        'warmup_event_id', 'thread_id', 'warmup_campaign_id',
        'event_type', 'outcome', 'details', 'execution_time_ms',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(WarmupEvent::class, 'warmup_event_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
