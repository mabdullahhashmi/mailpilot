<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerRun extends Model
{
    protected $fillable = [
        'started_at', 'finished_at',
        'events_processed', 'events_succeeded', 'events_failed', 'events_skipped',
        'execution_time_ms', 'summary',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary' => 'array',
    ];
}
