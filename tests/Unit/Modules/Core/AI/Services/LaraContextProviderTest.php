<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\Services\KnowledgeNavigator;
use App\Modules\Core\AI\Services\LaraContextProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../../../../Support/Auth/FakeAuthenticatable.php';
require_once __DIR__.'/../../../../../Support/Auth/FakeCompanyScopedAuthenticatable.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->navigator = Mockery::mock(KnowledgeNavigator::class);
    $this->service = new LaraContextProvider($this->navigator);
});

afterEach(function (): void {
    auth()->logout();
});

describe('contextForCurrentUser', function (): void {
    it('uses CompanyScoped company resolution for users without an employee link', function (): void {
        auth()->setUser(new FakeCompanyScopedAuthenticatable(42, 77));

        $context = $this->service->contextForCurrentUser();

        expect($context['actor']['user_id'])->toBe(42)
            ->and($context['actor']['company_id'])->toBe(77)
            ->and($context['providers'])->toBe([]);
    });

    it('falls back to the authenticatable company_id attribute when available', function (): void {
        auth()->setUser(new FakeAuthenticatable(15, ['company_id' => 88]));

        $context = $this->service->contextForCurrentUser();

        expect($context['actor']['user_id'])->toBe(15)
            ->and($context['actor']['company_id'])->toBe(88)
            ->and($context['providers'])->toBe([]);
    });
});
