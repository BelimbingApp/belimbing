<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Modules\Core\AI\Services\LaraNavigationRouter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\CreatesLaraFixtures;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, CreatesLaraFixtures::class);

const AI_PROVIDERS_URL = '/admin/ai/providers';

function makeAuthorizationService(bool $allowed): AuthorizationService
{
    return new class($allowed) implements AuthorizationService
    {
        public function __construct(
            private readonly bool $allowed
        ) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allowed
                ? AuthorizationDecision::allow(['test'])
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY, ['test']);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allowed) {
                throw new \App\Base\Authz\Exceptions\AuthorizationDeniedException(
                    AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY, ['test'])
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allowed ? collect($resources) : collect();
        }
    };
}

// --- Explicit /go commands ---

it('resolves /go dashboard to navigation payload', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $router = app(LaraNavigationRouter::class);
    $result = $router->resolve('/go dashboard');

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('navigation')
        ->and($result['navigation']['strategy'])->toBe('js_go_to_url')
        ->and($result['navigation']['url'])->toBe('/dashboard');
});

it('resolves /go providers to AI Providers route', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $this->app->instance(AuthorizationService::class, makeAuthorizationService(true));

    $router = app(LaraNavigationRouter::class);
    $result = $router->resolve('/go providers');

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('navigation')
        ->and($result['navigation']['url'])->toBe(AI_PROVIDERS_URL);
});

it('resolves /go users when user has core.user.list capability', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $router = new LaraNavigationRouter(makeAuthorizationService(true));
    $result = $router->resolve('/go users');

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('navigation')
        ->and($result['navigation']['url'])->toBe('/admin/users');
});

it('denies /go users when user lacks core.user.list capability', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $router = new LaraNavigationRouter(makeAuthorizationService(false));
    $result = $router->resolve('/go users');

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('navigation_denied')
        ->and($result['target'])->toBe('users')
        ->and($result['message'])->toContain('permission');
});

it('denies /go roles when user lacks admin.role.list capability', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $router = new LaraNavigationRouter(makeAuthorizationService(false));
    $result = $router->resolve('/go roles');

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('navigation_denied')
        ->and($result['target'])->toBe('roles');
});

it('returns unknown target status for unsupported target', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $router = app(LaraNavigationRouter::class);
    $result = $router->resolve('/go nonexistent');

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('unknown_navigation_target')
        ->and($result['target'])->toBe('nonexistent');
});

it('returns usage guidance for empty /go command', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $router = app(LaraNavigationRouter::class);
    $result = $router->resolve('/go');

    expect($result)->not->toBeNull()
        ->and($result['status'])->toBe('invalid_navigation_command');
});

it('returns null for non-navigation messages', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $router = app(LaraNavigationRouter::class);

    expect($router->resolve('Hello Lara'))->toBeNull()
        ->and($router->resolve('what is Belimbing?'))->toBeNull()
        ->and($router->resolve('navigate to users page'))->toBeNull()
        ->and($router->resolve('show me the employees page'))->toBeNull();
});

// --- Integration with LaraOrchestrationService ---

it('orchestration service delegates explicit /go to router', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $this->app->instance(AuthorizationService::class, makeAuthorizationService(true));

    $service = app(\App\Modules\Core\AI\Services\LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('/go providers');

    expect($result)->not->toBeNull()
        ->and($result['meta']['orchestration']['status'])->toBe('navigation')
        ->and($result['meta']['orchestration']['navigation']['url'])->toBe(AI_PROVIDERS_URL);
});

it('orchestration returns null for natural language navigation (deferred to LLM)', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $service = app(\App\Modules\Core\AI\Services\LaraOrchestrationService::class);
    $result = $service->dispatchFromMessage('open the dashboard');

    expect($result)->toBeNull();
});

// --- Target completeness ---

it('resolves all expanded targets', function (): void {
    $fixture = $this->createLaraFixture();
    $this->actingAs($fixture['user']);

    $router = new LaraNavigationRouter(makeAuthorizationService(true));

    $targets = [
        'dashboard' => '/dashboard',
        'users' => '/admin/users',
        'companies' => '/admin/companies',
        'employees' => '/admin/employees',
        'roles' => '/admin/roles',
        'addresses' => '/admin/addresses',
        'providers' => AI_PROVIDERS_URL,
        'models' => AI_PROVIDERS_URL,
        'playground' => '/admin/ai/task-models',
        'task-models' => '/admin/ai/task-models',
        'setup-lara' => '/admin/setup/lara',
    ];

    foreach ($targets as $target => $expectedUrl) {
        $result = $router->resolve('/go '.$target);

        expect($result)->not->toBeNull("Target '{$target}' returned null")
            ->and($result['status'])->toBe('navigation', "Target '{$target}' status is not 'navigation'")
            ->and($result['navigation']['url'])->toBe($expectedUrl, "Target '{$target}' URL mismatch");
    }
});
