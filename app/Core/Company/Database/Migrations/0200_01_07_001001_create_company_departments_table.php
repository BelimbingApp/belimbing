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
        Schema::create('company_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('department_type_id')->constrained('company_department_types')->cascadeOnDelete();
            $table->unsignedBigInteger('head_id')->nullable()->index();

            $table->string('status')->default('active')->index(); // active, inactive, archived
            $table->json('metadata')->nullable(); // Budget code, location, etc.

            $table->timestamps();

            // One department type per company
            $table->unique(['company_id', 'department_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_departments');
    }
};
