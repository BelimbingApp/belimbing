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
        Schema::create('company_external_accesses', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table
                ->foreignId('relationship_id')
                ->constrained('company_relationships')
                ->cascadeOnDelete();

            // Access configuration
            $table->json('permissions')->nullable(); // Granular permissions for what data is visible
            $table->boolean('is_active')->default(true);

            // Access validity period
            $table->timestamp('access_granted_at')->nullable();
            $table->timestamp('access_expires_at')->nullable();

            // Metadata
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index(['company_id', 'is_active']);
            $table->index(['access_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_external_accesses');
    }
};
