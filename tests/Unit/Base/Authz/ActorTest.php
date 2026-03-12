<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;

require_once __DIR__.'/../../../Support/Auth/FakeAuthenticatable.php';
require_once __DIR__.'/../../../Support/Auth/FakeCompanyScopedAuthenticatable.php';

it('builds a human user actor from a company scoped user', function (): void {
    $user = new FakeCompanyScopedAuthenticatable(42, 7);

    $actor = Actor::forUser($user);

    expect($actor->type)->toBe(PrincipalType::HUMAN_USER)
        ->and($actor->id)->toBe(42)
        ->and($actor->companyId)->toBe(7)
        ->and($actor->actingForUserId)->toBeNull();
});

it('builds an actor from a user company_id attribute when company scoped is unavailable', function (): void {
    $user = new FakeAuthenticatable(99, ['company_id' => '15']);

    $actor = Actor::forUser($user, PrincipalType::DIGITAL_WORKER, actingForUserId: 5, attributes: ['source' => 'test']);

    expect($actor->type)->toBe(PrincipalType::DIGITAL_WORKER)
        ->and($actor->id)->toBe(99)
        ->and($actor->companyId)->toBe(15)
        ->and($actor->actingForUserId)->toBe(5)
        ->and($actor->attributes)->toBe(['source' => 'test']);
});
