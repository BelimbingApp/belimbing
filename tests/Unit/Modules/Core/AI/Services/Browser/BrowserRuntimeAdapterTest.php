<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\BrowserSessionStatus;
use App\Modules\Core\AI\Models\BrowserSession;
use App\Modules\Core\AI\Services\Browser\BrowserRuntimeAdapter;
use App\Modules\Core\AI\Services\Browser\BrowserSessionException;
use App\Modules\Core\AI\Services\Browser\BrowserSessionRepository;
use App\Modules\Core\AI\Services\Browser\PlaywrightRunner;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Create a BrowserSession model with the given status (in-memory, not persisted).
 */
function makeAdapterSession(BrowserSessionStatus $status, array $attrs = []): BrowserSession
{
    $session = new BrowserSession;
    $session->id = $attrs['id'] ?? 'bs_adapter_test';
    $session->employee_id = $attrs['employee_id'] ?? 1;
    $session->company_id = $attrs['company_id'] ?? 1;
    $session->status = $status;
    $session->headless = $attrs['headless'] ?? true;
    $session->current_url = $attrs['current_url'] ?? null;
    $session->active_tab_id = $attrs['active_tab_id'] ?? null;
    $session->tabs = $attrs['tabs'] ?? null;
    $session->page_state = $attrs['page_state'] ?? null;

    return $session;
}

beforeEach(function () {
    $this->runner = Mockery::mock(PlaywrightRunner::class);
    $this->repository = Mockery::mock(BrowserSessionRepository::class);

    $this->adapter = new BrowserRuntimeAdapter($this->runner, $this->repository);
});

describe('execute', function () {
    it('transitions Busy, executes, then transitions idle', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready);

        $this->repository->shouldReceive('markBusy')->with($session)->once()->andReturn(true);
        $this->runner->shouldReceive('execute')
            ->with('navigate', Mockery::on(fn ($args) => $args['url'] === 'https://example.com' && $args['headless'] === true))
            ->andReturn(['ok' => true, 'action' => 'navigate', 'url' => 'https://example.com']);
        $this->repository->shouldReceive('updatePageState')->once();
        $this->repository->shouldReceive('markIdle')->with($session)->once()->andReturn(true);

        $result = $this->adapter->execute($session, 'navigate', ['url' => 'https://example.com']);

        expect($result['ok'])->toBeTrue()
            ->and($result['url'])->toBe('https://example.com');
    });

    it('throws when session is not actionable', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Closed);

        expect(fn () => $this->adapter->execute($session, 'navigate'))
            ->toThrow(BrowserSessionException::class, 'Cannot execute');
    });

    it('marks session Failed on RuntimeException', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andThrow(new RuntimeException('Process crashed'));
        $this->repository->shouldReceive('markFailed')->with($session, 'Process crashed')->once();

        expect(fn () => $this->adapter->execute($session, 'navigate'))
            ->toThrow(RuntimeException::class, 'Process crashed');
    });

    it('injects headless mode from session state', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready, ['headless' => false]);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')
            ->with('navigate', Mockery::on(fn ($args) => $args['headless'] === false))
            ->andReturn(['ok' => true, 'action' => 'navigate']);
        $this->repository->shouldReceive('updatePageState')->once();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $result = $this->adapter->execute($session, 'navigate', ['url' => 'https://example.com']);

        expect($result['ok'])->toBeTrue();
    });
});

describe('page state updates', function () {
    it('updates current URL after navigate', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andReturn([
            'ok' => true, 'action' => 'navigate', 'url' => 'https://example.com/page',
        ]);
        $this->repository->shouldReceive('updatePageState')
            ->withArgs(function ($s, $activeTabId, $currentUrl) {
                return $currentUrl === 'https://example.com/page';
            })
            ->once();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $this->adapter->execute($session, 'navigate', ['url' => 'https://example.com/page']);
    });

    it('updates tabs after open', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andReturn([
            'ok' => true,
            'action' => 'open',
            'tab_id' => 'tab2',
            'url' => 'https://example.com',
            'tabs' => [['tab_id' => 'tab1'], ['tab_id' => 'tab2']],
        ]);
        $this->repository->shouldReceive('updatePageState')
            ->withArgs(function ($s, $activeTabId, $currentUrl, $tabs) {
                return $activeTabId === 'tab2' && count($tabs) === 2;
            })
            ->once();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $this->adapter->execute($session, 'open', ['url' => 'https://example.com']);
    });

    it('merges element refs from snapshot', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andReturn([
            'ok' => true,
            'action' => 'snapshot',
            'refs' => ['e1' => '#btn', 'e2' => '#input'],
            'url' => 'https://example.com',
        ]);
        $this->repository->shouldReceive('updatePageState')
            ->withArgs(function ($s, $activeTabId, $currentUrl, $tabs, $pageState) {
                return isset($pageState['element_refs'])
                    && $pageState['element_refs']['e1'] === '#btn'
                    && isset($pageState['refs_captured_at']);
            })
            ->once();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $this->adapter->execute($session, 'snapshot', []);
    });

    it('does not update page state on failed result', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andReturn([
            'ok' => false, 'action' => 'navigate', 'error' => 'timeout',
        ]);
        $this->repository->shouldReceive('updatePageState')
            ->withArgs(function ($s, $activeTabId, $currentUrl) {
                // Page state should still be called but with unchanged values.
                return $currentUrl === null;
            })
            ->never();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $this->adapter->execute($session, 'navigate', ['url' => 'https://example.com']);
    });
});

describe('isAvailable', function () {
    it('delegates to runner isAvailable', function () {
        $this->runner->shouldReceive('isAvailable')->andReturn(true);

        expect($this->adapter->isAvailable())->toBeTrue();
    });

    it('returns false when runner unavailable', function () {
        $this->runner->shouldReceive('isAvailable')->andReturn(false);

        expect($this->adapter->isAvailable())->toBeFalse();
    });
});

describe('ref freshness validation', function () {
    it('rejects act when no element refs exist', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready, [
            'page_state' => [],
        ]);

        expect(fn () => $this->adapter->execute($session, 'act', ['kind' => 'click', 'ref' => 'e1']))
            ->toThrow(BrowserSessionException::class, 'no element refs available');
    });

    it('rejects act when refs belong to a different URL', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready, [
            'current_url' => 'https://example.com/page-b',
            'page_state' => [
                'element_refs' => ['e1' => '#btn'],
                'refs_url' => 'https://example.com/page-a',
                'refs_captured_at' => now()->toIso8601String(),
            ],
        ]);

        expect(fn () => $this->adapter->execute($session, 'act', ['kind' => 'click', 'ref' => 'e1']))
            ->toThrow(BrowserSessionException::class, 'different page');
    });

    it('rejects act when refs are stale (beyond configured threshold)', function () {
        config(['ai.tools.browser.ref_stale_seconds' => 60]);

        $session = makeAdapterSession(BrowserSessionStatus::Ready, [
            'current_url' => 'https://example.com',
            'page_state' => [
                'element_refs' => ['e1' => '#btn'],
                'refs_url' => 'https://example.com',
                'refs_captured_at' => now()->subSeconds(120)->toIso8601String(),
            ],
        ]);

        expect(fn () => $this->adapter->execute($session, 'act', ['kind' => 'click', 'ref' => 'e1']))
            ->toThrow(BrowserSessionException::class, 'refs are stale');
    });

    it('allows act when refs are fresh and URL matches', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready, [
            'current_url' => 'https://example.com',
            'page_state' => [
                'element_refs' => ['e1' => '#btn'],
                'refs_url' => 'https://example.com',
                'refs_captured_at' => now()->toIso8601String(),
            ],
        ]);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andReturn(['ok' => true, 'action' => 'act']);
        $this->repository->shouldReceive('updatePageState')->once();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $result = $this->adapter->execute($session, 'act', ['kind' => 'click', 'ref' => 'e1']);

        expect($result['ok'])->toBeTrue();
    });

    it('does not validate refs for non-act actions', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready, [
            'page_state' => [], // No refs — would fail for act, but navigate should pass.
        ]);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andReturn([
            'ok' => true, 'action' => 'navigate', 'url' => 'https://example.com',
        ]);
        $this->repository->shouldReceive('updatePageState')->once();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $result = $this->adapter->execute($session, 'navigate', ['url' => 'https://example.com']);

        expect($result['ok'])->toBeTrue();
    });
});

describe('ref invalidation after navigate', function () {
    it('clears element refs from page state after successful navigate', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready, [
            'page_state' => [
                'element_refs' => ['e1' => '#btn'],
                'refs_url' => 'https://example.com/old',
                'refs_captured_at' => now()->toIso8601String(),
            ],
        ]);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andReturn([
            'ok' => true, 'action' => 'navigate', 'url' => 'https://example.com/new',
        ]);
        $this->repository->shouldReceive('updatePageState')
            ->withArgs(function ($s, $activeTabId, $currentUrl, $tabs, $pageState) {
                return $currentUrl === 'https://example.com/new'
                    && ! isset($pageState['element_refs'])
                    && ! isset($pageState['refs_url'])
                    && ! isset($pageState['refs_captured_at']);
            })
            ->once();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $this->adapter->execute($session, 'navigate', ['url' => 'https://example.com/new']);
    });

    it('preserves refs after snapshot action', function () {
        $session = makeAdapterSession(BrowserSessionStatus::Ready);

        $this->repository->shouldReceive('markBusy')->andReturn(true);
        $this->runner->shouldReceive('execute')->andReturn([
            'ok' => true,
            'action' => 'snapshot',
            'refs' => ['e1' => '#btn'],
            'url' => 'https://example.com',
        ]);
        $this->repository->shouldReceive('updatePageState')
            ->withArgs(function ($s, $activeTabId, $currentUrl, $tabs, $pageState) {
                return isset($pageState['element_refs'])
                    && $pageState['element_refs']['e1'] === '#btn'
                    && isset($pageState['refs_captured_at']);
            })
            ->once();
        $this->repository->shouldReceive('markIdle')->andReturn(true);

        $this->adapter->execute($session, 'snapshot', []);
    });
});
