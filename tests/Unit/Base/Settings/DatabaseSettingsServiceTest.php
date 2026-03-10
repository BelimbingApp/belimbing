<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Models\Setting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    $this->service = app(SettingsService::class);
});

// --- Resolution Order ---

it('falls back to config when no DB override exists', function (): void {
    config(['ai.tools.web_search.cache_ttl_minutes' => 15]);

    expect($this->service->get('ai.tools.web_search.cache_ttl_minutes'))->toBe(15);
});

it('falls back to default when no DB override and no config', function (): void {
    expect($this->service->get('nonexistent.key', 'fallback'))->toBe('fallback');
});

it('returns null when no value found and no default', function (): void {
    expect($this->service->get('nonexistent.key'))->toBeNull();
});

it('global DB override wins over config', function (): void {
    config(['some.setting' => 'from-config']);

    $this->service->set('some.setting', 'from-db');

    expect($this->service->get('some.setting'))->toBe('from-db');
});

it('company scope wins over global DB', function (): void {
    $this->service->set('some.setting', 'global-value');
    $this->service->set('some.setting', 'company-value', Scope::company(1));

    expect($this->service->get('some.setting', null, Scope::company(1)))->toBe('company-value');
});

it('employee scope wins over company scope', function (): void {
    $this->service->set('some.setting', 'company-value', Scope::company(1));
    $this->service->set('some.setting', 'employee-value', Scope::employee(10, 1));

    expect($this->service->get('some.setting', null, Scope::employee(10, 1)))->toBe('employee-value');
});

it('employee cascades to company when no employee override', function (): void {
    $this->service->set('some.setting', 'company-value', Scope::company(1));

    expect($this->service->get('some.setting', null, Scope::employee(10, 1)))->toBe('company-value');
});

it('employee cascades to global when no employee or company override', function (): void {
    $this->service->set('some.setting', 'global-value');

    expect($this->service->get('some.setting', null, Scope::employee(10, 1)))->toBe('global-value');
});

it('company cascades to global when no company override', function (): void {
    $this->service->set('some.setting', 'global-value');

    expect($this->service->get('some.setting', null, Scope::company(1)))->toBe('global-value');
});

// --- Full Cascade ---

it('resolves full cascade: employee → company → global → config', function (): void {
    config(['cascade.test' => 'config-value']);

    // Config only
    expect($this->service->get('cascade.test', null, Scope::employee(10, 1)))->toBe('config-value');

    // Add global
    $this->service->set('cascade.test', 'global-value');
    expect($this->service->get('cascade.test', null, Scope::employee(10, 1)))->toBe('global-value');

    // Add company
    $this->service->set('cascade.test', 'company-value', Scope::company(1));
    expect($this->service->get('cascade.test', null, Scope::employee(10, 1)))->toBe('company-value');

    // Add employee
    $this->service->set('cascade.test', 'employee-value', Scope::employee(10, 1));
    expect($this->service->get('cascade.test', null, Scope::employee(10, 1)))->toBe('employee-value');
});

// --- Set / Forget / Has ---

it('set creates a new setting', function (): void {
    $this->service->set('new.key', 'new-value');

    expect(Setting::query()->where('key', 'new.key')->exists())->toBeTrue();
    expect($this->service->get('new.key'))->toBe('new-value');
});

it('set overwrites an existing setting', function (): void {
    $this->service->set('overwrite.key', 'first');
    $this->service->set('overwrite.key', 'second');

    expect($this->service->get('overwrite.key'))->toBe('second');
    expect(Setting::query()->where('key', 'overwrite.key')->count())->toBe(1);
});

it('forget removes a DB override', function (): void {
    config(['forget.test' => 'config-value']);

    $this->service->set('forget.test', 'db-value');
    expect($this->service->get('forget.test'))->toBe('db-value');

    $this->service->forget('forget.test');
    expect($this->service->get('forget.test'))->toBe('config-value');
});

it('forget at scope does not affect other scopes', function (): void {
    $this->service->set('scope.test', 'global-value');
    $this->service->set('scope.test', 'company-value', Scope::company(1));

    $this->service->forget('scope.test', Scope::company(1));

    expect($this->service->get('scope.test'))->toBe('global-value');
    expect($this->service->get('scope.test', null, Scope::company(1)))->toBe('global-value');
});

it('has returns true when DB override exists at scope', function (): void {
    $this->service->set('has.test', 'value', Scope::company(1));

    expect($this->service->has('has.test', Scope::company(1)))->toBeTrue();
    expect($this->service->has('has.test'))->toBeFalse();
});

// --- JSON Value Types ---

it('stores and retrieves various JSON types', function (mixed $value): void {
    $this->service->set('type.test', $value);

    expect($this->service->get('type.test'))->toBe($value);
})->with([
    'string' => ['hello'],
    'integer' => [42],
    'float' => [3.14],
    'boolean true' => [true],
    'boolean false' => [false],
    'array' => [['a', 'b', 'c']],
    'associative array' => [['key' => 'value', 'nested' => ['deep' => true]]],
]);

// --- Cache Invalidation ---

it('busts cache on set', function (): void {
    config(['settings.cache_ttl' => 3600]);

    $this->service->set('cache.test', 'first');
    expect($this->service->get('cache.test'))->toBe('first');

    $this->service->set('cache.test', 'second');
    expect($this->service->get('cache.test'))->toBe('second');
});

it('busts cache on forget', function (): void {
    config(['settings.cache_ttl' => 3600]);
    config(['cache.forget.test' => 'config-value']);

    $this->service->set('cache.forget.test', 'db-value');
    expect($this->service->get('cache.forget.test'))->toBe('db-value');

    $this->service->forget('cache.forget.test');
    expect($this->service->get('cache.forget.test'))->toBe('config-value');
});

// --- Scope Isolation ---

it('different companies have independent settings', function (): void {
    $this->service->set('isolation.test', 'company-1', Scope::company(1));
    $this->service->set('isolation.test', 'company-2', Scope::company(2));

    expect($this->service->get('isolation.test', null, Scope::company(1)))->toBe('company-1');
    expect($this->service->get('isolation.test', null, Scope::company(2)))->toBe('company-2');
});

it('different employees have independent settings', function (): void {
    $this->service->set('emp.test', 'emp-10', Scope::employee(10, 1));
    $this->service->set('emp.test', 'emp-20', Scope::employee(20, 1));

    expect($this->service->get('emp.test', null, Scope::employee(10, 1)))->toBe('emp-10');
    expect($this->service->get('emp.test', null, Scope::employee(20, 1)))->toBe('emp-20');
});

// --- Encryption ---

it('stores and retrieves encrypted values', function (): void {
    $this->service->set('secret.api_key', 'sk-12345', encrypted: true);

    expect($this->service->get('secret.api_key'))->toBe('sk-12345');
});

it('encrypted values are not stored as plaintext in DB', function (): void {
    $this->service->set('secret.token', 'my-secret-token', encrypted: true);

    $setting = Setting::query()->where('key', 'secret.token')->first();
    expect($setting->is_encrypted)->toBeTrue();
    // The raw value should not contain the plaintext
    expect($setting->value)->not->toBe('my-secret-token');
    expect($setting->value)->not->toContain('my-secret-token');
});

it('encrypted values work with scope cascade', function (): void {
    $this->service->set('api.key', 'global-key', encrypted: true);
    $this->service->set('api.key', 'company-key', Scope::company(1), encrypted: true);

    expect($this->service->get('api.key', null, Scope::company(1)))->toBe('company-key');
    expect($this->service->get('api.key'))->toBe('global-key');
});

it('encrypts complex values correctly', function (): void {
    $complexValue = ['key' => 'value', 'nested' => ['deep' => true]];
    $this->service->set('complex.secret', $complexValue, encrypted: true);

    expect($this->service->get('complex.secret'))->toBe($complexValue);
});
