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
        Schema::create('addressables', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('address_id')
                ->constrained('addresses')
                ->cascadeOnDelete();

            $table->morphs('addressable');

            // Relationship metadata (role + ordering + lifecycle)
            $table->json('kind')->default('[]');
            $table->boolean('is_primary')->default(false)->index();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->timestamps();

            // morphs('addressable') already indexes (addressable_type, addressable_id)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addressables');
    }
};
