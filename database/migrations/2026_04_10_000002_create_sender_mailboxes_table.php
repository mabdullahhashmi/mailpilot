<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('email_address')->unique();
            $table->enum('provider_type', ['gmail', 'outlook', 'yahoo', 'custom_smtp'])->default('custom_smtp');
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
            $table->boolean('is_warmup_enabled')->default(false);
            $table->date('warmup_start_date')->nullable();
            $table->unsignedSmallInteger('current_warmup_day')->default(0);
            $table->unsignedSmallInteger('target_warmup_duration_days')->default(20);
            $table->unsignedSmallInteger('daily_send_cap')->default(5);
            $table->unsignedSmallInteger('daily_reply_cap')->default(3);
            $table->string('timezone', 50)->default('UTC');
            $table->time('working_hours_start')->default('08:00:00');
            $table->time('working_hours_end')->default('18:00:00');
            $table->unsignedTinyInteger('health_score')->default(100);
            $table->unsignedTinyInteger('readiness_score')->default(0);
            $table->boolean('is_paused')->default(false);
            $table->boolean('maintenance_mode')->default(false);
            $table->timestamp('last_smtp_test_at')->nullable();
            $table->timestamp('last_imap_test_at')->nullable();
            $table->enum('last_smtp_test_result', ['pass', 'fail', 'untested'])->default('untested');
            $table->enum('last_imap_test_result', ['pass', 'fail', 'untested'])->default('untested');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_mailboxes');
    }
};
