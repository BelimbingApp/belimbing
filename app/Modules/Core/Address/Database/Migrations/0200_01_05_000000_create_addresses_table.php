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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            // Human-friendly label (e.g. "HQ", "Warehouse 2")
            $table->string('label')->nullable();
            $table->string('phone')->nullable(); // Contact at this location; addressables get phone via primary address

            // Structured address components
            $table->text('line1')->nullable();
            $table->text('line2')->nullable();
            $table->text('line3')->nullable();
            $table->string('locality')->nullable(); // city/town
            $table->string('postcode')->nullable();

            // Geonames v1 normalization references (optional)
            $table->string('country_iso', 2)->nullable()->index();
            $table->string('admin1Code', 20)->nullable()->index();

            // AI/import provenance
            $table->text('rawInput')->nullable(); // pasted/scanned block
            $table->string('source')->nullable()->index(); // manual, scan, paste, import_api
            $table->string('sourceRef')->nullable();
            $table->string('parserVersion')->nullable();
            $table->decimal('parseConfidence', 5, 4)->nullable();
            $table->timestamp('parsed_at')->nullable();

            // Normalization/QA
            $table->timestamp('normalized_at')->nullable();
            $table->json('normalization_notes')->nullable();
            $table
                ->string('verificationStatus')
                ->default('unverified')
                ->index(); // unverified, suggested, verified
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table
                ->foreign('country_iso')
                ->references('iso')
                ->on('geonames_countries')
                ->nullOnDelete();
            $table
                ->foreign('admin1Code')
                ->references('code')
                ->on('geonames_admin1')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
