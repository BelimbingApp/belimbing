<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\DTO\ControlPlane\HealthSnapshot;
use App\Modules\Core\AI\DTO\Session;
use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\PresenceState;
use App\Modules\Core\AI\Enums\ToolHealthState;
use App\Modules\Core\AI\Enums\ToolReadiness;
use App\Modules\Core\AI\Services\ControlPlane\HealthAndPresenceService;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\ToolReadinessService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const HAPS_TOOL_NAME = 'bash';
const HAPS_EMPLOYEE_ID = 1;
const HAPS_PROVIDER_NAME = 'anthropic';
const HAPS_RUN_TIME_1 = '2026-04-02T10:00:00+00:00';
const HAPS_RUN_TIME_2 = '2026-04-02T10:01:00+00:00';
const HAPS_PROVIDER_LAST_TEST_AT = '.last_test_at';
const HAPS_PROVIDER_LAST_TEST_SUCCESS = '.last_test_success';

function makeHapsService(
    ?ToolReadinessService $toolReadiness = null,
    ?SessionManager $sessionManager = null,
    ?SettingsService $settings = null,
): HealthAndPresenceService {
    return new HealthAndPresenceService(
        $toolReadiness ?? Mockery::mock(ToolReadinessService::class),
        $sessionManager ?? Mockery::mock(SessionManager::class),
        $settings ?? app(SettingsService::class),
    );
}

function hapsSession(int $minutesAgo = 5, array $runs = []): Session
{
    $lastActivity = (new DateTimeImmutable)->modify("-{$minutesAgo} minutes");

    return new Session(
        id: 'sess_haps_001',
        employeeId: HAPS_EMPLOYEE_ID,
        channelType: 'web',
        title: 'Test',
        createdAt: $lastActivity,
        lastActivityAt: $lastActivity,
        runs: $runs,
    );
}

// ------------------------------------------------------------------
// toolSnapshot
// ------------------------------------------------------------------

describe('toolSnapshot', function () {
    it('returns a healthy active snapshot for a ready tool with fresh verification', function () {
        $freshTime = (new DateTimeImmutable)->format('c');

        $toolReadiness = Mockery::mock(ToolReadinessService::class);
        $toolReadiness->shouldReceive('snapshot')
            ->with(HAPS_TOOL_NAME)
            ->andReturn([
                'readiness' => ToolReadiness::READY,
                'lastVerified' => ['at' => $freshTime, 'success' => true],
            ]);

        $service = makeHapsService(toolReadiness: $toolReadiness);
        $snapshot = $service->toolSnapshot(HAPS_TOOL_NAME);

        expect($snapshot)->toBeInstanceOf(HealthSnapshot::class)
            ->and($snapshot->targetType)->toBe(ControlPlaneTarget::Tool)
            ->and($snapshot->targetId)->toBe(HAPS_TOOL_NAME)
            ->and($snapshot->readiness)->toBe(ToolReadiness::READY)
            ->and($snapshot->health)->toBe(ToolHealthState::HEALTHY)
            ->and($snapshot->presence)->toBe(PresenceState::Active);
    });

    it('returns unknown health when tool is not ready', function () {
        $toolReadiness = Mockery::mock(ToolReadinessService::class);
        $toolReadiness->shouldReceive('snapshot')
            ->with(HAPS_TOOL_NAME)
            ->andReturn([
                'readiness' => ToolReadiness::UNCONFIGURED,
                'lastVerified' => null,
            ]);

        $service = makeHapsService(toolReadiness: $toolReadiness);
        $snapshot = $service->toolSnapshot(HAPS_TOOL_NAME);

        expect($snapshot->health)->toBe(ToolHealthState::UNKNOWN)
            ->and($snapshot->presence)->toBe(PresenceState::Offline);
    });

    it('returns failing health when last verification failed', function () {
        $recentTime = (new DateTimeImmutable)->format('c');

        $toolReadiness = Mockery::mock(ToolReadinessService::class);
        $toolReadiness->shouldReceive('snapshot')
            ->with(HAPS_TOOL_NAME)
            ->andReturn([
                'readiness' => ToolReadiness::READY,
                'lastVerified' => ['at' => $recentTime, 'success' => false],
            ]);

        $service = makeHapsService(toolReadiness: $toolReadiness);
        $snapshot = $service->toolSnapshot(HAPS_TOOL_NAME);

        expect($snapshot->health)->toBe(ToolHealthState::FAILING);
    });

    it('returns degraded health when verification is stale', function () {
        $staleTime = (new DateTimeImmutable)->modify('-48 hours')->format('c');

        $toolReadiness = Mockery::mock(ToolReadinessService::class);
        $toolReadiness->shouldReceive('snapshot')
            ->with(HAPS_TOOL_NAME)
            ->andReturn([
                'readiness' => ToolReadiness::READY,
                'lastVerified' => ['at' => $staleTime, 'success' => true],
            ]);

        $service = makeHapsService(toolReadiness: $toolReadiness);
        $snapshot = $service->toolSnapshot(HAPS_TOOL_NAME);

        expect($snapshot->health)->toBe(ToolHealthState::DEGRADED);
    });

    it('returns unknown health when no verification has been performed', function () {
        $toolReadiness = Mockery::mock(ToolReadinessService::class);
        $toolReadiness->shouldReceive('snapshot')
            ->with(HAPS_TOOL_NAME)
            ->andReturn([
                'readiness' => ToolReadiness::READY,
                'lastVerified' => null,
            ]);

        $service = makeHapsService(toolReadiness: $toolReadiness);
        $snapshot = $service->toolSnapshot(HAPS_TOOL_NAME);

        expect($snapshot->health)->toBe(ToolHealthState::UNKNOWN);
    });
});

// ------------------------------------------------------------------
// allToolSnapshots
// ------------------------------------------------------------------

describe('allToolSnapshots', function () {
    it('returns snapshots for all known tools', function () {
        $freshTime = (new DateTimeImmutable)->format('c');

        $toolReadiness = Mockery::mock(ToolReadinessService::class);
        $toolReadiness->shouldReceive('allSnapshots')
            ->andReturn([
                'bash' => [
                    'readiness' => ToolReadiness::READY,
                    'lastVerified' => ['at' => $freshTime, 'success' => true],
                ],
                'web_search' => [
                    'readiness' => ToolReadiness::UNCONFIGURED,
                    'lastVerified' => null,
                ],
            ]);

        $service = makeHapsService(toolReadiness: $toolReadiness);
        $snapshots = $service->allToolSnapshots();

        expect($snapshots)->toHaveCount(2)
            ->and($snapshots[0]->targetId)->toBe('bash')
            ->and($snapshots[0]->health)->toBe(ToolHealthState::HEALTHY)
            ->and($snapshots[1]->targetId)->toBe('web_search')
            ->and($snapshots[1]->health)->toBe(ToolHealthState::UNKNOWN);
    });
});

// ------------------------------------------------------------------
// agentSnapshot
// ------------------------------------------------------------------

describe('agentSnapshot', function () {
    it('detects active presence when session activity is recent', function () {
        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('list')
            ->with(HAPS_EMPLOYEE_ID)
            ->andReturn([hapsSession(minutesAgo: 5)]);

        $service = makeHapsService(sessionManager: $sessionManager);
        $snapshot = $service->agentSnapshot(HAPS_EMPLOYEE_ID);

        expect($snapshot->targetType)->toBe(ControlPlaneTarget::Agent)
            ->and($snapshot->presence)->toBe(PresenceState::Active);
    });

    it('detects idle presence when session activity is within idle threshold', function () {
        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('list')
            ->with(HAPS_EMPLOYEE_ID)
            ->andReturn([hapsSession(minutesAgo: 30)]);

        $service = makeHapsService(sessionManager: $sessionManager);
        $snapshot = $service->agentSnapshot(HAPS_EMPLOYEE_ID);

        expect($snapshot->presence)->toBe(PresenceState::Idle);
    });

    it('detects offline presence when no recent sessions', function () {
        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('list')
            ->with(HAPS_EMPLOYEE_ID)
            ->andReturn([hapsSession(minutesAgo: 120)]);

        $service = makeHapsService(sessionManager: $sessionManager);
        $snapshot = $service->agentSnapshot(HAPS_EMPLOYEE_ID);

        expect($snapshot->presence)->toBe(PresenceState::Offline);
    });

    it('detects offline presence when agent has no sessions', function () {
        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('list')
            ->with(HAPS_EMPLOYEE_ID)
            ->andReturn([]);

        $service = makeHapsService(sessionManager: $sessionManager);
        $snapshot = $service->agentSnapshot(HAPS_EMPLOYEE_ID);

        expect($snapshot->presence)->toBe(PresenceState::Offline)
            ->and($snapshot->health)->toBe(ToolHealthState::UNKNOWN);
    });

    it('computes healthy agent health from successful recent runs', function () {
        $runs = [
            'run_1' => ['meta' => ['latency_ms' => 100], 'recorded_at' => HAPS_RUN_TIME_1],
            'run_2' => ['meta' => ['latency_ms' => 200], 'recorded_at' => HAPS_RUN_TIME_2],
        ];

        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('list')
            ->with(HAPS_EMPLOYEE_ID)
            ->andReturn([hapsSession(minutesAgo: 5, runs: $runs)]);

        $service = makeHapsService(sessionManager: $sessionManager);
        $snapshot = $service->agentSnapshot(HAPS_EMPLOYEE_ID);

        expect($snapshot->health)->toBe(ToolHealthState::HEALTHY);
    });

    it('computes failing agent health when most recent runs have errors', function () {
        $runs = [
            'run_1' => ['meta' => ['error' => 'Timeout'], 'recorded_at' => HAPS_RUN_TIME_1],
            'run_2' => ['meta' => ['error' => 'Rate limit'], 'recorded_at' => HAPS_RUN_TIME_2],
            'run_3' => ['meta' => ['error' => 'Auth fail'], 'recorded_at' => '2026-04-02T10:02:00+00:00'],
        ];

        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('list')
            ->with(HAPS_EMPLOYEE_ID)
            ->andReturn([hapsSession(minutesAgo: 5, runs: $runs)]);

        $service = makeHapsService(sessionManager: $sessionManager);
        $snapshot = $service->agentSnapshot(HAPS_EMPLOYEE_ID);

        expect($snapshot->health)->toBe(ToolHealthState::FAILING);
    });

    it('computes degraded agent health when some runs have errors', function () {
        $runs = [
            'run_1' => ['meta' => ['latency_ms' => 100], 'recorded_at' => HAPS_RUN_TIME_1],
            'run_2' => ['meta' => ['error' => 'Timeout'], 'recorded_at' => HAPS_RUN_TIME_2],
            'run_3' => ['meta' => ['latency_ms' => 200], 'recorded_at' => '2026-04-02T10:02:00+00:00'],
        ];

        $sessionManager = Mockery::mock(SessionManager::class);
        $sessionManager->shouldReceive('list')
            ->with(HAPS_EMPLOYEE_ID)
            ->andReturn([hapsSession(minutesAgo: 5, runs: $runs)]);

        $service = makeHapsService(sessionManager: $sessionManager);
        $snapshot = $service->agentSnapshot(HAPS_EMPLOYEE_ID);

        expect($snapshot->health)->toBe(ToolHealthState::DEGRADED);
    });
});

// ------------------------------------------------------------------
// providerSnapshot
// ------------------------------------------------------------------

describe('providerSnapshot', function () {
    it('returns unknown health when provider has never been tested', function () {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')
            ->with('ai.providers.'.HAPS_PROVIDER_NAME.HAPS_PROVIDER_LAST_TEST_AT)
            ->andReturn(null);
        $settings->shouldReceive('get')
            ->with('ai.providers.'.HAPS_PROVIDER_NAME.HAPS_PROVIDER_LAST_TEST_SUCCESS, false)
            ->andReturn(false);

        $service = makeHapsService(settings: $settings);
        $snapshot = $service->providerSnapshot(HAPS_PROVIDER_NAME);

        expect($snapshot->targetType)->toBe(ControlPlaneTarget::Provider)
            ->and($snapshot->health)->toBe(ToolHealthState::UNKNOWN)
            ->and($snapshot->presence)->toBe(PresenceState::Offline);
    });

    it('returns healthy when last test succeeded recently', function () {
        $recentTime = (new DateTimeImmutable)->format('c');

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')
            ->with('ai.providers.'.HAPS_PROVIDER_NAME.HAPS_PROVIDER_LAST_TEST_AT)
            ->andReturn($recentTime);
        $settings->shouldReceive('get')
            ->with('ai.providers.'.HAPS_PROVIDER_NAME.HAPS_PROVIDER_LAST_TEST_SUCCESS, false)
            ->andReturn(true);

        $service = makeHapsService(settings: $settings);
        $snapshot = $service->providerSnapshot(HAPS_PROVIDER_NAME);

        expect($snapshot->health)->toBe(ToolHealthState::HEALTHY)
            ->and($snapshot->presence)->toBe(PresenceState::Active);
    });

    it('returns failing when last test failed', function () {
        $recentTime = (new DateTimeImmutable)->format('c');

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')
            ->with('ai.providers.'.HAPS_PROVIDER_NAME.HAPS_PROVIDER_LAST_TEST_AT)
            ->andReturn($recentTime);
        $settings->shouldReceive('get')
            ->with('ai.providers.'.HAPS_PROVIDER_NAME.HAPS_PROVIDER_LAST_TEST_SUCCESS, false)
            ->andReturn(false);

        $service = makeHapsService(settings: $settings);
        $snapshot = $service->providerSnapshot(HAPS_PROVIDER_NAME);

        expect($snapshot->health)->toBe(ToolHealthState::FAILING);
    });
});
