<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function hasIndex(string $table, string $indexName): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $indexName]
        );

        return !empty($rows);
    }

    private function ensureUserIdColumn(string $table): void
    {
        if (!Schema::hasColumn($table, 'user_id')) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
                $table->index('user_id');
            });
            return;
        }

        if (!$this->hasIndex($table, "{$table}_user_id_index")) {
            Schema::table($table, function (Blueprint $table) {
                $table->index('user_id');
            });
        }
    }

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('user')->after('password');
            }
        });

        foreach (['domains', 'sender_mailboxes', 'seed_mailboxes', 'warmup_profiles', 'warmup_campaigns', 'content_templates'] as $table) {
            $this->ensureUserIdColumn($table);
        }

        $defaultUserId = DB::table('users')->orderBy('id')->value('id');

        if ($defaultUserId) {
            foreach (['domains', 'sender_mailboxes', 'seed_mailboxes', 'warmup_profiles', 'warmup_campaigns', 'content_templates'] as $table) {
                DB::table($table)->whereNull('user_id')->update(['user_id' => $defaultUserId]);
            }
        }

        if (Schema::hasTable('domains')) {
            Schema::table('domains', function (Blueprint $table) {
                try {
                    $table->dropUnique('domains_domain_name_unique');
                } catch (\Throwable $e) {
                    // Ignore if the old unique index does not exist.
                }

                if (!$this->hasIndex('domains', 'domains_user_id_domain_name_unique')) {
                    $table->unique(['user_id', 'domain_name']);
                }
            });
        }

        if (Schema::hasTable('warmup_profiles')) {
            Schema::table('warmup_profiles', function (Blueprint $table) {
                try {
                    $table->dropUnique('warmup_profiles_profile_name_unique');
                } catch (\Throwable $e) {
                    // Ignore if the old unique index does not exist.
                }

                if (!$this->hasIndex('warmup_profiles', 'warmup_profiles_user_id_profile_name_unique')) {
                    $table->unique(['user_id', 'profile_name']);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('warmup_profiles')) {
            Schema::table('warmup_profiles', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'profile_name']);
            });
        }

        if (Schema::hasTable('domains')) {
            Schema::table('domains', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'domain_name']);
            });
        }

        Schema::table('content_templates', function (Blueprint $table) {
            if (Schema::hasColumn('content_templates', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('warmup_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('warmup_campaigns', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('warmup_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('warmup_profiles', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('seed_mailboxes', function (Blueprint $table) {
            if (Schema::hasColumn('seed_mailboxes', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('sender_mailboxes', function (Blueprint $table) {
            if (Schema::hasColumn('sender_mailboxes', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('domains', function (Blueprint $table) {
            if (Schema::hasColumn('domains', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};