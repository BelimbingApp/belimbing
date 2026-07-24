<?php

use App\Base\AI\Services\AiRuntimeSettings;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;
use App\Base\Settings\Exceptions\InvalidSettingScopeException;
use App\Base\Settings\Exceptions\InvalidSettingValueException;
use App\Base\Settings\Models\Setting;
use App\Base\Settings\Services\DatabaseSettingsService;
use App\Base\Settings\Services\RuntimeSettingClaimRegistry;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    $this->settings = app(SettingsService::class);
});

function registerTestDefinitions(array $definitions, array $runtimeClaims = []): void
{
    config([
        'settings.definitions' => [
            ...config('settings.definitions', []),
            ...$definitions,
        ],
        'settings.runtime' => [
            ...config('settings.runtime', []),
            ...$runtimeClaims,
        ],
    ]);
    app(SettingDefinitionRegistry::class)->refresh();
    app(RuntimeSettingClaimRegistry::class)->refresh();
}

function testDefinition(
    string $type = 'mixed',
    mixed $default = null,
    array $scopes = ['user', 'company', 'global'],
    bool $encrypted = false,
    array $rules = [],
): array {
    return [
        'type' => $type,
        'scopes' => $scopes,
        'default' => $default,
        'nullable' => $default === null,
        'encrypted' => $encrypted,
        'rules' => $rules,
        'owner' => 'tests.settings',
    ];
}

it('uses only a definition-owned default when no override exists', function (): void {
    config([
        'ai.tools.web_search.cache_ttl_minutes' => 999,
        AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY => 12,
    ]);

    expect($this->settings->get('ai.tools.web_search.cache_ttl_minutes'))->toBe(15)
        ->and($this->settings->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBe(100);
});

it('uses definition defaults before the settings table exists', function (): void {
    $originalConnection = DB::getDefaultConnection();
    config(['database.connections.settings_without_schema' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('settings_without_schema');

    try {
        expect($this->settings->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBe(100)
            ->and($this->settings->getMany([
                AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
                AiRuntimeSettings::PDFTOTEXT_PATH_KEY,
            ]))->toBe([
                AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY => 100,
                AiRuntimeSettings::PDFTOTEXT_PATH_KEY => null,
            ])
            ->and($this->settings->has(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBeFalse();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('settings_without_schema');
    }
});

it('rejects keys without a parameter definition or runtime-state claim', function (): void {
    expect(fn () => $this->settings->get('unknown.setting'))
        ->toThrow(InvalidSettingDefinitionException::class)
        ->and(fn () => $this->settings->set('unknown.setting', 'value'))
        ->toThrow(InvalidSettingDefinitionException::class);
});

it('returns null for claimed runtime state until its owner stores a value', function (): void {
    registerTestDefinitions([], ['tests.runtime.*']);

    expect($this->settings->get('tests.runtime.last_run'))->toBeNull();

    $this->settings->set('tests.runtime.last_run', ['status' => 'ok']);

    expect($this->settings->get('tests.runtime.last_run'))->toBe(['status' => 'ok']);
});

it('resolves only the scopes allowed by a definition', function (): void {
    registerTestDefinitions([
        'tests.inherited' => testDefinition(default: 'default'),
        'tests.personal' => testDefinition(
            type: 'string',
            default: 'system',
            scopes: ['user'],
            rules: ['required', 'string'],
        ),
    ]);

    $this->settings->set('tests.inherited', 'global');
    $this->settings->set('tests.inherited', 'company', Scope::company(1));
    $this->settings->set('tests.inherited', 'user', Scope::user(10, 1));

    expect($this->settings->get('tests.inherited', Scope::user(10, 1)))->toBe('user')
        ->and($this->settings->get('tests.inherited', Scope::user(11, 1)))->toBe('company')
        ->and($this->settings->get('tests.inherited', Scope::company(2)))->toBe('global')
        ->and($this->settings->get('tests.personal', Scope::user(11, 1)))->toBe('system')
        ->and(fn () => $this->settings->set('tests.personal', 'dark', Scope::company(1)))
        ->toThrow(InvalidSettingScopeException::class);
});

it('keeps user settings isolated without an employee identity', function (): void {
    registerTestDefinitions([
        'tests.user-only' => testDefinition(
            type: 'string',
            default: 'default',
            scopes: ['user'],
            rules: ['required', 'string'],
        ),
    ]);

    $this->settings->set('tests.user-only', 'first', Scope::user(10));
    $this->settings->set('tests.user-only', 'second', Scope::user(20));

    expect($this->settings->get('tests.user-only', Scope::user(10)))->toBe('first')
        ->and($this->settings->get('tests.user-only', Scope::user(20)))->toBe('second');
});

it('validates definition scope type and rules on every write', function (): void {
    expect(fn () => $this->settings->set(
        AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
        '100',
    ))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => $this->settings->set(
            AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
            501,
        ))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => $this->settings->set(
            PerfRuntimeSettings::RETENTION_DAYS_KEY,
            0,
        ))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => $this->settings->set(
            TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
            'Not/A/Timezone',
            Scope::company(1),
        ))->toThrow(InvalidSettingValueException::class);
});

it('restores an override by deleting its row', function (): void {
    $this->settings->set(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY, 160);

    expect($this->settings->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBe(160)
        ->and($this->settings->has(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBeTrue();

    $this->settings->forget(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY);

    expect($this->settings->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBe(100)
        ->and($this->settings->has(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBeFalse();
});

it('prevents duplicate global rows at the database boundary', function (): void {
    $this->settings->set(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY, 160);

    expect(fn () => DB::table('base_settings')->insert([
        'key' => AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY,
        'value' => json_encode(200, JSON_THROW_ON_ERROR),
        'is_encrypted' => false,
        'scope_type' => null,
        'scope_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('resolves global declared parameters in one database query', function (): void {
    $this->settings->set(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY, 160);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $values = $this->settings->getMany([
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

it('memoizes repeated reads within one request or job scope', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $first = $this->settings->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY);
    $second = $this->settings->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY);
    $settingQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'base_settings'))
        ->count();

    DB::disableQueryLog();

    expect($first)->toBe(100)
        ->and($second)->toBe(100)
        ->and($settingQueries)->toBe(1);
});

it('preserves JSON values and invalidates cached overrides', function (mixed $value): void {
    registerTestDefinitions([
        'tests.mixed' => testDefinition(),
    ]);
    config(['settings.cache_ttl' => 3600]);

    $this->settings->set('tests.mixed', $value);
    expect($this->settings->get('tests.mixed'))->toBe($value);

    $this->settings->set('tests.mixed', ['replacement' => true]);
    expect($this->settings->get('tests.mixed'))->toBe(['replacement' => true]);

    $this->settings->forget('tests.mixed');
    expect($this->settings->get('tests.mixed'))->toBeNull();
})->with([
    'string' => ['hello'],
    'integer' => [42],
    'float' => [3.14],
    'true' => [true],
    'false' => [false],
    'list' => [['a', 'b']],
    'map' => [['nested' => ['deep' => true]]],
]);

it('derives encryption from the definition and never persists plaintext cache data', function (): void {
    config(['settings.cache_ttl' => 3600]);
    $cache = app('cache')->store('database');
    $service = new DatabaseSettingsService(
        $cache,
        app(SettingDefinitionRegistry::class),
        app(RuntimeSettingClaimRegistry::class),
    );
    $secret = 'postgresql://mirror-user:cache-secret@example.test/postgres';

    $service->set('data_share.mirror.url', $secret);

    $row = Setting::query()->where('key', 'data_share.mirror.url')->firstOrFail();

    expect($row->is_encrypted)->toBeTrue()
        ->and((string) $row->getRawOriginal('value'))->not->toContain('cache-secret')
        ->and($service->get('data_share.mirror.url'))->toBe($secret)
        ->and(DB::table(config('cache.stores.database.table', 'cache'))
            ->where('value', 'like', '%cache-secret%')
            ->exists())->toBeFalse();
});

it('resolves the distinct company timezone and user display mode contracts', function (): void {
    $this->settings->set(
        TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
        'Asia/Kuala_Lumpur',
        Scope::company(1),
    );
    $this->settings->set(
        TimezoneSettings::MODE_KEY,
        TimezoneMode::LOCAL->value,
        Scope::user(10, 1),
    );

    expect($this->settings->get(
        TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
        Scope::company(1),
    ))->toBe('Asia/Kuala_Lumpur')
        ->and($this->settings->get(
            TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
            Scope::company(2),
        ))->toBe('UTC')
        ->and($this->settings->get(
            TimezoneSettings::MODE_KEY,
            Scope::user(10, 1),
        ))->toBe(TimezoneMode::LOCAL->value);
});
