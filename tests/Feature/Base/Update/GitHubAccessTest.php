<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Update\Livewire\GitHubAccess\Index;
use App\Base\Update\Services\DeploymentAdminEndpointResolver;
use App\Base\Update\Services\DeploymentBuildRunner;
use App\Base\Update\Services\DeploymentRunHistory;
use App\Base\Update\Services\DeploymentService;
use App\Base\Update\Services\DistributionBundleRepository;
use Livewire\Livewire;

beforeEach(function (): void {
    app()->instance(DeploymentService::class, new class(app(DistributionBundleRepository::class), app(DeploymentBuildRunner::class), app(DeploymentAdminEndpointResolver::class), app(DeploymentRunHistory::class)) extends DeploymentService
    {
        public function owners(): array
        {
            return [
                ['owner' => 'kiatng', 'repos' => ['kiatng/blb-ham'], 'has_token' => false],
                ['owner' => 'BelimbingApp', 'repos' => ['BelimbingApp/belimbing'], 'has_token' => false],
            ];
        }

        public function testOwner(string $owner, ?string $token = null): array
        {
            return $owner === 'kiatng'
                ? [['repo' => 'kiatng/blb-ham', 'ok' => true, 'status' => 200, 'message' => 'Reachable (private).']]
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
        ->assertSee('kiatng')         // private extension owner (blb-ham)
        ->assertSee('BelimbingApp');  // public platform + module owner
});

test('saving stores a per-owner token in settings', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('tokens.kiatng', 'github_pat_0123456789abcdef')
        ->call('save', 'kiatng')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('integrations.github.token.kiatng'))->toBe('github_pat_0123456789abcdef');
});

test('save rejects a too-short token for an owner', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('tokens.kiatng', 'short')
        ->call('save', 'kiatng')
        ->assertHasErrors('tokens.kiatng');
});

test('test connection probes an owner repos with its token', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $component = Livewire::test(Index::class)
        ->set('tokens.kiatng', 'github_pat_0123456789abcdef')
        ->call('test', 'kiatng')
        ->assertHasNoErrors();

    $results = $component->get('testResults')['kiatng'] ?? [];

    expect($results)->not->toBeEmpty()
        ->and(collect($results)->every(fn (array $r): bool => $r['ok'] === true))->toBeTrue();
});
