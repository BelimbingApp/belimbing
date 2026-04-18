<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;

beforeEach(function (): void {
    setupAuthzRoles();
});

it('denies when actor context is invalid', function (): void {
    $service = app(AuthorizationService::class);

    $decision = $service->can(new Actor(PrincipalType::USER, 0, null), 'core.user.view');

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_INVALID_ACTOR_CONTEXT);
});

it('denies when Agent has no acting_for_user_id', function (): void {
    $service = app(AuthorizationService::class);

    $decision = $service->can(
        new Actor(PrincipalType::AGENT, 1, 10),
        'core.user.view'
    );

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_INVALID_ACTOR_CONTEXT);
});

it('denies when resource company is outside actor scope', function (): void {
    $service = app(AuthorizationService::class);

    $decision = $service->can(
        new Actor(PrincipalType::USER, 888, 10),
        'core.user.view',
        new ResourceContext('users', 1, 20)
    );

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_COMPANY_SCOPE);
});

it('denies unknown capability and authorize throws', function (): void {
    $service = app(AuthorizationService::class);

    $decision = $service->can(new Actor(PrincipalType::USER, 999, 10), 'core.user.manage');

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_UNKNOWN_CAPABILITY);

    expect(fn () => $service->authorize(new Actor(PrincipalType::USER, 999, 10), 'core.user.manage'))
        ->toThrow(AuthorizationDeniedException::class);
});

it('allows when user has capability via role', function (): void {
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => 10,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => 42,
        'role_id' => $role->id,
    ]);

    $service = app(AuthorizationService::class);

    $decision = $service->can(
        new Actor(PrincipalType::USER, 42, 10),
        'core.user.view'
    );

    expect($decision->allowed)->toBeTrue();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::ALLOWED);
    expect($decision->appliedPolicies)->toContain('grant_all');
});

it('allows when user has explicit direct capability grant', function (): void {
    PrincipalCapability::query()->create([
        'company_id' => 10,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => 55,
        'capability_key' => 'core.company.view',
        'is_allowed' => true,
    ]);

    $service = app(AuthorizationService::class);

    $decision = $service->can(
        new Actor(PrincipalType::USER, 55, 10),
        'core.company.view'
    );

    expect($decision->allowed)->toBeTrue();
    expect($decision->appliedPolicies)->toContain('direct_capability');
});

it('denies when user has explicit direct deny even with role grant', function (): void {
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => 10,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => 60,
        'role_id' => $role->id,
    ]);

    PrincipalCapability::query()->create([
        'company_id' => 10,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => 60,
        'capability_key' => 'core.user.delete',
        'is_allowed' => false,
    ]);

    $service = app(AuthorizationService::class);

    $decision = $service->can(
        new Actor(PrincipalType::USER, 60, 10),
        'core.user.delete'
    );

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_EXPLICITLY);
});

it('denies when user has no grants at all', function (): void {
    $service = app(AuthorizationService::class);

    $decision = $service->can(
        new Actor(PrincipalType::USER, 999, 10),
        'core.user.view'
    );

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
});

it('filters allowed resources correctly', function (): void {
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => 10,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => 70,
        'role_id' => $role->id,
    ]);

    $service = app(AuthorizationService::class);
    $actor = new Actor(PrincipalType::USER, 70, 10);

    $resources = [
        new ResourceContext('users', 1, 10),
        new ResourceContext('users', 2, 20),  // different company
        new ResourceContext('users', 3, 10),
    ];

    $allowed = $service->filterAllowed($actor, 'core.user.view', $resources);

    expect($allowed)->toHaveCount(2);
    expect($allowed[0]->id)->toBe(1);
    expect($allowed[1]->id)->toBe(3);
});

it('allows agent with valid acting_for_user_id', function (): void {
    PrincipalCapability::query()->create([
        'company_id' => 10,
        'principal_type' => PrincipalType::AGENT->value,
        'principal_id' => 100,
        'capability_key' => 'ai.agent.execute',
        'is_allowed' => true,
    ]);

    $service = app(AuthorizationService::class);

    $decision = $service->can(
        new Actor(PrincipalType::AGENT, 100, 10, actingForUserId: 42),
        'ai.agent.execute'
    );

    expect($decision->allowed)->toBeTrue();
});

it('records applied policy trail in decision', function (): void {
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => 10,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => 80,
        'role_id' => $role->id,
    ]);

    $service = app(AuthorizationService::class);

    $decision = $service->can(
        new Actor(PrincipalType::USER, 80, 10),
        'core.user.view'
    );

    expect($decision->allowed)->toBeTrue();
    expect($decision->appliedPolicies)->toContain('actor_context');
    expect($decision->appliedPolicies)->toContain('capability_registry');
    expect($decision->appliedPolicies)->toContain('company_scope');
    expect($decision->appliedPolicies)->toContain('grant');
});
