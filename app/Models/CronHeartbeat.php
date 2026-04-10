<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CronHeartbeat extends Model
{
    protected $fillable = [
        'task_name', 'last_run_at', 'last_success_at',
        'expected_interval_minutes', 'status',
        'consecutive_failures', 'last_error', 'run_history',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
        'last_success_at' => 'datetime',
        'run_history' => 'array',
    ];

    public function isLate(): bool
    {
        if (!$this->last_run_at) return true;
        $gracePeriod = $this->expected_interval_minutes * 2;
        return $this->last_run_at->diffInMinutes(now()) > $gracePeriod;
    }

    public function isMissed(): bool
    {
        if (!$this->last_run_at) return true;
        $missThreshold = $this->expected_interval_minutes * 5;
        return $this->last_run_at->diffInMinutes(now()) > $missThreshold;
    }
}
