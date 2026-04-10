<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemAlert extends Model
{
    protected $fillable = [
        'severity', 'title', 'message',
        'context_type', 'context_id',
        'is_read', 'is_dismissed',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'is_dismissed' => 'boolean',
    ];

    public static function fire(string $severity, string $title, string $message, ?string $contextType = null, ?int $contextId = null): static
    {
        return static::create(compact('severity', 'title', 'message', 'context_type', 'context_id'));
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false)->where('is_dismissed', false);
    }
}
