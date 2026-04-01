<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\BrowserSessionStatus;
use App\Modules\Core\AI\Models\BrowserSession;
use App\Modules\Core\AI\Services\Browser\BrowserSessionRepository;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\CreatesLaraFixtures;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, CreatesLaraFixtures::class);

beforeEach(function () {
    $this->repository = new BrowserSessionRepository;
    $fixture = $this->createLaraFixture();
    $this->employeeId = $fixture['employee']->id;
    $this->companyId = $fixture['company']->id;
});

describe('create', function () {
    it('creates a session with Opening status', function () {
        $session = $this->repository->create(
            employeeId: $this->employeeId,
            companyId: $this->companyId,
            headless: true,
            ttlSeconds: 300,
        );

        expect($session->id)->toStartWith('bs_')
            ->and($session->employee_id)->toBe($this->employeeId)
            ->and($session->company_id)->toBe($this->companyId)
            ->and($session->status)->toBe(BrowserSessionStatus::Opening)
            ->and($session->headless)->toBeTrue()
            ->and($session->expires_at)->not()->toBeNull();
    });

    it('creates unique session IDs', function () {
        $s1 = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $s2 = $this->repository->create($this->employeeId, $this->companyId, true, 300);

        expect($s1->id)->not()->toBe($s2->id);
    });
});

describe('find', function () {
    it('returns null for non-existent session', function () {
        expect($this->repository->find('bs_nonexistent'))->toBeNull();
    });

    it('finds existing session by ID', function () {
        $created = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $found = $this->repository->find($created->id);

        expect($found)->not()->toBeNull()
            ->and($found->id)->toBe($created->id);
    });
});

describe('findActiveForEmployee', function () {
    it('returns null when no active sessions exist', function () {
        expect($this->repository->findActiveForEmployee($this->employeeId, $this->companyId))->toBeNull();
    });

    it('returns the most recent active session for employee+company', function () {
        $s1 = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($s1);

        // Ensure s2 has a later created_at for deterministic ordering.
        $this->travel(1)->seconds();

        $s2 = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($s2);

        $found = $this->repository->findActiveForEmployee($this->employeeId, $this->companyId);

        expect($found->id)->toBe($s2->id);
    });

    it('does not return terminal sessions', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);
        $this->repository->markClosed($session);

        expect($this->repository->findActiveForEmployee($this->employeeId, $this->companyId))->toBeNull();
    });

    it('does not return sessions from a different company', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);

        $other = $this->createLaraFixture();

        expect($this->repository->findActiveForEmployee($this->employeeId, $other['company']->id))->toBeNull();
    });
});

describe('countActiveForCompany', function () {
    it('returns 0 for company with no sessions', function () {
        expect($this->repository->countActiveForCompany($this->companyId))->toBe(0);
    });

    it('counts only non-terminal sessions', function () {
        $employee2 = Employee::factory()->create([
            'company_id' => $this->companyId,
            'status' => 'active',
        ]);

        $s1 = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($s1);

        $s2 = $this->repository->create($employee2->id, $this->companyId, true, 300);
        $this->repository->markReady($s2);
        $this->repository->markClosed($s2);

        expect($this->repository->countActiveForCompany($this->companyId))->toBe(1);
    });
});

describe('state transitions', function () {
    it('transitions Opening → Ready', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);

        expect($this->repository->markReady($session))->toBeTrue()
            ->and($session->status)->toBe(BrowserSessionStatus::Ready);
    });

    it('transitions Ready → Busy', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);

        expect($this->repository->markBusy($session))->toBeTrue()
            ->and($session->status)->toBe(BrowserSessionStatus::Busy);
    });

    it('transitions Busy → Ready (idle)', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);
        $this->repository->markBusy($session);

        expect($this->repository->markIdle($session))->toBeTrue()
            ->and($session->status)->toBe(BrowserSessionStatus::Ready);
    });

    it('rejects Ready → Ready transition', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);

        expect($this->repository->markReady($session))->toBeFalse();
    });

    it('rejects Busy → Busy transition', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);
        $this->repository->markBusy($session);

        expect($this->repository->markBusy($session))->toBeFalse();
    });

    it('transitions to Failed from non-terminal', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);

        expect($this->repository->markFailed($session, 'Process crashed'))->toBeTrue()
            ->and($session->status)->toBe(BrowserSessionStatus::Failed)
            ->and($session->failure_reason)->toBe('Process crashed');
    });

    it('rejects transition from terminal to Failed', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);
        $this->repository->markClosed($session);

        expect($this->repository->markFailed($session, 'Too late'))->toBeFalse();
    });

    it('transitions to Closed from non-terminal', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);

        expect($this->repository->markClosed($session))->toBeTrue()
            ->and($session->status)->toBe(BrowserSessionStatus::Closed);
    });

    it('transitions to Expired from non-terminal', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);

        expect($this->repository->markExpired($session))->toBeTrue()
            ->and($session->status)->toBe(BrowserSessionStatus::Expired);
    });
});

describe('updatePageState', function () {
    it('updates tab and page state', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);

        $this->repository->updatePageState(
            session: $session,
            activeTabId: 'tab1',
            currentUrl: 'https://example.com',
            tabs: [['tab_id' => 'tab1', 'url' => 'https://example.com', 'title' => 'Example', 'is_active' => true]],
            pageState: ['element_refs' => ['e1' => '#btn']],
        );

        $refreshed = $this->repository->find($session->id);

        expect($refreshed->active_tab_id)->toBe('tab1')
            ->and($refreshed->current_url)->toBe('https://example.com')
            ->and($refreshed->tabs)->toBeArray()
            ->and($refreshed->tabs[0]['tab_id'])->toBe('tab1')
            ->and($refreshed->page_state['element_refs']['e1'])->toBe('#btn');
    });
});

describe('touchActivity', function () {
    it('extends expiry time', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 10);
        $originalExpiry = $session->expires_at->copy();

        // Advance and touch.
        $this->repository->touchActivity($session, 600);

        $refreshed = $this->repository->find($session->id);

        expect($refreshed->expires_at->gt($originalExpiry))->toBeTrue();
    });
});

describe('findStaleSessions', function () {
    it('returns sessions past their expiry', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);

        // Manually set expires_at to the past.
        BrowserSession::query()
            ->where('id', $session->id)
            ->update(['expires_at' => now()->subMinute()]);

        $stale = $this->repository->findStaleSessions();

        expect($stale)->toHaveCount(1)
            ->and($stale->first()->id)->toBe($session->id);
    });

    it('does not return terminal sessions', function () {
        $session = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($session);
        $this->repository->markClosed($session);

        BrowserSession::query()
            ->where('id', $session->id)
            ->update(['expires_at' => now()->subMinute()]);

        expect($this->repository->findStaleSessions())->toHaveCount(0);
    });
});

describe('getActiveSessionsForCompany', function () {
    it('returns active sessions ordered by last activity', function () {
        $employee2 = Employee::factory()->create([
            'company_id' => $this->companyId,
            'status' => 'active',
        ]);

        $s1 = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($s1);

        $s2 = $this->repository->create($employee2->id, $this->companyId, true, 300);
        $this->repository->markReady($s2);

        $sessions = $this->repository->getActiveSessionsForCompany($this->companyId);

        expect($sessions)->toHaveCount(2);
    });

    it('excludes sessions from other companies', function () {
        $other = $this->createLaraFixture();

        $s1 = $this->repository->create($this->employeeId, $this->companyId, true, 300);
        $this->repository->markReady($s1);

        $s2 = $this->repository->create($other['employee']->id, $other['company']->id, true, 300);
        $this->repository->markReady($s2);

        expect($this->repository->getActiveSessionsForCompany($this->companyId))->toHaveCount(1);
    });
});
