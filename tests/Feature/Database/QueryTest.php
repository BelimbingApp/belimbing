<?php

use App\Base\AI\Contracts\LlmTransportTap;
use App\Base\AI\Contracts\Tracing\LlmTraceContextFactory;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Services\LlmClient;
use App\Base\AI\Services\Tracing\LlmTraceContext;
use App\Base\AI\Services\UrlSafetyGuard;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Database\Exceptions\BlbQueryException;
use App\Base\Database\Livewire\Queries\Index;
use App\Base\Database\Livewire\Queries\Show;
use App\Base\Database\Services\QueryExecutor;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\AI\Models\AiProvider;
use App\Core\AI\Models\AiProviderModel;
use App\Core\Company\Models\Company;
use App\Core\User\Models\Query;
use App\Core\User\Models\User;
use App\Core\User\Models\UserPin;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissiveUrlSafetyGuard;

const QUERY_TEST_SQL = 'SELECT 1 AS id, \'hello\' AS name';
const QUERY_TEST_ACTIVE_USERS = 'Active Users';
const QUERY_TEST_VIEW_NAME = 'Test View';

beforeEach(function (): void {
    app(TenantContext::class)->set((int) platformOperatorTenant()->id);
});

function createDatabaseQueryReadOnlyUser(): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.system.database-table.list',
        'is_allowed' => true,
    ]);

    app(TenantContext::class)->set((int) $company->tenant_id);

    return $user;
}

function createNonOperatorDatabaseConsoleAdmin(): User
{
    [$tenant, $company] = createTenantWithCompany();
    $user = User::factory()->create(['company_id' => $company->id]);
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    app(TenantContext::class)->set((int) $tenant->id);

    return $user;
}

// ─── Slug generation ────────────────────────────────────────────────

test('slug generation handles collisions per user', function (): void {
    $user = User::factory()->create();

    $first = Query::query()->create([
        'user_id' => $user->id,
        'name' => QUERY_TEST_ACTIVE_USERS,
        'slug' => Query::generateSlug(QUERY_TEST_ACTIVE_USERS, $user->id),
        'sql_query' => QUERY_TEST_SQL,
    ]);

    $secondSlug = Query::generateSlug(QUERY_TEST_ACTIVE_USERS, $user->id);

    expect($first->slug)->toBe('active-users');
    expect($secondSlug)->toBe('active-users-2');

    // Different user can have the same slug
    $otherUser = User::factory()->create();
    $otherSlug = Query::generateSlug(QUERY_TEST_ACTIVE_USERS, $otherUser->id);

    expect($otherSlug)->toBe('active-users');
});

// ─── Query validation ───────────────────────────────────────────────

test('executor rejects non-SELECT queries', function (string $sql): void {
    $executor = app(QueryExecutor::class);

    expect(fn () => $executor->validate($sql))
        ->toThrow(BlbQueryException::class);
})->with([
    'empty' => [''],
    'INSERT' => ['INSERT INTO users (name) VALUES (\'x\')'],
    'DELETE' => ['DELETE FROM users'],
    'DROP' => ['DROP TABLE users'],
    'UPDATE' => ['UPDATE users SET name = \'x\''],
    'ALTER' => ['ALTER TABLE users ADD col int'],
    'TRUNCATE' => ['TRUNCATE users'],
]);

test('executor rejects SELECT with embedded write keywords', function (): void {
    $executor = app(QueryExecutor::class);

    expect(fn () => $executor->validate('SELECT 1; DROP TABLE users'))
        ->toThrow(BlbQueryException::class, 'DROP');
});

test('executor accepts valid SELECT queries', function (string $sql): void {
    $executor = app(QueryExecutor::class);

    $executor->validate($sql);

    // No exception means validation passed
    expect(true)->toBeTrue();
})->with([
    'simple' => ['SELECT 1'],
    'with FROM' => ['SELECT * FROM users'],
    'lowercase' => ['select id from users'],
    'subquery' => ['SELECT * FROM (SELECT 1) AS sub'],
    'column named deleted_at' => ['SELECT deleted_at FROM users'],
    'column named created_at' => ['SELECT created_at FROM users'],
]);

// ─── CRUD via Livewire ──────────────────────────────────────────────

test('query CRUD operations and sharing', function (): void {
    $owner = createAdminUser();
    $recipient = createAdminUser();

    // Create a saved query
    $view = Query::query()->create([
        'user_id' => $owner->id,
        'name' => QUERY_TEST_VIEW_NAME,
        'slug' => Query::generateSlug(QUERY_TEST_VIEW_NAME, $owner->id),
        'prompt' => 'Show me a test row',
        'sql_query' => QUERY_TEST_SQL,
        'description' => 'Original description',
        'icon' => 'heroicon-o-circle-stack',
    ]);

    // Show page loads for owner
    $this->actingAs($owner)
        ->get(route('admin.system.database-queries.show', $view->slug))
        ->assertOk();

    // Show page 404s for non-owner (user-scoped)
    $this->actingAs($recipient)
        ->get(route('admin.system.database-queries.show', $view->slug))
        ->assertNotFound();

    // Share creates independent copy + auto-pin for recipient
    Livewire\Livewire::actingAs($owner)
        ->test(Show::class, ['slug' => $view->slug])
        ->call('shareWith', $recipient->id);

    $sharedView = Query::query()
        ->where('user_id', $recipient->id)
        ->where('name', QUERY_TEST_VIEW_NAME)
        ->first();

    expect($sharedView)->not->toBeNull();
    expect($sharedView->sql_query)->toBe(QUERY_TEST_SQL);
    expect($sharedView->description)->toContain('Shared by '.$owner->name);

    // Auto-pin was created for recipient
    $recipientPin = UserPin::query()
        ->where('user_id', $recipient->id)
        ->where('url', 'like', '%/database-queries/'.$sharedView->slug)
        ->first();

    expect($recipientPin)->not->toBeNull();
    expect($recipientPin->label)->toBe(QUERY_TEST_VIEW_NAME);

    // Recipient can now access their own copy
    $this->actingAs($recipient)
        ->get(route('admin.system.database-queries.show', $sharedView->slug))
        ->assertOk();

    // Owner deletes original — recipient's copy is unaffected
    Livewire\Livewire::actingAs($owner)
        ->test(Index::class)
        ->call('deleteView', $view->id);

    expect(Query::query()->find($view->id))->toBeNull();
    expect(Query::query()->find($sharedView->id))->not->toBeNull();
});

test('read-only query users cannot reach mutation paths', function (): void {
    $user = createDatabaseQueryReadOnlyUser();
    $recipient = User::factory()->create(['company_id' => $user->company_id]);
    $query = Query::query()->create([
        'user_id' => $user->id,
        'name' => QUERY_TEST_VIEW_NAME,
        'slug' => Query::generateSlug(QUERY_TEST_VIEW_NAME, $user->id),
        'prompt' => 'Show one row',
        'sql_query' => QUERY_TEST_SQL,
        'description' => 'Original description',
    ]);

    $this->actingAs($user)
        ->get(route('admin.system.database-queries.index'))
        ->assertOk()
        ->assertSee('Read-only')
        ->assertDontSee('wire:click="createView"', false)
        ->assertDontSee('wire:click="duplicateView', false)
        ->assertDontSee('wire:click="deleteView', false);

    $this->actingAs($user)
        ->get(route('admin.system.database-queries.show', $query->slug))
        ->assertOk()
        ->assertSee('Read-only')
        ->assertDontSee('wire:click="save"', false)
        ->assertDontSee('wire:click="delete"', false)
        ->assertDontSee('wire:click="runQuery"', false)
        ->assertDontSee('wire:click="generateSql"', false)
        ->assertDontSee('wire:click="shareWith', false);

    $this->actingAs($user)
        ->get(route('admin.system.database-queries.show', '_new'))
        ->assertForbidden();

    $index = Livewire\Livewire::actingAs($user)->test(Index::class);
    $index->call('createView')->assertNoRedirect();
    $index->call('duplicateView', $query->id);
    $index->call('deleteView', $query->id);

    expect(Query::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($query->fresh())->not->toBeNull();

    $show = Livewire\Livewire::actingAs($user)
        ->test(Show::class, ['slug' => $query->slug])
        ->set('editName', 'Unauthorized name')
        ->set('editSql', 'SELECT 2 AS id')
        ->call('save')
        ->call('runQuery')
        ->call('generateSql')
        ->call('shareWith', $recipient->id)
        ->call('delete');

    $show->set('shareSearch', $recipient->email);

    expect($query->fresh()?->name)->toBe(QUERY_TEST_VIEW_NAME)
        ->and($query->fresh()?->sql_query)->toBe(QUERY_TEST_SQL)
        ->and($show->instance()->shareableUsers())->toBe([])
        ->and(Query::query()->where('user_id', $recipient->id)->exists())->toBeFalse()
        ->and(UserPin::query()->where('user_id', $recipient->id)->exists())->toBeFalse();
});

test('fully capable non-operator tenants cannot reach the database console', function (): void {
    $user = createNonOperatorDatabaseConsoleAdmin();

    $routes = [
        route('admin.system.database.index'),
        route('admin.system.database-tables.index'),
        route('admin.system.database-tables.show', 'users'),
        route('admin.system.database-queries.index'),
        route('admin.system.database-queries.show', '_new'),
    ];

    foreach ($routes as $route) {
        $this->actingAs($user)->get($route)->assertForbidden();
    }
});

test('database console menu entries are visible only to the operator tenant', function (): void {
    $operator = createAdminUser();

    expect(app(MenuConditionRegistry::class)->allows('tenancy.platform_operator', $operator))->toBeTrue();

    $nonOperator = createNonOperatorDatabaseConsoleAdmin();

    expect(app(MenuConditionRegistry::class)->allows('tenancy.platform_operator', $nonOperator))->toBeFalse();
});

test('live database console actions re-check the operator tenant before mutation', function (): void {
    $operator = createAdminUser();
    $query = Query::query()->create([
        'user_id' => $operator->id,
        'name' => QUERY_TEST_VIEW_NAME,
        'slug' => Query::generateSlug(QUERY_TEST_VIEW_NAME, $operator->id),
        'sql_query' => QUERY_TEST_SQL,
    ]);
    $component = Livewire\Livewire::actingAs($operator)->test(Index::class);
    $nonOperator = createTenant();

    app(TenantContext::class)->set((int) $nonOperator->id);

    $component->call('deleteView', $query->id)->assertForbidden();

    expect($query->fresh())->not->toBeNull();
});

// ─── Query execution ────────────────────────────────────────────────

test('executor returns structured result for valid query', function (): void {
    $executor = app(QueryExecutor::class);

    $result = $executor->execute(QUERY_TEST_SQL);

    expect($result['columns'])->toBe(['id', 'name']);
    expect($result['rows'])->toHaveCount(1);
    expect($result['rows'][0])->toMatchArray(['id' => 1, 'name' => 'hello']);
    expect($result['total'])->toBe(1);
    expect($result['current_page'])->toBe(1);
    expect($result['last_page'])->toBe(1);
});

test('executor arms the PostgreSQL read-only transaction guard, not only the SELECT validation', function (): void {
    // SKIP CATEGORY: test mechanism. `transaction_read_only` is a PostgreSQL
    // setting and SQLite has no transaction-level read-only mode at all, so
    // there is no SQLite behaviour for this assertion to read — QueryExecutor
    // documents application-level enforcement as the whole guard on that
    // driver, and the SELECT-only validation tests above are that guard. This
    // is not a "fails on SQLite" skip: nothing about the code's behaviour is
    // being hidden, only a setting that one driver does not have.
    if (DB::connection('readonly')->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires a real PostgreSQL connection (DB_CONNECTION=pgsql) — see the postgres-mirror CI job or a local pgsql .env.');
    }

    $result = app(QueryExecutor::class)->execute("SELECT current_setting('transaction_read_only') AS read_only");

    expect($result['rows'][0]['read_only'])->toBe('on');
});

test('executor rejects a non-operator tenant before touching the database', function (): void {
    createNonOperatorDatabaseConsoleAdmin();

    try {
        app(QueryExecutor::class)->execute('SELECT * FROM definitely_missing_operator_gate_table');
    } catch (BlbQueryException $exception) {
        expect($exception->reasonCode)->toBe(DatabaseErrorCode::DATABASE_QUERY_PLATFORM_OPERATOR_REQUIRED)
            ->and($exception->getMessage())->toContain('platform-operator tenant');

        return;
    }

    $this->fail('Expected the database query engine to refuse a non-operator tenant.');
});

test('database query SQL generation attaches trace tap from the trace context factory', function (): void {
    app()->instance(UrlSafetyGuard::class, new PermissiveUrlSafetyGuard);
    $user = createAdminUser();
    $this->actingAs($user);

    $provider = AiProvider::query()->create([
        'company_id' => $user->company_id,
        'name' => 'trace-provider',
        'display_name' => 'Trace Provider',
        'base_url' => 'https://trace-provider.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'trace-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'trace-model',
        'is_active' => true,
        'is_default' => true,
    ]);

    $traceTap = Mockery::mock(LlmTransportTap::class);

    $traceContextFactory = Mockery::mock(LlmTraceContextFactory::class);
    $traceContextFactory->shouldReceive('start')
        ->once()
        ->with('base_database_query_generator', Mockery::on(fn (array $metadata): bool => (
            ($metadata['action'] ?? null) === 'generate_sql'
            && ($metadata['selected_model_id'] ?? null) === $provider->id.':::trace-model'
        )))
        ->andReturn(new LlmTraceContext(
            correlationId: 'trace-correlation',
            source: 'base_database_query_generator',
            transportTap: $traceTap,
        ));

    app()->instance(LlmTraceContextFactory::class, $traceContextFactory);

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chat')
        ->once()
        ->with(Mockery::on(function (ChatRequest $request) use ($traceTap, $provider): bool {
            return $request->transportTap === $traceTap
                && $request->providerName === $provider->name
                && $request->messages !== [];
        }))
        ->andReturn([
            'content' => "TITLE: Trace Query\nDESCRIPTION: Generated with tracing\nSQL: SELECT 1",
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
            'latency_ms' => 18,
        ]);

    app()->instance(LlmClient::class, $llmClient);

    Livewire\Livewire::test(Show::class, ['slug' => '_new'])
        ->set('selectedModelId', $provider->id.':::trace-model')
        ->set('editPrompt', 'Show one row')
        ->call('generateSql')
        ->assertSet('aiError', '')
        ->assertSet('editSql', 'SELECT 1');
});
