<?php
use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Company\Database\Seeders\RelationshipTypeSeeder;
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
        Schema::create('company_relationship_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique(); // internal, customer, supplier, partner, agency
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_external')->default(false); // Whether this relationship type allows external access
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable(); // Additional configuration
            $table->timestamps();

            // Indexes
            $table->index(['is_active', 'code']);
        });

        $this->registerSeeder(RelationshipTypeSeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_relationship_types');
        $this->unregisterSeeder(RelationshipTypeSeeder::class);
    }
};
