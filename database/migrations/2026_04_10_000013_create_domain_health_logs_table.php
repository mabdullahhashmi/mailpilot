<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->date('log_date');
            $table->unsignedSmallInteger('active_sender_count')->default(0);
            $table->unsignedSmallInteger('daily_action_count')->default(0);
            $table->json('dns_health')->nullable();
            $table->unsignedTinyInteger('readiness_score')->default(0);
            $table->timestamps();

            $table->unique(['domain_id', 'log_date']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_health_logs');
    }
};
