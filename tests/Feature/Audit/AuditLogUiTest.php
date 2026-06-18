<?php

use App\Base\Audit\Listeners\MutationListener;
use App\Base\Audit\Livewire\AuditLog\Actions;
use App\Base\Audit\Livewire\AuditLog\Mutations;
use App\Base\Authz\Enums\PrincipalType;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function auditLogUiActor(): User
{
    return MutationListener::withoutAuditing(
        fn (): User => User::factory()->create(['name' => 'Audit Actor'])
    );
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
        ->assertSee('TRCE-1234-5678')
        ->assertSee('GET admin.users.show')
        ->assertSeeHtml('<details open')
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
