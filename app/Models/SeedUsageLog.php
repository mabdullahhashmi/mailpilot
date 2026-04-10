<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeedUsageLog extends Model
{
    protected $fillable = [
        'seed_mailbox_id', 'log_date', 'interactions_today',
        'per_domain_usage', 'per_sender_usage',
        'health_score', 'overload_flag', 'is_paused',
    ];

    protected $casts = [
        'log_date' => 'date',
        'per_domain_usage' => 'array',
        'per_sender_usage' => 'array',
        'overload_flag' => 'boolean',
        'is_paused' => 'boolean',
    ];

    public function seedMailbox(): BelongsTo
    {
        return $this->belongsTo(SeedMailbox::class);
    }
}
