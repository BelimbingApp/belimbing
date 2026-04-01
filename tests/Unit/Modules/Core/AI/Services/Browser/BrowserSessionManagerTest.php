<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\BrowserSessionStatus;
use App\Modules\Core\AI\Models\BrowserSession;
use App\Modules\Core\AI\Services\Browser\BrowserContextFactory;
use App\Modules\Core\AI\Services\Browser\BrowserRuntimeAdapter;
use App\Modules\Core\AI\Services\Browser\BrowserSessionException;
use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use App\Modules\Core\AI\Services\Browser\BrowserSessionRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Helper to make a fake BrowserSession model (not persisted).
 */
function fakeSession(array $attrs = []): BrowserSession
{
    $session = new BrowserSession;
    $session->id = $attrs['id'] ?? 'bs_test_mgr';
    $session->employee_id = $attrs['employee_id'] ?? 1;
    $session->company_id = $attrs['company_id'] ?? 1;
    $session->status = $attrs['status'] ?? BrowserSessionStatus::Ready;
    $session->headless = $attrs['headless'] ?? true;
    $session->last_activity_at = now();
    $session->expires_at = now()->addMinutes(5);
    $session->created_at = now();

    return $session;
}

beforeEach(function () {
    $this->repository = Mockery::mock(BrowserSessionRepository::class);
    $this->runtimeAdapter = Mockery::mock(BrowserRuntimeAdapter::class);
    $this->contextFactory = Mockery::mock(BrowserContextFactory::class);

    $this->manager = new BrowserSessionManager(
        $this->repository,
        $this->runtimeAdapter,
        $this->contextFactory,
    );
});

describe('isAvailable', function () {
    it('returns true when config enabled, factory available, and runtime available', function () {
        config()->set('ai.tools.browser.enabled', true);
        $this->contextFactory->shouldReceive('isAvailable')->andReturn(true);
        $this->runtimeAdapter->shouldReceive('isAvailable')->andReturn(true);

        expect($this->manager->isAvailable())->toBeTrue();
    });

    it('returns false when config disabled', function () {
        config()->set('ai.tools.browser.enabled', false);
        $this->contextFactory->shouldReceive('isAvailable')->andReturn(true);
        $this->runtimeAdapter->shouldReceive('isAvailable')->andReturn(true);

        expect($this->manager->isAvailable())->toBeFalse();
    });

    it('returns false when factory unavailable', function () {
        config()->set('ai.tools.browser.enabled', true);
        $this->contextFactory->shouldReceive('isAvailable')->andReturn(false);
        $this->runtimeAdapter->shouldReceive('isAvailable')->andReturn(true);

        expect($this->manager->isAvailable())->toBeFalse();
    });

    it('returns false when runtime unavailable', function () {
        config()->set('ai.tools.browser.enabled', true);
        $this->contextFactory->shouldReceive('isAvailable')->andReturn(true);
        $this->runtimeAdapter->shouldReceive('isAvailable')->andReturn(false);

        expect($this->manager->isAvailable())->toBeFalse();
    });
});

describe('open', function () {
    beforeEach(function () {
        config()->set('ai.tools.browser.enabled', true);
        config()->set('ai.tools.browser.max_contexts_per_company', 3);
        config()->set('ai.tools.browser.context_idle_timeout_seconds', 300);
        $this->contextFactory->shouldReceive('isAvailable')->andReturn(true);
        $this->runtimeAdapter->shouldReceive('isAvailable')->andReturn(true);
    });

    it('reuses an existing active session for the same agent+company', function () {
        $existing = fakeSession();
        $this->repository->shouldReceive('findActiveForEmployee')->with(1, 1)->andReturn($existing);
        $this->repository->shouldReceive('touchActivity')->once();

        $result = $this->manager->open(1, 1, true);

        expect($result->id)->toBe('bs_test_mgr');
    });

    it('creates a new session when none exists', function () {
        $newSession = fakeSession(['id' => 'bs_new']);
        $this->repository->shouldReceive('findActiveForEmployee')->andReturn(null);
        $this->repository->shouldReceive('countActiveForCompany')->with(1)->andReturn(0);
        $this->repository->shouldReceive('create')->andReturn($newSession);
        $this->repository->shouldReceive('markReady')->once();

        $result = $this->manager->open(1, 1, true);

        expect($result->id)->toBe('bs_new');
    });

    it('throws when browser is not available', function () {
        config()->set('ai.tools.browser.enabled', false);

        expect(fn () => $this->manager->open(1, 1, true))
            ->toThrow(BrowserSessionException::class, 'not available');
    });

    it('throws when concurrency limit reached', function () {
        $this->repository->shouldReceive('findActiveForEmployee')->andReturn(null);
        $this->repository->shouldReceive('countActiveForCompany')->with(1)->andReturn(3);

        expect(fn () => $this->manager->open(1, 1, true))
            ->toThrow(BrowserSessionException::class, 'maximum');
    });
});

describe('executeAction', function () {
    it('delegates to runtime adapter and extends TTL', function () {
        $session = fakeSession();
        $this->repository->shouldReceive('find')->with('bs_test_mgr')->andReturn($session);
        $this->runtimeAdapter->shouldReceive('execute')
            ->with($session, 'navigate', ['url' => 'https://example.com'])
            ->andReturn(['ok' => true, 'action' => 'navigate', 'url' => 'https://example.com']);
        $this->repository->shouldReceive('touchActivity')->once();

        $result = $this->manager->executeAction('bs_test_mgr', 'navigate', ['url' => 'https://example.com']);

        expect($result['ok'])->toBeTrue();
    });

    it('throws when session not found', function () {
        $this->repository->shouldReceive('find')->andReturn(null);

        expect(fn () => $this->manager->executeAction('bs_gone', 'navigate'))
            ->toThrow(BrowserSessionException::class, 'not found');
    });

    it('expires and throws for expired sessions', function () {
        $session = fakeSession();
        $session->expires_at = now()->subMinute();
        $this->repository->shouldReceive('find')->andReturn($session);
        $this->repository->shouldReceive('markExpired')->once()->andReturn(true);

        expect(fn () => $this->manager->executeAction('bs_test_mgr', 'navigate'))
            ->toThrow(BrowserSessionException::class, 'expired');
    });
});

describe('close', function () {
    it('marks session as closed', function () {
        $session = fakeSession();
        $this->repository->shouldReceive('find')->with('bs_test_mgr')->andReturn($session);
        $this->repository->shouldReceive('markClosed')->with($session)->once()->andReturn(true);

        $this->manager->close('bs_test_mgr');
    });

    it('throws when session not found', function () {
        $this->repository->shouldReceive('find')->andReturn(null);

        expect(fn () => $this->manager->close('bs_gone'))
            ->toThrow(BrowserSessionException::class, 'not found');
    });
});

describe('sweepStaleSessions', function () {
    it('expires stale sessions and returns count', function () {
        $stale = new EloquentCollection([fakeSession(['id' => 'bs_s1']), fakeSession(['id' => 'bs_s2'])]);
        $this->repository->shouldReceive('findStaleSessions')->andReturn($stale);
        $this->repository->shouldReceive('markExpired')->twice()->andReturn(true);

        expect($this->manager->sweepStaleSessions())->toBe(2);
    });

    it('returns zero when no stale sessions', function () {
        $this->repository->shouldReceive('findStaleSessions')->andReturn(new EloquentCollection);

        expect($this->manager->sweepStaleSessions())->toBe(0);
    });
});

describe('getSession', function () {
    it('returns session by ID', function () {
        $session = fakeSession();
        $this->repository->shouldReceive('find')->with('bs_test_mgr')->andReturn($session);

        expect($this->manager->getSession('bs_test_mgr'))->toBe($session);
    });

    it('returns null for missing session', function () {
        $this->repository->shouldReceive('find')->andReturn(null);

        expect($this->manager->getSession('bs_gone'))->toBeNull();
    });
});

describe('getSessionState', function () {
    it('returns DTO for existing session', function () {
        $session = fakeSession();
        $session->tabs = [['tab_id' => 't1', 'url' => 'https://example.com', 'title' => 'E', 'is_active' => true]];
        $session->current_url = 'https://example.com';
        $session->page_state = ['refs_captured_at' => '2026-01-01'];
        $this->repository->shouldReceive('find')->andReturn($session);

        $state = $this->manager->getSessionState('bs_test_mgr');

        expect($state)->not()->toBeNull()
            ->and($state->sessionId)->toBe('bs_test_mgr')
            ->and($state->currentUrl)->toBe('https://example.com')
            ->and($state->tabs)->toHaveCount(1)
            ->and($state->lastSnapshotRef)->toBe('2026-01-01');
    });

    it('returns null for missing session', function () {
        $this->repository->shouldReceive('find')->andReturn(null);

        expect($this->manager->getSessionState('bs_gone'))->toBeNull();
    });
});

describe('getActiveSessionsForCompany', function () {
    it('returns DTOs for active sessions', function () {
        $sessions = new EloquentCollection([fakeSession(['id' => 'bs_1']), fakeSession(['id' => 'bs_2'])]);
        $this->repository->shouldReceive('getActiveSessionsForCompany')->with(1)->andReturn($sessions);

        $states = $this->manager->getActiveSessionsForCompany(1);

        expect($states)->toHaveCount(2)
            ->and($states[0]->sessionId)->toBe('bs_1')
            ->and($states[1]->sessionId)->toBe('bs_2');
    });
});
