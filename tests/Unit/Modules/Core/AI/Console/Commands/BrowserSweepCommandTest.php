<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->manager = Mockery::mock(BrowserSessionManager::class);
    $this->app->instance(BrowserSessionManager::class, $this->manager);
});

describe('blb:ai:browser:sweep', function () {
    it('reports no stale sessions when none found', function () {
        $this->manager->shouldReceive('sweepStaleSessions')->once()->andReturn(0);

        $this->artisan('blb:ai:browser:sweep')
            ->expectsOutputToContain('No stale sessions')
            ->assertSuccessful();
    });

    it('reports count of expired sessions', function () {
        $this->manager->shouldReceive('sweepStaleSessions')->once()->andReturn(3);

        $this->artisan('blb:ai:browser:sweep')
            ->expectsOutputToContain('Expired 3 stale session(s)')
            ->assertSuccessful();
    });
});
