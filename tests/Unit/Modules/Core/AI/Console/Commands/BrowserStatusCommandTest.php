<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\BrowserSessionState;
use App\Modules\Core\AI\Enums\BrowserSessionStatus;
use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->manager = Mockery::mock(BrowserSessionManager::class);
    $this->app->instance(BrowserSessionManager::class, $this->manager);
});

function makeSessionState(array $overrides = []): BrowserSessionState
{
    return new BrowserSessionState(
        sessionId: $overrides['sessionId'] ?? 'bs_test_cmd',
        agentEmployeeId: $overrides['agentEmployeeId'] ?? 1,
        actingForUserId: $overrides['actingForUserId'] ?? 10,
        companyId: $overrides['companyId'] ?? 1,
        status: $overrides['status'] ?? BrowserSessionStatus::Ready,
        headless: $overrides['headless'] ?? true,
        activeTabId: $overrides['activeTabId'] ?? null,
        currentUrl: $overrides['currentUrl'] ?? 'https://example.com',
        tabs: $overrides['tabs'] ?? [],
        lastSnapshotRef: $overrides['lastSnapshotRef'] ?? null,
        failureReason: $overrides['failureReason'] ?? null,
        createdAt: $overrides['createdAt'] ?? '2026-01-01T00:00:00+00:00',
        lastActivityAt: $overrides['lastActivityAt'] ?? '2026-01-01T00:01:00+00:00',
        expiresAt: $overrides['expiresAt'] ?? '2026-01-01T00:06:00+00:00',
    );
}

describe('blb:ai:browser:status', function () {
    it('fails without --session or --company', function () {
        $this->artisan('blb:ai:browser:status')
            ->expectsOutputToContain('Provide')
            ->assertFailed();
    });

    it('shows session detail for --session option', function () {
        $this->manager->shouldReceive('getSessionState')
            ->with('bs_test_cmd')
            ->andReturn(makeSessionState());

        $this->artisan('blb:ai:browser:status', ['--session' => 'bs_test_cmd'])
            ->expectsOutputToContain('bs_test_cmd')
            ->expectsOutputToContain('Ready')
            ->assertSuccessful();
    });

    it('fails when session not found', function () {
        $this->manager->shouldReceive('getSessionState')
            ->with('bs_gone')
            ->andReturn(null);

        $this->artisan('blb:ai:browser:status', ['--session' => 'bs_gone'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('shows failure reason for failed session', function () {
        $this->manager->shouldReceive('getSessionState')
            ->andReturn(makeSessionState([
                'status' => BrowserSessionStatus::Failed,
                'failureReason' => 'Process crashed',
            ]));

        $this->artisan('blb:ai:browser:status', ['--session' => 'bs_failed'])
            ->expectsOutputToContain('Process crashed')
            ->assertSuccessful();
    });

    it('lists active sessions for --company option', function () {
        $this->manager->shouldReceive('getActiveSessionsForCompany')
            ->with(1)
            ->andReturn([
                makeSessionState(['sessionId' => 'bs_1']),
                makeSessionState(['sessionId' => 'bs_2', 'headless' => false]),
            ]);

        $this->artisan('blb:ai:browser:status', ['--company' => 1])
            ->expectsOutputToContain('bs_1')
            ->expectsOutputToContain('bs_2')
            ->assertSuccessful();
    });

    it('reports when no active sessions for company', function () {
        $this->manager->shouldReceive('getActiveSessionsForCompany')
            ->with(99)
            ->andReturn([]);

        $this->artisan('blb:ai:browser:status', ['--company' => 99])
            ->expectsOutputToContain('No active browser sessions')
            ->assertSuccessful();
    });
});
