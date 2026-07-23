<?php

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorBlocker;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
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
use App\Base\Database\Services\DataShare\Mirror\SupabaseMirrorSetupService;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('restores a fresh mirror catalog snapshot on page reload and refreshes it only on demand', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase']);
    $manager->shouldReceive('configurationFingerprint')->zeroOrMoreTimes()->andReturn('saved-mirror-fingerprint');
    $manager->shouldReceive('status')->zeroOrMoreTimes()->andReturn(new DataShareMirrorConnectionStatus(
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
        message: 'Ready.',
        providerKey: 'supabase',
        providerLabel: 'Supabase',
        localDriver: 'pgsql',
        transferMode: 'portable',
    ));
    $hamRows = [new DataShareMirrorCatalogTable('ham_orders', 'Ham', 'blb/ham', null, true, true, 'table', 'table', true)];
    // Local-first: localCatalog() renders immediately; catalog() enriches after.
    $manager->shouldReceive('localCatalog')->zeroOrMoreTimes()->andReturn($hamRows);
    $manager->shouldReceive('catalog')->zeroOrMoreTimes()->andReturn($hamRows);
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareIndex::class)
        ->call('dataShareTabSelected', 'mirror')
        ->assertSet('mirrorCatalogLoaded', true)
        ->assertSet('mirrorTables.0.table', 'ham_orders') // Local rows render before remote enrichment
        ->assertSet('mirrorRemotePending', true)
        ->call('enrichMirrorRemote')
        ->assertSet('mirrorRemotePending', false)
        ->assertSet('mirrorTables.0.table', 'ham_orders');

    Livewire::test(DataShareIndex::class) // reload serves the cached enriched snapshot
        ->assertSet('mirrorCatalogLoaded', true)
        ->assertSet('mirrorTables.0.table', 'ham_orders')
        ->call('refreshMirrorCatalog')
        ->assertSet('mirrorTables.0.table', 'ham_orders');
});

it('excludes permanently protected infrastructure tables from the mirror list', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase']);
    $manager->shouldReceive('configurationFingerprint')->zeroOrMoreTimes()->andReturn('saved-mirror-fingerprint');
    $manager->shouldReceive('status')->zeroOrMoreTimes()->andReturn(new DataShareMirrorConnectionStatus(
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
        message: 'Ready.',
        providerKey: 'supabase',
        providerLabel: 'Supabase',
        localDriver: 'pgsql',
        transferMode: 'portable',
    ));
    // Mirrors what DataShareMirrorCatalog::catalog() actually returns for a
    // protected table: unsupported, with a single protected_table blocker.
    $manager->shouldReceive('localCatalog')->zeroOrMoreTimes()->andReturn([
        new DataShareMirrorCatalogTable(
            'ham_orders', 'Ham', 'blb/ham', null, true, true, 'table', 'table', true,
        ),
        new DataShareMirrorCatalogTable(
            'cache', null, null, null, true, true, 'table', 'table', false,
            blockers: [new DataShareMirrorBlocker(
                'protected_table',
                'cache is Base infrastructure or runtime state and cannot be mirrored.',
            )],
        ),
    ]);
    app()->instance(DataShareMirrorManager::class, $manager);

    $component = Livewire::test(DataShareIndex::class)
        ->call('dataShareTabSelected', 'mirror')
        ->assertDontSee('cache is Base infrastructure');

    expect(collect($component->get('mirrorTables'))->pluck('table')->all())->toBe(['ham_orders']);
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
    $manager->shouldReceive('configurationFingerprint')->once()->andReturn('saved-mirror-fingerprint');
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
        ->assertSet('statusMessage', 'psql could not complete the mirror operation. (exit 1) The commit outcome could not be confirmed. Refresh the catalog and inspect the selected tables before retrying.')
        ->assertDontSee('no selected-table changes were committed');
});

it('offers destructive force push for schema blockers but never force pull', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $blockedReview = fn (DataShareMirrorDirection $direction) => new DataShareMirrorReview(
        $direction,
        [new DataShareMirrorReviewItem(
            'ham_orders',
            DataShareMirrorAction::Blocked,
            DataShareMirrorAction::Replace,
            [new DataShareMirrorBlocker('schema_incompatible', 'Schemas differ.')],
        )],
        true,
        ['create' => 0, 'replace' => 0, 'delete' => 0, 'blocked' => 1],
        'blocked-schema-state',
    );
    $manager->shouldReceive('review')->once()->with('push', ['ham_orders'])->andReturn($blockedReview(DataShareMirrorDirection::Push));
    $manager->shouldReceive('forcePush')->once()->with(['ham_orders'], 'blocked-schema-state')->andReturn(new DataShareMirrorExecutionResult(
        DataShareMirrorDirection::Push,
        ['create' => 0, 'replace' => 1, 'delete' => 0],
        [['table' => 'ham_orders', 'action' => 'replace', 'local_rows' => 1234, 'remote_rows' => 1234]],
    ));
    $manager->shouldReceive('review')->once()->with('pull', ['ham_orders'])->andReturn($blockedReview(DataShareMirrorDirection::Pull));
    app()->instance(DataShareMirrorManager::class, $manager);

    $component = Livewire::test(DataShareIndex::class)
        ->set('mirrorSelectedTables', ['ham_orders'])
        ->call('reviewMirror', 'push')
        ->assertSet('mirrorReview._can_force_push', true)
        ->assertSee('Force push 1 selected table')
        ->call('forcePushMirror')
        ->assertSet('mirrorResult.counts.replace', 1)
        ->assertSet('statusVariant', 'success')
        // The transient per-table result table is gone; the counts now persist in
        // the catalog columns and the durable run. A compact summary remains.
        ->assertSee('Development table mirror completed');

    $component
        ->call('reviewMirror', 'pull')
        ->assertSet('mirrorReview._can_force_push', false)
        ->assertDontSee('Force push 1 selected table');
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

it('reports and references an unexpected review failure without exposing its diagnostics', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    Log::spy();
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('review')
        ->once()
        ->andThrow(new RuntimeException('postgresql://private-user:private-password@private-host.example/database'));
    app()->instance(DataShareMirrorManager::class, $manager);

    $component = Livewire::test(DataShareIndex::class)
        ->set('mirrorSelectedTables', ['ham_orders'])
        ->call('reviewMirror', 'push')
        ->assertSet('mirrorReview', null)
        ->assertSet('statusVariant', 'danger')
        ->assertDontSee('private-user')
        ->assertDontSee('private-password')
        ->assertDontSee('private-host.example');

    expect($component->get('statusMessage'))
        ->toStartWith('An unexpected database error prevented the selected tables from being reviewed. No data was changed. Diagnostic reference:')
        ->toMatch('/[A-Z0-9]{8}\.$/');
    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Unexpected Data Share mirror failure.'
            && $context['operation'] === 'review'
            && preg_match('/^[A-Z0-9]{8}$/', $context['diagnostic_reference']) === 1,
    );
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
    $settings->set('data_share.mirror.url', $secretUrl);

    $component = Livewire::test(DataShareSettingsPage::class)
        ->assertSet('values.data_share__mirror__url', '******')
        ->assertDontSee($secretUrl)
        ->assertSee('Cloud provider')
        ->assertSee('Supabase')
        ->assertSee('PostgreSQL')
        ->assertSee('Check and prepare mirror')
        ->assertDontSee('Finish setup')
        ->assertSee('Remove connection')
        ->set('values.data_share__mirror__url', '')
        ->call('save')
        ->assertHasNoErrors();

    $component
        ->call('startSupabaseReplacement')
        ->assertSet('replaceSavedSupabaseConnection', true)
        ->assertSee('Change Supabase connection')
        ->assertSee('Belimbing already knows which Supabase database to use')
        ->assertSee('Test and save connection')
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
    $settings->set(
        'data_share.mirror.supabase.access_token',
        'sbp_remove-with-connection',
    );
    $component = Livewire::test(DataShareSettingsPage::class);
    session()->put('data_share.mirror.supabase.setup_state', ['projects' => []]);

    $component
        ->call('removeMirrorConnection')
        ->assertSet('values.data_share__mirror__url', '');

    expect($settings->has('data_share.mirror.url'))->toBeFalse()
        ->and($settings->has('data_share.mirror.supabase.access_token'))->toBeFalse()
        ->and(session()->has('data_share.mirror.supabase.setup_state'))->toBeFalse();
});

it('renders distinct guided and existing-mirror Supabase setup paths', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());

    Livewire::test(DataShareSettingsPage::class)
        ->assertSee('Set up Supabase')
        ->assertSee('to continue with the same development data on another machine')
        ->assertSee('Nothing moves automatically')
        ->assertSee('Setup does not change or wipe your local database.')
        ->assertSee('Choose your situation')
        ->assertSee('Set up a mirror')
        ->assertSee('Connect to an existing mirror')
        ->assertSee('Use a mirror that was already prepared from another Belimbing installation.')
        ->assertSee('Set up a mirror on Supabase')
        ->assertSee('After that, you choose whether to create a dedicated mirror project or prepare an existing project.')
        ->assertSee('Open Supabase Personal Access Tokens.')
        ->assertSee('Sign in or create a Supabase account, then generate a token to grant that permission.')
        ->assertSee('Check token and find projects')
        ->assertDontSee('Connect this machine to an existing mirror')
        ->set('supabaseConnectionPath', 'existing')
        ->assertSee('Connect this machine to an existing mirror')
        ->assertDontSee('Supabase personal access token')
        ->assertSee('The URL tells this machine which project and database to connect to')
        ->assertSee('the password below replaces that placeholder')
        ->assertSee('Enter the Supabase project database password')
        ->assertSee('not a personal access token or project API key')
        ->assertSee('Test and save connection')
        ->set('values.data_share__mirror__provider', 'postgresql')
        ->assertSee('Connect PostgreSQL')
        ->assertSee('The database user must be allowed to create tables and write data.')
        ->assertDontSee('Connect this machine to an existing mirror')
        ->assertDontSee('personal access token');
});

it('tests and saves an existing Supabase mirror URL with its separate password', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $password = 'mirror p@ss/word';
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn([
        'supabase' => 'Supabase',
        'postgresql' => 'PostgreSQL',
    ]);
    $manager->shouldReceive('testConnection')
        ->once()
        ->with(
            'postgresql://postgres.project:'.rawurlencode($password).'@example.supabase.com:6543/postgres',
            'supabase',
        )
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
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->set('supabaseConnectionPath', 'existing')
        ->set('values.data_share__mirror__url', 'postgresql://postgres.project:[YOUR-PASSWORD]@example.supabase.com:6543/postgres')
        ->set('supabaseManualDatabasePassword', $password)
        ->call('testMirrorConnection')
        ->assertHasNoErrors()
        ->assertSet('supabaseManualDatabasePassword', '')
        ->assertSet('values.data_share__mirror__url', '******')
        ->assertSee('Supabase connection saved')
        ->assertDontSee($password);

    expect(app(SettingsService::class)->get('data_share.mirror.url'))
        ->toBe('postgresql://postgres.project:'.rawurlencode($password).'@example.supabase.com:6543/postgres');
});

it('updates the password in a saved Supabase mirror URL without repeating guided setup', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $password = 'replacement p@ssword';
    app(SettingsService::class)->set(
        'data_share.mirror.url',
        'postgresql://postgres.project:old-password@example.supabase.com:6543/postgres',
    );
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn([
        'supabase' => 'Supabase',
        'postgresql' => 'PostgreSQL',
    ]);
    $manager->shouldReceive('testConnection')
        ->once()
        ->with(
            'postgresql://postgres.project:'.rawurlencode($password).'@example.supabase.com:6543/postgres',
            'supabase',
        )
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
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->assertSet('values.data_share__mirror__url', '******')
        ->assertDontSee('supabase-manual-database-password')
        ->call('startSupabaseReplacement')
        ->assertSee('Belimbing already knows which Supabase database to use')
        ->set('supabaseManualDatabasePassword', $password)
        ->call('testMirrorConnection')
        ->assertHasNoErrors()
        ->assertSet('supabaseManualDatabasePassword', '')
        ->assertSee('Supabase connection saved')
        ->assertDontSee($password);

    expect(app(SettingsService::class)->get('data_share.mirror.url'))
        ->toBe('postgresql://postgres.project:'.rawurlencode($password).'@example.supabase.com:6543/postgres');
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
        ->assertSet('supabaseSetupChoice', 'new')
        ->assertSee('Create a Supabase project for this mirror')
        ->assertSee('Skip this if a Supabase project already exists for this Belimbing mirror.')
        ->assertSee('Supabase creates the PostgreSQL database with the project')
        ->assertSee('Belimbing generates and securely saves its database password')
        ->assertDontSee('Use a Supabase project I already created')
        ->assertDontSee('Database password')
        ->assertSee('Back')
        ->assertDontSee('sbp_transient-token-do-not-store');

    $settings = app(SettingsService::class);
    $storedAccessToken = Setting::query()->where('key', 'data_share.mirror.supabase.access_token')->firstOrFail();
    expect($settings->get('data_share.mirror.supabase.access_token'))->toBe('sbp_transient-token-do-not-store')
        ->and($storedAccessToken->is_encrypted)->toBeTrue()
        ->and($storedAccessToken->getRawOriginal('value'))->not->toContain('sbp_transient-token-do-not-store')
        ->and(session()->has('data_share.mirror.supabase.setup_token'))->toBeFalse();

    Livewire::test(DataShareSettingsPage::class)
        ->assertSet('supabaseAccessToken', '')
        ->assertSet('supabaseDiscoveryComplete', true)
        ->assertSet('supabaseSetupChoice', 'new')
        ->assertSet('supabaseProjectRef', 'abcdefghijklmnopqrst')
        ->assertSee('Create project and set up mirror')
        ->assertDontSee('Database password')
        ->assertDontSee('Check token and find projects')
        ->assertDontSee('sbp_transient-token-do-not-store');

    session()->forget('data_share.mirror.supabase.setup_state');

    Livewire::test(DataShareSettingsPage::class)
        ->assertSet('supabaseDiscoveryComplete', false)
        ->assertSet('supabaseConnectionPath', 'existing')
        ->assertSee('Connect this machine to an existing mirror')
        ->assertDontSee('Supabase personal access token')
        ->assertSee('Find my project')
        ->assertDontSee('Supabase PostgreSQL URL')
        ->call('continueSupabaseWithSavedToken')
        ->assertHasNoErrors()
        ->assertSet('supabaseDiscoveryComplete', true)
        ->assertSee('Choose the existing mirror project')
        ->assertSee('no URL is needed')
        ->assertSee('Enter the selected project’s database password')
        ->assertDontSee('Supabase PostgreSQL URL')
        ->call('returnToSupabaseConnectionChoice')
        ->assertSet('supabaseDiscoveryComplete', false)
        ->set('supabaseConnectionPath', 'setup')
        ->assertSee('Continue with this account')
        ->call('continueSupabaseWithSavedToken')
        ->assertHasNoErrors()
        ->assertSet('supabaseDiscoveryComplete', true)
        ->assertSet('supabaseProjectRef', 'abcdefghijklmnopqrst')
        ->call('returnToSupabaseConnectionChoice')
        ->assertSet('supabaseDiscoveryComplete', false)
        ->assertSet('supabaseSetupChoice', '')
        ->assertSet('supabaseConnectionPath', 'existing')
        ->assertSee('Choose your situation')
        ->assertSee('Connect this machine to an existing mirror');
});

it('forgets an expired saved Supabase token and asks for a replacement', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    app(SettingsService::class)->set(
        'data_share.mirror.supabase.access_token',
        'sbp_expired-token',
    );
    Http::fake([
        'api.supabase.com/v1/organizations' => Http::response([], 401),
    ]);

    Livewire::test(DataShareSettingsPage::class)
        ->assertSet('supabaseConnectionPath', 'existing')
        ->set('supabaseConnectionPath', 'setup')
        ->assertSee('Continue with this account')
        ->call('continueSupabaseWithSavedToken')
        ->assertHasErrors(['supabaseAccessToken'])
        ->assertSee('The saved Supabase personal access token has expired or was revoked. Create a new token to continue.')
        ->assertSet('supabaseDiscoveryComplete', false)
        ->assertDontSee('Continue with this account');

    expect(app(SettingsService::class)->has('data_share.mirror.supabase.access_token'))->toBeFalse();
});

it('forgets a saved Supabase token that expires while configuring a project', function (): void {
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
        'api.supabase.com/v1/projects/abcdefghijklmnopqrst' => Http::response([], 401),
    ]);

    Livewire::test(DataShareSettingsPage::class)
        ->set('supabaseAccessToken', 'sbp_expires-during-setup')
        ->call('discoverSupabase')
        ->set('supabaseSetupChoice', 'existing')
        ->set('supabaseProjectRef', 'abcdefghijklmnopqrst')
        ->set('supabaseDatabasePassword', 'temporary password')
        ->call('useExistingSupabaseProject')
        ->assertHasErrors(['supabaseAccessToken'])
        ->assertSet('supabaseAccessToken', '')
        ->assertSet('supabaseDatabasePassword', '')
        ->assertSet('supabaseDiscoveryComplete', false)
        ->assertSee('The saved Supabase personal access token has expired or was revoked. Create a new token to continue.')
        ->assertDontSee('sbp_expires-during-setup');

    expect(app(SettingsService::class)->has('data_share.mirror.supabase.access_token'))->toBeFalse()
        ->and(session()->has('data_share.mirror.supabase.setup_state'))->toBeFalse();
});

it('does not delete a newer Supabase token when an in-flight token expires', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.supabase.com/v1/organizations') {
            return Http::response([
                ['id' => 'org-id', 'slug' => 'acme-dev', 'name' => 'Acme Development'],
            ]);
        }

        if ($request->url() === 'https://api.supabase.com/v1/projects') {
            return Http::response([
                [
                    'ref' => 'abcdefghijklmnopqrst',
                    'name' => 'Existing Development',
                    'organization_slug' => 'acme-dev',
                    'region' => 'ap-southeast-1',
                    'status' => 'ACTIVE_HEALTHY',
                    'database' => ['host' => 'db.abcdefghijklmnopqrst.supabase.co'],
                ],
            ]);
        }

        if ($request->url() === 'https://api.supabase.com/v1/projects/abcdefghijklmnopqrst') {
            app(SettingsService::class)->set(
                'data_share.mirror.supabase.access_token',
                'sbp_newer-token',
            );

            return Http::response([], 401);
        }

        return Http::response([], 404);
    });

    Livewire::test(DataShareSettingsPage::class)
        ->set('supabaseAccessToken', 'sbp_old-token')
        ->call('discoverSupabase')
        ->set('supabaseSetupChoice', 'existing')
        ->set('supabaseProjectRef', 'abcdefghijklmnopqrst')
        ->set('supabaseDatabasePassword', 'temporary password')
        ->call('useExistingSupabaseProject')
        ->assertHasErrors(['supabaseAccessToken'])
        ->assertSee('The saved Supabase token changed during setup. Continue with the current saved token and try again.')
        ->assertSet('supabaseDiscoveryComplete', false)
        ->assertDontSee('sbp_old-token')
        ->assertDontSee('sbp_newer-token');

    expect(app(SettingsService::class)->get('data_share.mirror.supabase.access_token'))->toBe('sbp_newer-token');
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
        ->assertSet('supabaseAccessToken', '')
        ->assertSee('Supabase did not accept this personal access token. Create a new token in your Supabase account and try again.')
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
        'api.supabase.com/v1/projects/abcdefghijklmnopqrst/config/database/pooler' => Http::response([[
            'database_type' => 'PRIMARY',
            'pool_mode' => 'transaction',
            'connection_string' => 'postgres://postgres.abcdefghijklmnopqrst:[YOUR-PASSWORD]@aws-0-ap-southeast-1.pooler.supabase.com:6543/postgres',
        ]]),
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

function seedSavedSupabaseMirrorConnection(): void
{
    $settings = app(SettingsService::class);
    $settings->set(SupabaseMirrorSetupService::ACCESS_TOKEN_SETTING, 'sbp_saved-token');
    $settings->set(SupabaseMirrorSetupService::PROJECT_REF_SETTING, 'abcdefghijklmnopqrst');
    $settings->set(SupabaseMirrorSetupService::PROJECT_NAME_SETTING, 'Existing Development');
    $settings->set('data_share.mirror.provider', 'supabase');
    $settings->set(
        'data_share.mirror.url',
        'postgres://postgres.abcdefghijklmnopqrst:old@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres',
    );
}

function fakeSupabaseSavedProjectLookup(): void
{
    Http::fake([
        'api.supabase.com/v1/projects/abcdefghijklmnopqrst' => Http::response([
            'ref' => 'abcdefghijklmnopqrst',
            'name' => 'Existing Development',
            'organization_slug' => 'acme-dev',
            'region' => 'ap-southeast-1',
            'status' => 'ACTIVE_HEALTHY',
            'database' => ['host' => 'db.abcdefghijklmnopqrst.supabase.co'],
        ]),
        'api.supabase.com/v1/projects/abcdefghijklmnopqrst/config/database/pooler' => Http::response([[
            'database_type' => 'PRIMARY',
            'pool_mode' => 'transaction',
            'connection_string' => 'postgres://postgres.abcdefghijklmnopqrst:[YOUR-PASSWORD]@aws-0-ap-southeast-1.pooler.supabase.com:6543/postgres',
        ]]),
    ]);
}

it('updates the Supabase database password inline using the saved project without re-discovery', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    seedSavedSupabaseMirrorConnection();
    fakeSupabaseSavedProjectLookup();

    $newPassword = 'brand-new-db-password';
    $manager = Mockery::mock(DataShareMirrorManager::class)->shouldIgnoreMissing();
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase', 'postgresql' => 'PostgreSQL']);
    $manager->shouldReceive('testConnection')
        ->withArgs(fn (string $url, string $provider): bool => $provider === 'supabase' && str_contains($url, rawurlencode($newPassword)))
        ->andReturn(new DataShareMirrorConnectionStatus(
            configured: true, available: true, reachable: true, driver: 'pgsql',
            localRole: 'development', remoteRole: 'development', serverVersion: '17',
            pgDumpVersion: null, psqlVersion: null, reasonCode: null, message: 'Ready.',
            providerKey: 'supabase', providerLabel: 'Supabase', localDriver: 'sqlite', transferMode: 'portable',
        ));
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->assertSee('Supabase connection saved')
        ->call('beginSupabasePasswordUpdate')
        ->assertHasNoErrors()
        ->assertSet('updatingSupabaseDatabasePassword', true)
        ->set('supabaseDatabasePassword', $newPassword)
        ->call('updateSupabaseDatabasePassword')
        ->assertHasNoErrors()
        ->assertSet('updatingSupabaseDatabasePassword', false)
        ->assertSet('supabaseDatabasePassword', '')
        ->assertDontSee($newPassword);

    // The single project was fetched by ref; the list-all-projects discovery endpoint was never hit.
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.supabase.com/v1/projects');
    expect(app(SettingsService::class)->get('data_share.mirror.url'))->toContain('aws-0-ap-southeast-1.pooler.supabase.com');
    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), $newPassword));
});

it('keeps the inline password field open and its value when the update fails', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    seedSavedSupabaseMirrorConnection();
    fakeSupabaseSavedProjectLookup();

    $manager = Mockery::mock(DataShareMirrorManager::class)->shouldIgnoreMissing();
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase', 'postgresql' => 'PostgreSQL']);
    $manager->shouldReceive('testConnection')->andReturn(new DataShareMirrorConnectionStatus(
        configured: true, available: false, reachable: false, driver: 'pgsql',
        localRole: 'development', remoteRole: null, serverVersion: null,
        pgDumpVersion: null, psqlVersion: null, reasonCode: 'connection_failed',
        message: 'The mirror connection is unavailable.',
        providerKey: 'supabase', providerLabel: 'Supabase', localDriver: 'sqlite', transferMode: 'portable',
    ));
    app()->instance(DataShareMirrorManager::class, $manager);

    Livewire::test(DataShareSettingsPage::class)
        ->call('beginSupabasePasswordUpdate')
        ->set('supabaseDatabasePassword', 'wrong-password')
        ->call('updateSupabaseDatabasePassword')
        ->assertHasErrors(['supabaseDatabasePassword'])
        ->assertSet('updatingSupabaseDatabasePassword', true)
        ->assertSet('supabaseDatabasePassword', 'wrong-password');
});

it('falls back to the full replacement flow when no Supabase token is saved', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $settings = app(SettingsService::class);
    $settings->set(SupabaseMirrorSetupService::PROJECT_REF_SETTING, 'abcdefghijklmnopqrst');
    $settings->set('data_share.mirror.url', 'postgres://x@host:5432/postgres');

    Livewire::test(DataShareSettingsPage::class)
        ->call('beginSupabasePasswordUpdate')
        ->assertSet('updatingSupabaseDatabasePassword', false)
        ->assertSet('replaceSavedSupabaseConnection', true);
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
        ->assertSee('Check and prepare mirror')
        ->assertDontSee('Finish setup')
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

it('tests and saves a replacement URL encrypted and write-only', function (): void {
    configureDevelopmentMirrorUiIdentity();
    $this->actingAs(createAdminUser());
    $candidateUrl = 'postgresql://mirror-user:new-secret@example.test:5432/postgres?sslmode=require';
    $manager = Mockery::mock(DataShareMirrorManager::class);
    $manager->shouldReceive('providerOptions')->zeroOrMoreTimes()->andReturn(['supabase' => 'Supabase', 'postgresql' => 'PostgreSQL']);
    $manager->shouldReceive('testConnection')
        ->once()
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
        ->once()
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
