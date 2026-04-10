<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seed_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('email_address')->unique();
            $table->enum('provider_type', ['gmail', 'outlook', 'yahoo', 'custom_smtp'])->default('gmail');
            $table->text('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->enum('smtp_encryption', ['tls', 'ssl', 'none'])->default('tls');
            $table->text('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->text('imap_username')->nullable();
            $table->text('imap_password')->nullable();
            $table->enum('imap_encryption', ['tls', 'ssl', 'none'])->default('ssl');
            $table->enum('status', ['active', 'paused', 'disabled', 'failed'])->default('active');
            $table->time('working_hours_start')->default('08:00:00');
            $table->time('working_hours_end')->default('18:00:00');
            $table->unsignedSmallInteger('daily_total_interaction_cap')->default(20);
            $table->unsignedSmallInteger('per_domain_interaction_cap')->default(5);
            $table->unsignedSmallInteger('per_sender_interaction_cap')->default(3);
            $table->unsignedSmallInteger('concurrent_thread_cap')->default(5);
            $table->unsignedSmallInteger('cooldown_minutes_between_threads')->default(30);
            $table->enum('trust_tier', ['low', 'medium', 'high'])->default('medium');
            $table->unsignedTinyInteger('health_score')->default(100);
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_paused')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_mailboxes');
    }
};
