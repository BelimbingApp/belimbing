<?php
use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Company\Database\Seeders\DepartmentTypeSeeder;
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
        Schema::create('company_department_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category')->index(); // administrative, operational, revenue, support
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['is_active', 'category']);
        });

        $this->registerSeeder(DepartmentTypeSeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_department_types');
        $this->unregisterSeeder(DepartmentTypeSeeder::class);
    }
};
