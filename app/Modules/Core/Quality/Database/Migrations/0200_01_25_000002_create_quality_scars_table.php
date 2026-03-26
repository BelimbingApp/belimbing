<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Quality\Database\Seeders\ScarWorkflowSeeder;
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
        Schema::create('quality_scars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ncr_id')->index()->constrained('quality_ncrs')->cascadeOnDelete();
            $table->string('scar_no')->unique();
            $table->string('status')->default('draft')->index();
            $table->string('supplier_name');
            $table->string('supplier_site')->nullable();
            $table->string('supplier_contact_name')->nullable();
            $table->string('supplier_contact_email')->nullable();
            $table->string('supplier_contact_phone')->nullable();
            $table->string('po_do_invoice_no')->nullable()->index();
            $table->string('product_name')->nullable();
            $table->string('product_code')->nullable();
            $table->string('detected_area')->nullable();
            $table->string('issued_by')->nullable();
            $table->date('issuing_date')->nullable();
            $table->string('request_type')->nullable(); // corrective_action, corrective_action_and_compensation
            $table->string('severity')->nullable();
            $table->decimal('claim_quantity', 14, 4)->nullable();
            $table->string('uom')->nullable();
            $table->decimal('claim_value', 14, 2)->nullable();
            $table->text('problem_description')->nullable();
            $table->foreignId('issue_owner_user_id')->nullable()->constrained('users');
            $table->timestamp('acknowledgement_due_at')->nullable();
            $table->timestamp('containment_due_at')->nullable();
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('verification_due_at')->nullable();
            $table->text('containment_response')->nullable();
            $table->text('root_cause_response')->nullable();
            $table->text('corrective_action_response')->nullable();
            $table->timestamp('supplier_response_submitted_at')->nullable();
            $table->string('commercial_resolution_type')->nullable();
            $table->decimal('commercial_resolution_amount', 14, 2)->nullable();
            $table->timestamp('commercial_resolution_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->registerSeeder(ScarWorkflowSeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->unregisterSeeder(ScarWorkflowSeeder::class);
        Schema::dropIfExists('quality_scars');
    }
};
