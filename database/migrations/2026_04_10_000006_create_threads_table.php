<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warmup_campaign_id')->constrained('warmup_campaigns')->cascadeOnDelete();
            $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes');
            $table->foreignId('seed_mailbox_id')->constrained('seed_mailboxes');
            $table->foreignId('domain_id')->constrained('domains');
            $table->enum('initiator_type', ['sender', 'seed'])->default('sender');
            $table->enum('thread_status', ['planned', 'active', 'awaiting_reply', 'closing', 'closed', 'failed'])->default('planned');
            $table->unsignedTinyInteger('planned_message_count')->default(2);
            $table->unsignedTinyInteger('actual_message_count')->default(0);
            $table->unsignedTinyInteger('current_step_number')->default(0);
            $table->enum('next_actor_type', ['sender', 'seed', 'none'])->default('sender');
            $table->timestamp('next_scheduled_at')->nullable();
            $table->enum('close_condition_type', ['step_limit', 'natural', 'timeout', 'error'])->default('step_limit');
            $table->unsignedBigInteger('template_group_id')->nullable();
            $table->string('subject_line')->nullable();
            $table->timestamps();

            $table->index(['warmup_campaign_id', 'thread_status']);
            $table->index(['sender_mailbox_id', 'seed_mailbox_id']);
            $table->index('next_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
