<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Enums\ToolReadiness;
use App\Modules\Core\AI\Services\AgentToolRegistry;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use App\Modules\Core\AI\Services\ToolReadinessService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

dataset('conditional tools', [
    'web_search' => ['web_search'],
    'memory_search' => ['memory_search'],
]);

function makeReadinessService(?AgentToolRegistry $toolRegistry = null): ToolReadinessService
{
    $toolRegistry ??= Mockery::mock(AgentToolRegistry::class);

    return new ToolReadinessService(
        $toolRegistry,
        app(ToolMetadataRegistry::class),
        app(SettingsService::class),
    );
}

it('returns UNAVAILABLE for unregistered non-conditional tool', function (): void {
    $toolRegistry = Mockery::mock(AgentToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('fake_tool')->andReturn(false);

    $service = makeReadinessService($toolRegistry);

    expect($service->readiness('fake_tool'))->toBe(ToolReadiness::UNAVAILABLE);
});

it('returns UNCONFIGURED for unregistered conditional tool', function (string $toolName): void {
    $toolRegistry = Mockery::mock(AgentToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with($toolName)->andReturn(false);

    $service = makeReadinessService($toolRegistry);

    expect($service->readiness($toolName))->toBe(ToolReadiness::UNCONFIGURED);
})->with('conditional tools');

it('returns UNAUTHORIZED when user lacks tool capability', function (): void {
    $toolRegistry = Mockery::mock(AgentToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('bash')->andReturn(true);
    $toolRegistry->shouldReceive('canCurrentUserUseTool')->with('bash')->andReturn(false);

    $service = makeReadinessService($toolRegistry);

    expect($service->readiness('bash'))->toBe(ToolReadiness::UNAUTHORIZED);
});

it('returns READY when tool is registered and user is authorized', function (): void {
    $toolRegistry = Mockery::mock(AgentToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('query_data')->andReturn(true);
    $toolRegistry->shouldReceive('canCurrentUserUseTool')->with('query_data')->andReturn(true);

    $service = makeReadinessService($toolRegistry);

    expect($service->readiness('query_data'))->toBe(ToolReadiness::READY);
});

it('returns null lastVerified when no test has been run', function (): void {
    $service = makeReadinessService();

    expect($service->lastVerified('fake_tool'))->toBeNull();
});

it('returns lastVerified from settings when test has been run', function (): void {
    $settings = app(SettingsService::class);
    $settings->set('ai.tools.query_data.last_verified_at', '2026-03-10T12:00:00+00:00');
    $settings->set('ai.tools.query_data.last_verified_success', true);

    $service = makeReadinessService();
    $result = $service->lastVerified('query_data');

    expect($result)->not->toBeNull()
        ->and($result['at'])->toBe('2026-03-10T12:00:00+00:00')
        ->and($result['success'])->toBeTrue();
});

it('provides combined snapshot with readiness and lastVerified', function (): void {
    $toolRegistry = Mockery::mock(AgentToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('query_data')->andReturn(true);
    $toolRegistry->shouldReceive('canCurrentUserUseTool')->with('query_data')->andReturn(true);

    $service = makeReadinessService($toolRegistry);
    $snapshot = $service->snapshot('query_data');

    expect($snapshot)->toHaveKeys(['readiness', 'lastVerified'])
        ->and($snapshot['readiness'])->toBe(ToolReadiness::READY)
        ->and($snapshot['lastVerified'])->toBeNull();
});

it('provides metadata and readiness for all known tool snapshots', function (): void {
    $toolRegistry = Mockery::mock(AgentToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->withAnyArgs()->andReturn(false);

    $service = makeReadinessService($toolRegistry);
    $snapshots = $service->allSnapshots();

    expect($snapshots)->toHaveKey('query_data')
        ->and($snapshots)->toHaveKey('web_search')
        ->and($snapshots['query_data']['readiness'])->toBe(ToolReadiness::UNAVAILABLE)
        ->and($snapshots['web_search']['readiness'])->toBe(ToolReadiness::UNCONFIGURED)
        ->and($snapshots['query_data']['metadata']->name)->toBe('query_data')
        ->and($snapshots['web_search']['metadata']->name)->toBe('web_search');
});
