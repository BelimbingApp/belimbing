<?php

use App\Base\AI\Services\AiRuntimeSettings;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Exceptions\InvalidSettingScopeException;
use App\Base\Settings\Exceptions\InvalidSettingValueException;
use App\Base\Settings\Models\Setting;
use App\Base\Settings\Services\DatabaseSettingsService;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('resolves a declared parameter from its definition without config or caller fallback', function (): void {
    config([
        AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY => 24,
    ]);

    expect($this->service->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY, 12))->toBe(100);
});

it('resolves global declared parameters as one definition-backed group', function (): void {
    $this->service->set(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY, 160);
    config([AiRuntimeSettings::PDFTOTEXT_PATH_KEY => 'C:\\environment-fallback']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $values = $this->service->getMany([
        AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
        AiRuntimeSettings::PDFTOTEXT_PATH_KEY,
    ]);
    $settingQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'base_settings'))
        ->count();

    DB::disableQueryLog();

    expect($values)->toBe([
        AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY => 160,
        AiRuntimeSettings::PDFTOTEXT_PATH_KEY => null,
    ])->and($settingQueries)->toBe(1);
});

it('validates declared parameter scope and stored value type', function (): void {
    expect(fn () => $this->service->set(
        AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
        100,
        Scope::company(1),
    ))->toThrow(InvalidSettingScopeException::class)
        ->and(fn () => $this->service->set(
            AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
            '100',
        ))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => $this->service->set(
            AiRuntimeSettings::PDFTOTEXT_PATH_KEY,
            null,
        ))->toThrow(InvalidSettingValueException::class);
});

it('enforces each declared parameters validation rules on every write', function (): void {
    expect(fn () => $this->service->set(
        AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
        0,
    ))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => $this->service->set(
            AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
            501,
        ))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => $this->service->set(
            PerfRuntimeSettings::RETENTION_DAYS_KEY,
            0,
        ))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => $this->service->set(
            TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
            'Not/A/Timezone',
            Scope::company(1),
        ))->toThrow(InvalidSettingValueException::class);
});

it('restores a declared parameter to its definition default', function (): void {
    $this->service->set(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY, 160);
    expect($this->service->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBe(160);

    $this->service->forget(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY);

    expect($this->service->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBe(100)
        ->and($this->service->has(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBeFalse();
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

it('user scope wins over company and remains isolated between users', function (): void {
    $this->service->set('some.setting', 'company-value', Scope::company(1));
    $this->service->set('some.setting', 'user-value', Scope::user(10, 1));

    expect($this->service->get('some.setting', scope: Scope::user(10, 1)))->toBe('user-value')
        ->and($this->service->get('some.setting', scope: Scope::user(11, 1)))->toBe('company-value');
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

it('resolves declared timezone settings only through their allowed scopes', function (): void {
    $this->service->set(
        TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
        'Asia/Kuala_Lumpur',
        Scope::company(1),
    );
    $this->service->set(
        TimezoneSettings::MODE_KEY,
        TimezoneMode::LOCAL->value,
        Scope::user(10, 1),
    );

    expect($this->service->get(
        TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
        scope: Scope::company(1),
    ))->toBe('Asia/Kuala_Lumpur')
        ->and($this->service->get(
            TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
            scope: Scope::company(2),
        ))->toBe('UTC')
        ->and($this->service->get(
            TimezoneSettings::MODE_KEY,
            scope: Scope::user(10, 1),
        ))->toBe(TimezoneMode::LOCAL->value)
        ->and($this->service->get(
            TimezoneSettings::MODE_KEY,
            scope: Scope::user(11, 1),
        ))->toBe(TimezoneMode::COMPANY->value)
        ->and(fn () => $this->service->set(
            TimezoneSettings::MODE_KEY,
            TimezoneMode::UTC->value,
            Scope::company(1),
        ))->toThrow(InvalidSettingScopeException::class);
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

it('never writes decrypted settings to a persistent cache store', function (): void {
    config(['settings.cache_ttl' => 3600]);
    $cache = app('cache')->store('database');
    $service = new DatabaseSettingsService(
        $cache,
        app(SettingDefinitionRegistry::class),
    );
    $secret = 'postgresql://mirror-user:cache-secret@example.test/postgres';

    $service->set('data_share.mirror.credentials', $secret, encrypted: true);

    expect($service->get('data_share.mirror.credentials'))->toBe($secret)
        ->and(DB::table(config('cache.stores.database.table', 'cache'))
            ->where('value', 'like', '%cache-secret%')
            ->exists())->toBeFalse();
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
