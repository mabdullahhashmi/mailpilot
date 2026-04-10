<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_events', function (Blueprint $table) {
            $table->id();
            $table->enum('event_type', [
                'sender_send_initial',
                'seed_open_email',
                'seed_reply',
                'sender_reply',
                'seed_mark_important',
                'seed_star_message',
                'seed_archive_message',
                'seed_remove_from_spam',
                'thread_close',
                'event_retry',
                'mailbox_pause',
                'mailbox_resume'
            ]);
            $table->enum('actor_type', ['sender', 'seed', 'system']);
            $table->unsignedBigInteger('actor_mailbox_id')->nullable();
            $table->enum('recipient_type', ['sender', 'seed', 'system'])->nullable();
            $table->unsignedBigInteger('recipient_mailbox_id')->nullable();
            $table->foreignId('thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->unsignedBigInteger('warmup_campaign_id')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('executed_at')->nullable();
            $table->enum('status', ['pending', 'locked', 'executing', 'completed', 'failed', 'final_failed', 'cancelled'])->default('pending');
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->unsignedTinyInteger('max_retries')->default(3);
            $table->unsignedTinyInteger('priority')->default(5); // 1=highest, 10=lowest
            $table->json('payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('lock_token', 64)->nullable();
            $table->timestamp('lock_expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['thread_id', 'event_type']);
            $table->index(['actor_mailbox_id', 'status']);
            $table->index('warmup_campaign_id');
            $table->index('lock_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_events');
    }
};
