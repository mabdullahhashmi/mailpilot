<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('events_processed')->default(0);
            $table->unsignedInteger('events_succeeded')->default(0);
            $table->unsignedInteger('events_failed')->default(0);
            $table->unsignedInteger('events_skipped')->default(0);
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_runs');
    }
};
