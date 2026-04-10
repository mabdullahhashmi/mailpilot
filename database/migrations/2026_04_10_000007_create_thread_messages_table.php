<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('threads')->cascadeOnDelete();
            $table->enum('actor_type', ['sender', 'seed']);
            $table->unsignedBigInteger('actor_mailbox_id');
            $table->unsignedBigInteger('recipient_mailbox_id');
            $table->enum('direction', ['sender_to_seed', 'seed_to_sender']);
            $table->string('subject');
            $table->text('body');
            $table->string('provider_message_id')->nullable();
            $table->string('in_reply_to_message_id')->nullable();
            $table->unsignedTinyInteger('message_step_number')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->enum('delivery_state', ['pending', 'sent', 'delivered', 'opened', 'failed', 'bounced'])->default('pending');
            $table->timestamps();

            $table->index(['thread_id', 'message_step_number']);
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_messages');
    }
};
