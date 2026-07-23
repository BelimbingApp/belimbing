<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Base\System\Services\MailRuntimeSettings;
use App\Base\System\Services\SystemRuntimeSettings;
use Illuminate\Support\Facades\Artisan;

it('previews and deliberately imports legacy environment parameters without revealing values', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'blb-settings-import-');
    expect($path)->toBeString();

    try {
        file_put_contents($path, <<<'ENV'
APP_NAME="Imported Belimbing"
SESSION_LIFETIME=240
MAIL_PASSWORD="super-secret-value"
PERF_LOG_MIN_MS=not-an-integer
ENV);

        expect(Artisan::call('blb:settings:import-environment', ['--file' => $path]))->toBe(0)
            ->and(Artisan::output())->toContain('Preview only')
            ->and(Artisan::output())->not->toContain('super-secret-value')
            ->and(Artisan::output())->not->toContain('not-an-integer')
            ->and(app(SettingsService::class)->has(SystemRuntimeSettings::PRODUCT_NAME_KEY))->toBeFalse()
            ->and(app(SettingsService::class)->has(SystemRuntimeSettings::SESSION_LIFETIME_KEY))->toBeFalse()
            ->and(app(SettingsService::class)->has(MailRuntimeSettings::PASSWORD_KEY))->toBeFalse();

        expect(Artisan::call('blb:settings:import-environment', [
            '--file' => $path,
            '--apply' => true,
        ]))->toBe(0)
            ->and(Artisan::output())->not->toContain('super-secret-value');

        $settings = app(SettingsService::class);

        expect($settings->get(SystemRuntimeSettings::PRODUCT_NAME_KEY))->toBe('Imported Belimbing')
            ->and($settings->get(SystemRuntimeSettings::SESSION_LIFETIME_KEY))->toBe(240)
            ->and($settings->get(MailRuntimeSettings::PASSWORD_KEY))->toBe('super-secret-value')
            ->and($settings->has('perf.min_ms'))->toBeFalse();

        $passwordRow = Setting::query()->where('key', MailRuntimeSettings::PASSWORD_KEY)->firstOrFail();
        expect($passwordRow->is_encrypted)->toBeTrue()
            ->and((string) $passwordRow->getRawOriginal('value'))->not->toContain('super-secret-value');
    } finally {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
});

it('keeps explicit settings unless force is requested', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'blb-settings-import-');
    expect($path)->toBeString();

    try {
        app(SettingsService::class)->set(SystemRuntimeSettings::PRODUCT_NAME_KEY, 'Existing Name');
        file_put_contents($path, "APP_NAME=\"Replacement Name\"\n");

        Artisan::call('blb:settings:import-environment', [
            '--file' => $path,
            '--apply' => true,
        ]);

        expect(app(SettingsService::class)->get(SystemRuntimeSettings::PRODUCT_NAME_KEY))
            ->toBe('Existing Name');

        Artisan::call('blb:settings:import-environment', [
            '--file' => $path,
            '--apply' => true,
            '--force' => true,
        ]);

        expect(app(SettingsService::class)->get(SystemRuntimeSettings::PRODUCT_NAME_KEY))
            ->toBe('Replacement Name');
    } finally {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
});
