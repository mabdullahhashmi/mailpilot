<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Sender Mailboxes: deliverability safety rails ──
        Schema::table('sender_mailboxes', function (Blueprint $table) {
            $table->decimal('bounce_rate_threshold', 5, 2)->default(5.00)->after('readiness_score');
            $table->decimal('spam_rate_threshold', 5, 2)->default(2.00)->after('bounce_rate_threshold');
            $table->boolean('auto_pause_on_threshold')->default(true)->after('spam_rate_threshold');
            $table->boolean('ramp_down_active')->default(false)->after('auto_pause_on_threshold');
            $table->unsignedSmallInteger('consecutive_clean_days')->default(0)->after('ramp_down_active');
            $table->unsignedSmallInteger('ramp_down_percentage')->default(50)->after('consecutive_clean_days');
            $table->timestamp('auto_paused_at')->nullable()->after('ramp_down_percentage');
            $table->string('auto_pause_reason', 100)->nullable()->after('auto_paused_at');
        });

        // ── Seed Mailboxes: seed quality controls ──
        Schema::table('seed_mailboxes', function (Blueprint $table) {
            $table->unsignedTinyInteger('seed_health_score')->default(100)->after('health_score');
            $table->unsignedTinyInteger('reply_quality_score')->default(80)->after('seed_health_score');
            $table->unsignedSmallInteger('total_replies_sent')->default(0)->after('reply_quality_score');
            $table->unsignedSmallInteger('total_opens')->default(0)->after('total_replies_sent');
            $table->unsignedSmallInteger('failed_interactions')->default(0)->after('total_opens');
            $table->timestamp('last_health_check_at')->nullable()->after('failed_interactions');
            $table->timestamp('auto_disabled_at')->nullable()->after('last_health_check_at');
            $table->string('auto_disable_reason', 255)->nullable()->after('auto_disabled_at');
        });

        // ── Mailbox Health Logs: rename + add missing tracking columns ──
        Schema::table('mailbox_health_logs', function (Blueprint $table) {
            $table->renameColumn('sent_today', 'sends_today');
            $table->renameColumn('replied_today', 'replies_today');
        });
        Schema::table('mailbox_health_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('bounces_today')->default(0)->after('replies_today');
            $table->unsignedSmallInteger('opens_today')->default(0)->after('bounces_today');
            $table->unsignedSmallInteger('spam_reports_today')->default(0)->after('opens_today');
        });

        // ── Send Slots: visible slot scheduling ──
        Schema::create('send_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warmup_campaign_id')->constrained('warmup_campaigns')->cascadeOnDelete();
            $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes')->cascadeOnDelete();
            $table->foreignId('seed_mailbox_id')->nullable()->constrained('seed_mailboxes')->nullOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->foreignId('warmup_event_id')->nullable()->constrained('warmup_events')->nullOnDelete();
            $table->enum('slot_type', ['initial_send', 'reply', 'auxiliary'])->default('initial_send');
            $table->dateTime('planned_at');
            $table->dateTime('executed_at')->nullable();
            $table->enum('status', ['planned', 'executing', 'completed', 'skipped', 'failed'])->default('planned');
            $table->string('skip_reason', 255)->nullable();
            $table->date('slot_date');
            $table->timestamps();

            $table->index(['warmup_campaign_id', 'slot_date', 'status']);
            $table->index(['sender_mailbox_id', 'slot_date']);
        });

        // ── Cron Heartbeats: operational resilience ──
        Schema::create('cron_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('task_name', 80)->unique();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedSmallInteger('expected_interval_minutes')->default(60);
            $table->enum('status', ['healthy', 'late', 'missed', 'failed'])->default('healthy');
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->json('run_history')->nullable();
            $table->timestamps();
        });

        // ── Content Fingerprints: anti-pattern protection ──
        Schema::create('content_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes')->cascadeOnDelete();
            $table->foreignId('content_template_id')->nullable()->constrained('content_templates')->nullOnDelete();
            $table->string('fingerprint_hash', 64);
            $table->string('recipient_email', 255);
            $table->timestamp('used_at');
            $table->timestamps();

            $table->index(['sender_mailbox_id', 'fingerprint_hash']);
            $table->index(['sender_mailbox_id', 'used_at']);
            $table->index('recipient_email');
        });

        // ── Diagnostic Snapshots: daily self-check reports ──
        Schema::create('diagnostic_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->unsignedSmallInteger('total_senders')->default(0);
            $table->unsignedSmallInteger('active_senders')->default(0);
            $table->unsignedSmallInteger('paused_senders')->default(0);
            $table->unsignedSmallInteger('total_seeds')->default(0);
            $table->unsignedSmallInteger('active_seeds')->default(0);
            $table->unsignedSmallInteger('disabled_seeds')->default(0);
            $table->unsignedSmallInteger('events_planned')->default(0);
            $table->unsignedSmallInteger('events_completed')->default(0);
            $table->unsignedSmallInteger('events_failed')->default(0);
            $table->unsignedSmallInteger('events_stuck')->default(0);
            $table->unsignedSmallInteger('avg_queue_lag_seconds')->default(0);
            $table->unsignedSmallInteger('smtp_failures')->default(0);
            $table->unsignedSmallInteger('imap_failures')->default(0);
            $table->decimal('avg_health_score', 5, 2)->default(0);
            $table->decimal('avg_bounce_rate', 5, 2)->default(0);
            $table->json('cron_statuses')->nullable();
            $table->json('alerts_summary')->nullable();
            $table->enum('overall_status', ['healthy', 'degraded', 'critical'])->default('healthy');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_snapshots');
        Schema::dropIfExists('content_fingerprints');
        Schema::dropIfExists('cron_heartbeats');
        Schema::dropIfExists('send_slots');

        Schema::table('mailbox_health_logs', function (Blueprint $table) {
            $table->dropColumn(['bounces_today', 'opens_today', 'spam_reports_today']);
        });
        Schema::table('mailbox_health_logs', function (Blueprint $table) {
            $table->renameColumn('sends_today', 'sent_today');
            $table->renameColumn('replies_today', 'replied_today');
        });

        Schema::table('seed_mailboxes', function (Blueprint $table) {
            $table->dropColumn([
                'seed_health_score', 'reply_quality_score', 'total_replies_sent',
                'total_opens', 'failed_interactions', 'last_health_check_at',
                'auto_disabled_at', 'auto_disable_reason',
            ]);
        });

        Schema::table('sender_mailboxes', function (Blueprint $table) {
            $table->dropColumn([
                'bounce_rate_threshold', 'spam_rate_threshold', 'auto_pause_on_threshold',
                'ramp_down_active', 'consecutive_clean_days', 'ramp_down_percentage',
                'auto_paused_at', 'auto_pause_reason',
            ]);
        });
    }
};
