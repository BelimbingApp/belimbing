<?php

use App\Base\Audit\Listeners\MutationListener;
use App\Base\Audit\Livewire\AuditLog\Actions;
use App\Base\Audit\Livewire\AuditLog\Mutations;
use App\Base\Audit\Livewire\AuditLog\SourceHistory;
use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function auditLogUiActor(): User
{
    return MutationListener::withoutAuditing(
        fn (): User => User::factory()->create(['name' => 'Audit Actor'])
    );
}

function auditLogUiFlushBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);
}

/** @return array<string, mixed> */
function auditLogUiUserHistoryParams(User $user): array
{
    return [
        'title' => __('History for :name', ['name' => $user->name]),
        'subjects' => [['name' => 'user', 'id' => $user->id]],
        'auditableType' => $user->getMorphClass(),
        'auditableId' => $user->id,
        'allUrl' => route('admin.audit.mutations', ['search' => 'User#'.$user->id]),
        'sourceCapability' => 'admin.user.view',
    ];
}

/** @param  array<string, mixed>  $overrides */
function auditLogUiInsertAction(array $overrides = []): int
{
    $payload = $overrides['payload'] ?? [
        'method' => 'GET',
        'route' => 'admin.users.show',
        'status' => 200,
        'duration_ms' => 25,
    ];

    unset($overrides['payload']);

    return (int) DB::table('base_audit_actions')->insertGetId(array_replace([
        'company_id' => null,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'actor_role' => 'core_admin',
        'ip_address' => '127.0.0.1',
        'url' => 'https://example.test/admin/users/1',
        'user_agent' => 'Chrome 148 / Linux',
        'event' => 'http.request',
        'payload' => json_encode($payload),
        'trace_id' => 'A1B2C3D4E5F6',
        'is_retained' => false,
        'occurred_at' => now()->toDateTimeString(),
    ], $overrides));
}

/** @param  array<string, mixed>  $overrides */
function auditLogUiInsertMutation(array $overrides = []): int
{
    $oldValues = $overrides['old_values'] ?? ['email' => 'old@example.com'];
    $newValues = $overrides['new_values'] ?? ['email' => 'new@example.com'];

    unset($overrides['old_values'], $overrides['new_values']);

    return (int) DB::table('base_audit_mutations')->insertGetId(array_replace([
        'company_id' => null,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'actor_role' => 'core_admin',
        'ip_address' => '127.0.0.1',
        'url' => 'https://example.test/admin/users/1',
        'user_agent' => 'Chrome 148 / Linux',
        'auditable_type' => User::class,
        'auditable_id' => 1,
        'subject_name' => null,
        'subject_id' => null,
        'subject_identifier' => null,
        'source' => 'listener',
        'event' => 'updated',
        'old_values' => json_encode($oldValues),
        'new_values' => json_encode($newValues),
        'trace_id' => 'A1B2C3D4E5F6',
        'occurred_at' => now()->toDateTimeString(),
    ], $overrides));
}

it('hides successful diagnostic requests by default while surfacing failures', function (): void {
    $actor = auditLogUiActor();

    auditLogUiInsertAction([
        'actor_id' => $actor->id,
        'trace_id' => 'AUDTMAIN0001',
        'payload' => ['method' => 'GET', 'route' => 'admin.users.show', 'status' => 200, 'duration_ms' => 31],
        'url' => 'https://example.test/admin/users/'.$actor->id,
    ]);

    auditLogUiInsertAction([
        'actor_id' => $actor->id,
        'trace_id' => 'LWUP2000ABCD',
        'payload' => ['method' => 'POST', 'route' => 'default-livewire.update', 'status' => 200, 'duration_ms' => 12],
        'url' => 'https://example.test/livewire-89cd7b8d/update',
    ]);

    auditLogUiInsertAction([
        'actor_id' => $actor->id,
        'trace_id' => 'LWUP5000ABCD',
        'payload' => ['method' => 'POST', 'route' => 'default-livewire.update', 'status' => 500, 'duration_ms' => 12],
        'url' => 'https://example.test/livewire-89cd7b8d/update',
    ]);

    Livewire::test(Actions::class)
        ->assertSee('GET admin.users.show')
        ->assertSee('LWUP-5000-ABCD')
        ->assertDontSee('LWUP-2000-ABCD')
        ->set('filterDiagnostics', 'show')
        ->assertSee('LWUP-2000-ABCD');
});

it('opens a combined action and mutation trace timeline from actions', function (): void {
    $actor = auditLogUiActor();
    $trace = 'TRCE12345678';

    auditLogUiInsertAction([
        'actor_id' => $actor->id,
        'trace_id' => $trace,
        'payload' => ['method' => 'GET', 'route' => 'admin.users.show', 'status' => 200, 'duration_ms' => 31],
        'url' => 'https://example.test/admin/users/'.$actor->id,
    ]);

    auditLogUiInsertMutation([
        'actor_id' => $actor->id,
        'auditable_id' => $actor->id,
        'trace_id' => $trace,
    ]);

    Livewire::test(Actions::class)
        ->call('openTrace', 'TRCE-1234-5678')
        ->assertSet('traceDrawerOpen', true)
        ->assertSee('Resize inspector panel')
        ->assertSee('Reset inspector width')
        ->assertSeeHtml('role="separator"')
        ->assertSeeHtml('sm:pointer-events-none')
        ->assertSeeHtml('bg-black/50 sm:hidden')
        ->assertSeeHtml('aria-modal="false"')
        ->assertSee('TRCE-1234-5678')
        ->assertSee('GET admin.users.show')
        ->assertSee('Raw action detail')
        ->assertDontSeeHtml('<details open')
        ->assertSee('User#'.$actor->id)
        ->assertSee('email')
        ->assertSee('old@example.com')
        ->assertSee('new@example.com');
});

it('opens the same trace timeline from mutations', function (): void {
    $actor = auditLogUiActor();
    $trace = 'MUTR12345678';

    auditLogUiInsertAction([
        'actor_id' => $actor->id,
        'trace_id' => $trace,
        'payload' => ['method' => 'POST', 'route' => 'admin.users.show', 'status' => 200, 'duration_ms' => 42],
        'url' => 'https://example.test/admin/users/'.$actor->id,
    ]);

    auditLogUiInsertMutation([
        'actor_id' => $actor->id,
        'auditable_id' => $actor->id,
        'trace_id' => $trace,
    ]);

    Livewire::test(Mutations::class)
        ->call('openTrace', $trace)
        ->assertSet('traceDrawerOpen', true)
        ->assertSee('MUTR-1234-5678')
        ->assertSee('POST admin.users.show')
        ->assertSee('old@example.com')
        ->assertSee('new@example.com');
});

it('shows user record history from direct mutations and redacts protected values', function (): void {
    $actor = createAdminUser();
    $target = MutationListener::withoutAuditing(
        fn (): User => User::factory()->create(['name' => 'History Target', 'email' => 'history-old@example.com'])
    );

    $this->actingAs($actor);

    auditLogUiInsertMutation([
        'actor_id' => $actor->id,
        'auditable_id' => $target->id,
        'old_values' => ['email' => 'legacy-old@example.com'],
        'new_values' => ['email' => 'legacy-new@example.com'],
        'trace_id' => 'HIST12345678',
    ]);

    Livewire::test('admin.users.show', ['user' => $target])
        ->call('saveField', 'name', 'History Target Renamed')
        ->set('password', 'SecurePassword123!')
        ->set('passwordConfirmation', 'SecurePassword123!')
        ->call('updatePassword')
        ->assertHasNoErrors();

    auditLogUiFlushBuffer();

    Livewire::test('admin.users.show', ['user' => $target->fresh()])
        ->assertSee('History');

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target->fresh()))
        ->call('open')
        ->assertSet('sourceHistoryDrawerOpen', true)
        ->assertSee('Resize inspector panel')
        ->assertSee('History for History Target Renamed')
        ->assertSee('legacy-old@example.com')
        ->assertSee('legacy-new@example.com')
        ->assertSee('name')
        ->assertSee('History Target')
        ->assertSee('History Target Renamed')
        ->assertSee('password')
        ->assertSee('[redacted]')
        ->assertDontSee('SecurePassword123!');

    $fieldAction = AuditAction::query()->where('event', 'user.field.updated')->firstOrFail();
    $passwordAction = AuditAction::query()->where('event', 'user.password.updated')->firstOrFail();

    expect($fieldAction->is_retained)->toBeTrue()
        ->and($fieldAction->payload['semantic'])->toBeTrue()
        ->and($fieldAction->payload['summary'])->toBe('Updated user name')
        ->and($fieldAction->payload['subject']['label'])->toBe('User#'.$target->id)
        ->and($fieldAction->payload['surface'])->toBe('admin.users.show')
        ->and($fieldAction->payload['ui_element'])->toBe('Name inline editor')
        ->and($fieldAction->payload['context']['fields'])->toContain('name')
        ->and($passwordAction->payload['summary'])->toBe('Changed user password')
        ->and($passwordAction->payload['ui_element'])->toBe('Password form Save button')
        ->and($passwordAction->payload['context']['fields'])->toBe(['password']);

    Livewire::test(Actions::class)
        ->set('filterEventFamily', 'product')
        ->assertSee('Updated user name')
        ->assertSee('User#'.$target->id)
        ->assertSee('Name inline editor')
        ->assertSee('Changed user password')
        ->assertSee('Password form Save button');

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target->fresh()))
        ->call('openTrace', $fieldAction->trace_id)
        ->assertSet('traceDrawerOpen', true)
        ->assertSee('Updated user name')
        ->assertSee('Name inline editor')
        ->assertSee('History Target Renamed');

    expect(
        AuditMutation::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $target->id)
            ->where('subject_name', 'user')
            ->where('subject_id', $target->id)
            ->exists()
    )->toBeTrue();
});

it('does not expose source history or trace data without audit permission', function (): void {
    setupAuthzRoles();

    [$company, $viewer, $target] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->create();
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $target = User::factory()->create(['company_id' => $company->id, 'name' => 'Hidden History Target']);

        return [$company, $viewer, $target];
    });

    $viewerRole = Role::query()->where('code', 'user_viewer')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $viewerRole->id,
    ]);

    auditLogUiInsertMutation([
        'actor_id' => $viewer->id,
        'auditable_id' => $target->id,
        'old_values' => ['email' => 'hidden-old@example.com'],
        'new_values' => ['email' => 'hidden-new@example.com'],
        'trace_id' => 'NOAUDT123456',
    ]);

    $this->actingAs($viewer);

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target))
        ->assertDontSeeHtml('wire:click="open"')
        ->call('open')
        ->assertSet('sourceHistoryDrawerOpen', false)
        ->assertSet('sourceHistory', [])
        ->assertDontSee('hidden-old@example.com')
        ->assertDontSee('hidden-new@example.com')
        ->call('openTrace', 'NOAUDT-1234-56')
        ->assertSet('traceDrawerOpen', false)
        ->assertSet('selectedTraceId', '')
        ->assertSet('traceTimeline', [])
        ->assertDontSee('hidden-old@example.com')
        ->assertDontSee('hidden-new@example.com');
});

it('requires source page view permission in addition to audit permission', function (): void {
    setupAuthzRoles();

    [$company, $viewer, $target] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->create();
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $target = User::factory()->create(['company_id' => $company->id, 'name' => 'Source Hidden Target']);

        return [$company, $viewer, $target];
    });

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'admin.audit.log.list',
        'is_allowed' => true,
    ]);

    auditLogUiInsertMutation([
        'actor_id' => $viewer->id,
        'auditable_id' => $target->id,
        'old_values' => ['email' => 'source-hidden-old@example.com'],
        'new_values' => ['email' => 'source-hidden-new@example.com'],
        'trace_id' => 'NOVIEW123456',
    ]);

    $this->actingAs($viewer);

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target))
        ->assertDontSeeHtml('wire:click="open"')
        ->call('open')
        ->assertSet('sourceHistoryDrawerOpen', false)
        ->assertSet('sourceHistory', [])
        ->assertDontSee('source-hidden-old@example.com')
        ->assertDontSee('source-hidden-new@example.com')
        ->call('openTrace', 'NOVIEW-1234-56')
        ->assertSet('traceDrawerOpen', false)
        ->assertSet('selectedTraceId', '')
        ->assertSet('traceTimeline', [])
        ->assertDontSee('source-hidden-old@example.com')
        ->assertDontSee('source-hidden-new@example.com');
});

it('includes user role and direct capability mutations in user record history', function (): void {
    $actor = createAdminUser();

    [$company, $target, $role] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->create();
        $target = User::factory()->create(['company_id' => $company->id, 'name' => 'Authz Target']);
        $role = Role::query()->create([
            'company_id' => null,
            'name' => 'History Role',
            'code' => 'history_role',
            'is_system' => false,
        ]);

        return [$company, $target, $role];
    });

    $this->actingAs($actor);

    Livewire::test('admin.users.show', ['user' => $target])
        ->set('selectedRoleIds', [$role->id])
        ->call('assignRoles')
        ->set('selectedCapabilityKeys', ['admin.user.view'])
        ->call('addCapabilities');

    auditLogUiFlushBuffer();

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target))
        ->call('open')
        ->assertSet('sourceHistoryDrawerOpen', true)
        ->assertSee('PrincipalRole')
        ->assertSee('role_id')
        ->assertSee('PrincipalCapability')
        ->assertSee('capability_key')
        ->assertSee('admin.user.view');

    $roleAction = AuditAction::query()->where('event', 'user.roles.assigned')->firstOrFail();
    $capabilityAction = AuditAction::query()->where('event', 'user.capabilities.granted')->firstOrFail();

    expect($roleAction->payload['semantic'])->toBeTrue()
        ->and($roleAction->payload['summary'])->toBe('Assigned 1 role to user')
        ->and($roleAction->payload['context']['role_ids'])->toBe([$role->id])
        ->and($roleAction->payload['context']['role_names'])->toBe(['History Role'])
        ->and($capabilityAction->payload['summary'])->toBe('Granted 1 direct capability to user')
        ->and($capabilityAction->payload['context']['capability_keys'])->toBe(['admin.user.view']);

    Livewire::test(Actions::class)
        ->set('filterEventFamily', 'product')
        ->assertSee('Assigned 1 role to user')
        ->assertSee('History Role')
        ->assertSee('Granted 1 direct capability to user')
        ->assertSee('admin.user.view');

    expect(
        AuditMutation::query()
            ->where('auditable_type', PrincipalRole::class)
            ->where('subject_name', 'user')
            ->where('subject_id', $target->id)
            ->exists()
    )->toBeTrue()
        ->and(
            AuditMutation::query()
                ->where('auditable_type', PrincipalCapability::class)
                ->where('subject_name', 'user')
                ->where('subject_id', $target->id)
                ->exists()
        )->toBeTrue();

    expect($company->id)->toBe($target->company_id);
});

it('renders the record history bridge on first-wave detail pages', function (): void {
    $actor = createAdminUser();

    [$company, $employee, $address] = MutationListener::withoutAuditing(function () use ($actor): array {
        $company = Company::query()->findOrFail($actor->company_id);
        $employee = Employee::factory()->create([
            'company_id' => $company->id,
            'full_name' => 'Bridge History Employee',
        ]);
        $address = Address::factory()->create([
            'country_iso' => null,
            'label' => 'Bridge History Address',
        ]);

        return [$company, $employee, $address];
    });

    $this->actingAs($actor);

    foreach ([
        route('admin.users.show', $actor),
        route('admin.companies.show', $company),
        route('admin.employees.show', $employee),
        route('admin.addresses.show', $address),
        route('people.employees.show', $employee),
    ] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('History')
            ->assertSeeHtml('wire:click="open"');
    }
});

it('does not mount the record history bridge without audit permission', function (): void {
    setupAuthzRoles();

    [$company, $viewer, $target] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->create();
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $target = User::factory()->create(['company_id' => $company->id, 'name' => 'Bridge Hidden Target']);

        return [$company, $viewer, $target];
    });

    $viewerRole = Role::query()->where('code', 'user_viewer')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $viewerRole->id,
    ]);

    $this->actingAs($viewer)
        ->get(route('admin.users.show', $target))
        ->assertOk()
        ->assertDontSee('Record history')
        ->assertDontSeeHtml('sourceHistoryDrawerOpen')
        ->assertDontSeeHtml('wire:click="open"');
});
