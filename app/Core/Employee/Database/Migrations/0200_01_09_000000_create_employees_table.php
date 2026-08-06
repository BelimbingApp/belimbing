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
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();

            // Company & organizational structure
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('company_departments')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();

            // Identity
            $table->string('employee_number')->index();
            $table->string('full_name'); // Official/Legal name (Passport/ID)
            $table->string('short_name')->nullable(); // Preferred/Display name
            $table->string('designation')->nullable(); // Job title (e.g., "Senior Software Engineer")
            $table->string('employee_type')->default('full_time')->index(); // full_time, part_time, contractor, intern, agent
            $table->text('job_description')->nullable(); // Short role label or summary

            // Primary Contact
            $table->string('email')->nullable()->index();
            $table->string('mobile_number')->nullable();

            // Employment details
            $table->string('status')->default('active')->index(); // pending, probation, active, inactive, terminated
            $table->date('employment_start')->nullable();
            $table->date('employment_end')->nullable();

            // Flexible data
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Unique constraint on employee number per company
            $table->unique(['company_id', 'employee_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
