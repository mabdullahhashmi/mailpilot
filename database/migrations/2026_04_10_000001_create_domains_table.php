<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain_name')->unique();
            $table->enum('spf_status', ['unknown', 'pass', 'fail', 'none'])->default('unknown');
            $table->enum('dkim_status', ['unknown', 'pass', 'fail', 'none'])->default('unknown');
            $table->enum('dmarc_status', ['unknown', 'pass', 'fail', 'none'])->default('unknown');
            $table->enum('mx_status', ['unknown', 'pass', 'fail', 'none'])->default('unknown');
            $table->timestamp('dns_last_checked_at')->nullable();
            $table->unsignedTinyInteger('domain_health_score')->default(0);
            $table->unsignedTinyInteger('readiness_score')->default(0);
            $table->unsignedSmallInteger('daily_domain_cap')->default(50);
            $table->unsignedSmallInteger('daily_growth_cap')->default(5);
            $table->unsignedSmallInteger('max_active_warming_mailboxes')->default(5);
            $table->boolean('maintenance_mode')->default(false);
            $table->enum('status', ['active', 'paused', 'disabled'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
