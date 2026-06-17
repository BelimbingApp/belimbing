<?php

// Filename retains "migrate_image_credentials" for environments that already ran
// this migration; the migration only adds the `family` column (no data migration).

use App\Modules\Core\AI\Models\AiProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->string('family', 20)->default(AiProvider::FAMILY_LLM)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropColumn('family');
        });
    }
};
