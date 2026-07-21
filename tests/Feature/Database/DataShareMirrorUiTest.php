<?php

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorBlocker;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorExecutionResult;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReviewItem;
use App\Base\Database\Enums\DataShareMirrorAction;
use App\Base\Database\Enums\DataShareMirrorDirection;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Livewire\DataShare\Index as DataShareIndex;
use App\Base\Database\Livewire\DataShare\Settings as DataShareSettingsPage;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $catalog = Mockery::mock(DataShareScopeCatalog::class);
    $catalog->shouldReceive('scopes')->zeroOrMoreTimes()->andReturn([]);
    $catalog->shouldReceive('discover')->zeroOrMoreTimes()->andReturn(['scopes' => [], 'rejected' => []]);
    app()->instance(DataShareScopeCatalog::class, $catalog);
    $mirror = Mockery::mock(DataShareMirrorManager::class)->shouldIgnoreMissing();
    $mirror->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn([
        'supabase' => 'Supabase',
        'postgresql' => 'PostgreSQL',
    ]);
    app()->instance(DataShareMirrorManager::class, $mirror);
});

function configureDevelopmentMirrorUiIdentity(string $role = 'development'): void
{
    $settings = app(SettingsService::class);
    $settings->set('data_share.instance.id', 'mirror-ui-local');
    $settings->set('data_share.instance.name', 'Mirror UI Local');
    $settings->set('data_share.instance.role', $role);
}

/** @return list<array<string, mixed>> */
function mirrorUiCatalogFixture(): array
{
    return [
        [
            'table' => 'ham_orders',
            'module_name' => 'Ham',
            'module_path' => 'blb/ham',
            'local_exists' => true,
            'mirror_exists' => false,
            'supported' => true,
            'blockers' => [],
        ],
        [
            'table' => 'ham_order_lines',
            'module_name' => 'Ham',
            'module_path' => 'blb/ham',
            'local_exists' => true,
            'mirror_exists' => true,
            'supported' => true,
            'blockers' => [],
        ],
        [
            'table' => 'sbg_runs',
            'module_name' => 'Sbg',
            'module_path' => 'blb/sbg',
            'local_exists' => false,
            'mirror_exists' => true,
            'supported' => false,
            'blockers' => [
                ['code' => 'missing_prerequisite', 'message' => 'Required parent table sbg_projects is missing.'],
            ],
        ],
    ];
}

it('renders the development-only mirror as an explicit table-first workflow', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());

    Livewire::test(DataShareIndex::class)
        ->set('mirrorCatalogLoaded', true)
        ->set('mirrorConnectionStatus', [
            'configured' => true,
            'available' => true,
            'reachable' => true,
            'server_version' => '17',
            'provider_label' => 'Supabase',
            'transfer_mode' => 'portable',
        ])
        ->set('mirrorTables', mirrorUiCatalogFixture())
        ->assertSee('Mirror complete development tables')
        ->assertSee('Push selected tables to Supabase')
        ->assertSee('Pull selected tables from Supabase')
        ->set('mirrorSelectedTables', ['ham_orders', 'ham_order_lines'])
        ->assertSee('Push 2 selected tables to Supabase')
        ->assertSee('Pull 2 selected tables from Supabase')
        ->assertSee('Required parent table sbg_projects is missing.');
});

it('materializes only visible table names and never auto-selects on module changes', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());

    Livewire::test(DataShareIndex::class)
        ->set('mirrorTables', mirrorUiCatalogFixture())
        ->set('mirrorModulePath', 'blb/ham')
        ->call('selectAllVisibleMirrorTables')
        ->assertSet('mirrorSelectedTables', ['ham_orders', 'ham_order_lines'])
        ->set('mirrorModulePath', 'blb/sbg')
        ->assertSet('mirrorSelectedTables', ['ham_orders', 'ham_order_lines'])
        ->call('selectAllVisibleMirrorTables')
        ->assertSet('mirrorSelectedTables', ['ham_orders', 'ham_order_lines', 'sbg_runs'])
        ->call('clearMirrorSelection')
        ->assertSet('mirrorSelectedTables', []);
});

it('reviews an exact push payload before executing the same payload and state token', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $review = new DataShareMirrorReview(
        DataShareMirrorDirection::Push,
        [
            new DataShareMirrorReviewItem(
                'ham_orders',
                DataShareMirrorAction::Create,
                DataShareMirrorAction::Create,
            ),
        ],
        false,
        ['create' => 1, 'replace' => 0, 'delete' => 0, 'blocked' => 0],
        'mirror-review-state',
    );
    $manager->shouldReceive('review')
        ->once()
        ->with('push', ['ham_orders'])
        ->ordered()
        ->andReturn($review);
    $manager->shouldReceive('execute')
        ->once()
        ->with('push', ['ham_orders'], 'mirror-review-state')
        ->ordered()
        ->andReturn(new DataShareMirrorExecutionResult(
            DataShareMirrorDirection::Push,
            ['create' => 1, 'replace' => 0, 'delete' => 0],
            [['table' => 'ham_orders', 'action' => 'create']],
        ));
    $manager->shouldReceive('catalog')->once()->andReturn([]);
    app()->instance(DataShareMirrorManager::class, $manager);

    $component = Livewire::test(DataShareIndex::class)
        ->set('mirrorSelectedTables', ['ham_orders'])
        ->call('reviewMirror', 'push')
        ->assertSet('mirrorDirection', 'push')
        ->assertSet('mirrorReview.state_token', 'mirror-review-state')
        ->assertSet('mirrorReview.items.0.action', 'create')
        ->assertSee('No changes yet');

    expect($component->get('mirrorResult'))->toBeNull();

    $component
        ->call('executeMirror')
        ->assertSet('mirrorReview', null)
        ->assertSet('mirrorResult.counts.create', 1)
        ->assertSet('statusVariant', 'success');
});

it('reports a commit-time connection failure as indeterminate', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('review')
        ->once()
        ->with('push', ['ham_orders'])
        ->andReturn(new DataShareMirrorReview(
            DataShareMirrorDirection::Push,
            [
                new DataShareMirrorReviewItem(
                    'ham_orders',
                    DataShareMirrorAction::Replace,
                    DataShareMirrorAction::Replace,
                ),
            ],
            false,
            ['create' => 0, 'replace' => 1, 'delete' => 0, 'blocked' => 0],
            'commit-time-review',
        ));
    $manager->shouldReceive('execute')
        ->once()
        ->with('push', ['ham_orders'], 'commit-time-review')
        ->andThrow(DataShareMirrorException::processFailed('psql', 1));
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareIndex::class)
        ->set('mirrorSelectedTables', ['ham_orders'])
        ->call('reviewMirror', 'push')
        ->call('executeMirror')
        ->assertSet('mirrorReview', null)
        ->assertSet('mirrorResult', null)
        ->assertSet('statusVariant', 'danger')
        ->assertSet('statusMessage', 'The mirror did not report a successful commit. Refresh the catalog and inspect the selected tables before retrying; the outcome may be indeterminate if the connection ended during commit.')
        ->assertDontSee('no selected-table changes were committed');
});

it('reports a stale final review as safe to review again rather than indeterminate', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('review')->once()->andReturn(new DataShareMirrorReview(
        DataShareMirrorDirection::Push,
        [new DataShareMirrorReviewItem('ham_orders', DataShareMirrorAction::Replace, DataShareMirrorAction::Replace)],
        false,
        ['create' => 0, 'replace' => 1, 'delete' => 0, 'blocked' => 0],
        'stale-review-token',
    ));
    $manager->shouldReceive('execute')
        ->once()
        ->with('push', ['ham_orders'], 'stale-review-token')
        ->andThrow(DataShareMirrorException::staleReview());
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareIndex::class)
        ->set('mirrorSelectedTables', ['ham_orders'])
        ->call('reviewMirror', 'push')
        ->call('executeMirror')
        ->assertSet('mirrorReview', null)
        ->assertSet('mirrorResult', null)
        ->assertSet('statusVariant', 'warning')
        ->assertSet('statusMessage', 'The table state changed after review. Review the selected tables again before executing.')
        ->assertDontSee('outcome may be indeterminate');
});

it('keeps a blocked review visible and never calls execution', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('review')
        ->once()
        ->with('pull', ['sbg_runs'])
        ->andReturn(new DataShareMirrorReview(
            DataShareMirrorDirection::Pull,
            [
                new DataShareMirrorReviewItem(
                    'sbg_runs',
                    DataShareMirrorAction::Blocked,
                    DataShareMirrorAction::Create,
                    [new DataShareMirrorBlocker(
                        'missing_prerequisite',
                        'Required parent table sbg_projects is missing.',
                    )],
                ),
            ],
            true,
            ['create' => 0, 'replace' => 0, 'delete' => 0, 'blocked' => 1],
            'blocked-state',
        ));
    $manager->shouldNotReceive('execute');
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareIndex::class)
        ->set('mirrorSelectedTables', ['sbg_runs'])
        ->call('reviewMirror', 'pull')
        ->assertSet('mirrorDirection', 'pull')
        ->assertSet('mirrorReview.has_blockers', true)
        ->assertSee('Required parent table sbg_projects is missing.')
        ->call('executeMirror')
        ->assertSet('statusVariant', 'warning')
        ->assertSet('mirrorReview.has_blockers', true);
});

it('refuses an empty mirror selection before calling the reviewer', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldNotReceive('review');
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareIndex::class)
        ->call('reviewMirror', 'push')
        ->assertHasErrors(['mirrorSelectedTables']);
});

it('does not render the mirror operator surface outside development', function (): void {
    configureDevelopmentMirrorUiIdentity('production');
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.data-share.index'))
        ->assertOk()
        ->assertDontSee('Mirror complete development tables');
});

it('keeps the saved mirror URL write-only, preserves it on blank save, and removes it deliberately', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $settings = app(SettingsService::class);
    $secretUrl = 'postgresql://mirror-user:do-not-render@example.test:5432/postgres?sslmode=require';
    $settings->set('data_share.mirror.url', $secretUrl, encrypted: true);

    $component = Livewire::test(DataShareSettingsPage::class)
        ->assertSet('values.data_share__mirror__url', '******')
        ->assertDontSee($secretUrl)
        ->assertSee('Cloud provider')
        ->assertSee('Supabase')
        ->assertSee('PostgreSQL')
        ->assertSee('Test connection')
        ->assertSee('Finish setup')
        ->assertSee('Remove connection')
        ->set('values.data_share__mirror__url', '')
        ->call('save')
        ->assertHasNoErrors();

    $component
        ->call('startSupabaseReplacement')
        ->assertSet('replaceSavedSupabaseConnection', true)
        ->assertSee('Change Supabase connection')
        ->assertSee('Find my projects')
        ->call('cancelSupabaseReplacement')
        ->assertSet('replaceSavedSupabaseConnection', false)
        ->assertSee('Supabase connection saved');

    expect($settings->get('data_share.mirror.url'))->toBe($secretUrl)
        ->and(Setting::query()->where('key', 'data_share.mirror.url')->firstOrFail()->is_encrypted)->toBeTrue()
        ->and(Setting::query()->where('key', 'data_share.mirror.url')->firstOrFail()->getRawOriginal('value'))
        ->not->toContain('do-not-render');

    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase', 'postgresql' => 'PostgreSQL']);
    $manager->shouldReceive('disconnect')->once();
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->call('removeMirrorConnection')
        ->assertSet('values.data_share__mirror__url', '');

    expect($settings->has('data_share.mirror.url'))->toBeFalse();
});

it('renders Supabase as guided account and project setup instead of requiring a database URL', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());

    Livewire::test(DataShareSettingsPage::class)
        ->assertSee('Connect Supabase')
        ->assertSee('Get Supabase access token')
        ->assertSee('Find my projects')
        ->assertSee('You never need to assemble a database URL.')
        ->assertSee('Advanced connection');
});

it('discovers Supabase organizations and projects from a transient access token', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    Http::fake([
        'api.supabase.com/v1/organizations' => Http::response([
            ['id' => 'org-id', 'slug' => 'acme-dev', 'name' => 'Acme Development'],
        ]),
        'api.supabase.com/v1/projects' => Http::response([
            [
                'ref' => 'abcdefghijklmnopqrst',
                'name' => 'Existing Development',
                'organization_slug' => 'acme-dev',
                'region' => 'ap-southeast-1',
                'status' => 'ACTIVE_HEALTHY',
                'database' => ['host' => 'db.abcdefghijklmnopqrst.supabase.co'],
            ],
        ]),
    ]);

    Livewire::test(DataShareSettingsPage::class)
        ->set('supabaseAccessToken', 'sbp_transient-token-do-not-store')
        ->call('discoverSupabase')
        ->assertHasNoErrors()
        ->assertSet('supabaseAccessToken', '')
        ->assertSet('supabaseDiscoveryComplete', true)
        ->assertSet('supabaseOrganizationSlug', 'acme-dev')
        ->assertSet('supabaseProjectRef', 'abcdefghijklmnopqrst')
        ->assertSee('Create a dedicated project')
        ->assertSee('Use an existing development project')
        ->assertSee('Acme Development')
        ->assertDontSee('sbp_transient-token-do-not-store');

    $encryptedSessionToken = session()->get('data_share.mirror.supabase.setup_token');
    expect(app(SettingsService::class)->has('data_share.mirror.supabase.access_token'))->toBeFalse()
        ->and($encryptedSessionToken)->toBeString()->not->toContain('sbp_transient-token-do-not-store');
});

it('turns Supabase authentication failures into a safe actionable field error', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    Http::fake([
        'api.supabase.com/v1/organizations' => Http::response([
            'message' => 'internal response containing sbp_private-token and provider diagnostics',
        ], 401),
    ]);

    Livewire::test(DataShareSettingsPage::class)
        ->set('supabaseAccessToken', 'sbp_private-token')
        ->call('discoverSupabase')
        ->assertHasErrors(['supabaseAccessToken'])
        ->assertSee('Supabase did not accept this access token. Create a new token and try again.')
        ->assertDontSee('internal response')
        ->assertDontSee('sbp_private-token')
        ->assertSet('supabaseDiscoveryComplete', false);

    expect(session()->has('data_share.mirror.supabase.setup_token'))->toBeFalse();
});

it('configures and initializes an existing Supabase project without asking for its URL', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $accessToken = 'sbp_existing-project-token';
    $databasePassword = 'Existing database password = private';
    Http::fake([
        'api.supabase.com/v1/organizations' => Http::response([
            ['id' => 'org-id', 'slug' => 'acme-dev', 'name' => 'Acme Development'],
        ]),
        'api.supabase.com/v1/projects' => Http::response([
            [
                'ref' => 'abcdefghijklmnopqrst',
                'name' => 'Existing Development',
                'organization_slug' => 'acme-dev',
                'region' => 'ap-southeast-1',
                'status' => 'ACTIVE_HEALTHY',
                'database' => ['host' => 'db.abcdefghijklmnopqrst.supabase.co'],
            ],
        ]),
        'api.supabase.com/v1/projects/abcdefghijklmnopqrst' => Http::response([
            'ref' => 'abcdefghijklmnopqrst',
            'name' => 'Existing Development',
            'organization_slug' => 'acme-dev',
            'region' => 'ap-southeast-1',
            'status' => 'ACTIVE_HEALTHY',
            'database' => ['host' => 'db.abcdefghijklmnopqrst.supabase.co'],
        ]),
        'api.supabase.com/v1/projects/abcdefghijklmnopqrst/config/database/pgbouncer' => Http::response([
            'connection_string' => 'postgres://postgres.abcdefghijklmnopqrst:[YOUR-PASSWORD]@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres',
        ]),
    ]);
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn([
        'supabase' => 'Supabase',
        'postgresql' => 'PostgreSQL',
    ]);
    $manager->shouldReceive('testConnection')
        ->once()
        ->withArgs(fn (string $url, string $provider): bool => $provider === 'supabase'
            && str_contains($url, 'postgres.abcdefghijklmnopqrst')
            && str_contains($url, 'aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres')
            && str_contains($url, rawurlencode($databasePassword)))
        ->andReturn(new DataShareMirrorConnectionStatus(
            configured: true,
            available: true,
            reachable: true,
            driver: 'pgsql',
            localRole: 'development',
            remoteRole: 'development',
            serverVersion: '17',
            pgDumpVersion: null,
            psqlVersion: null,
            reasonCode: null,
            message: 'Supabase mirror ready.',
            providerKey: 'supabase',
            providerLabel: 'Supabase',
            localDriver: 'sqlite',
            transferMode: 'portable',
        ));
    $manager->shouldReceive('disconnect')->once();
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->set('supabaseAccessToken', $accessToken)
        ->call('discoverSupabase')
        ->set('supabaseSetupChoice', 'existing')
        ->set('supabaseProjectRef', 'abcdefghijklmnopqrst')
        ->set('supabaseDatabasePassword', $databasePassword)
        ->call('useExistingSupabaseProject')
        ->assertHasNoErrors()
        ->assertSet('supabaseAccessToken', '')
        ->assertSet('supabaseDatabasePassword', '')
        ->assertSet('values.data_share__mirror__url', '******')
        ->assertSee('Supabase connection saved')
        ->assertDontSee($accessToken)
        ->assertDontSee($databasePassword);

    $settings = app(SettingsService::class);
    $stored = Setting::query()->where('key', 'data_share.mirror.url')->firstOrFail();
    expect($settings->get('data_share.mirror.url'))
        ->toContain('aws-0-ap-southeast-1.pooler.supabase.com')
        ->and($stored->is_encrypted)->toBeTrue()
        ->and($stored->getRawOriginal('value'))->not->toContain($databasePassword)
        ->and($settings->get('data_share.mirror.supabase.project_name'))->toBe('Existing Development');

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), $databasePassword));
});

it('creates a dedicated Supabase project with a generated password that never enters browser state', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $generatedPassword = null;
    Http::fake(function (Request $request) use (&$generatedPassword) {
        if ($request->url() === 'https://api.supabase.com/v1/organizations') {
            return Http::response([
                ['id' => 'org-id', 'slug' => 'acme-dev', 'name' => 'Acme Development'],
            ]);
        }

        if ($request->url() === 'https://api.supabase.com/v1/projects' && $request->method() === 'GET') {
            return Http::response([]);
        }

        if ($request->url() === 'https://api.supabase.com/v1/projects' && $request->method() === 'POST') {
            $generatedPassword = $request->data()['db_pass'] ?? null;

            return Http::response([
                'ref' => 'zyxwvutsrqponmlkjihg',
                'name' => 'Mirror UI Local development mirror',
                'organization_slug' => 'acme-dev',
                'region' => 'ap-southeast-1',
                'status' => 'COMING_UP',
            ], 201);
        }

        return Http::response([], 404);
    });
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn([
        'supabase' => 'Supabase',
        'postgresql' => 'PostgreSQL',
    ]);
    $manager->shouldReceive('testConnection')
        ->once()
        ->withArgs(fn (string $url, string $provider): bool => $provider === 'supabase'
            && str_contains($url, 'db.zyxwvutsrqponmlkjihg.supabase.co:5432/postgres'))
        ->andReturn(new DataShareMirrorConnectionStatus(
            configured: true,
            available: false,
            reachable: false,
            driver: 'pgsql',
            localRole: 'development',
            remoteRole: null,
            serverVersion: null,
            pgDumpVersion: null,
            psqlVersion: null,
            reasonCode: 'connection_failed',
            message: 'The new project is still provisioning.',
            providerKey: 'supabase',
            providerLabel: 'Supabase',
            localDriver: 'sqlite',
            transferMode: 'portable',
        ));
    $manager->shouldReceive('disconnect')->once();
    app()->instance(DataShareMirrorManager::class, $manager);

    $component = Livewire::test(DataShareSettingsPage::class)
        ->set('supabaseAccessToken', 'sbp_create-project-token')
        ->call('discoverSupabase')
        ->set('supabaseOrganizationSlug', 'acme-dev')
        ->set('supabaseRegionGroup', 'apac')
        ->call('createSupabaseMirror')
        ->assertHasNoErrors()
        ->assertSet('supabaseAccessToken', '')
        ->assertSet('values.data_share__mirror__url', '******')
        ->assertSee('Supabase connection saved')
        ->assertSee('Finish setup')
        ->assertDontSee('sbp_create-project-token');

    expect($generatedPassword)->toBeString()->toHaveLength(48);
    $component->assertDontSee($generatedPassword);

    $settings = app(SettingsService::class);
    $stored = Setting::query()->where('key', 'data_share.mirror.url')->firstOrFail();
    expect($stored->is_encrypted)->toBeTrue()
        ->and($stored->getRawOriginal('value'))->not->toContain($generatedPassword)
        ->and($settings->get('data_share.mirror.supabase.project_ref'))->toBe('zyxwvutsrqponmlkjihg');
});

it('preserves a generated project password when database preflight fails after Supabase creation', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $generatedPassword = null;
    Http::fake(function (Request $request) use (&$generatedPassword) {
        if ($request->url() === 'https://api.supabase.com/v1/organizations') {
            return Http::response([
                ['id' => 'org-id', 'slug' => 'acme-dev', 'name' => 'Acme Development'],
            ]);
        }

        if ($request->url() === 'https://api.supabase.com/v1/projects' && $request->method() === 'GET') {
            return Http::response([]);
        }

        if ($request->url() === 'https://api.supabase.com/v1/projects' && $request->method() === 'POST') {
            $generatedPassword = $request->data()['db_pass'] ?? null;

            return Http::response([
                'ref' => 'recoverableprojectref',
                'name' => 'Recoverable mirror',
                'organization_slug' => 'acme-dev',
                'region' => 'ap-southeast-1',
                'status' => 'COMING_UP',
            ], 201);
        }

        return Http::response([], 404);
    });
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn([
        'supabase' => 'Supabase',
        'postgresql' => 'PostgreSQL',
    ]);
    $manager->shouldReceive('disconnect')->once();
    $manager->shouldReceive('testConnection')->once()->andThrow(new RuntimeException(
        'postgresql://private-user:private-password@private-host.example/postgres',
    ));
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->set('supabaseAccessToken', 'sbp_recoverable-project-token')
        ->call('discoverSupabase')
        ->set('supabaseOrganizationSlug', 'acme-dev')
        ->call('createSupabaseMirror')
        ->assertHasNoErrors()
        ->assertSet('supabaseAccessToken', '')
        ->assertSet('values.data_share__mirror__url', '******')
        ->assertSee('Supabase connection saved')
        ->assertDontSee('private-host.example')
        ->assertDontSee('private-password');

    $settings = app(SettingsService::class);
    $stored = Setting::query()->where('key', 'data_share.mirror.url')->firstOrFail();
    expect($generatedPassword)->toBeString()->toHaveLength(48)
        ->and($settings->get('data_share.mirror.url'))->toContain(rawurlencode($generatedPassword))
        ->and($stored->getRawOriginal('value'))->not->toContain($generatedPassword);
});

it('tests a replacement URL before saving it encrypted and write-only', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $candidateUrl = 'postgresql://mirror-user:new-secret@example.test:5432/postgres?sslmode=require';
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase', 'postgresql' => 'PostgreSQL']);
    $manager->shouldReceive('testConnection')
        ->twice()
        ->with($candidateUrl, 'supabase')
        ->andReturn(new DataShareMirrorConnectionStatus(
            configured: true,
            available: true,
            reachable: true,
            driver: 'pgsql',
            localRole: 'development',
            remoteRole: 'development',
            serverVersion: '17',
            pgDumpVersion: '17',
            psqlVersion: '17',
            reasonCode: null,
            message: 'Development mirror is available.',
        ));
    app()->instance(DataShareMirrorManager::class, $manager);
    $settings = app(SettingsService::class);

    $component = Livewire::test(DataShareSettingsPage::class)
        ->set('values.data_share__mirror__url', $candidateUrl)
        ->call('testMirrorConnection')
        ->assertHasNoErrors();

    expect($settings->has('data_share.mirror.url'))->toBeFalse();

    $component
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('values.data_share__mirror__url', '******')
        ->assertDontSee($candidateUrl);

    $stored = Setting::query()->where('key', 'data_share.mirror.url')->firstOrFail();
    expect($settings->get('data_share.mirror.url'))->toBe($candidateUrl)
        ->and($stored->is_encrypted)->toBeTrue()
        ->and($stored->getRawOriginal('value'))->not->toContain('new-secret');
});

it('accepts a reachable empty provider so it can be saved and initialized', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $candidateUrl = 'postgresql://mirror-user:new-secret@example.test:5432/postgres?sslmode=require';
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase', 'postgresql' => 'PostgreSQL']);
    $manager->shouldReceive('testConnection')
        ->twice()
        ->with($candidateUrl, 'supabase')
        ->andReturn(new DataShareMirrorConnectionStatus(
            configured: true,
            available: false,
            reachable: true,
            driver: 'pgsql',
            localRole: 'development',
            remoteRole: null,
            serverVersion: '17',
            pgDumpVersion: null,
            psqlVersion: null,
            reasonCode: 'remote_not_initialized',
            message: 'Initialize the provider schema.',
            providerKey: 'supabase',
            providerLabel: 'Supabase',
            localDriver: 'sqlite',
            transferMode: 'portable',
            initializable: true,
        ));
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->set('values.data_share__mirror__url', $candidateUrl)
        ->call('testMirrorConnection')
        ->assertHasNoErrors()
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('values.data_share__mirror__url', '******');

    expect(app(SettingsService::class)->get('data_share.mirror.provider'))->toBe('supabase')
        ->and(app(SettingsService::class)->get('data_share.mirror.url'))->toBe($candidateUrl);
});

it('rejects a non-PostgreSQL mirror URL before connection testing', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase', 'postgresql' => 'PostgreSQL']);
    $manager->shouldNotReceive('testConnection');
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->set('values.data_share__mirror__url', 'https://example.test/postgres')
        ->call('testMirrorConnection')
        ->assertHasErrors(['values.data_share__mirror__url']);
});

it('denies mirror review to an authenticated user without execute authority', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
    $this->actingAs($user);

    Livewire::test(DataShareIndex::class)
        ->set('mirrorSelectedTables', ['ham_orders'])
        ->call('reviewMirror', 'push')
        ->assertForbidden();
});
