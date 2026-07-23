<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string OLD_KEY = 'ui.timezone.default';

    private const string NEW_KEY = 'localization.timezone';

    public function up(): void
    {
        $this->renameSetting(self::OLD_KEY, self::NEW_KEY);
    }

    public function down(): void
    {
        $this->renameSetting(self::NEW_KEY, self::OLD_KEY);
    }

    private function renameSetting(string $from, string $to): void
    {
        if (! Schema::hasTable('base_settings')) {
            return;
        }

        $rows = DB::table('base_settings')
            ->where('key', $from)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $this->forgetCachedValue($from, $row->scope_type, $row->scope_id);
            $this->forgetCachedValue($to, $row->scope_type, $row->scope_id);

            $matchingScope = DB::table('base_settings')
                ->where('key', $to)
                ->when(
                    $row->scope_type === null,
                    fn ($query) => $query->whereNull('scope_type'),
                    fn ($query) => $query->where('scope_type', $row->scope_type),
                )
                ->when(
                    $row->scope_id === null,
                    fn ($query) => $query->whereNull('scope_id'),
                    fn ($query) => $query->where('scope_id', $row->scope_id),
                )
                ->exists();

            if ($matchingScope) {
                DB::table('base_settings')->where('id', $row->id)->delete();

                continue;
            }

            DB::table('base_settings')->where('id', $row->id)->update([
                'key' => $to,
                'updated_at' => now(),
            ]);
        }
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
