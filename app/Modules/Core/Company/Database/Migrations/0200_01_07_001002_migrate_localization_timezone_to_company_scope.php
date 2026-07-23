<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string KEY = 'localization.timezone';

    public function up(): void
    {
        if (! Schema::hasTable('base_settings') || ! Schema::hasTable('companies')) {
            return;
        }

        $rows = DB::table('base_settings')
            ->where('key', self::KEY)
            ->orderBy('id')
            ->get();

        foreach ($rows->where('scope_type', 'company') as $row) {
            $this->forgetCachedValue('company', (int) $row->scope_id);
        }

        $global = $rows->first(
            fn (object $row): bool => $row->scope_type === null && $row->scope_id === null,
        );

        if ($global !== null) {
            foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
                $hasCompanyValue = $rows->contains(
                    fn (object $row): bool => $row->scope_type === 'company'
                        && (int) $row->scope_id === (int) $companyId,
                );

                if ($hasCompanyValue) {
                    continue;
                }

                DB::table('base_settings')->insert([
                    'key' => self::KEY,
                    'value' => $global->value,
                    'is_encrypted' => (bool) $global->is_encrypted,
                    'scope_type' => 'company',
                    'scope_id' => (int) $companyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->forgetCachedValue('company', (int) $companyId);
            }
        }

        foreach ($rows->reject(fn (object $row): bool => $row->scope_type === 'company') as $row) {
            DB::table('base_settings')->where('id', $row->id)->delete();
            $this->forgetCachedValue($row->scope_type, $row->scope_id);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('base_settings')) {
            return;
        }

        $firstCompanyValue = DB::table('base_settings')
            ->where('key', self::KEY)
            ->where('scope_type', 'company')
            ->orderBy('id')
            ->first();

        if ($firstCompanyValue === null || DB::table('base_settings')
            ->where('key', self::KEY)
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->exists()) {
            return;
        }

        DB::table('base_settings')->insert([
            'key' => self::KEY,
            'value' => $firstCompanyValue->value,
            'is_encrypted' => (bool) $firstCompanyValue->is_encrypted,
            'scope_type' => null,
            'scope_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->forgetCachedValue(null, null);
    }

    private function forgetCachedValue(?string $scopeType, ?int $scopeId): void
    {
        $prefix = (string) config('settings.cache_prefix', 'blb:settings');
        $scope = $scopeType === null ? 'global' : "{$scopeType}:{$scopeId}";
        $cacheKey = "{$prefix}:{$scope}:".self::KEY;

        Cache::forget($cacheKey);
        Cache::forget($cacheKey.':is-encrypted');
    }
};
