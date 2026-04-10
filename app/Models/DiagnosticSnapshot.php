<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiagnosticSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date', 'total_senders', 'active_senders', 'paused_senders',
        'total_seeds', 'active_seeds', 'disabled_seeds',
        'events_planned', 'events_completed', 'events_failed', 'events_stuck',
        'avg_queue_lag_seconds', 'smtp_failures', 'imap_failures',
        'avg_health_score', 'avg_bounce_rate',
        'cron_statuses', 'alerts_summary', 'overall_status',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'cron_statuses' => 'array',
        'alerts_summary' => 'array',
    ];
}
