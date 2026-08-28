<?php

use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Models\Setting;
use App\Base\Settings\Support\SettingSubject;

it('builds the stable global and scoped setting audit identities', function (): void {
    expect(SettingSubject::handle('mail.host'))->toBe([
        'name' => 'setting',
        'id' => 'mail.host',
    ])->and(SettingSubject::handle('mail.host', Scope::company(42)))->toBe([
        'name' => 'setting',
        'id' => 'mail.host@company:42',
    ]);
});

it('deduplicates bounded setting subjects without changing their order', function (): void {
    expect(SettingSubject::handles(['mail.host', 'mail.port', 'mail.host']))->toBe([
        ['name' => 'setting', 'id' => 'mail.host'],
        ['name' => 'setting', 'id' => 'mail.port'],
    ]);
});

it('preserves legacy persisted scope identities without requiring a current scope enum', function (): void {
    $setting = new Setting([
        'key' => 'localization.timezone.mode',
        'scope_type' => 'employee',
        'scope_id' => 42,
    ]);

    expect($setting->getAuditSubject())->toBe([
        'name' => 'setting',
        'id' => 'localization.timezone.mode@employee:42',
    ]);
});
