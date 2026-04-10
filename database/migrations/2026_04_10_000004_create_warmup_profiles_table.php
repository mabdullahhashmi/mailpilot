<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('profile_name')->unique();
            $table->text('description')->nullable();
            $table->enum('profile_type', ['default', 'aggressive', 'conservative', 'maintenance', 'custom'])->default('default');
            $table->json('day_rules')->nullable(); // JSON: per-day overrides {day_num: {max_new_threads, max_replies, max_total}}
            $table->unsignedSmallInteger('default_max_new_threads_per_day')->default(2);
            $table->unsignedSmallInteger('default_max_reply_actions_per_day')->default(3);
            $table->unsignedSmallInteger('default_max_total_actions_per_day')->default(5);
            $table->json('provider_distribution')->nullable(); // {"gmail":40,"outlook":30,"yahoo":20,"other":10}
            $table->json('thread_length_distribution')->nullable(); // {"2":40,"3":30,"4":20,"5":10}
            $table->json('reply_delay_distribution')->nullable(); // {"min_minutes":15,"max_minutes":180,"peak_minutes":60}
            $table->time('working_hours_start')->default('08:00:00');
            $table->time('working_hours_end')->default('18:00:00');
            $table->json('anomaly_thresholds')->nullable(); // {"max_spike_pct":30,"min_interval_seconds":300}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_profiles');
    }
};
