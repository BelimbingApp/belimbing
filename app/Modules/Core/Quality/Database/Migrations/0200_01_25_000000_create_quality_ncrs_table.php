<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Quality\Database\Seeders\NcrWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersSeeders;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quality_ncrs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->string('ncr_no')->unique();
            $table->string('ncr_kind')->index(); // internal, customer, incoming_inspection, process
            $table->string('source')->nullable(); // manual, email, api, inspection
            $table->string('status')->default('open')->index();
            $table->string('severity')->nullable(); // critical, major, minor, observation
            $table->string('classification')->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_code')->nullable()->index();
            $table->decimal('quantity_affected', 14, 4)->nullable();
            $table->string('uom')->nullable();
            $table->timestamp('reported_at');
            $table->string('reported_by_name');
            $table->string('reported_by_email')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->index()->constrained('users');
            $table->foreignId('current_owner_user_id')->nullable()->index()->constrained('users');
            $table->string('current_owner_department')->nullable();
            $table->timestamp('current_owner_assigned_at')->nullable();
            $table->boolean('is_supplier_related')->default(false);
            $table->boolean('requires_follow_up')->default(false);
            $table->timestamp('follow_up_completed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Composite indexes
            $table->index(['company_id', 'status']);
            $table->index(['status', 'severity']);
            $table->index(['ncr_kind', 'status']);
        });

        $this->registerSeeder(NcrWorkflowSeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->unregisterSeeder(NcrWorkflowSeeder::class);
        Schema::dropIfExists('quality_ncrs');
    }
};
