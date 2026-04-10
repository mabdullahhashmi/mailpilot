<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_templates', function (Blueprint $table) {
            $table->id();
            $table->enum('template_type', ['initial', 'reply', 'continuation', 'closing']);
            $table->string('category', 50)->default('general'); // business, casual, meeting, etc.
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('greetings')->nullable(); // ["Hi", "Hello", "Hey"]
            $table->json('signoffs')->nullable(); // ["Best", "Thanks", "Regards"]
            $table->json('variations')->nullable(); // sentence-level alternatives
            $table->json('placeholders')->nullable(); // ["{{name}}", "{{company}}"]
            $table->enum('warmup_stage', ['any', 'initial_trust', 'controlled_expansion', 'behavioral_maturity', 'readiness', 'maintenance'])->default('any');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedSmallInteger('cooldown_minutes')->default(60);
            $table->string('content_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->index(['template_type', 'warmup_stage', 'is_active']);
            $table->index('content_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_templates');
    }
};
