<?php

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Listeners\AuthListener;
use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Auth\Events\Login;

function createUserWithDuplicateRoleAssignments(): User
{
    setupAuthzRoles();

    $currentCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $currentCompany->id]);
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    foreach ([$currentCompany, $otherCompany] as $company) {
        PrincipalRole::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    return $user;
}

it('snapshots actor roles for the current company without duplicate role codes', function (): void {
    $user = createUserWithDuplicateRoleAssignments();

    $this->actingAs($user);
    app()->forgetInstance(RequestContext::class);

    $context = app(RequestContext::class);

    expect($context->actorRole)->toBe('core_admin');
});

it('snapshots login event actor roles for the event user company without duplicate role codes', function (): void {
    $user = createUserWithDuplicateRoleAssignments();

    app()->instance(RequestContext::class, new RequestContext(
        traceId: '7K9M2F4Q8XDW',
        ipAddress: '127.0.0.1',
        url: 'https://test.example.com/login',
        actorType: null,
        actorId: null,
        companyId: null,
        actorRole: null,
    ));

    app(AuthListener::class)->handleLogin(new Login('web', $user, false));

    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);

    expect(AuditAction::query()->where('event', 'auth.login')->value('actor_role'))
        ->toBe('core_admin');
});
