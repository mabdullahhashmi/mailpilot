<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReputationScore extends Model
{
    protected $fillable = [
        'domain_id', 'sender_mailbox_id', 'score_date',
        'overall_score', 'dns_score', 'engagement_score', 'bounce_score',
        'placement_score', 'volume_score', 'risk_level', 'breakdown',
    ];

    protected $casts = [
        'score_date' => 'date',
        'breakdown' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class);
    }
}
