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
use Illuminate\Support\Facades\Route;

const ACTOR_ROLE_TRACE_ID = '7K9M2F4Q8XDW';

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

it('stores a compact client label instead of a full browser user agent', function (): void {
    $user = createUserWithDuplicateRoleAssignments();

    $this->actingAs($user);
    request()->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36');

    $context = RequestContext::fromRequest();

    expect($context->userAgent)->toBe('Chrome 148 / Windows');
});

it('snapshots login event actor roles for the event user company without duplicate role codes', function (): void {
    $user = createUserWithDuplicateRoleAssignments();

    app()->instance(RequestContext::class, new RequestContext(
        traceId: ACTOR_ROLE_TRACE_ID,
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

it('falls back to the authenticated request user for HTTP request audit actors', function (): void {
    $user = createUserWithDuplicateRoleAssignments();

    app()->instance(RequestContext::class, new RequestContext(
        traceId: ACTOR_ROLE_TRACE_ID,
        ipAddress: '127.0.0.1',
        url: 'https://test.example.com/audit-request-context',
        actorType: null,
        actorId: null,
        companyId: null,
        actorRole: null,
    ));

    Route::middleware('web')->get('/audit-request-context', fn () => 'ok')->name('audit.request-context');

    $this->actingAs($user)
        ->get('/audit-request-context')
        ->assertOk();

    flushActorRoleAuditBuffer();

    $action = AuditAction::query()
        ->where('event', 'http.request')
        ->where('trace_id', ACTOR_ROLE_TRACE_ID)
        ->latest('id')
        ->first();

    expect($action)->not->toBeNull();
    expect($action->actor_type)->toBe(PrincipalType::USER->value);
    expect($action->actor_id)->toBe($user->id);
    expect($action->company_id)->toBe($user->company_id);
});

function flushActorRoleAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);
}
