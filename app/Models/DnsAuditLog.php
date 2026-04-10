<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsAuditLog extends Model
{
    protected $fillable = [
        'domain_id', 'record_type', 'previous_status', 'new_status',
        'record_value', 'details',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
