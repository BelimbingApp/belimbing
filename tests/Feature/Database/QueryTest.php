<?php

use App\Base\AI\Contracts\LlmTransportTap;
use App\Base\AI\Contracts\Tracing\LlmTraceContextFactory;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Services\LlmClient;
use App\Base\AI\Services\Tracing\LlmTraceContext;
use App\Base\Database\Exceptions\BlbQueryException;
use App\Base\Database\Livewire\Queries\Index;
use App\Base\Database\Livewire\Queries\Show;
use App\Base\Database\Services\QueryExecutor;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\User\Models\Query;
use App\Modules\Core\User\Models\User;
use App\Modules\Core\User\Models\UserPin;

const QUERY_TEST_SQL = 'SELECT 1 AS id, \'hello\' AS name';
const QUERY_TEST_ACTIVE_USERS = 'Active Users';
const QUERY_TEST_VIEW_NAME = 'Test View';

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

test('database query SQL generation attaches trace tap from the trace context factory', function (): void {
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
