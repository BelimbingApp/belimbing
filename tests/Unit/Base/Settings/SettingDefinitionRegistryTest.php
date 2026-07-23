<?php

use App\Base\AI\Services\AiRuntimeSettings;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Settings\DTO\SettingDefinition;
use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('discovers canonical AI runtime parameter definitions', function (): void {
    $registry = app(SettingDefinitionRegistry::class);
    $maxToolRounds = $registry->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY);
    $pdfToTextPath = $registry->get(AiRuntimeSettings::PDFTOTEXT_PATH_KEY);

    expect($maxToolRounds->type)->toBe('integer')
        ->and($maxToolRounds->owner)->toBe('core.ai')
        ->and($maxToolRounds->editable)->toBe('admin.ai.control-plane')
        ->and($maxToolRounds->capability)->toBe('admin.ai.control-plane.manage')
        ->and($maxToolRounds->scopes)->toBe(['global'])
        ->and($maxToolRounds->default)->toBe(100)
        ->and($maxToolRounds->nullable)->toBeFalse()
        ->and($pdfToTextPath->type)->toBe('string')
        ->and($pdfToTextPath->scopes)->toBe(['global'])
        ->and($pdfToTextPath->default)->toBeNull()
        ->and($pdfToTextPath->nullable)->toBeTrue();
});

it('discovers performance definitions with reusable UI and validation metadata', function (): void {
    $registry = app(SettingDefinitionRegistry::class);
    $enabled = $registry->get(PerfRuntimeSettings::ENABLED_KEY);
    $minimumDuration = $registry->get(PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY);
    $logPath = $registry->get(PerfRuntimeSettings::LOG_PATH_KEY);
    $retention = $registry->get(PerfRuntimeSettings::RETENTION_DAYS_KEY);

    expect($enabled->default)->toBeTrue()
        ->and($enabled->label)->toBe('Record performance activity')
        ->and($minimumDuration->default)->toBe(0.0)
        ->and($minimumDuration->ruleParameter('min'))->toBe('0')
        ->and($minimumDuration->ruleParameter('max'))->toBe('3600000')
        ->and($logPath->default)->toBeNull()
        ->and($logPath->nullable)->toBeTrue()
        ->and($retention->default)->toBe(14)
        ->and($retention->scopes)->toBe(['global']);
});

it('discovers distinct company timezone and user display mode definitions', function (): void {
    $registry = app(SettingDefinitionRegistry::class);
    $timezone = $registry->get(TimezoneSettings::LOCALIZATION_TIMEZONE_KEY);
    $mode = $registry->get(TimezoneSettings::MODE_KEY);

    expect($timezone->type)->toBe('string')
        ->and($timezone->scopes)->toBe(['company'])
        ->and($timezone->default)->toBe('UTC')
        ->and($mode->type)->toBe('string')
        ->and($mode->scopes)->toBe(['user'])
        ->and($mode->default)->toBe(TimezoneMode::COMPANY->value);
});

it('matches wildcard definitions by the most specific declared pattern', function (): void {
    $registry = app(SettingDefinitionRegistry::class);

    expect($registry->get('integrations.cloudflare.api_token')->encrypted)->toBeTrue()
        ->and($registry->get('integrations.cloudflare.api_token.description')->encrypted)->toBeFalse();
});

it('rejects incomplete or internally inconsistent definitions', function (array $definition): void {
    expect(fn () => SettingDefinition::fromArray('example.setting', $definition))
        ->toThrow(InvalidSettingDefinitionException::class);
})->with([
    'missing type' => [[
        'scopes' => ['global'],
        'default' => 10,
        'owner' => 'tests.settings',
    ]],
    'missing scopes' => [[
        'type' => 'integer',
        'default' => 10,
        'owner' => 'tests.settings',
    ]],
    'missing default' => [[
        'type' => 'integer',
        'scopes' => ['global'],
        'owner' => 'tests.settings',
    ]],
    'incompatible default' => [[
        'type' => 'integer',
        'scopes' => ['global'],
        'default' => '10',
        'owner' => 'tests.settings',
    ]],
    'null without nullable' => [[
        'type' => 'string',
        'scopes' => ['global'],
        'default' => null,
        'owner' => 'tests.settings',
    ]],
    'unsupported scope' => [[
        'type' => 'string',
        'scopes' => ['employee'],
        'default' => '',
        'owner' => 'tests.settings',
    ]],
    'blank label' => [[
        'type' => 'string',
        'scopes' => ['global'],
        'default' => '',
        'label' => ' ',
        'owner' => 'tests.settings',
    ]],
    'default outside validation rules' => [[
        'type' => 'integer',
        'scopes' => ['global'],
        'default' => 0,
        'rules' => ['required', 'integer', 'min:1'],
        'owner' => 'tests.settings',
    ]],
    'string encryption flag' => [[
        'type' => 'string',
        'scopes' => ['global'],
        'default' => '',
        'encrypted' => 'false',
        'owner' => 'tests.settings',
    ]],
    'missing owner' => [[
        'type' => 'string',
        'scopes' => ['global'],
        'default' => '',
    ]],
    'editable without help metadata' => [[
        'type' => 'string',
        'scopes' => ['global'],
        'default' => '',
        'owner' => 'tests.settings',
        'editable' => 'tests.form',
        'label' => 'Example',
    ]],
]);
