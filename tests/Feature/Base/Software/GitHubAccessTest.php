<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Livewire\GitHubAccess\Index;
use App\Base\Software\Services\DeploymentAdminEndpointResolver;
use App\Base\Software\Services\DeploymentBuildRunner;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\SoftwareSourceRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

const GITHUB_ACCESS_REMOTE = 'https://github.com/BelimbingApp/belimbing.git';

beforeEach(function (): void {
    // Repo visibility is cached (SoftwareSourceRepository::OWNER_VISIBILITY_CACHE_SECONDS);
    // several tests below probe BelimbingApp/belimbing with different Http fakes, so a
    // cache carried over from a prior test would make them read each other's answers.
    Cache::flush();

    app()->instance(DeploymentService::class, new class(app(SoftwareSourceRepository::class), app(DeploymentBuildRunner::class), app(DeploymentAdminEndpointResolver::class), app(DeploymentRunHistory::class)) extends DeploymentService
    {
        public function owners(): array
        {
            return [
                ['owner' => 'exampleowner', 'repos' => [['repo' => 'exampleowner/blb-ham', 'visibility' => 'private']], 'has_token' => false, 'all_public' => false],
                ['owner' => 'BelimbingApp', 'repos' => [['repo' => 'BelimbingApp/belimbing', 'visibility' => 'public']], 'has_token' => false, 'all_public' => true],
            ];
        }

        public function testOwner(string $owner, ?string $token = null): array
        {
            return $owner === 'exampleowner'
                ? [['repo' => 'exampleowner/blb-ham', 'ok' => true, 'status' => 200, 'message' => 'Reachable (private).']]
                : [];
        }
    });
});

test('github access page lists the deployment owners for admins', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('admin.system.software.github-access.index'))
        ->assertOk()
        ->assertSee('GitHub Access')
        ->assertSee('exampleowner')   // private extension owner (blb-ham)
        ->assertSee('BelimbingApp');  // public platform + module owner
});

test('saving stores a per-owner token in settings', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('tokens.exampleowner', 'github_pat_0123456789abcdef')
        ->call('save', 'exampleowner')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('integrations.github.token.exampleowner'))->toBe('github_pat_0123456789abcdef');
});

test('save rejects a too-short token for an owner', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('tokens.exampleowner', 'short')
        ->call('save', 'exampleowner')
        ->assertHasErrors('tokens.exampleowner');
});

test('test connection probes an owner repos with its token', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $component = Livewire::test(Index::class)
        ->set('tokens.exampleowner', 'github_pat_0123456789abcdef')
        ->call('test', 'exampleowner')
        ->assertHasNoErrors();

    $results = $component->get('testResults')['exampleowner'] ?? [];

    expect($results)->not->toBeEmpty()
        ->and(collect($results)->every(fn (array $r): bool => $r['ok'] === true))->toBeTrue();
});

test('a public-only owner is labelled public with no token workflow implied', function (): void {
    $user = createAdminUser();

    $response = $this->actingAs($user)
        ->get(route('admin.system.software.github-access.index'));

    $response->assertOk()
        ->assertSee('Public — no token required')
        ->assertSee('Optional token for BelimbingApp');

    // The public owner's card must not carry the warning "No token" state —
    // that string belongs only to exampleowner (private, no token stored).
    $body = $response->getContent();
    expect(substr_count($body, 'No token'))->toBe(1);
});

test('a mixed owner names which repos are public and which need a token', function (): void {
    app()->instance(DeploymentService::class, new class(app(SoftwareSourceRepository::class), app(DeploymentBuildRunner::class), app(DeploymentAdminEndpointResolver::class), app(DeploymentRunHistory::class)) extends DeploymentService
    {
        public function owners(): array
        {
            return [
                [
                    'owner' => 'mixedowner',
                    'repos' => [
                        ['repo' => 'mixedowner/open-module', 'visibility' => 'public'],
                        ['repo' => 'mixedowner/private-extension', 'visibility' => 'private'],
                    ],
                    'has_token' => false,
                    'all_public' => false,
                ],
            ];
        }
    });

    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('admin.system.software.github-access.index'))
        ->assertOk()
        // Mixed owner still gets the ordinary token workflow (not the public badge)...
        ->assertSee('No token')
        ->assertDontSee('Public — no token required')
        // ...but names which repo is which rather than implying both need credentials.
        ->assertSee('Public, no token needed: mixedowner/open-module.')
        ->assertSee('Needs a token: mixedowner/private-extension.');
});

test('the token field offers a reveal toggle labelled for the token', function (): void {
    $user = createAdminUser();

    $response = $this->actingAs($user)
        ->get(route('admin.system.software.github-access.index'));

    $response->assertOk()->assertSee('Show token', false);

    // showRevealButton must actually be on, not just the label text incidentally
    // present — the component only renders the toggle button when it is true.
    expect($response->getContent())->toContain('showRevealButton: true');
});

function fakeGitHubAccessRemote(): void
{
    Process::fake(function ($process) {
        return gitCommandWithoutConfig($process->command) === ['git', 'remote', 'get-url', 'origin']
            ? Process::result(GITHUB_ACCESS_REMOTE)
            : Process::result();
    });
}

test('SoftwareSourceRepository::owners reports a repo GitHub confirms public as public with no token required', function (): void {
    fakeGitHubAccessRemote();
    Http::fake([
        'api.github.com/repos/BelimbingApp/belimbing' => Http::response(['private' => false], 200),
    ]);

    $owners = collect(app(SoftwareSourceRepository::class)->owners());
    $platformOwner = $owners->firstWhere('owner', 'BelimbingApp');

    expect($platformOwner)->not->toBeNull()
        ->and($platformOwner['all_public'])->toBeTrue()
        ->and(collect($platformOwner['repos'])->firstWhere('repo', 'BelimbingApp/belimbing')['visibility'])->toBe('public');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.github.com/repos/BelimbingApp/belimbing'
        && ! $request->hasHeader('Authorization'));
});

test('SoftwareSourceRepository::owners treats an anonymous 404 as private, confirmed', function (): void {
    fakeGitHubAccessRemote();
    Http::fake([
        'api.github.com/repos/BelimbingApp/belimbing' => Http::response(null, 404),
    ]);

    $owners = collect(app(SoftwareSourceRepository::class)->owners());
    $platformOwner = $owners->firstWhere('owner', 'BelimbingApp');

    expect($platformOwner['all_public'])->toBeFalse()
        ->and(collect($platformOwner['repos'])->firstWhere('repo', 'BelimbingApp/belimbing')['visibility'])->toBe('private');
});

test('SoftwareSourceRepository::owners caches repo visibility instead of checking GitHub on every call', function (): void {
    fakeGitHubAccessRemote();
    Http::fake([
        'api.github.com/repos/BelimbingApp/belimbing' => Http::response(['private' => false], 200),
    ]);

    $repository = app(SoftwareSourceRepository::class);
    $repository->owners();
    $repository->owners();

    Http::assertSentCount(1);
});

test('GitHub Access does not throw when GitHub is unreachable, and shows the ordinary workflow instead of asserting private', function (): void {
    fakeGitHubAccessRemote();
    Http::fake(function () {
        throw new ConnectionException('cURL error 7: Failed to connect');
    });

    $user = createAdminUser();

    $response = $this->actingAs($user)
        ->get(route('admin.system.software.github-access.index'));

    // The page must render, not 500 — this is the page an operator opens
    // exactly when the network to GitHub is broken.
    $response->assertOk();

    $owners = collect(app(SoftwareSourceRepository::class)->owners());
    $platformOwner = $owners->firstWhere('owner', 'BelimbingApp');

    expect($platformOwner['all_public'])->toBeFalse()
        ->and(collect($platformOwner['repos'])->firstWhere('repo', 'BelimbingApp/belimbing')['visibility'])->toBe('unknown');

    // A page that could not confirm visibility must not claim the repo needs
    // a token — that claim is false as often as it's true here.
    $response->assertDontSee('Needs a token');
});

test('a rate-limited (403) probe is not cached as, or presented as, a private repo', function (): void {
    fakeGitHubAccessRemote();
    $requestCount = 0;
    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        return Http::response(['message' => 'API rate limit exceeded'], 403);
    });

    $owners = collect(app(SoftwareSourceRepository::class)->owners());
    $platformOwner = $owners->firstWhere('owner', 'BelimbingApp');

    expect(collect($platformOwner['repos'])->firstWhere('repo', 'BelimbingApp/belimbing')['visibility'])->toBe('unknown')
        ->and($requestCount)->toBe(1);

    // Re-rendering within the failure TTL must not re-probe GitHub — that would
    // make a rate-limit outage worse, not better.
    app(SoftwareSourceRepository::class)->owners();
    expect($requestCount)->toBe(1);

    // An 'unknown' result must not sit in the cache for the long
    // (visibility-changes-rarely) TTL: past the short failure TTL, the next
    // render must probe again rather than staying pinned for minutes.
    $this->travel(21)->seconds();
    app(SoftwareSourceRepository::class)->owners();
    expect($requestCount)->toBe(2);
});
