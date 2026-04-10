<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Placement Tests (test runs) ──
        Schema::create('placement_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes')->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->unsignedSmallInteger('seeds_tested')->default(0);
            $table->unsignedSmallInteger('inbox_count')->default(0);
            $table->unsignedSmallInteger('spam_count')->default(0);
            $table->unsignedSmallInteger('missing_count')->default(0);
            $table->decimal('placement_score', 5, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['sender_mailbox_id', 'created_at']);
            $table->index('status');
        });

        // ── Placement Results (per-seed result per test) ──
        Schema::create('placement_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('placement_test_id')->constrained('placement_tests')->cascadeOnDelete();
            $table->foreignId('seed_mailbox_id')->constrained('seed_mailboxes')->cascadeOnDelete();
            $table->enum('result', ['inbox', 'spam', 'missing', 'error'])->default('missing');
            $table->string('provider')->nullable(); // gmail, outlook, yahoo, etc.
            $table->unsignedSmallInteger('delivery_time_seconds')->nullable();
            $table->text('headers_snippet')->nullable();
            $table->timestamps();

            $table->index(['placement_test_id', 'result']);
        });

        // ── Bounce Events (classified bounces) ──
        Schema::create('bounce_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes')->cascadeOnDelete();
            $table->foreignId('warmup_event_id')->nullable()->constrained('warmup_events')->nullOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->string('recipient_email');
            $table->enum('bounce_type', ['hard', 'soft', 'transient', 'policy', 'unknown'])->default('unknown');
            $table->string('bounce_code')->nullable();
            $table->text('bounce_message')->nullable();
            $table->string('provider')->nullable();
            $table->boolean('is_suppressed')->default(false);
            $table->timestamp('bounced_at')->nullable();
            $table->timestamps();

            $table->index(['sender_mailbox_id', 'bounced_at']);
            $table->index(['bounce_type', 'bounced_at']);
            $table->index('recipient_email');
        });

        // ── DNS Audit Logs (track DNS changes over time) ──
        Schema::create('dns_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->enum('record_type', ['spf', 'dkim', 'dmarc', 'mx']);
            $table->enum('previous_status', ['unknown', 'pass', 'fail', 'none'])->nullable();
            $table->enum('new_status', ['unknown', 'pass', 'fail', 'none']);
            $table->text('record_value')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'created_at']);
            $table->index('record_type');
        });

        // ── Reputation Scores (daily domain/sender reputation) ──
        Schema::create('reputation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $table->foreignId('sender_mailbox_id')->nullable()->constrained('sender_mailboxes')->cascadeOnDelete();
            $table->date('score_date');
            $table->unsignedTinyInteger('overall_score')->default(50);
            $table->unsignedTinyInteger('dns_score')->default(0);
            $table->unsignedTinyInteger('engagement_score')->default(0);
            $table->unsignedTinyInteger('bounce_score')->default(0);
            $table->unsignedTinyInteger('placement_score')->default(0);
            $table->unsignedTinyInteger('volume_score')->default(0);
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->json('breakdown')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'sender_mailbox_id', 'score_date'], 'rep_score_unique');
            $table->index(['score_date', 'risk_level']);
        });

        // ── Sending Strategy Logs (optimizer recommendations) ──
        Schema::create('sending_strategy_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes')->cascadeOnDelete();
            $table->foreignId('warmup_campaign_id')->nullable()->constrained('warmup_campaigns')->nullOnDelete();
            $table->enum('recommendation', ['maintain', 'ramp_up', 'slow_down', 'pause', 'resume'])->default('maintain');
            $table->unsignedSmallInteger('current_daily_cap')->default(0);
            $table->unsignedSmallInteger('recommended_daily_cap')->default(0);
            $table->text('reasoning')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->boolean('was_applied')->default(false);
            $table->timestamps();

            $table->index(['sender_mailbox_id', 'created_at']);
        });

        // ── Add columns to sender_mailboxes ──
        Schema::table('sender_mailboxes', function (Blueprint $table) {
            $table->decimal('placement_score', 5, 2)->nullable()->after('readiness_score');
            $table->unsignedTinyInteger('reputation_score')->default(50)->after('placement_score');
            $table->enum('reputation_risk', ['low', 'medium', 'high', 'critical'])->default('low')->after('reputation_score');
            $table->timestamp('last_placement_test_at')->nullable()->after('reputation_risk');
            $table->timestamp('last_reputation_scan_at')->nullable()->after('last_placement_test_at');
        });

        // ── Add columns to domains ──
        Schema::table('domains', function (Blueprint $table) {
            $table->enum('reputation_risk_level', ['low', 'medium', 'high', 'critical'])->default('low')->after('readiness_score');
            $table->unsignedTinyInteger('reputation_score')->default(50)->after('reputation_risk_level');
            $table->timestamp('last_reputation_scan_at')->nullable()->after('reputation_score');
            $table->unsignedSmallInteger('total_bounces_7d')->default(0)->after('last_reputation_scan_at');
            $table->unsignedSmallInteger('total_sends_7d')->default(0)->after('total_bounces_7d');
        });

        // ── Add columns to mailbox_health_logs ──
        Schema::table('mailbox_health_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('hard_bounces')->default(0)->after('bounces_today');
            $table->unsignedSmallInteger('soft_bounces')->default(0)->after('hard_bounces');
            $table->unsignedSmallInteger('placement_inbox')->default(0)->after('soft_bounces');
            $table->unsignedSmallInteger('placement_spam')->default(0)->after('placement_inbox');
            $table->unsignedSmallInteger('placement_missing')->default(0)->after('placement_spam');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_health_logs', function (Blueprint $table) {
            $table->dropColumn(['hard_bounces', 'soft_bounces', 'placement_inbox', 'placement_spam', 'placement_missing']);
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['reputation_risk_level', 'reputation_score', 'last_reputation_scan_at', 'total_bounces_7d', 'total_sends_7d']);
        });

        Schema::table('sender_mailboxes', function (Blueprint $table) {
            $table->dropColumn(['placement_score', 'reputation_score', 'reputation_risk', 'last_placement_test_at', 'last_reputation_scan_at']);
        });

        Schema::dropIfExists('sending_strategy_logs');
        Schema::dropIfExists('reputation_scores');
        Schema::dropIfExists('dns_audit_logs');
        Schema::dropIfExists('bounce_events');
        Schema::dropIfExists('placement_results');
        Schema::dropIfExists('placement_tests');
    }
};
