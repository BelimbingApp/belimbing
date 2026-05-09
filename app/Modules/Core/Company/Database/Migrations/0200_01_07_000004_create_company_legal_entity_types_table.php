<?php
use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Company\Database\Seeders\LegalEntityTypeSeeder;
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
        Schema::create('company_legal_entity_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'code']);
        });

        $this->registerSeeder(LegalEntityTypeSeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_legal_entity_types');
        $this->unregisterSeeder(LegalEntityTypeSeeder::class);
    }
};
