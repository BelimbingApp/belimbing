<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache of plugin manifests discovered from the BelimbingApp GitHub org.
 *
 * Per docs/plans/plugin-manager-ui.md: anonymous GitHub-API discovery
 * with a 24h-default cache. Refreshing replaces all rows; the table is
 * never queried by anything other than the catalog service.
 */
return new class extends Migration
{
    use RegistersTables;

    public function up(): void
    {
        Schema::create('base_foundation_plugin_catalog_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->index();            // e.g. "github:BelimbingApp"
            $table->string('repo_name');                  // e.g. "blb-payroll-my"
            $table->string('html_url');                   // e.g. "https://github.com/BelimbingApp/blb-payroll-my"
            $table->string('default_branch')->nullable();
            $table->string('default_branch_sha', 64)->nullable();
            $table->string('composer_name')->nullable();  // from composer.json "name"
            $table->string('module_identifier')->nullable(); // extra.blb.module, e.g. "people/payroll"
            $table->string('role')->nullable();           // extra.blb.role
            $table->string('version')->nullable();
            $table->text('description')->nullable();
            $table->json('manifest');                     // full extra.blb block
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['source', 'repo_name'], 'base_foundation_plugin_catalog_cache_source_repo_unique');
        });
        $this->registerTable('base_foundation_plugin_catalog_cache');
    }

    public function down(): void
    {
        $this->unregisterTable('base_foundation_plugin_catalog_cache');
        Schema::dropIfExists('base_foundation_plugin_catalog_cache');
    }
};
