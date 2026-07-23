<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string KEY = 'ui.timezone.mode';

    private const string DEFAULT_MODE = 'company';

    private const array VALID_MODES = [
        self::DEFAULT_MODE,
        'local',
        'utc',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('base_settings') || ! Schema::hasTable('users')) {
            return;
        }

        $rows = DB::table('base_settings')
            ->where('key', self::KEY)
            ->orderBy('id')
            ->get();

        $users = DB::table('users')
            ->orderBy('id')
            ->get(['id', 'company_id', 'employee_id']);

        foreach ($users as $user) {
            if ($this->rowAt($rows, 'user', (int) $user->id) !== null) {
                continue;
            }

            $legacy = $user->employee_id !== null
                ? $this->rowAt($rows, 'employee', (int) $user->employee_id)
                : null;
            $legacy ??= $user->company_id !== null
                ? $this->rowAt($rows, 'company', (int) $user->company_id)
                : null;
            $legacy ??= $this->rowAt($rows, null, null);

            if ($legacy === null || $this->modeFrom($legacy->value) === self::DEFAULT_MODE) {
                continue;
            }

            $mode = $this->modeFrom($legacy->value);

            if ($mode === null) {
                continue;
            }

            DB::table('base_settings')->insert([
                'key' => self::KEY,
                'value' => $legacy->value,
                'is_encrypted' => false,
                'scope_type' => 'user',
                'scope_id' => (int) $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->forgetCachedValue('user', (int) $user->id);
        }

        foreach ($rows->reject(fn (object $row): bool => $row->scope_type === 'user') as $row) {
            DB::table('base_settings')->where('id', $row->id)->delete();
            $this->forgetCachedValue($row->scope_type, $row->scope_id);
        }

        foreach ($rows->where('scope_type', 'user') as $row) {
            $this->forgetCachedValue('user', (int) $row->scope_id);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('base_settings') || ! Schema::hasTable('users')) {
            return;
        }

        $rows = DB::table('base_settings')
            ->where('key', self::KEY)
            ->where('scope_type', 'user')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $user = DB::table('users')->where('id', $row->scope_id)->first([
                'company_id',
                'employee_id',
            ]);

            if ($user === null) {
                continue;
            }

            [$scopeType, $scopeId] = match (true) {
                $user->employee_id !== null => ['employee', (int) $user->employee_id],
                $user->company_id !== null => ['company', (int) $user->company_id],
                default => [null, null],
            };

            $conflict = DB::table('base_settings')
                ->where('key', self::KEY)
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
                ->where('id', '!=', $row->id)
                ->exists();

            if ($conflict) {
                DB::table('base_settings')->where('id', $row->id)->delete();
            } else {
                DB::table('base_settings')->where('id', $row->id)->update([
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'updated_at' => now(),
                ]);
            }

            $this->forgetCachedValue('user', (int) $row->scope_id);
            $this->forgetCachedValue($scopeType, $scopeId);
        }
    }

    private function rowAt(Collection $rows, ?string $scopeType, ?int $scopeId): ?object
    {
        return $rows->first(
            fn (object $row): bool => $row->scope_type === $scopeType
                && ($row->scope_id === null ? null : (int) $row->scope_id) === $scopeId,
        );
    }

    private function modeFrom(mixed $value): ?string
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $mode = is_string($decoded) ? $decoded : (is_string($value) ? $value : null);

        return in_array($mode, self::VALID_MODES, true) ? $mode : null;
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
