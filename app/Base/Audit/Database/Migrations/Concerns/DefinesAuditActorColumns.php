<?php

namespace App\Base\Audit\Database\Migrations\Concerns;

use Illuminate\Database\Schema\Blueprint;

trait DefinesAuditActorColumns
{
    private function addAuditActorColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('company_id')->nullable()->index();
        $table->string('actor_type', 40)->index();
        $table->unsignedBigInteger('actor_id')->index();
        $table->string('actor_role', 100)->nullable();
        $table->ipAddress('ip_address')->nullable();
        $table->text('url')->nullable();
        $table->string('user_agent', 80)->nullable();
    }

    private function addAuditActorOccurredIndex(Blueprint $table): void
    {
        $table->index(['actor_type', 'actor_id', 'occurred_at']);
    }
}
