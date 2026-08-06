<?php
use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Employee\Database\Seeders\EmployeeTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersSeeders;

    /**
     * Run the migrations.
     *
     * Creates reference table for employee types. System types are seeded and
     * protected (is_system); licensees add custom types for management via UI.
     */
    public function up(): void
    {
        Schema::create('employee_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'code']);
        });

        $this->registerSeeder(EmployeeTypeSeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_types');
        $this->unregisterSeeder(EmployeeTypeSeeder::class);
    }
};
