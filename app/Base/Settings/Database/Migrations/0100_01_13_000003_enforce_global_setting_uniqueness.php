<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX_NAME = 'base_settings_global_key_unique';

    public function up(): void
    {
        if (! Schema::hasTable('base_settings')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new LogicException(
                "The settings scope invariant has no database implementation for [{$driver}].",
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX_NAME
            .' ON base_settings (key)'
            .' WHERE scope_type IS NULL AND scope_id IS NULL',
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('base_settings')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
    }
};
