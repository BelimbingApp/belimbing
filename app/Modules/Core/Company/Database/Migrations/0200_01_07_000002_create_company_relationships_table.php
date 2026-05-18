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
        Schema::create('company_relationships', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table
                ->foreignId('related_company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table
                ->foreignId('relationship_type_id')
                ->constrained('company_relationship_types')
                ->cascadeOnDelete();

            // Temporal validity
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            // Additional relationship metadata
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for common queries
            $table->index(['company_id', 'relationship_type_id']);
            $table->index(['related_company_id', 'relationship_type_id'], 'company_relationships_related_type_index');
            $table->index(['effective_from', 'effective_to']);

            // Unique constraint to prevent duplicate relationships
            $table->unique(
                [
                    'company_id',
                    'related_company_id',
                    'relationship_type_id',
                    'effective_from',
                ],
                'company_relationship_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_relationships');
    }
};
