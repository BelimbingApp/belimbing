<?php
use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Geonames\Database\Seeders\Admin1Seeder;
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
        Schema::create('geonames_admin1', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->index();
            $table->string('name');
            $table->string('alt_name')->nullable();
            $table->unsignedInteger('geoname_id')->nullable()->unique();
            $table->timestamps();
        });

        // Registers after CountrySeeder (migration file timestamp ensures this)
        $this->registerSeeder(Admin1Seeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geonames_admin1');
        $this->unregisterSeeder(Admin1Seeder::class);
    }
};
