<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\WarmupProfile;
use App\Models\ContentTemplate;
use App\Models\SystemSetting;

class WarmupSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedProfiles();
        $this->seedContentTemplates();
        $this->seedSystemSettings();
    }

    private function seedProfiles(): void
    {
        // Default profile: 14-day moderate warmup
        WarmupProfile::updateOrCreate(['profile_name' => 'Default (14 Day)'], [
            'description' => 'Standard 14-day warmup with gradual ramp-up',
            'profile_type' => 'default',
            'day_rules' => [
                '1'  => ['max_new_threads' => 2, 'max_replies' => 0, 'max_total' => 2],
                '2'  => ['max_new_threads' => 3, 'max_replies' => 1, 'max_total' => 4],
                '3'  => ['max_new_threads' => 4, 'max_replies' => 2, 'max_total' => 6],
                '4'  => ['max_new_threads' => 5, 'max_replies' => 3, 'max_total' => 8],
                '5'  => ['max_new_threads' => 6, 'max_replies' => 4, 'max_total' => 10],
                '6'  => ['max_new_threads' => 7, 'max_replies' => 5, 'max_total' => 12],
                '7'  => ['max_new_threads' => 8, 'max_replies' => 6, 'max_total' => 14],
                '8'  => ['max_new_threads' => 10, 'max_replies' => 8, 'max_total' => 18],
                '9'  => ['max_new_threads' => 12, 'max_replies' => 10, 'max_total' => 22],
                '10' => ['max_new_threads' => 14, 'max_replies' => 12, 'max_total' => 26],
                '11' => ['max_new_threads' => 16, 'max_replies' => 14, 'max_total' => 30],
                '12' => ['max_new_threads' => 18, 'max_replies' => 16, 'max_total' => 34],
                '13' => ['max_new_threads' => 20, 'max_replies' => 18, 'max_total' => 38],
                '14' => ['max_new_threads' => 22, 'max_replies' => 20, 'max_total' => 42],
            ],
            'default_max_new_threads_per_day' => 2,
            'default_max_reply_actions_per_day' => 3,
            'default_max_total_actions_per_day' => 5,
            'thread_length_distribution' => ['2' => 40, '3' => 30, '4' => 20, '5' => 10],
            'reply_delay_distribution' => ['min_minutes' => 15, 'max_minutes' => 180, 'peak_minutes' => 60],
            'provider_distribution' => ['google' => 50, 'microsoft' => 30, 'other' => 20],
            'working_hours_start' => '08:00:00',
            'working_hours_end' => '18:00:00',
        ]);

        // Aggressive profile: 10-day fast warmup
        WarmupProfile::updateOrCreate(['profile_name' => 'Aggressive (10 Day)'], [
            'description' => 'Faster 10-day warmup with steeper ramp-up. Use with caution.',
            'profile_type' => 'aggressive',
            'day_rules' => [
                '1'  => ['max_new_threads' => 3, 'max_replies' => 1, 'max_total' => 4],
                '2'  => ['max_new_threads' => 5, 'max_replies' => 3, 'max_total' => 8],
                '3'  => ['max_new_threads' => 8, 'max_replies' => 5, 'max_total' => 13],
                '4'  => ['max_new_threads' => 12, 'max_replies' => 8, 'max_total' => 20],
                '5'  => ['max_new_threads' => 16, 'max_replies' => 12, 'max_total' => 28],
                '6'  => ['max_new_threads' => 20, 'max_replies' => 16, 'max_total' => 36],
                '7'  => ['max_new_threads' => 24, 'max_replies' => 20, 'max_total' => 44],
                '8'  => ['max_new_threads' => 28, 'max_replies' => 24, 'max_total' => 52],
                '9'  => ['max_new_threads' => 30, 'max_replies' => 26, 'max_total' => 56],
                '10' => ['max_new_threads' => 32, 'max_replies' => 28, 'max_total' => 60],
            ],
            'default_max_new_threads_per_day' => 3,
            'default_max_reply_actions_per_day' => 3,
            'default_max_total_actions_per_day' => 8,
            'thread_length_distribution' => ['2' => 50, '3' => 30, '4' => 15, '5' => 5],
            'reply_delay_distribution' => ['min_minutes' => 10, 'max_minutes' => 120, 'peak_minutes' => 40],
            'provider_distribution' => ['google' => 50, 'microsoft' => 30, 'other' => 20],
            'working_hours_start' => '08:00:00',
            'working_hours_end' => '18:00:00',
        ]);

        // Short test profile: 6 warmup days where each day can be treated as 60 minutes
        WarmupProfile::updateOrCreate(['profile_name' => 'Short Test (6 Hour Days)'], [
            'description' => 'Testing profile: each warmup day lasts 60 minutes, total 6 warmup days.',
            'profile_type' => 'custom',
            'day_rules' => [
                '1' => ['max_new_threads' => 2,  'max_replies' => 0, 'max_total' => 2],
                '2' => ['max_new_threads' => 3,  'max_replies' => 2, 'max_total' => 5],
                '3' => ['max_new_threads' => 5,  'max_replies' => 3, 'max_total' => 8],
                '4' => ['max_new_threads' => 7,  'max_replies' => 4, 'max_total' => 11],
                '5' => ['max_new_threads' => 8,  'max_replies' => 5, 'max_total' => 13],
                '6' => ['max_new_threads' => 10, 'max_replies' => 6, 'max_total' => 16],
            ],
            'default_max_new_threads_per_day' => 2,
            'default_max_reply_actions_per_day' => 2,
            'default_max_total_actions_per_day' => 4,
            'thread_length_distribution' => ['3' => 35, '4' => 45, '5' => 20],
            'reply_delay_distribution' => ['min_minutes' => 2, 'max_minutes' => 20, 'peak_minutes' => 6],
            'provider_distribution' => ['google' => 50, 'microsoft' => 30, 'other' => 20],
            'working_hours_start' => '00:00:00',
            'working_hours_end' => '23:59:00',
            'anomaly_thresholds' => [
                'max_spike_pct' => 50,
                'min_interval_seconds' => 60,
                'day_duration_minutes' => 60,
                'is_test_profile' => true,
            ],
        ]);

        // Conservative profile: 20-day slow warmup
        WarmupProfile::updateOrCreate(['profile_name' => 'Conservative (20 Day)'], [
            'description' => 'Slow and steady 20-day warmup for sensitive domains.',
            'profile_type' => 'conservative',
            'day_rules' => [
                '1'  => ['max_new_threads' => 1, 'max_replies' => 0, 'max_total' => 1],
                '2'  => ['max_new_threads' => 1, 'max_replies' => 1, 'max_total' => 2],
                '3'  => ['max_new_threads' => 2, 'max_replies' => 1, 'max_total' => 3],
                '4'  => ['max_new_threads' => 2, 'max_replies' => 2, 'max_total' => 4],
                '5'  => ['max_new_threads' => 3, 'max_replies' => 2, 'max_total' => 5],
                '6'  => ['max_new_threads' => 3, 'max_replies' => 3, 'max_total' => 6],
                '7'  => ['max_new_threads' => 4, 'max_replies' => 3, 'max_total' => 7],
                '8'  => ['max_new_threads' => 5, 'max_replies' => 4, 'max_total' => 9],
                '9'  => ['max_new_threads' => 6, 'max_replies' => 5, 'max_total' => 11],
                '10' => ['max_new_threads' => 7, 'max_replies' => 6, 'max_total' => 13],
                '11' => ['max_new_threads' => 8, 'max_replies' => 7, 'max_total' => 15],
                '12' => ['max_new_threads' => 9, 'max_replies' => 8, 'max_total' => 17],
                '13' => ['max_new_threads' => 10, 'max_replies' => 9, 'max_total' => 19],
                '14' => ['max_new_threads' => 11, 'max_replies' => 10, 'max_total' => 21],
                '15' => ['max_new_threads' => 12, 'max_replies' => 11, 'max_total' => 23],
                '16' => ['max_new_threads' => 13, 'max_replies' => 12, 'max_total' => 25],
                '17' => ['max_new_threads' => 14, 'max_replies' => 13, 'max_total' => 27],
                '18' => ['max_new_threads' => 15, 'max_replies' => 14, 'max_total' => 29],
                '19' => ['max_new_threads' => 16, 'max_replies' => 15, 'max_total' => 31],
                '20' => ['max_new_threads' => 17, 'max_replies' => 16, 'max_total' => 33],
            ],
            'default_max_new_threads_per_day' => 1,
            'default_max_reply_actions_per_day' => 1,
            'default_max_total_actions_per_day' => 3,
            'thread_length_distribution' => ['2' => 30, '3' => 35, '4' => 25, '5' => 10],
            'reply_delay_distribution' => ['min_minutes' => 30, 'max_minutes' => 300, 'peak_minutes' => 90],
            'provider_distribution' => ['google' => 50, 'microsoft' => 30, 'other' => 20],
            'working_hours_start' => '08:00:00',
            'working_hours_end' => '18:00:00',
        ]);

        // Maintenance profile
        WarmupProfile::updateOrCreate(['profile_name' => 'Maintenance'], [
            'description' => 'Low-volume maintenance mode to keep reputation alive.',
            'profile_type' => 'maintenance',
            'day_rules' => null,
            'default_max_new_threads_per_day' => 3,
            'default_max_reply_actions_per_day' => 3,
            'default_max_total_actions_per_day' => 6,
            'thread_length_distribution' => ['2' => 50, '3' => 30, '4' => 20],
            'reply_delay_distribution' => ['min_minutes' => 30, 'max_minutes' => 240, 'peak_minutes' => 90],
            'provider_distribution' => ['google' => 50, 'microsoft' => 30, 'other' => 20],
            'working_hours_start' => '08:00:00',
            'working_hours_end' => '18:00:00',
        ]);
    }

    private function seedContentTemplates(): void
    {
        $templates = [
            // Initial templates
            [
                'name' => 'Quick Check-in',
                'template_type' => 'initial',
                'warmup_stage' => 'any',
                'subject' => '{{var:subject_prefix}} - Quick check-in',
                'body' => '<p>{{greeting}} {{recipient_name}},</p><p>{{var:opening}} I wanted to touch base and see how things are going on your end.</p><p>{{var:closing}}</p><p>{{signoff}},<br>{{sender_name}}</p>',
                'greetings' => ['Hi', 'Hello', 'Hey'],
                'signoffs' => ['Best', 'Thanks', 'Regards', 'Cheers'],
                'variations' => [
                    'subject_prefix' => ['Quick note', 'Brief update', 'Touching base'],
                    'opening' => ['Hope you\'re well!', 'Hope your week is going well.', 'Just a quick note.'],
                    'closing' => ['Let me know if you need anything.', 'Would love to catch up when you have a moment.', 'Looking forward to hearing from you.'],
                ],
                'cooldown_minutes' => 60,
            ],
            [
                'name' => 'Meeting Follow-up',
                'template_type' => 'initial',
                'warmup_stage' => 'any',
                'subject' => '{{var:subject_prefix}}',
                'body' => '<p>{{greeting}} {{recipient_name}},</p><p>{{var:body_main}}</p><p>{{var:cta}}</p><p>{{signoff}},<br>{{sender_name}}</p>',
                'greetings' => ['Hi', 'Hello'],
                'signoffs' => ['Best', 'Thanks', 'Talk soon'],
                'variations' => [
                    'subject_prefix' => ['Following up on our discussion', 'Re: Our conversation', 'Quick follow-up'],
                    'body_main' => [
                        'I wanted to follow up on what we discussed earlier. I think there are some good next steps we can take.',
                        'Thanks for taking the time to chat. I\'ve been thinking about what we discussed and have a few ideas.',
                        'Great speaking with you. I wanted to send a quick note while it\'s fresh in my mind.',
                    ],
                    'cta' => [
                        'Can we schedule a quick call this week?',
                        'Let me know your thoughts when you get a chance.',
                        'Would love to continue the conversation.',
                    ],
                ],
                'cooldown_minutes' => 90,
            ],
            [
                'name' => 'Resource Share',
                'template_type' => 'initial',
                'warmup_stage' => 'any',
                'subject' => '{{var:subject_prefix}}',
                'body' => '<p>{{greeting}} {{recipient_name}},</p><p>{{var:intro}} {{var:resource}}</p><p>{{var:closing}}</p><p>{{signoff}},<br>{{sender_name}}</p>',
                'greetings' => ['Hi', 'Hey'],
                'signoffs' => ['Best', 'Cheers', 'Take care'],
                'variations' => [
                    'subject_prefix' => ['Thought this was relevant', 'Interesting resource', 'Something you might find useful'],
                    'intro' => [
                        'I came across something that made me think of our conversation.',
                        'Wanted to share something I found interesting.',
                        'I stumbled upon this and thought of you.',
                    ],
                    'resource' => [
                        'It\'s a great overview of recent industry trends.',
                        'Covers some interesting perspectives on the topic.',
                        'Has some actionable insights that might be useful.',
                    ],
                    'closing' => [
                        'Let me know what you think!',
                        'Would be curious to hear your take.',
                        'Hope you find it useful.',
                    ],
                ],
                'cooldown_minutes' => 120,
            ],

            // Reply templates
            [
                'name' => 'Positive Reply',
                'template_type' => 'reply',
                'warmup_stage' => 'any',
                'subject' => null,
                'body' => '<p>{{var:reply}}</p><p>{{signoff}},<br>{{sender_name}}</p>',
                'greetings' => [],
                'signoffs' => ['Best', 'Thanks', 'Cheers'],
                'variations' => [
                    'reply' => [
                        'Thanks for the update! That all sounds good to me.',
                        'Appreciate you sharing that. I\'ll take a closer look and get back to you.',
                        'Great, thanks for getting back to me. Let me review this.',
                        'That makes sense. I think we\'re on the right track.',
                        'Thanks! I\'ll loop back around on this shortly.',
                    ],
                ],
                'cooldown_minutes' => 30,
            ],
            [
                'name' => 'Question Reply',
                'template_type' => 'reply',
                'warmup_stage' => 'any',
                'subject' => null,
                'body' => '<p>{{var:reply}}</p><p>{{signoff}},<br>{{sender_name}}</p>',
                'greetings' => [],
                'signoffs' => ['Best', 'Thanks'],
                'variations' => [
                    'reply' => [
                        'Thanks! Quick question - what\'s the timeline on this?',
                        'Good to know. Can you clarify the next steps from your end?',
                        'Makes sense. When would be a good time to sync up on this?',
                        'Appreciate it. Is there anything you need from me in the meantime?',
                    ],
                ],
                'cooldown_minutes' => 30,
            ],

            // Closing templates
            [
                'name' => 'Thread Closer',
                'template_type' => 'closing',
                'warmup_stage' => 'any',
                'subject' => null,
                'body' => '<p>{{var:closing}}</p><p>{{signoff}},<br>{{sender_name}}</p>',
                'greetings' => [],
                'signoffs' => ['Best', 'Thanks', 'Take care', 'Talk soon'],
                'variations' => [
                    'closing' => [
                        'Sounds good, thanks for the quick exchange! Talk soon.',
                        'Perfect, I think we\'re all set for now. Thanks!',
                        'Great, appreciate the help. Let\'s reconnect next week.',
                        'Thanks for the update. I\'ll circle back if anything comes up.',
                        'All good on my end. Thanks for getting back to me so quickly!',
                    ],
                ],
                'cooldown_minutes' => 20,
            ],
        ];

        foreach ($templates as $data) {
            $fingerprint = hash('sha256', ($data['template_type'] ?? '') . '|' . ($data['subject'] ?? '') . '|' . ($data['body'] ?? ''));
            ContentTemplate::updateOrCreate(
                ['content_fingerprint' => $fingerprint],
                [
                    'template_type' => $data['template_type'],
                    'category' => $data['category'] ?? 'general',
                    'subject' => $data['subject'],
                    'body' => $data['body'],
                    'greetings' => $data['greetings'] ?? null,
                    'signoffs' => $data['signoffs'] ?? null,
                    'variations' => $data['variations'] ?? null,
                    'placeholders' => $data['placeholders'] ?? null,
                    'warmup_stage' => $data['warmup_stage'] ?? 'any',
                    'cooldown_minutes' => $data['cooldown_minutes'] ?? 60,
                    'content_fingerprint' => $fingerprint,
                    'is_active' => true,
                    'usage_count' => 0,
                ]
            );
        }
    }

    private function seedSystemSettings(): void
    {
        $defaults = [
            'scheduler_batch_size' => '20',
            'scheduler_interval_minutes' => '2',
            'max_sender_failures_before_pause' => '3',
            'pair_cooldown_days' => '2',
            'max_growth_rate_percent' => '30',
            'stale_lock_timeout_minutes' => '5',
            'daily_planner_hour' => '06',
            'health_update_hour' => '23',
            'dns_check_day' => '1',
        ];

        foreach ($defaults as $key => $value) {
            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'description' => ucfirst(str_replace('_', ' ', $key))]
            );
        }
    }
}
