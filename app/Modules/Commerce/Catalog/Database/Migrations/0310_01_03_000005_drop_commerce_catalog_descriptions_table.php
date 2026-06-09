<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The per-item listing description moved to a single source of truth on the
 * inventory item (commerce_inventory_items.description). The standalone
 * versioned descriptions table is retired; history is covered by the audit log.
 *
 * dropIfExists keeps fresh installs (which no longer create the table) a no-op
 * while removing the orphaned table from environments that already had it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('commerce_catalog_descriptions');
    }

    public function down(): void
    {
        // Intentionally irreversible: the table and its model have been removed.
    }
};
