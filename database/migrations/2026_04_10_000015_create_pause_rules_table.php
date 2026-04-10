<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pause_rules', function (Blueprint $table) {
            $table->id();
            $table->morphs('pausable'); // pausable_type + pausable_id
            $table->enum('reason', [
                'manual',
                'auth_failure',
                'health_degradation',
                'repeated_failures',
                'overload',
                'provider_challenge',
                'mailbox_lockout',
                'rate_limit'
            ]);
            $table->text('details')->nullable();
            $table->timestamp('paused_at');
            $table->timestamp('auto_resume_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->enum('status', ['active', 'resumed', 'expired'])->default('active');
            $table->timestamps();

            $table->index(['pausable_type', 'pausable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pause_rules');
    }
};
