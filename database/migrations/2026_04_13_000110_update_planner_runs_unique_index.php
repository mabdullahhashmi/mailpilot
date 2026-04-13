<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planner_runs', function (Blueprint $table) {
            try {
                $table->dropUnique('planner_runs_warmup_campaign_id_plan_date_unique');
            } catch (\Throwable $e) {
                // Ignore when the old index does not exist on this environment.
            }

            $table->unique(['warmup_campaign_id', 'warmup_day_number'], 'planner_runs_campaign_day_unique');
        });
    }

    public function down(): void
    {
        Schema::table('planner_runs', function (Blueprint $table) {
            try {
                $table->dropUnique('planner_runs_campaign_day_unique');
            } catch (\Throwable $e) {
                // Ignore when the index does not exist on this environment.
            }

            $table->unique(['warmup_campaign_id', 'plan_date'], 'planner_runs_warmup_campaign_id_plan_date_unique');
        });
    }
};
