<?php

use App\Base\Settings\DTO\Scope;
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
