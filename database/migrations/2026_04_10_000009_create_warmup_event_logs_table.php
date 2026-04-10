<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warmup_event_id')->constrained('warmup_events')->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->unsignedBigInteger('warmup_campaign_id')->nullable();
            $table->string('event_type', 50);
            $table->enum('outcome', ['success', 'failure', 'retry', 'skipped']);
            $table->text('details')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->timestamps();

            $table->index(['warmup_campaign_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_event_logs');
    }
};
