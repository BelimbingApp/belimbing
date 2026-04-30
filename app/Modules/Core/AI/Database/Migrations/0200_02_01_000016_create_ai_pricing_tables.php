<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pricing is recorded as cents-per-token so `ai_run_calls.cost_*_cents`
     * can be re-derived from the stored rate snapshot.
     */
    public function up(): void
    {
        Schema::create('ai_pricing_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider')->nullable();
            $table->string('model');
            $table->decimal('input_cents_per_token', 20, 12);
            $table->decimal('cached_input_cents_per_token', 20, 12)->nullable();
            $table->decimal('output_cents_per_token', 20, 12);
            $table->string('source', 40);
            $table->string('source_version', 80)->nullable();
            $table->date('snapshot_date');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['provider', 'model', 'snapshot_date']);
            $table->index(['model', 'snapshot_date']);
            $table->index('source');
        });

        Schema::create('ai_pricing_overrides', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider')->nullable();
            $table->string('model');
            $table->decimal('input_cents_per_token', 20, 12);
            $table->decimal('cached_input_cents_per_token', 20, 12)->nullable();
            $table->decimal('output_cents_per_token', 20, 12);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'model']);
            $table->index('model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_pricing_overrides');
        Schema::dropIfExists('ai_pricing_snapshots');
    }
};
