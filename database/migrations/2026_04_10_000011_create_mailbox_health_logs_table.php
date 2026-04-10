<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes')->cascadeOnDelete();
            $table->date('log_date');
            $table->unsignedSmallInteger('warmup_day');
            $table->unsignedSmallInteger('sent_today')->default(0);
            $table->unsignedSmallInteger('replied_today')->default(0);
            $table->unsignedSmallInteger('active_threads')->default(0);
            $table->unsignedSmallInteger('failed_events')->default(0);
            $table->unsignedSmallInteger('auth_failures')->default(0);
            $table->enum('smtp_status', ['ok', 'degraded', 'failed'])->default('ok');
            $table->enum('imap_status', ['ok', 'degraded', 'failed'])->default('ok');
            $table->json('anomaly_flags')->nullable();
            $table->unsignedTinyInteger('health_score')->default(100);
            $table->unsignedTinyInteger('readiness_score')->default(0);
            $table->timestamps();

            $table->unique(['sender_mailbox_id', 'log_date']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_health_logs');
    }
};
