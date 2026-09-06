<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::create('base_feature_flag_overrides', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('flag', 191);
            $table->boolean('enabled');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_id', 'flag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_feature_flag_overrides');
    }
};
