<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flow_test_runs')) {
            Schema::create('flow_test_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes')->cascadeOnDelete();
                $table->unsignedTinyInteger('phase_count')->default(1);
                $table->unsignedSmallInteger('open_delay_seconds')->default(20);
                $table->unsignedSmallInteger('star_delay_seconds')->default(10);
                $table->unsignedSmallInteger('reply_delay_seconds')->default(20);
                $table->string('status', 20)->default('queued'); // queued|running|completed|failed
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('summary')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }

        if (!Schema::hasTable('flow_test_steps')) {
            Schema::create('flow_test_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('flow_test_run_id')->constrained('flow_test_runs')->cascadeOnDelete();
                $table->foreignId('seed_mailbox_id')->constrained('seed_mailboxes')->cascadeOnDelete();
                $table->unsignedSmallInteger('step_index');
                $table->string('action_type', 40);
                $table->timestamp('scheduled_at');
                $table->timestamp('executed_at')->nullable();
                $table->string('status', 20)->default('pending'); // pending|executing|completed|failed|skipped
                $table->string('subject')->nullable();
                $table->string('message_id')->nullable();
                $table->string('in_reply_to')->nullable();
                $table->text('notes')->nullable();
                $table->text('error_message')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['flow_test_run_id', 'seed_mailbox_id', 'step_index']);
                $table->index(['status', 'scheduled_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_test_steps');
        Schema::dropIfExists('flow_test_runs');
    }
};
