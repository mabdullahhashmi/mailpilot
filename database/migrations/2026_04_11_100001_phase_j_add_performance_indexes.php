<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bounce events: suppression queries
        Schema::table('bounce_events', function (Blueprint $table) {
            $table->index('is_suppressed');
        });

        // Reputation scores: compound index for history queries
        Schema::table('reputation_scores', function (Blueprint $table) {
            $table->index(['sender_mailbox_id', 'score_date'], 'rep_sender_date_idx');
        });

        // Sending strategy logs: filter by recommendation type
        Schema::table('sending_strategy_logs', function (Blueprint $table) {
            $table->index('recommendation');
            $table->index('was_applied');
        });

        // Placement tests: compound for recent completed tests per sender
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->index(['sender_mailbox_id', 'status', 'completed_at'], 'pt_sender_status_completed_idx');
        });

        // Warmup events: critical for scheduler queries
        Schema::table('warmup_events', function (Blueprint $table) {
            $table->index(['status', 'scheduled_at', 'priority'], 'we_status_scheduled_priority_idx');
        });

        // Thread messages: common lookup patterns
        Schema::table('thread_messages', function (Blueprint $table) {
            $table->index(['thread_id', 'message_step_number'], 'tm_thread_step_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bounce_events', function (Blueprint $table) {
            $table->dropIndex(['is_suppressed']);
        });

        Schema::table('reputation_scores', function (Blueprint $table) {
            $table->dropIndex('rep_sender_date_idx');
        });

        Schema::table('sending_strategy_logs', function (Blueprint $table) {
            $table->dropIndex(['recommendation']);
            $table->dropIndex(['was_applied']);
        });

        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropIndex('pt_sender_status_completed_idx');
        });

        Schema::table('warmup_events', function (Blueprint $table) {
            $table->dropIndex('we_status_scheduled_priority_idx');
        });

        Schema::table('thread_messages', function (Blueprint $table) {
            $table->dropIndex('tm_thread_step_idx');
        });
    }
};
