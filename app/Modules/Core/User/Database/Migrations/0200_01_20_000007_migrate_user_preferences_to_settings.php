<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const array KEY_MAP = [
        'landing_menu_id' => 'ui.landing_menu_id',
        'dashboard' => 'ui.dashboard.layout',
        'last_used_model' => 'ai.last_used_model_hints',
        'theme' => 'ui.theme',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('base_settings')) {
            return;
        }

        if (Schema::hasColumn('users', 'prefs')) {
            DB::table('users')
                ->select(['id', 'prefs'])
                ->orderBy('id')
                ->eachById(function (object $user): void {
                    $preferences = $this->decodePreferences($user->prefs);

                    foreach (self::KEY_MAP as $legacyKey => $settingKey) {
                        if (! array_key_exists($legacyKey, $preferences)) {
                            continue;
                        }

                        $value = $preferences[$legacyKey];

                        if (! $this->isMigratable($settingKey, $value)
                            || $this->settingExists($settingKey, (int) $user->id)) {
                            continue;
                        }

                        DB::table('base_settings')->insert([
                            'key' => $settingKey,
                            'value' => json_encode($value, JSON_THROW_ON_ERROR),
                            'is_encrypted' => false,
                            'scope_type' => 'user',
                            'scope_id' => (int) $user->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('prefs');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'prefs')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('prefs')->nullable();
        });

        if (! Schema::hasTable('base_settings')) {
            return;
        }

        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->eachById(function (object $user): void {
                $preferences = [];

                foreach (self::KEY_MAP as $legacyKey => $settingKey) {
                    $row = DB::table('base_settings')
                        ->where('key', $settingKey)
                        ->where('scope_type', 'user')
                        ->where('scope_id', (int) $user->id)
                        ->first(['value']);

                    if ($row !== null) {
                        $preferences[$legacyKey] = $this->decodeValue($row->value);
                    }
                }

                DB::table('users')->where('id', (int) $user->id)->update([
                    'prefs' => $preferences === []
                        ? null
                        : json_encode($preferences, JSON_THROW_ON_ERROR),
                ]);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePreferences(mixed $value): array
    {
        $decoded = $this->decodeValue($value);

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function settingExists(string $key, int $userId): bool
    {
        return DB::table('base_settings')
            ->where('key', $key)
            ->where('scope_type', 'user')
            ->where('scope_id', $userId)
            ->exists();
    }

    private function isMigratable(string $key, mixed $value): bool
    {
        return match ($key) {
            'ui.landing_menu_id' => is_string($value),
            'ui.dashboard.layout', 'ai.last_used_model_hints' => is_array($value),
            'ui.theme' => in_array($value, ['light', 'dark', 'system'], true),
            default => false,
        };
    }
};
