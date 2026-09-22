<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\AI\Enums\LifecycleAction;
use App\Core\AI\Enums\LifecycleActionStatus;
use App\Core\AI\Livewire\ControlPlane;
use App\Core\AI\Models\LifecycleRequest;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Livewire\Livewire;

function controlPlaneViewOnlyUser(Company $company): User
{
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.ai.control-plane.view',
        'is_allowed' => true,
    ]);

    app(TenantContext::class)->set((int) $user->tenant_id);

    return $user;
}

function controlPlaneAiOperator(Company $company): User
{
    setupAuthzRoles();

    $user = User::factory()->create(['company_id' => $company->id]);
    $role = Role::query()->where('code', 'ai_operator')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    app(TenantContext::class)->set((int) $user->tenant_id);

    return $user;
}

test('view-only operators cannot execute any lifecycle action', function (): void {
    $company = Company::factory()->create();
    $viewer = controlPlaneViewOnlyUser($company);

    $this->actingAs($viewer);

    Livewire::test(ControlPlane::class)
        ->assertDontSeeHtml('wire:click="executeLifecycleAction"')
        ->assertSeeHtml('wire:click="previewLifecycleAction"')
        ->assertSee('Lifecycle Controls');

    foreach (LifecycleAction::cases() as $action) {
        expect(fn () => Livewire::test(ControlPlane::class)
            ->set('lifecycleAction', $action->value)
            ->call('executeLifecycleAction'))
            ->toThrow(AuthorizationDeniedException::class);
    }

    expect(LifecycleRequest::query()->count())->toBe(0);
});

test('non-platform AI operators cannot view preview or execute platform lifecycle operations', function (): void {
    [, $company] = createTenantWithCompany(['name' => 'Non-platform Lifecycle Tenant']);
    $operator = controlPlaneAiOperator($company);

    LifecycleRequest::query()->create([
        'id' => 'lc_global_secret',
        'action' => LifecycleAction::SweepOperations,
        'scope' => ['stale_minutes' => 30],
        'status' => LifecycleActionStatus::Completed,
    ]);

    $component = Livewire::actingAs($operator)->test(ControlPlane::class);

    $component
        ->assertDontSee('Lifecycle Controls')
        ->assertDontSee('lc_global_secret')
        ->assertSet('recentLifecycleRequests', [])
        ->call('setActiveTab', 'lifecycle')
        ->assertSet('activeTab', 'inspector');

    expect(fn () => Livewire::actingAs($operator)
        ->test(ControlPlane::class)
        ->set('lifecycleAction', LifecycleAction::SweepOperations->value)
        ->call('previewLifecycleAction'))
        ->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => Livewire::actingAs($operator)
            ->test(ControlPlane::class)
            ->call('loadRecentLifecycleRequests'))
        ->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => Livewire::actingAs($operator)
            ->test(ControlPlane::class)
            ->set('lifecycleAction', LifecycleAction::SweepOperations->value)
            ->call('executeLifecycleAction'))
        ->toThrow(AuthorizationDeniedException::class);

    expect(LifecycleRequest::query()->count())->toBe(1);
});

test('platform AI operators can view and execute lifecycle actions', function (): void {
    $company = provisionPlatformOperatorCompany('Lifecycle Platform Operator');
    $manager = controlPlaneAiOperator($company);

    Livewire::actingAs($manager)
        ->test(ControlPlane::class)
        ->assertSee('Lifecycle Controls')
        ->assertSeeHtml('wire:click="previewLifecycleAction"')
        ->assertSeeHtml('wire:click="executeLifecycleAction"')
        ->set('lifecycleAction', LifecycleAction::SweepOperations->value)
        ->set('lifecycleStaleMinutes', 30)
        ->call('executeLifecycleAction')
        ->assertSet('lifecycleError', '');

    $request = LifecycleRequest::query()->sole();

    expect($request->action)->toBe(LifecycleAction::SweepOperations)
        ->and($request->requested_by)->toBe($manager->id);
});
