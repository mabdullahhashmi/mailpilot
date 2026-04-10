<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlacementTest extends Model
{
    protected $fillable = [
        'sender_mailbox_id', 'domain_id', 'status',
        'seeds_tested', 'inbox_count', 'spam_count', 'missing_count',
        'placement_score', 'started_at', 'completed_at', 'failure_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'placement_score' => 'decimal:2',
    ];

    public function senderMailbox(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(PlacementResult::class);
    }
}
