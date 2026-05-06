<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentTemplate extends Model
{
    protected $fillable = [
        'template_type', 'category', 'subject', 'body',
        'greetings', 'signoffs', 'variations', 'placeholders',
        'warmup_stage', 'is_active', 'usage_count', 'last_used_at',
        'cooldown_minutes', 'content_fingerprint',
    ];

    protected $casts = [
        'greetings' => 'array',
        'signoffs' => 'array',
        'variations' => 'array',
        'placeholders' => 'array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function isOnCooldown(): bool
    {
        if (!$this->last_used_at) return false;
        return $this->last_used_at->addMinutes($this->cooldown_minutes)->isFuture();
    }
}
