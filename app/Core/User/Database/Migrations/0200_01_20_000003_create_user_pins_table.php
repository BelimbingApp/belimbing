<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the user_pins table for per-user pinned sidebar items.
     *
     * Pins are identified by their normalized URL. A url_hash column
     * (MD5 of the normalized URL) provides an efficient unique constraint
     * regardless of pin origin (menu item, page, DB view, etc.).
     */
    public function up(): void
    {
        Schema::create('user_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label', 150);
            $table->string('url', 500);
            $table->char('url_hash', 32);
            $table->string('icon', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'url_hash']);
            $table->index(['user_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_pins');
    }
};
