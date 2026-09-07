<?php

use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Session\Livewire\Sessions\Index;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * A session row names no tenant, so the boundary is the user behind it (#895).
 * Before this, any holder of admin.system.session.manage could read every
 * tenant's user names, IPs and agents, and log another tenant's users out.
 */

function sessionsRow(string $id, ?int $userId, string $agent): string
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '198.51.100.7',
        'user_agent' => $agent,
        'payload' => base64_encode('probe'),
        'last_activity' => now()->getTimestamp(),
    ]);

    return $id;
}

/** The recorder buffers; the rows land on flush, which no Livewire test triggers. */
function sessionsFlushAudit(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

function sessionsTerminationRows(): int
{
    sessionsFlushAudit();

    return DB::table('base_audit_actions')->where('event', 'session.terminated')->count();
}

function sessionsGrant(User $user, string $capability): void
{
    PrincipalCapability::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => $capability,
        'is_allowed' => true,
    ]);
}

/**
 * A viewer in tenant B, plus a user of tenant A whose session they must not
 * see. The ambient tenant is left set to B.
 *
 * @return array{viewer: User, foreignUser: User, companyB: int}
 */
function sessionsForeignFixture(string $label): array
{
    [, $companyA] = createTenantWithCompany(['name' => $label.' Tenant A']);
    $foreignUser = User::factory()->create([
        'company_id' => $companyA->id, 'name' => $label.' Tenant A Subject',
    ]);

    [$tenantB, $companyB] = createTenantWithCompany(['name' => $label.' Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);

    $viewer = User::factory()->create([
        'company_id' => $companyB->id, 'name' => $label.' Tenant B Admin',
    ]);
    $role = Role::query()->where('code', 'tenant_owner')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $companyB->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $role->id,
    ]);

    return ['viewer' => $viewer, 'foreignUser' => $foreignUser, 'companyB' => (int) $companyB->id];
}

it('lists only sessions of users in the ambient tenant', function (): void {
    $f = sessionsForeignFixture('List');
    sessionsRow('sess-foreign-list', (int) $f['foreignUser']->id, 'foreign-agent-marker');
    sessionsRow('sess-home-list', (int) $f['viewer']->id, 'home-agent-marker');

    Livewire::actingAs($f['viewer'])
        ->test(Index::class)
        ->assertOk()
        ->assertSee('home-agent-marker')
        ->assertDontSee('foreign-agent-marker')
        ->assertDontSee($f['foreignUser']->name);
});

it('keeps the list tenant-scoped when a non-operator forces allTenants over the wire', function (): void {
    $f = sessionsForeignFixture('Forced');
    sessionsRow('sess-foreign-forced', (int) $f['foreignUser']->id, 'foreign-forced-marker');
    sessionsRow('sess-home-forced', (int) $f['viewer']->id, 'home-forced-marker');

    Livewire::actingAs($f['viewer'])
        ->test(Index::class)
        ->set('allTenants', true)
        ->assertSee('home-forced-marker')
        ->assertDontSee('foreign-forced-marker')
        // The toggle is not even offered to a tenant admin.
        ->assertDontSee(__('All tenants'));
});

it('shows guest sessions only to the platform operator with the toggle', function (): void {
    $f = sessionsForeignFixture('Guest');
    sessionsRow('sess-guest', null, 'guest-agent-marker');

    Livewire::actingAs($f['viewer'])
        ->test(Index::class)
        ->assertDontSee('guest-agent-marker');

    $operator = platformOperatorTenant();
    app(TenantContext::class)->set((int) $operator->id);
    $operatorUser = User::factory()->create([
        'company_id' => platformOperatorCompany()->id, 'name' => 'Operator Admin',
    ]);
    sessionsGrant($operatorUser, 'admin.system.session.list');

    Livewire::actingAs($operatorUser)
        ->test(Index::class)
        ->assertDontSee('guest-agent-marker')
        ->set('allTenants', true)
        ->assertSee('guest-agent-marker');
});

it('refuses to terminate a session belonging to another tenant', function (): void {
    $f = sessionsForeignFixture('Terminate');
    sessionsGrant($f['viewer'], 'admin.system.session.manage');
    $foreign = sessionsRow('sess-foreign-terminate', (int) $f['foreignUser']->id, 'foreign-terminate-marker');

    Livewire::actingAs($f['viewer'])
        ->test(Index::class)
        ->call('terminate', $foreign)
        ->assertDispatched('notify', variant: 'error');

    // A refusal leaves no session gone and no action claiming one was.
    expect(DB::table('sessions')->where('id', $foreign)->exists())->toBeTrue()
        ->and(sessionsTerminationRows())->toBe(0);
});

it('terminates a same-tenant session and records one session.terminated audit action', function (): void {
    $f = sessionsForeignFixture('Record');
    sessionsGrant($f['viewer'], 'admin.system.session.manage');
    $subject = User::factory()->create(['company_id' => $f['companyB'], 'name' => 'Ends Here']);
    $target = sessionsRow('sess-home-terminate', (int) $subject->id, 'home-terminate-marker');

    Livewire::actingAs($f['viewer'])->test(Index::class)->call('terminate', $target);

    expect(DB::table('sessions')->where('id', $target)->exists())->toBeFalse()
        ->and(sessionsTerminationRows())->toBe(1);

    $row = DB::table('base_audit_actions')->where('event', 'session.terminated')->sole();
    $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['surface'])->toBe('admin.system.sessions')
        ->and($payload['subject']['name'])->toBe('session')
        ->and($payload['subject']['identifier'])->toBe('Ends Here')
        // A prefix of a session id is still part of a live credential, so the
        // row carries a hash prefix: enough to tie two rows to one session,
        // useless to anyone who reads the audit log looking for a token.
        ->and($payload['subject']['id'])->toBe(substr(hash('sha256', $target), 0, 12))
        ->and((string) $row->payload)->not->toContain($target);
});

it('never terminates the current session', function (): void {
    $f = sessionsForeignFixture('Self');
    sessionsGrant($f['viewer'], 'admin.system.session.manage');
    $own = sessionsRow(session()->getId(), (int) $f['viewer']->id, 'own-agent-marker');

    Livewire::actingAs($f['viewer'])->test(Index::class)->call('terminate', $own);

    expect(DB::table('sessions')->where('id', $own)->exists())->toBeTrue();
});

it('denies the page without admin.system.session.list', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Sessions Denied']);
    app(TenantContext::class)->set((int) $tenant->id);
    $stranger = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($stranger)->get(route('admin.system.sessions.index'))->assertForbidden();
});
