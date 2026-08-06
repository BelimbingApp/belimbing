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
        Schema::create('geonames_postcodes', function (Blueprint $table) {
            $table->id();

            $table->string('country_iso', 2)->index();
            $table->string('postcode', 20)->index();
            $table->string('place_name', 180)->index();
            $table->string('admin1Code', 20)->nullable()->index();
            $table->string('admin_name1', 100)->nullable();
            $table->string('admin_code1', 20)->nullable();
            $table->string('admin_name2', 100)->nullable();
            $table->string('admin_code2', 20)->nullable();
            $table->string('admin_name3', 100)->nullable();
            $table->string('admin_code3', 20)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedTinyInteger('accuracy')->nullable();

            $table->timestamps();

            $table
                ->foreign('country_iso')
                ->references('iso')
                ->on('geonames_countries')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->index(['country_iso', 'postcode']);
            $table->index(['country_iso', 'place_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geonames_postcodes');
    }
};
