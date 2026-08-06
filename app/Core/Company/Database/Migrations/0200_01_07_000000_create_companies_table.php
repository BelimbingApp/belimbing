<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('active')->index(); // active, suspended, pending, archived

            // Registration details
            $table->string('legal_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('tax_id')->nullable();
            $table->foreignId('legal_entity_type_id')->nullable()->index();
            $table->string('jurisdiction')->nullable();

            // Contact information (phone is on Address; use primary address for phone)
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Addresses: use Address module via addressables (morphToMany)

            // Business context (JSON for flexibility)
            $table->json('scope_activities')->nullable(); // Industry, services, business focus
            $table->json('metadata')->nullable(); // Additional AI inference metadata

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index(['parent_id', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
