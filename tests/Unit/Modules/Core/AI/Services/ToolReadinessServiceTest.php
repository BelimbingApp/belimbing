<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\ToolHealthState;
use App\Modules\Core\AI\Enums\ToolReadiness;
use App\Modules\Core\AI\Services\DigitalWorkerToolRegistry;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use App\Modules\Core\AI\Services\ToolReadinessService;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('returns UNAVAILABLE for unregistered non-conditional tool', function () {
    $toolRegistry = Mockery::mock(DigitalWorkerToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('fake_tool')->andReturn(false);

    $metadataRegistry = app(ToolMetadataRegistry::class);
    $service = new ToolReadinessService($toolRegistry, $metadataRegistry);

    expect($service->readiness('fake_tool'))->toBe(ToolReadiness::UNAVAILABLE);
});

it('returns UNCONFIGURED for unregistered conditional tool', function () {
    $toolRegistry = Mockery::mock(DigitalWorkerToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('web_search')->andReturn(false);

    $metadataRegistry = app(ToolMetadataRegistry::class);
    $service = new ToolReadinessService($toolRegistry, $metadataRegistry);

    expect($service->readiness('web_search'))->toBe(ToolReadiness::UNCONFIGURED);
});

it('returns UNAUTHORIZED when user lacks tool capability', function () {
    $toolRegistry = Mockery::mock(DigitalWorkerToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('bash')->andReturn(true);
    $toolRegistry->shouldReceive('canCurrentUserUseTool')->with('bash')->andReturn(false);

    $metadataRegistry = app(ToolMetadataRegistry::class);
    $service = new ToolReadinessService($toolRegistry, $metadataRegistry);

    expect($service->readiness('bash'))->toBe(ToolReadiness::UNAUTHORIZED);
});

it('returns READY when tool is registered and user is authorized', function () {
    $toolRegistry = Mockery::mock(DigitalWorkerToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('query_data')->andReturn(true);
    $toolRegistry->shouldReceive('canCurrentUserUseTool')->with('query_data')->andReturn(true);

    $metadataRegistry = app(ToolMetadataRegistry::class);
    $service = new ToolReadinessService($toolRegistry, $metadataRegistry);

    expect($service->readiness('query_data'))->toBe(ToolReadiness::READY);
});

it('returns UNKNOWN health for unregistered tools', function () {
    $toolRegistry = Mockery::mock(DigitalWorkerToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('fake_tool')->andReturn(false);

    $metadataRegistry = app(ToolMetadataRegistry::class);
    $service = new ToolReadinessService($toolRegistry, $metadataRegistry);

    expect($service->health('fake_tool'))->toBe(ToolHealthState::UNKNOWN);
});

it('returns HEALTHY health for system_info tool', function () {
    $toolRegistry = Mockery::mock(DigitalWorkerToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('system_info')->andReturn(true);

    $metadataRegistry = app(ToolMetadataRegistry::class);
    $service = new ToolReadinessService($toolRegistry, $metadataRegistry);

    expect($service->health('system_info'))->toBe(ToolHealthState::HEALTHY);
});

it('provides combined snapshot with readiness and health', function () {
    $toolRegistry = Mockery::mock(DigitalWorkerToolRegistry::class);
    $toolRegistry->shouldReceive('isRegistered')->with('query_data')->andReturn(true);
    $toolRegistry->shouldReceive('canCurrentUserUseTool')->with('query_data')->andReturn(true);

    $metadataRegistry = app(ToolMetadataRegistry::class);
    $service = new ToolReadinessService($toolRegistry, $metadataRegistry);

    $snapshot = $service->snapshot('query_data');

    expect($snapshot)->toHaveKeys(['readiness', 'health'])
        ->and($snapshot['readiness'])->toBe(ToolReadiness::READY)
        ->and($snapshot['health'])->toBe(ToolHealthState::HEALTHY);
});
