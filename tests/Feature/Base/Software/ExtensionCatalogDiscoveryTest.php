<?php

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Livewire\Domains;
use App\Base\Software\Services\ExtensionCatalogDiscovery;
use App\Base\Software\Services\GitHubTokenStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Support\FakeDomainRuntimeReloader;

const EXT_DISCOVERY_ORG = 'zzorg';
const EXT_DISCOVERY_ORG_TOKEN = 'ghp_org_token_0123456789';
const EXT_DISCOVERY_USER = 'zzuser';
const EXT_DISCOVERY_USER_TOKEN = 'ghp_user_token_0123456789';
const EXT_DISCOVERY_MARKED_REPO = 'https://github.com/zzorg/blb-sbg';
const EXT_DISCOVERY_MARKED_FOLDER = 'BlbSbg';

beforeEach(function (): void {
    app()->instance(DomainRuntimeReloader::class, new FakeDomainRuntimeReloader);
    setupAuthzRoles();
    config(['extensions.catalog' => []]);
});

afterEach(function (): void {
    File::deleteDirectory(base_path('app/Extensions/'.EXT_DISCOVERY_MARKED_FOLDER));
});

function extensionDiscoveryOrgRepos(): array
{
    return [
        [
            'name' => 'blb-sbg',
            'html_url' => EXT_DISCOVERY_MARKED_REPO,
            'description' => 'SBG deployment extension.',
            'topics' => [ExtensionCatalogDiscovery::TOPIC],
            'owner' => ['login' => EXT_DISCOVERY_ORG],
        ],
        [
            'name' => 'blb-unmarked',
            'html_url' => 'https://github.com/zzorg/blb-unmarked',
            'description' => 'No topic; must never be offered.',
            'topics' => ['some-other-topic'],
            'owner' => ['login' => EXT_DISCOVERY_ORG],
        ],
    ];
}

function fakeExtensionDiscoveryOrg(): void
{
    Http::fake([
        'https://api.github.com/orgs/'.EXT_DISCOVERY_ORG.'/repos*' => Http::response(extensionDiscoveryOrgRepos(), 200),
    ]);
}

it('offers only repositories marked with the belimbing-extension topic', function (): void {
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_ORG, EXT_DISCOVERY_ORG_TOKEN);
    fakeExtensionDiscoveryOrg();

    $result = app(ExtensionCatalogDiscovery::class)->discover();

    expect(array_keys($result['candidates']))->toBe([EXT_DISCOVERY_MARKED_FOLDER])
        ->and($result['candidates'][EXT_DISCOVERY_MARKED_FOLDER])->toBe([
            'repo' => EXT_DISCOVERY_MARKED_REPO,
            'description' => 'SBG deployment extension.',
            'owner' => EXT_DISCOVERY_ORG,
            'has_token' => true,
        ])
        ->and($result['errors'])->toBe([]);
});

it('sends the stored owner token when listing repositories', function (): void {
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_ORG, EXT_DISCOVERY_ORG_TOKEN);
    fakeExtensionDiscoveryOrg();

    app(ExtensionCatalogDiscovery::class)->discover();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer '.EXT_DISCOVERY_ORG_TOKEN));
});

it('falls back to the token user listing for non-org owners and keeps only that owner', function (): void {
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_USER, EXT_DISCOVERY_USER_TOKEN);
    Http::fake([
        'https://api.github.com/orgs/'.EXT_DISCOVERY_USER.'/repos*' => Http::response(['message' => 'Not Found'], 404),
        'https://api.github.com/user/repos*' => Http::response([
            [
                'name' => 'blb_zztool',
                'html_url' => 'https://github.com/zzuser/blb_zztool',
                'description' => 'User-owned extension.',
                'topics' => [ExtensionCatalogDiscovery::TOPIC],
                'owner' => ['login' => EXT_DISCOVERY_USER],
            ],
            [
                'name' => 'blb-foreign',
                'html_url' => 'https://github.com/someoneelse/blb-foreign',
                'description' => 'Marked but owned by an untrusted owner.',
                'topics' => [ExtensionCatalogDiscovery::TOPIC],
                'owner' => ['login' => 'someoneelse'],
            ],
        ], 200),
    ]);

    $result = app(ExtensionCatalogDiscovery::class)->discover();

    expect(array_keys($result['candidates']))->toBe(['BlbZztool'])
        ->and($result['candidates']['BlbZztool']['owner'])->toBe(EXT_DISCOVERY_USER)
        ->and($result['errors'])->toBe([]);
});

it('collects a per-owner error while other owners still list', function (): void {
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_ORG, EXT_DISCOVERY_ORG_TOKEN);
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_USER, EXT_DISCOVERY_USER_TOKEN);
    Http::fake([
        'https://api.github.com/orgs/'.EXT_DISCOVERY_ORG.'/repos*' => Http::response(extensionDiscoveryOrgRepos(), 200),
        'https://api.github.com/orgs/'.EXT_DISCOVERY_USER.'/repos*' => Http::response(['message' => 'boom'], 500),
    ]);

    $result = app(ExtensionCatalogDiscovery::class)->discover();

    expect(array_keys($result['candidates']))->toBe([EXT_DISCOVERY_MARKED_FOLDER])
        ->and($result['errors'])->toHaveKey(EXT_DISCOVERY_USER)
        ->and($result['errors'][EXT_DISCOVERY_USER])->toContain('500');
});

it('caches an owner listing briefly instead of re-hitting the API', function (): void {
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_ORG, EXT_DISCOVERY_ORG_TOKEN);
    fakeExtensionDiscoveryOrg();

    app(ExtensionCatalogDiscovery::class)->discover();
    app(ExtensionCatalogDiscovery::class)->discover();

    Http::assertSentCount(1);
});

it('renders curated and discovered extensions with source badges', function (): void {
    config(['extensions.catalog' => [
        'Zzpinned' => ['repo' => 'https://github.com/zzorg/blb-zzpinned', 'description' => 'Curated entry.'],
    ]]);
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_ORG, EXT_DISCOVERY_ORG_TOKEN);
    fakeExtensionDiscoveryOrg();
    $this->actingAs(createAdminUser());

    Livewire::test(Domains::class, ['tab' => 'available'])
        ->assertSee('Available Extensions')
        ->assertSee('Zzpinned')
        ->assertSee('curated')
        ->assertSee(EXT_DISCOVERY_MARKED_FOLDER)
        ->assertSee('discovered')
        ->assertDontSee('BlbUnmarked');
});

it('lets a config catalog entry override a discovered candidate with the same key', function (): void {
    config(['extensions.catalog' => [
        EXT_DISCOVERY_MARKED_FOLDER => ['repo' => 'https://github.com/zzorg/pinned-fork', 'description' => 'Pinned override.'],
    ]]);
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_ORG, EXT_DISCOVERY_ORG_TOKEN);
    fakeExtensionDiscoveryOrg();
    $this->actingAs(createAdminUser());

    $availableExtensions = Livewire::test(Domains::class, ['tab' => 'available'])
        ->viewData('availableExtensions');

    expect($availableExtensions)->toHaveCount(1)
        ->and($availableExtensions[EXT_DISCOVERY_MARKED_FOLDER]['repo'])->toBe('https://github.com/zzorg/pinned-fork')
        ->and($availableExtensions[EXT_DISCOVERY_MARKED_FOLDER]['source'])->toBe('curated');
});

it('shows a discovery error note while other owners still render', function (): void {
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_ORG, EXT_DISCOVERY_ORG_TOKEN);
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_USER, EXT_DISCOVERY_USER_TOKEN);
    Http::fake([
        'https://api.github.com/orgs/'.EXT_DISCOVERY_ORG.'/repos*' => Http::response(extensionDiscoveryOrgRepos(), 200),
        'https://api.github.com/orgs/'.EXT_DISCOVERY_USER.'/repos*' => Http::response(['message' => 'boom'], 500),
    ]);
    $this->actingAs(createAdminUser());

    Livewire::test(Domains::class, ['tab' => 'available'])
        ->assertSee('Discovery could not list every trusted owner')
        ->assertSee(EXT_DISCOVERY_USER)
        ->assertSee(EXT_DISCOVERY_MARKED_FOLDER);
});

it('installs a discovered extension through the standard installer flow', function (): void {
    app(GitHubTokenStore::class)->saveToken(EXT_DISCOVERY_ORG, EXT_DISCOVERY_ORG_TOKEN);
    fakeExtensionDiscoveryOrg();
    Process::fake();
    $this->actingAs(createAdminUser());

    Livewire::test(Domains::class)
        ->call('installExtension', EXT_DISCOVERY_MARKED_FOLDER)
        ->assertRedirect(route('admin.system.software.domains.index'));

    $expectedAuthHeader = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.EXT_DISCOVERY_ORG_TOKEN);

    Process::assertRan(fn ($process): bool => in_array('clone', $process->command, true)
        && in_array(EXT_DISCOVERY_MARKED_REPO, $process->command, true)
        && in_array(base_path('app/Extensions/'.EXT_DISCOVERY_MARKED_FOLDER), $process->command, true)
        && in_array($expectedAuthHeader, $process->command, true));
});
