<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL can attach the foreign key on warmup_campaign_id to the old
        // composite unique index, so create a dedicated index first.
        Schema::table('planner_runs', function (Blueprint $table) {
            try {
                $table->index('warmup_campaign_id', 'planner_runs_warmup_campaign_id_idx');
            } catch (\Throwable $e) {
                // Ignore when index already exists.
            }
        });

        Schema::table('planner_runs', function (Blueprint $table) {
            try {
                $table->dropUnique('planner_runs_warmup_campaign_id_plan_date_unique');
            } catch (\Throwable $e) {
                // Ignore when the old index does not exist on this environment.
            }

            try {
                $table->unique(['warmup_campaign_id', 'warmup_day_number'], 'planner_runs_campaign_day_unique');
            } catch (\Throwable $e) {
                // Ignore when new unique index already exists.
            }
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

            try {
                $table->unique(['warmup_campaign_id', 'plan_date'], 'planner_runs_warmup_campaign_id_plan_date_unique');
            } catch (\Throwable $e) {
                // Ignore when old unique index already exists.
            }
        });
    }
};
