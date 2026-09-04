<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;

require_once __DIR__.'/../../../Support/Auth/FakeAuthenticatable.php';
require_once __DIR__.'/../../../Support/Auth/FakeCompanyScopedAuthenticatable.php';

it('builds a human user actor from a company scoped user', function (): void {
    $user = new FakeCompanyScopedAuthenticatable(42, 7);

    $actor = Actor::forUser($user);

    expect($actor->type)->toBe(PrincipalType::USER)
        ->and($actor->id)->toBe(42)
        ->and($actor->companyId)->toBe(7)
        ->and($actor->actingForUserId)->toBeNull();
});

it('builds an actor from a user company_id attribute when company scoped is unavailable', function (): void {
    $user = new FakeAuthenticatable(99, ['company_id' => '15']);

    $actor = Actor::forUser($user, PrincipalType::AGENT, actingForUserId: 5, attributes: ['source' => 'test']);

    expect($actor->type)->toBe(PrincipalType::AGENT)
        ->and($actor->id)->toBe(99)
        ->and($actor->companyId)->toBe(15)
        ->and($actor->actingForUserId)->toBe(5)
        ->and($actor->attributes)->toBe(['source' => 'test']);
});

it('requires a company for user and agent actors', function (): void {
    $user = new Actor(PrincipalType::USER, 1, companyId: null, tenantId: 9);
    $agent = new Actor(PrincipalType::AGENT, 2, companyId: null, actingForUserId: 1, tenantId: 9);

    expect($user->validate())->not->toBeNull()
        ->and($agent->validate())->not->toBeNull();
});

it('allows process actors to omit company for tenant-scoped work', function (): void {
    $scheduler = new Actor(PrincipalType::SCHEDULER, 70, companyId: null, tenantId: 9);
    $queue = new Actor(PrincipalType::QUEUE, 71, companyId: null, tenantId: 9);
    $console = new Actor(PrincipalType::CONSOLE, 72, companyId: null, tenantId: 9);

    expect($scheduler->validate())->toBeNull()
        ->and($queue->validate())->toBeNull()
        ->and($console->validate())->toBeNull();
});

it('still requires a positive id for process actors', function (): void {
    $scheduler = new Actor(PrincipalType::SCHEDULER, 0, companyId: null, tenantId: 9);

    expect($scheduler->validate())->not->toBeNull();
});
