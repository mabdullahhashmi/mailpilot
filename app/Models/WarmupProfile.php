<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarmupProfile extends Model
{
    protected $fillable = [
        'profile_name', 'description', 'profile_type',
        'day_rules', 'default_max_new_threads_per_day',
        'default_max_reply_actions_per_day', 'default_max_total_actions_per_day',
        'provider_distribution', 'thread_length_distribution',
        'reply_delay_distribution', 'working_hours_start', 'working_hours_end',
        'anomaly_thresholds',
    ];

    protected $casts = [
        'day_rules' => 'array',
        'provider_distribution' => 'array',
        'thread_length_distribution' => 'array',
        'reply_delay_distribution' => 'array',
        'anomaly_thresholds' => 'array',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(WarmupCampaign::class);
    }

    public function getRulesForDay(int $day): array
    {
        $dayRules = $this->day_rules ?? [];

        if (isset($dayRules[$day])) {
            return $dayRules[$day];
        }

        return [
            'max_new_threads' => $this->default_max_new_threads_per_day,
            'max_replies' => $this->default_max_reply_actions_per_day,
            'max_total' => $this->default_max_total_actions_per_day,
        ];
    }
}
