<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\AI\Enums\LifecycleAction;
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

test('view-only operators cannot execute any lifecycle action', function (): void {
    $company = Company::factory()->create();
    $viewer = controlPlaneViewOnlyUser($company);

    $this->actingAs($viewer);

    Livewire::test(ControlPlane::class)
        ->assertDontSeeHtml('wire:click="executeLifecycleAction"')
        ->assertSeeHtml('wire:click="previewLifecycleAction"');

    foreach (LifecycleAction::cases() as $action) {
        expect(fn () => Livewire::test(ControlPlane::class)
            ->set('lifecycleAction', $action->value)
            ->call('executeLifecycleAction'))
            ->toThrow(AuthorizationDeniedException::class);
    }

    expect(LifecycleRequest::query()->count())->toBe(0);
});

test('control-plane managers can execute lifecycle actions', function (): void {
    $manager = createAdminUser();

    Livewire::actingAs($manager)
        ->test(ControlPlane::class)
        ->assertSeeHtml('wire:click="executeLifecycleAction"')
        ->set('lifecycleAction', LifecycleAction::SweepOperations->value)
        ->set('lifecycleStaleMinutes', 30)
        ->call('executeLifecycleAction')
        ->assertSet('lifecycleError', '');

    $request = LifecycleRequest::query()->sole();

    expect($request->action)->toBe(LifecycleAction::SweepOperations)
        ->and($request->requested_by)->toBe($manager->id);
});
