<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Support\SettingsFieldValue;
use App\Base\System\Livewire\Email\Index as EmailSettings;
use App\Base\System\Livewire\Settings\General;
use App\Base\System\Services\MailRuntimeSettings;
use App\Base\System\Services\RuntimeConfigurationApplier;
use App\Base\System\Services\SystemRuntimeSettings;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('lets an authorized operator save and restore system defaults', function (): void {
    $settings = app(SettingsService::class);
    $name = SettingsFieldValue::formKey(SystemRuntimeSettings::PRODUCT_NAME_KEY);
    $lifetime = SettingsFieldValue::formKey(SystemRuntimeSettings::SESSION_LIFETIME_KEY);

    $this->actingAs(createAdminUser());

    Livewire::test(General::class)
        ->set("values.{$name}", 'Belimbing Operations')
        ->set("values.{$lifetime}", '240')
        ->call('save')
        ->assertHasNoErrors();

    expect($settings->get(SystemRuntimeSettings::PRODUCT_NAME_KEY))->toBe('Belimbing Operations')
        ->and($settings->get(SystemRuntimeSettings::SESSION_LIFETIME_KEY))->toBe(240);

    Livewire::test(General::class)
        ->call('restoreDefaults')
        ->assertSet("values.{$name}", 'Belimbing')
        ->assertSet("values.{$lifetime}", 120);

    expect($settings->has(SystemRuntimeSettings::PRODUCT_NAME_KEY))->toBeFalse()
        ->and($settings->has(SystemRuntimeSettings::SESSION_LIFETIME_KEY))->toBeFalse();
});

it('enforces the settings group capability at the page boundary', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.settings.index'))->assertForbidden();
});

it('stores mail credentials encrypted and keeps saved secrets write-only', function (): void {
    $username = SettingsFieldValue::formKey(MailRuntimeSettings::USERNAME_KEY);
    $password = SettingsFieldValue::formKey(MailRuntimeSettings::PASSWORD_KEY);

    $this->actingAs(createAdminUser());

    Livewire::test(EmailSettings::class)
        ->set("values.{$username}", 'smtp-user')
        ->set("values.{$password}", 'smtp-password')
        ->call('save')
        ->assertHasNoErrors();

    $rows = DB::table('base_settings')
        ->whereIn('key', [MailRuntimeSettings::USERNAME_KEY, MailRuntimeSettings::PASSWORD_KEY])
        ->get();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect((bool) $row->is_encrypted)->toBeTrue()
            ->and((string) $row->value)->not->toContain('smtp-');
    }

    Livewire::test(EmailSettings::class)
        ->assertSet("values.{$username}", '******')
        ->assertSet("values.{$password}", '******');
});

it('projects declared system and mail settings into framework runtime config', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(SystemRuntimeSettings::PRODUCT_NAME_KEY, 'Belimbing Runtime');
    $settings->set(SystemRuntimeSettings::SESSION_LIFETIME_KEY, 360);
    $settings->set(MailRuntimeSettings::MAILER_KEY, 'smtp');
    $settings->set(MailRuntimeSettings::HOST_KEY, 'smtp.example.test');
    $settings->set(MailRuntimeSettings::PORT_KEY, 587);
    $settings->set(MailRuntimeSettings::USERNAME_KEY, 'runtime-user');
    $settings->set(MailRuntimeSettings::PASSWORD_KEY, 'runtime-password');
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'hello@example.test');

    app(RuntimeConfigurationApplier::class)->apply();

    expect(config('app.name'))->toBe('Belimbing Runtime')
        ->and(config('session.lifetime'))->toBe(360)
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(587)
        ->and(config('mail.mailers.smtp.username'))->toBe('runtime-user')
        ->and(config('mail.mailers.smtp.password'))->toBe('runtime-password')
        ->and(config('mail.from.address'))->toBe('hello@example.test')
        ->and(config('mail.from.name'))->toBe('Belimbing Runtime');
});
