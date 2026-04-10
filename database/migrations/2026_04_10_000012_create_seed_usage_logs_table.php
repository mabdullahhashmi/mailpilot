<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seed_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seed_mailbox_id')->constrained('seed_mailboxes')->cascadeOnDelete();
            $table->date('log_date');
            $table->unsignedSmallInteger('interactions_today')->default(0);
            $table->json('per_domain_usage')->nullable(); // {domain_id: count}
            $table->json('per_sender_usage')->nullable(); // {sender_id: count}
            $table->unsignedTinyInteger('health_score')->default(100);
            $table->boolean('overload_flag')->default(false);
            $table->boolean('is_paused')->default(false);
            $table->timestamps();

            $table->unique(['seed_mailbox_id', 'log_date']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_usage_logs');
    }
};
