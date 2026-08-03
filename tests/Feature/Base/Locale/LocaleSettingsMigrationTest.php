<?php

use App\Base\Settings\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function localeConfirmationDropMigration(): Migration
{
    return require app_path(
        'Base/Settings/Database/Migrations/0100_01_13_000004_drop_locale_confirmed_at_setting.php',
    );
}

it('drops locale confirmation rows at every scope and leaves other locale settings intact', function (): void {
    Setting::query()->create([
        'key' => 'ui.locale_confirmed_at',
        'value' => '2026-04-01T10:00:00Z',
    ]);
    Setting::query()->create([
        'key' => 'ui.locale_confirmed_at',
        'value' => '2026-04-02T10:00:00Z',
        'scope_type' => 'company',
        'scope_id' => 1,
    ]);
    Setting::query()->create([
        'key' => 'ui.locale',
        'value' => 'en-MY',
    ]);
    Setting::query()->create([
        'key' => 'ui.locale_source',
        'value' => 'manual',
    ]);

    localeConfirmationDropMigration()->up();

    expect(Setting::query()->where('key', 'ui.locale_confirmed_at')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'ui.locale')->value('value'))->toBe('en-MY')
        ->and(Setting::query()->where('key', 'ui.locale_source')->value('value'))->toBe('manual');
});
