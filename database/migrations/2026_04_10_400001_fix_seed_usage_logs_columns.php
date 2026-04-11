<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seed_usage_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('seed_usage_logs', 'sender_mailbox_id')) {
                $table->unsignedBigInteger('sender_mailbox_id')->nullable()->after('seed_mailbox_id');
            }
            if (!Schema::hasColumn('seed_usage_logs', 'domain_id')) {
                $table->unsignedBigInteger('domain_id')->nullable()->after('sender_mailbox_id');
            }
            if (!Schema::hasColumn('seed_usage_logs', 'used_date')) {
                $table->date('used_date')->nullable()->after('log_date');
            }
            if (!Schema::hasColumn('seed_usage_logs', 'action_type')) {
                $table->string('action_type', 50)->nullable()->after('used_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seed_usage_logs', function (Blueprint $table) {
            $table->dropColumn(['sender_mailbox_id', 'domain_id', 'used_date', 'action_type']);
        });
    }
};
