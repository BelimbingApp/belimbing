<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Livewire\GitHubAccess\Index;
use App\Base\Software\Services\DeploymentAdminEndpointResolver;
use App\Base\Software\Services\DeploymentBuildRunner;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\SoftwareSourceRepository;
use Livewire\Livewire;

beforeEach(function (): void {
    app()->instance(DeploymentService::class, new class(app(SoftwareSourceRepository::class), app(DeploymentBuildRunner::class), app(DeploymentAdminEndpointResolver::class), app(DeploymentRunHistory::class)) extends DeploymentService
    {
        public function owners(): array
        {
            return [
                ['owner' => 'exampleowner', 'repos' => ['exampleowner/blb-ham'], 'has_token' => false],
                ['owner' => 'BelimbingApp', 'repos' => ['BelimbingApp/belimbing'], 'has_token' => false],
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
