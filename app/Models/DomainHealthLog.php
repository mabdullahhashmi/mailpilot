<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainHealthLog extends Model
{
    protected $fillable = [
        'domain_id', 'log_date', 'active_sender_count',
        'daily_action_count', 'dns_health', 'readiness_score',
    ];

    protected $casts = [
        'log_date' => 'date',
        'dns_health' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
