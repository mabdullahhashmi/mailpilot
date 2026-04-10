<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_mailbox_id')->constrained('sender_mailboxes')->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->foreignId('warmup_profile_id')->constrained('warmup_profiles');
            $table->date('start_date');
            $table->unsignedSmallInteger('planned_duration_days')->default(20);
            $table->unsignedSmallInteger('current_day_number')->default(0);
            $table->enum('current_stage', [
                'initial_trust',      // Days 1-4
                'controlled_expansion', // Days 5-9
                'behavioral_maturity', // Days 10-14
                'readiness',           // Days 15-20
                'maintenance',
                'completed'
            ])->default('initial_trust');
            $table->enum('status', ['pending', 'active', 'paused', 'completed', 'stopped', 'failed'])->default('pending');
            $table->boolean('maintenance_mode_enabled')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_campaigns');
    }
};
