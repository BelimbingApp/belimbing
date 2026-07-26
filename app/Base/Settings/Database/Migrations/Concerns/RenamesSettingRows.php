<?php

namespace App\Base\Settings\Database\Migrations\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait RenamesSettingRows
{
    private function renameSettingRows(string $from, string $to): void
    {
        if (! Schema::hasTable('base_settings')) {
            return;
        }

        $rows = DB::table('base_settings')
            ->where('key', $from)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $this->forgetSettingRowCache($from, $row->scope_type, $row->scope_id);
            $this->forgetSettingRowCache($to, $row->scope_type, $row->scope_id);

            if ($this->settingRowExistsForScope($to, $row->scope_type, $row->scope_id)) {
                DB::table('base_settings')->where('id', $row->id)->delete();

                continue;
            }

            DB::table('base_settings')->where('id', $row->id)->update([
                'key' => $to,
                'updated_at' => now(),
            ]);
        }
    }

    private function settingRowExistsForScope(string $key, ?string $scopeType, ?int $scopeId): bool
    {
        return DB::table('base_settings')
            ->where('key', $key)
            ->when(
                $scopeType === null,
                fn ($query) => $query->whereNull('scope_type'),
                fn ($query) => $query->where('scope_type', $scopeType),
            )
            ->when(
                $scopeId === null,
                fn ($query) => $query->whereNull('scope_id'),
                fn ($query) => $query->where('scope_id', $scopeId),
            )
            ->exists();
    }

    private function forgetSettingRowCache(string $key, ?string $scopeType, ?int $scopeId): void
    {
        $prefix = (string) config('settings.cache_prefix', 'blb:settings');
        $scope = $scopeType === null ? 'global' : "{$scopeType}:{$scopeId}";
        $cacheKey = "{$prefix}:{$scope}:{$key}";

        Cache::forget($cacheKey);
        Cache::forget($cacheKey.':is-encrypted');
    }
}
