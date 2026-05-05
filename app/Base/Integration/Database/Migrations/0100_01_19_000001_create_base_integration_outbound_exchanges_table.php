<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_integration_outbound_exchanges', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('system')->index();
            $table->string('provider')->nullable()->index();
            $table->string('operation')->index();
            $table->string('transport', 40)->default('http')->index();
            $table->string('protocol', 40)->default('rest')->index();
            $table->string('protocol_operation')->nullable()->index();
            $table->text('endpoint');
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('correlation_id')->nullable()->index();
            $table->string('traceparent')->nullable();
            $table->text('tracestate')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();
            $table->boolean('request_body_truncated')->default(false);
            $table->unsignedInteger('request_body_original_bytes')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable()->index();
            $table->json('response_headers')->nullable();
            $table->json('response_body')->nullable();
            $table->boolean('response_body_truncated')->default(false);
            $table->unsignedInteger('response_body_original_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->string('outcome', 40)->index();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->string('fallback_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['system', 'operation']);
            $table->index(['system', 'occurred_at']);
            $table->index(['transport', 'protocol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_integration_outbound_exchanges');
    }
};
