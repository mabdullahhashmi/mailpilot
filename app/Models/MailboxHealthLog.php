<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxHealthLog extends Model
{
    protected $fillable = [
        'sender_mailbox_id', 'log_date', 'warmup_day',
        'sends_today', 'replies_today', 'bounces_today', 'opens_today', 'spam_reports_today',
        'active_threads', 'failed_events', 'auth_failures',
        'smtp_status', 'imap_status', 'anomaly_flags',
        'health_score', 'readiness_score',
    ];

    protected $casts = [
        'log_date' => 'date',
        'anomaly_flags' => 'array',
    ];

    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class);
    }
}
