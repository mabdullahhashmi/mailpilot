<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacementResult extends Model
{
    protected $fillable = [
        'placement_test_id', 'seed_mailbox_id',
        'result', 'provider', 'delivery_time_seconds', 'headers_snippet',
    ];

    public function placementTest(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class);
    }

    public function seedMailbox(): BelongsTo
    {
        return $this->belongsTo(SeedMailbox::class);
    }
}
