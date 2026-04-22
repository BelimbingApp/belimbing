<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Middleware\AuthorizeCapability;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/Auth/FakeAuthenticatable.php';

uses(TestCase::class);

it('allows request when capability is authorized', function (): void {
    $service = new class implements AuthorizationService
    {
        /**
         * @var array{actor: Actor, capability: string, context: array<string, mixed>}|null
         */
        public ?array $authorizeCall = null;

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow(['test']);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            $this->authorizeCall = [
                'actor' => $actor,
                'capability' => $capability,
                'context' => $context,
            ];
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    };

    $middleware = new AuthorizeCapability($service);

    $request = Request::create('/admin/users', 'GET');
    $request->setUserResolver(fn () => new FakeAuthenticatable(1, ['company_id' => 10]));
    $request->setRouteResolver(fn () => Route::getRoutes()->getByName('admin.users.index'));

    $response = $middleware->handle($request, fn () => new Response('ok', 200), 'core.user.list');

    expect($response->getStatusCode())->toBe(200)
        ->and($service->authorizeCall)->not->toBeNull()
        ->and($service->authorizeCall['capability'])->toBe('core.user.list')
        ->and($service->authorizeCall['context'])->toBe(['route' => 'admin.users.index'])
        ->and($service->authorizeCall['actor']->id)->toBe(1)
        ->and($service->authorizeCall['actor']->companyId)->toBe(10);
});

it('aborts with 403 when capability is denied', function (): void {
    $service = new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY, ['test']);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            throw new AuthorizationDeniedException(
                AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY, ['test'])
            );
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect();
        }
    };

    $middleware = new AuthorizeCapability($service);

    $request = Request::create('/admin/users', 'GET');
    $request->setUserResolver(fn () => new FakeAuthenticatable(1, ['company_id' => 10]));

    $nextCalled = false;

    try {
        $middleware->handle($request, function () use (&$nextCalled): Response {
            $nextCalled = true;

            return new Response('ok', 200);
        }, 'core.user.list');

        $this->fail('Expected denied authorization to abort.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403)
            ->and($nextCalled)->toBeFalse();
    }
});

it('registers authz middleware on user routes', function (): void {
    $indexRoute = Route::getRoutes()->getByName('admin.users.index');
    $createRoute = Route::getRoutes()->getByName('admin.users.create');
    $showRoute = Route::getRoutes()->getByName('admin.users.show');

    expect($indexRoute)->not->toBeNull();
    expect($createRoute)->not->toBeNull();
    expect($showRoute)->not->toBeNull();

    expect($indexRoute->gatherMiddleware())->toContain('authz:core.user.list');
    expect($createRoute->gatherMiddleware())->toContain('authz:core.user.create');
    expect($showRoute->gatherMiddleware())->toContain('authz:core.user.view');
});
