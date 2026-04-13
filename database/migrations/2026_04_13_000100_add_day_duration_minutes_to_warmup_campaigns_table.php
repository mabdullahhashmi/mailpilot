<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warmup_campaigns', function (Blueprint $table) {
            $table->unsignedSmallInteger('day_duration_minutes')
                ->default(1440)
                ->after('planned_duration_days');
        });
    }

    public function down(): void
    {
        Schema::table('warmup_campaigns', function (Blueprint $table) {
            $table->dropColumn('day_duration_minutes');
        });
    }
};
