<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

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
            $table->string('user_agent', 80)->nullable();
            $table->string('event')->index();
            $table->jsonb('payload')->nullable();
            $table->string('trace_id', 12)->nullable()->index();
            $table->boolean('is_retained')->default(false);
            $table->timestamp('occurred_at')->index();

            $table->index(['event', 'occurred_at']);
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_audit_actions');
    }
};
