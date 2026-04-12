<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTestStep extends Model
{
    protected $fillable = [
        'flow_test_run_id',
        'seed_mailbox_id',
        'step_index',
        'action_type',
        'scheduled_at',
        'executed_at',
        'status',
        'subject',
        'message_id',
        'in_reply_to',
        'notes',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'executed_at' => 'datetime',
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FlowTestRun::class, 'flow_test_run_id');
    }

    public function seedMailbox(): BelongsTo
    {
        return $this->belongsTo(SeedMailbox::class);
    }
}
