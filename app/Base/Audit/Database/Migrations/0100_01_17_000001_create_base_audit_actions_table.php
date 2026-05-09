<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_audit_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('actor_type', 40)->index();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('actor_role', 100)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('url')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('event')->index();
            $table->jsonb('payload')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->boolean('is_retained')->default(false);
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'occurred_at']);
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_audit_actions');
    }
};
