<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SendingStrategyLog extends Model
{
    protected $fillable = [
        'sender_mailbox_id', 'warmup_campaign_id',
        'recommendation', 'current_daily_cap', 'recommended_daily_cap',
        'reasoning', 'metrics_snapshot', 'was_applied',
    ];

    protected $casts = [
        'metrics_snapshot' => 'array',
        'was_applied' => 'boolean',
    ];

    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class);
    }

    public function warmupCampaign(): BelongsTo
    {
        return $this->belongsTo(WarmupCampaign::class);
    }
}
