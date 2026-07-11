<?php

use App\Base\Audit\Listeners\MutationListener;
use App\Base\Audit\Livewire\AuditLog\Actions;
use App\Base\Audit\Livewire\AuditLog\Mutations;
use App\Base\Audit\Livewire\AuditLog\SourceHistory;
use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Audit\Services\AuditSourceHistory;
use App\Base\Audit\Services\AuditTraceTimeline;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Integration\Models\OutboundExchange;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use App\Base\Workflow\Services\WorkflowEngine;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Address\Models\Addressable;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

const AUDIT_LOG_UI_USER_PREFIX = 'User#';
const AUDIT_LOG_UI_USER_URL = 'https://example.test/admin/users/';
const AUDIT_LOG_UI_OLD_EMAIL = 'old@example.com';
const AUDIT_LOG_UI_NEW_EMAIL = 'new@example.com';
const AUDIT_LOG_UI_RENAMED_TARGET = 'History Target Renamed';
const AUDIT_LOG_UI_PASSWORD = 'SecurePassword123!';
const AUDIT_LOG_UI_UPDATED_NAME = 'Updated user name';
const AUDIT_LOG_UI_NAME_EDITOR = 'Name inline editor';
const AUDIT_LOG_UI_HIDDEN_OLD_EMAIL = 'hidden-old@example.com';
const AUDIT_LOG_UI_HIDDEN_NEW_EMAIL = 'hidden-new@example.com';
const AUDIT_LOG_UI_HISTORY_ROLE = 'History Role';
const AUDIT_LOG_UI_OPEN_WIRE_ACTION = 'wire:click="open"';
const AUDIT_LOG_UI_SOURCE_HIDDEN_OLD_EMAIL = 'source-hidden-old@example.com';
const AUDIT_LOG_UI_SOURCE_HIDDEN_NEW_EMAIL = 'source-hidden-new@example.com';
const AUDIT_LOG_UI_WORKFLOW_SUMMARY_PREFIX = 'Transitioned Company#';
const AUDIT_LOG_UI_WORKFLOW_SUMMARY_SUFFIX = ' from Pending Review to Active';
const AUDIT_LOG_UI_ADDRESS_PHONE = '03-77862444';
const AUDIT_LOG_UI_ADDRESS_PREFIX = 'Address#';

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

/** @return array{0: Company, 1: User, 2: User} */
function auditLogUiViewerWithoutAudit(string $targetName): array
{
    [$company, $viewer, $target] = MutationListener::withoutAuditing(function () use ($targetName): array {
        $company = Company::factory()->create();
        $viewer = User::factory()->create(['company_id' => $company->id]);
        $target = User::factory()->create(['company_id' => $company->id, 'name' => $targetName]);

        return [$company, $viewer, $target];
    });

    $viewerRole = Role::query()->where('code', 'user_viewer')->whereNull('company_id')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $viewerRole->id,
    ]);

    return [$company, $viewer, $target];
}

/** @return array<string, mixed> */
function auditLogUiUserHistoryParams(User $user): array
{
    return [
        'title' => __('History for :name', ['name' => $user->name]),
        'subjects' => [['name' => 'user', 'id' => $user->id]],
        'auditableType' => $user->getMorphClass(),
        'auditableId' => $user->id,
        'allUrl' => route('admin.audit.mutations', ['search' => AUDIT_LOG_UI_USER_PREFIX.$user->id]),
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
        'url' => AUDIT_LOG_UI_USER_URL.'1',
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
    $oldValues = $overrides['old_values'] ?? ['email' => AUDIT_LOG_UI_OLD_EMAIL];
    $newValues = $overrides['new_values'] ?? ['email' => AUDIT_LOG_UI_NEW_EMAIL];

    unset($overrides['old_values'], $overrides['new_values']);

    return (int) DB::table('base_audit_mutations')->insertGetId(array_replace([
        'company_id' => null,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => 1,
        'actor_role' => 'core_admin',
        'ip_address' => '127.0.0.1',
        'url' => AUDIT_LOG_UI_USER_URL.'1',
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
        'url' => AUDIT_LOG_UI_USER_URL.$actor->id,
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
        'url' => AUDIT_LOG_UI_USER_URL.$actor->id,
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
        ->assertSeeHtml('type="button"')
        ->assertSeeHtml('sm:pointer-events-none')
        ->assertSeeHtml('bg-black/50 sm:hidden')
        ->assertSee('TRCE-1234-5678')
        ->assertSee('GET admin.users.show')
        ->assertSee('Raw action detail')
        ->assertDontSeeHtml('<details open')
        ->assertSee(AUDIT_LOG_UI_USER_PREFIX.$actor->id)
        ->assertSee('email')
        ->assertSee(AUDIT_LOG_UI_OLD_EMAIL)
        ->assertSee(AUDIT_LOG_UI_NEW_EMAIL);
});

it('opens the same trace timeline from mutations', function (): void {
    $actor = auditLogUiActor();
    $trace = 'MUTR12345678';

    auditLogUiInsertAction([
        'actor_id' => $actor->id,
        'trace_id' => $trace,
        'payload' => ['method' => 'POST', 'route' => 'admin.users.show', 'status' => 200, 'duration_ms' => 42],
        'url' => AUDIT_LOG_UI_USER_URL.$actor->id,
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
        ->assertSee(AUDIT_LOG_UI_OLD_EMAIL)
        ->assertSee(AUDIT_LOG_UI_NEW_EMAIL);
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
        ->call('saveField', 'name', AUDIT_LOG_UI_RENAMED_TARGET)
        ->set('password', AUDIT_LOG_UI_PASSWORD)
        ->set('passwordConfirmation', AUDIT_LOG_UI_PASSWORD)
        ->call('updatePassword')
        ->assertHasNoErrors();

    auditLogUiFlushBuffer();

    Livewire::test('admin.users.show', ['user' => $target->fresh()])
        ->assertSee('History');

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target->fresh()))
        ->call('open')
        ->assertSet('sourceHistoryDrawerOpen', true)
        ->assertSee('Resize inspector panel')
        ->assertSee('Record history')
        ->assertSee('user#'.$target->id)
        ->assertSee('legacy-old@example.com')
        ->assertSee('legacy-new@example.com')
        ->assertSee('name')
        ->assertSee('History Target')
        ->assertSee(AUDIT_LOG_UI_RENAMED_TARGET)
        ->assertSee('password')
        ->assertSee('[redacted]')
        ->assertDontSee(AUDIT_LOG_UI_PASSWORD);

    $fieldAction = AuditAction::query()->where('event', 'user.field.updated')->firstOrFail();
    $passwordAction = AuditAction::query()->where('event', 'user.password.updated')->firstOrFail();

    expect($fieldAction->is_retained)->toBeTrue()
        ->and($fieldAction->payload['semantic'])->toBeTrue()
        ->and($fieldAction->payload['summary'])->toBe(AUDIT_LOG_UI_UPDATED_NAME)
        ->and($fieldAction->payload['subject']['label'])->toBe(AUDIT_LOG_UI_USER_PREFIX.$target->id)
        ->and($fieldAction->payload['surface'])->toBe('admin.users.show')
        ->and($fieldAction->payload['ui_element'])->toBe(AUDIT_LOG_UI_NAME_EDITOR)
        ->and($fieldAction->payload['context']['fields'])->toContain('name')
        ->and($passwordAction->payload['summary'])->toBe('Changed user password')
        ->and($passwordAction->payload['ui_element'])->toBe('Password form Save button')
        ->and($passwordAction->payload['context']['fields'])->toBe(['password']);

    Livewire::test(Actions::class)
        ->set('filterEventFamily', 'product')
        ->assertSee(AUDIT_LOG_UI_UPDATED_NAME)
        ->assertSee(AUDIT_LOG_UI_USER_PREFIX.$target->id)
        ->assertSee(AUDIT_LOG_UI_NAME_EDITOR)
        ->assertSee('Changed user password')
        ->assertSee('Password form Save button');

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target->fresh()))
        ->call('openTrace', $fieldAction->trace_id)
        ->assertSet('traceDrawerOpen', true)
        ->assertSee(AUDIT_LOG_UI_UPDATED_NAME)
        ->assertSee(AUDIT_LOG_UI_NAME_EDITOR)
        ->assertSee(AUDIT_LOG_UI_RENAMED_TARGET);

    expect(
        AuditMutation::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', (string) $target->id)
            ->where('subject_name', 'user')
            ->where('subject_id', (string) $target->id)
            ->exists()
    )->toBeTrue();
});

it('does not expose source history or trace data without audit permission', function (): void {
    setupAuthzRoles();

    [, $viewer, $target] = auditLogUiViewerWithoutAudit('Hidden History Target');

    auditLogUiInsertMutation([
        'actor_id' => $viewer->id,
        'auditable_id' => $target->id,
        'old_values' => ['email' => AUDIT_LOG_UI_HIDDEN_OLD_EMAIL],
        'new_values' => ['email' => AUDIT_LOG_UI_HIDDEN_NEW_EMAIL],
        'trace_id' => 'NOAUDT123456',
    ]);

    $this->actingAs($viewer);

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target))
        ->assertDontSeeHtml(AUDIT_LOG_UI_OPEN_WIRE_ACTION)
        ->call('open')
        ->assertSet('sourceHistoryDrawerOpen', false)
        ->assertSet('sourceHistory', [])
        ->assertDontSee(AUDIT_LOG_UI_HIDDEN_OLD_EMAIL)
        ->assertDontSee(AUDIT_LOG_UI_HIDDEN_NEW_EMAIL)
        ->call('openTrace', 'NOAUDT-1234-56')
        ->assertSet('traceDrawerOpen', false)
        ->assertSet('selectedTraceId', '')
        ->assertSet('traceTimeline', [])
        ->assertDontSee(AUDIT_LOG_UI_HIDDEN_OLD_EMAIL)
        ->assertDontSee(AUDIT_LOG_UI_HIDDEN_NEW_EMAIL);
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
        'old_values' => ['email' => AUDIT_LOG_UI_SOURCE_HIDDEN_OLD_EMAIL],
        'new_values' => ['email' => AUDIT_LOG_UI_SOURCE_HIDDEN_NEW_EMAIL],
        'trace_id' => 'NOVIEW123456',
    ]);

    $this->actingAs($viewer);

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target))
        ->assertDontSeeHtml(AUDIT_LOG_UI_OPEN_WIRE_ACTION)
        ->call('open')
        ->assertSet('sourceHistoryDrawerOpen', false)
        ->assertSet('sourceHistory', [])
        ->assertDontSee(AUDIT_LOG_UI_SOURCE_HIDDEN_OLD_EMAIL)
        ->assertDontSee(AUDIT_LOG_UI_SOURCE_HIDDEN_NEW_EMAIL)
        ->call('openTrace', 'NOVIEW-1234-56')
        ->assertSet('traceDrawerOpen', false)
        ->assertSet('selectedTraceId', '')
        ->assertSet('traceTimeline', [])
        ->assertDontSee(AUDIT_LOG_UI_SOURCE_HIDDEN_OLD_EMAIL)
        ->assertDontSee(AUDIT_LOG_UI_SOURCE_HIDDEN_NEW_EMAIL);
});

it('includes user role and direct capability mutations in user record history', function (): void {
    $actor = createAdminUser();

    [$company, $target, $role] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->create();
        $target = User::factory()->create(['company_id' => $company->id, 'name' => 'Authz Target']);
        $role = Role::query()->create([
            'company_id' => null,
            'name' => AUDIT_LOG_UI_HISTORY_ROLE,
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
        ->and($roleAction->payload['context']['role_names'])->toBe([AUDIT_LOG_UI_HISTORY_ROLE])
        ->and($capabilityAction->payload['summary'])->toBe('Granted 1 direct capability to user')
        ->and($capabilityAction->payload['context']['capability_keys'])->toBe(['admin.user.view']);

    Livewire::test(Actions::class)
        ->set('filterEventFamily', 'product')
        ->assertSee('Assigned 1 role to user')
        ->assertSee(AUDIT_LOG_UI_HISTORY_ROLE)
        ->assertSee('Granted 1 direct capability to user')
        ->assertSee('admin.user.view');

    expect(
        AuditMutation::query()
            ->where('auditable_type', PrincipalRole::class)
            ->where('subject_name', 'user')
            ->where('subject_id', (string) $target->id)
            ->exists()
    )->toBeTrue()
        ->and(
            AuditMutation::query()
                ->where('auditable_type', PrincipalCapability::class)
                ->where('subject_name', 'user')
                ->where('subject_id', (string) $target->id)
                ->exists()
        )->toBeTrue();

    expect($company->id)->toBe($target->company_id);
});

it('records semantic audit actions for workflow transitions', function (): void {
    $actor = createAdminUser();
    $this->actingAs($actor);

    $company = MutationListener::withoutAuditing(function (): Company {
        $company = Company::factory()->minimal()->pending()->create([
            'name' => 'Workflow Action Company',
        ]);

        Workflow::query()->create([
            'code' => 'audit_company_status',
            'label' => 'Company Status',
            'module' => 'test',
        ]);

        StatusConfig::query()->create([
            'flow' => 'audit_company_status',
            'code' => 'pending',
            'label' => 'Pending Review',
            'position' => 0,
        ]);

        StatusConfig::query()->create([
            'flow' => 'audit_company_status',
            'code' => 'active',
            'label' => 'Active',
            'position' => 1,
        ]);

        StatusTransition::query()->create([
            'flow' => 'audit_company_status',
            'from_code' => 'pending',
            'to_code' => 'active',
            'label' => 'Approve activation',
        ]);

        return $company;
    });

    $result = app(WorkflowEngine::class)->transition(
        model: $company,
        flow: 'audit_company_status',
        toCode: 'active',
        context: new TransitionContext(
            actor: Actor::forUser($actor, attributes: ['role' => 'core_admin', 'department' => 'Operations']),
            comment: 'Approved after review',
            commentTag: 'approval',
            assignees: [['user_id' => $actor->id]],
            attachments: [['name' => 'approval-note.txt']],
            metadata: ['approval_source' => 'admin-ui'],
        ),
    );

    expect($result->success)->toBeTrue();

    auditLogUiFlushBuffer();

    $action = AuditAction::query()
        ->where('event', 'workflow.transition.completed')
        ->firstOrFail();
    $payload = $action->payload;

    expect($payload['semantic'])->toBeTrue()
        ->and($payload['source'])->toBe('Workflow')
        ->and($payload['summary'])->toBe(AUDIT_LOG_UI_WORKFLOW_SUMMARY_PREFIX.$company->id.AUDIT_LOG_UI_WORKFLOW_SUMMARY_SUFFIX)
        ->and($payload['subject']['label'])->toBe('Company#'.$company->id)
        ->and($payload['surface'])->toBe('workflow.audit_company_status')
        ->and($payload['context'])->toMatchArray([
            'flow' => 'audit_company_status',
            'flow_model' => Company::class,
            'flow_id' => $company->id,
            'from_status' => 'pending',
            'to_status' => 'active',
            'from_label' => 'Pending Review',
            'to_label' => 'Active',
            'transition_label' => 'Approve activation',
            'actor_type' => 'user',
            'actor_id' => $actor->id,
            'actor_role' => 'core_admin',
            'actor_department' => 'Operations',
            'comment_present' => true,
            'comment_tag' => 'approval',
            'assignee_count' => 1,
            'attachment_count' => 1,
            'metadata_keys' => ['approval_source'],
        ]);

    expect(array_key_exists('comment', $payload['context']))->toBeFalse()
        ->and(array_key_exists('metadata', $payload['context']))->toBeFalse()
        ->and(array_key_exists('attachments', $payload['context']))->toBeFalse();

    $timeline = app(AuditTraceTimeline::class)->forTrace($action->trace_id);

    expect(collect($timeline['entries'])->contains(
        fn (array $entry): bool => $entry['kind'] === 'action'
            && $entry['event'] === 'workflow.transition.completed'
            && $entry['summary'] === AUDIT_LOG_UI_WORKFLOW_SUMMARY_PREFIX.$company->id.AUDIT_LOG_UI_WORKFLOW_SUMMARY_SUFFIX
    ))->toBeTrue();

    Livewire::test(Actions::class)
        ->set('filterEventFamily', 'product')
        ->assertSee(AUDIT_LOG_UI_WORKFLOW_SUMMARY_PREFIX.$company->id.AUDIT_LOG_UI_WORKFLOW_SUMMARY_SUFFIX)
        ->assertSee('Workflow')
        ->assertSee('workflow.audit_company_status');
});

it('deduplicates expanded subject rows from direct record history', function (): void {
    [$firstCompany, $secondCompany, $address] = MutationListener::withoutAuditing(function (): array {
        $firstCompany = Company::factory()->minimal()->create(['name' => 'First Linked Company']);
        $secondCompany = Company::factory()->minimal()->create(['name' => 'Second Linked Company']);
        $address = Address::factory()->create([
            'country_iso' => null,
            'phone' => null,
        ]);

        foreach ([$firstCompany, $secondCompany] as $company) {
            Addressable::query()->create([
                'address_id' => $address->id,
                'addressable_type' => $company->getMorphClass(),
                'addressable_id' => $company->id,
                'kind' => ['billing'],
            ]);
        }

        return [$firstCompany, $secondCompany, $address];
    });

    $address->update(['phone' => AUDIT_LOG_UI_ADDRESS_PHONE]);
    auditLogUiFlushBuffer();

    expect(
        AuditMutation::query()
            ->where('auditable_type', Address::class)
            ->where('auditable_id', (string) $address->id)
            ->count()
    )->toBe(3);

    $expandedSubjectIds = AuditMutation::query()
        ->where('auditable_type', Address::class)
        ->where('auditable_id', (string) $address->id)
        ->where('source', 'expanded')
        ->pluck('subject_id')
        ->sort()
        ->values()
        ->all();

    expect($expandedSubjectIds)->toBe([
        (string) $firstCompany->id,
        (string) $secondCompany->id,
    ]);

    $history = app(AuditSourceHistory::class)->forRecord(
        subjects: [['name' => 'address', 'id' => $address->id]],
        auditableType: $address->getMorphClass(),
        auditableId: $address->id,
    );

    expect($history['entries'])->toHaveCount(1)
        ->and($history['entries'][0]['auditable'])->toBe(AUDIT_LOG_UI_ADDRESS_PREFIX.$address->id)
        ->and($history['entries'][0]['diffs'])->toHaveCount(1)
        ->and($history['entries'][0]['diffs'][0])->toMatchArray([
            'field' => 'phone',
            'old' => '—',
            'new' => AUDIT_LOG_UI_ADDRESS_PHONE,
        ]);
});

it('includes explicitly linked address contact changes in company record history', function (): void {
    [$company, $unrelatedCompany, $address] = MutationListener::withoutAuditing(function (): array {
        $company = Company::factory()->minimal()->create(['name' => 'Company With Shared Address']);
        $unrelatedCompany = Company::factory()->minimal()->create(['name' => 'Company Without Address Link']);
        $address = Address::factory()->create([
            'country_iso' => null,
            'phone' => null,
        ]);

        Addressable::query()->create([
            'address_id' => $address->id,
            'addressable_type' => $company->getMorphClass(),
            'addressable_id' => $company->id,
            'kind' => ['billing'],
        ]);

        return [$company, $unrelatedCompany, $address];
    });

    $address->update(['phone' => AUDIT_LOG_UI_ADDRESS_PHONE]);
    auditLogUiFlushBuffer();

    $companyHistory = app(AuditSourceHistory::class)->forRecord(
        subjects: [['name' => 'company', 'id' => $company->id]],
        auditableType: $company->getMorphClass(),
        auditableId: $company->id,
    );

    $unrelatedHistory = app(AuditSourceHistory::class)->forRecord(
        subjects: [['name' => 'company', 'id' => $unrelatedCompany->id]],
        auditableType: $unrelatedCompany->getMorphClass(),
        auditableId: $unrelatedCompany->id,
    );

    expect($companyHistory['entries'])->toHaveCount(1)
        ->and($companyHistory['entries'][0]['auditable'])->toBe(AUDIT_LOG_UI_ADDRESS_PREFIX.$address->id)
        ->and($companyHistory['entries'][0]['target'])->toBe(AUDIT_LOG_UI_ADDRESS_PREFIX.$address->id)
        ->and($companyHistory['entries'][0]['diffs'])->toHaveCount(1)
        ->and($companyHistory['entries'][0]['diffs'][0])->toMatchArray([
            'field' => 'phone',
            'old' => '—',
            'new' => AUDIT_LOG_UI_ADDRESS_PHONE,
        ])
        ->and($unrelatedHistory['entries'])->toHaveCount(0);
});

it('searches, sorts, and progressively loads source history rows', function (): void {
    $actor = createAdminUser();

    $target = MutationListener::withoutAuditing(
        fn (): User => User::factory()->create(['company_id' => $actor->company_id, 'name' => 'Dense History Target'])
    );

    $this->actingAs($actor);

    auditLogUiInsertMutation([
        'actor_id' => $actor->id,
        'auditable_id' => (string) $target->id,
        'old_values' => ['phone' => '100'],
        'new_values' => ['phone' => 'alpha-phone'],
        'trace_id' => 'DENSEHIST001',
        'occurred_at' => now()->subMinutes(3)->toDateTimeString(),
    ]);
    auditLogUiInsertMutation([
        'actor_id' => $actor->id,
        'auditable_id' => (string) $target->id,
        'old_values' => ['email' => 'old@example.com'],
        'new_values' => ['email' => 'beta@example.com'],
        'trace_id' => 'DENSEHIST002',
        'occurred_at' => now()->subMinutes(2)->toDateTimeString(),
    ]);
    auditLogUiInsertMutation([
        'actor_id' => $actor->id,
        'auditable_id' => (string) $target->id,
        'event' => 'created',
        'old_values' => [],
        'new_values' => ['name' => 'created-target'],
        'trace_id' => 'DENSEHIST003',
        'occurred_at' => now()->subMinute()->toDateTimeString(),
    ]);

    Livewire::test(SourceHistory::class, auditLogUiUserHistoryParams($target))
        ->call('open')
        ->assertSet('sourceHistory.total', 3)
        ->assertSet('sourceHistorySubjectLabel', 'user#'.$target->id)
        ->set('sourceHistorySearch', 'phone')
        ->assertSet('sourceHistory.total', 1)
        ->assertSee('alpha-phone')
        ->assertDontSee('beta@example.com')
        ->call('clearSourceHistorySearch')
        ->assertSet('sourceHistory.total', 3)
        ->call('sortSourceHistory', 'event')
        ->assertSet('sourceHistorySortBy', 'event')
        ->assertSet('sourceHistorySortDir', 'asc')
        ->call('loadMoreSourceHistory')
        ->assertSet('sourceHistoryLimit', 100);
});

it('finds string-key source history and global mutation searches', function (): void {
    $id = 'ix_01JSTRINGAUDIT0000000001';
    $otherId = 'ix_01JSTRINGAUDIT0000000002';

    auditLogUiInsertMutation([
        'auditable_type' => OutboundExchange::class,
        'auditable_id' => $id,
        'subject_name' => 'outbound_exchange',
        'subject_id' => $id,
        'old_values' => ['outcome' => 'queued'],
        'new_values' => ['outcome' => 'string-key-visible'],
        'trace_id' => 'STRGKEY00001',
    ]);

    auditLogUiInsertMutation([
        'auditable_type' => OutboundExchange::class,
        'auditable_id' => $otherId,
        'subject_name' => 'outbound_exchange',
        'subject_id' => $otherId,
        'old_values' => ['outcome' => 'queued'],
        'new_values' => ['outcome' => 'string-key-hidden'],
        'trace_id' => 'STRGKEY00002',
    ]);

    $historyByDirect = app(AuditSourceHistory::class)->forRecord(
        subjects: [],
        auditableType: OutboundExchange::class,
        auditableId: $id,
    );

    $historyBySubject = app(AuditSourceHistory::class)->forRecord(
        subjects: [['name' => 'outbound_exchange', 'id' => $id]],
        auditableType: null,
        auditableId: null,
    );

    expect($historyByDirect['entries'])->toHaveCount(1)
        ->and($historyByDirect['entries'][0]['auditable'])->toBe('OutboundExchange#'.$id)
        ->and($historyByDirect['entries'][0]['diffs'][0]['new'])->toBe('string-key-visible')
        ->and($historyBySubject['entries'])->toHaveCount(1)
        ->and($historyBySubject['entries'][0]['auditable'])->toBe('OutboundExchange#'.$id);

    Livewire::test(Mutations::class)
        ->set('search', 'outbound_exchange#'.$id)
        ->assertSee($id)
        ->assertSee('string-key-visible')
        ->assertDontSee($otherId)
        ->assertDontSee('string-key-hidden');
});

it('renders the record history bridge on first-wave detail pages', function (): void {
    $actor = createAdminUser();

    [$company, $employee, $address] = MutationListener::withoutAuditing(function () use ($actor): array {
        $company = Company::query()->findOrFail($actor->company_id);
        $employee = Employee::factory()->create([
            'company_id' => $company->id,
            'full_name' => 'DataShare History Employee',
        ]);
        $address = Address::factory()->create([
            'country_iso' => null,
            'label' => 'DataShare History Address',
        ]);

        return [$company, $employee, $address];
    });

    $this->actingAs($actor);

    foreach ([
        route('admin.users.show', $actor),
        route('admin.companies.show', $company),
        route('admin.employees.show', $employee),
        route('admin.addresses.show', $address),
    ] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('History')
            ->assertSeeHtml(AUDIT_LOG_UI_OPEN_WIRE_ACTION);
    }
});

it('does not mount the record history bridge without audit permission', function (): void {
    setupAuthzRoles();

    [, $viewer, $target] = auditLogUiViewerWithoutAudit('DataShare Hidden Target');

    $this->actingAs($viewer)
        ->get(route('admin.users.show', $target))
        ->assertOk()
        ->assertDontSee('Record history')
        ->assertDontSeeHtml('sourceHistoryDrawerOpen')
        ->assertDontSeeHtml(AUDIT_LOG_UI_OPEN_WIRE_ACTION);
});

it('honors a URL-supplied perPage over the audit default on initial mount', function (): void {
    auditLogUiInsertMutation(['trace_id' => 'PERPAGEURL01']);

    Livewire::withQueryParams(['perPage' => 50])
        ->test(Mutations::class)
        ->assertSet('perPage', 50)
        ->assertViewHas('mutations', fn (LengthAwarePaginator $p): bool => $p->perPage() === 50);
});

it('clamps a stale out-of-range URL perPage to the largest audit option on initial mount', function (): void {
    auditLogUiInsertMutation(['trace_id' => 'PERPAGEURL02']);

    Livewire::withQueryParams(['perPage' => 9999])
        ->test(Mutations::class)
        ->assertSet('perPage', 100)
        ->assertViewHas('mutations', fn (LengthAwarePaginator $p): bool => $p->perPage() === 100);
});

it('falls back to the audit default perPage when the URL does not supply one', function (): void {
    auditLogUiInsertMutation(['trace_id' => 'PERPAGEURL03']);

    Livewire::test(Mutations::class)
        ->assertSet('perPage', 20)
        ->assertViewHas('mutations', fn (LengthAwarePaginator $p): bool => $p->perPage() === 20);
});
