<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planner_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warmup_campaign_id')->constrained('warmup_campaigns')->cascadeOnDelete();
            $table->date('plan_date');
            $table->unsignedSmallInteger('warmup_day_number');
            $table->string('warmup_stage', 30);
            $table->unsignedSmallInteger('total_action_budget')->default(0);
            $table->unsignedSmallInteger('new_thread_target')->default(0);
            $table->unsignedSmallInteger('reply_target')->default(0);
            $table->unsignedSmallInteger('actual_new_threads')->default(0);
            $table->unsignedSmallInteger('actual_replies')->default(0);
            $table->unsignedSmallInteger('actual_total_actions')->default(0);
            $table->json('eligible_seed_ids')->nullable();
            $table->json('provider_distribution')->nullable();
            $table->time('working_window_start')->nullable();
            $table->time('working_window_end')->nullable();
            $table->json('notes')->nullable();
            $table->enum('status', ['planned', 'executing', 'completed', 'partial', 'failed'])->default('planned');
            $table->timestamps();

            $table->unique(['warmup_campaign_id', 'plan_date']);
            $table->index('plan_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planner_runs');
    }
};
