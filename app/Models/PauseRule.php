<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PauseRule extends Model
{
    protected $fillable = [
        'pausable_type', 'pausable_id', 'reason', 'details',
        'paused_at', 'auto_resume_at', 'resumed_at', 'status',
    ];

    protected $casts = [
        'paused_at' => 'datetime',
        'auto_resume_at' => 'datetime',
        'resumed_at' => 'datetime',
    ];

    public function pausable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
