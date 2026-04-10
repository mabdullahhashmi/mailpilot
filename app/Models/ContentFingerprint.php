<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentFingerprint extends Model
{
    protected $fillable = [
        'sender_mailbox_id', 'content_template_id',
        'fingerprint_hash', 'recipient_email', 'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(SenderMailbox::class, 'sender_mailbox_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContentTemplate::class, 'content_template_id');
    }
}
