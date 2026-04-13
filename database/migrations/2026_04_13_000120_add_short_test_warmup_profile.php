<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('warmup_profiles')->updateOrInsert(
            ['profile_name' => 'Short Test (6 Hour Days)'],
            [
                'description' => 'Testing profile: each warmup day lasts 60 minutes, total 6 warmup days.',
                'profile_type' => 'custom',
                'day_rules' => json_encode([
                    '1' => ['max_new_threads' => 2,  'max_replies' => 0, 'max_total' => 2],
                    '2' => ['max_new_threads' => 3,  'max_replies' => 2, 'max_total' => 5],
                    '3' => ['max_new_threads' => 5,  'max_replies' => 3, 'max_total' => 8],
                    '4' => ['max_new_threads' => 7,  'max_replies' => 4, 'max_total' => 11],
                    '5' => ['max_new_threads' => 8,  'max_replies' => 5, 'max_total' => 13],
                    '6' => ['max_new_threads' => 10, 'max_replies' => 6, 'max_total' => 16],
                ]),
                'default_max_new_threads_per_day' => 2,
                'default_max_reply_actions_per_day' => 2,
                'default_max_total_actions_per_day' => 4,
                'provider_distribution' => json_encode(['google' => 50, 'microsoft' => 30, 'other' => 20]),
                'thread_length_distribution' => json_encode(['3' => 35, '4' => 45, '5' => 20]),
                'reply_delay_distribution' => json_encode(['min_minutes' => 2, 'max_minutes' => 20, 'peak_minutes' => 6]),
                'working_hours_start' => '00:00:00',
                'working_hours_end' => '23:59:00',
                'anomaly_thresholds' => json_encode([
                    'max_spike_pct' => 50,
                    'min_interval_seconds' => 60,
                    'day_duration_minutes' => 60,
                    'is_test_profile' => true,
                ]),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('warmup_profiles')
            ->where('profile_name', 'Short Test (6 Hour Days)')
            ->delete();
    }
};
