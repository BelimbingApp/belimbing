<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string KEY = 'ui.locale_confirmed_at';

    /**
     * Locale confirmation was removed, so the key is no longer a claimed
     * runtime setting. Leftover rows would be unreadable, so drop them.
     */
    public function up(): void
    {
        if (! Schema::hasTable('base_settings')) {
            return;
        }

        $rows = DB::table('base_settings')
            ->where('key', self::KEY)
            ->get();

        foreach ($rows as $row) {
            $this->forgetCachedValue(self::KEY, $row->scope_type, $row->scope_id);
        }

        DB::table('base_settings')->where('key', self::KEY)->delete();
    }

    public function down(): void
    {
        // The confirmation timestamp is no longer tracked; nothing to restore.
    }

    private function forgetCachedValue(string $key, ?string $scopeType, ?int $scopeId): void
    {
        $prefix = (string) config('settings.cache_prefix', 'blb:settings');
        $scope = $scopeType === null ? 'global' : "{$scopeType}:{$scopeId}";
        $cacheKey = "{$prefix}:{$scope}:{$key}";

        Cache::forget($cacheKey);
        Cache::forget($cacheKey.':is-encrypted');
    }
};
