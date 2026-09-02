<?php

namespace App\Base\Settings\Database\Migrations\Concerns;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\DTO\ScopeType;
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
        // Route through the settings service rather than re-deriving the cache
        // key by hand: it busts both the shared cache and its request-scoped
        // memo. A rename that only cleared the cache would leave a stale "miss"
        // memoized for the new key, so a read later in the same process would
        // fall back to the definition default instead of the renamed value.
        $scope = $scopeType === null ? null : match ($scopeType) {
            ScopeType::COMPANY->value => Scope::company((int) $scopeId),
            ScopeType::TENANT->value => Scope::tenant((int) $scopeId),
            ScopeType::USER->value => Scope::user((int) $scopeId),
            default => null,
        };

        app(SettingsService::class)->forgetCached($key, $scope);
    }
}
