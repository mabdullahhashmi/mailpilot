<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seed_mailboxes', function (Blueprint $table) {
            $table->timestamp('last_smtp_test_at')->nullable();
            $table->timestamp('last_imap_test_at')->nullable();
            $table->enum('last_smtp_test_result', ['pass', 'fail', 'untested'])->default('untested');
            $table->enum('last_imap_test_result', ['pass', 'fail', 'untested'])->default('untested');
        });
    }

    public function down(): void
    {
        Schema::table('seed_mailboxes', function (Blueprint $table) {
            $table->dropColumn([
                'last_smtp_test_at',
                'last_imap_test_at',
                'last_smtp_test_result',
                'last_imap_test_result',
            ]);
        });
    }
};
