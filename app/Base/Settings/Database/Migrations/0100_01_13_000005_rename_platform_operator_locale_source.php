<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('base_settings')
            ->where('key', 'ui.locale_source')
            ->where('is_encrypted', false)
            ->orderBy('id')
            ->get(['id', 'value'])
            ->each(function (object $row): void {
                $value = is_string($row->value)
                    ? json_decode($row->value, true)
                    : $row->value;

                if ($value === 'licensee_address') {
                    DB::table('base_settings')->where('id', $row->id)->update([
                        'value' => json_encode('platform_operator_address', JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring the obsolete persisted noun
        // would make a rollback silently misdescribe explicit operator data.
    }
};
