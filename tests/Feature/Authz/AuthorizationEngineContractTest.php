<?php

use App\Base\Authz\Contracts\AuthorizationPolicy;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Contracts\DecisionLogger;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\DecisionLog;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Base\Authz\Services\DatabaseDecisionLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    setupAuthzRoles();
});

it('wires the §9.1 policy pipeline in order', function (): void {
    $engine = app(AuthorizationEngine::class);
    $policies = (new ReflectionClass($engine))->getProperty('policies')->getValue($engine);

    expect(array_map(
        static fn (AuthorizationPolicy $policy): string => $policy->key(),
        $policies,
    ))->toBe([
        'actor_context',
        'capability_registry',
        'tenant_scope',
        'company_scope',
        'delegation',
        'grant',
    ]);
});

it('denies with DENIED_POLICY_ENGINE_ERROR and logs when a policy throws', function (): void {
    $throwing = new class implements AuthorizationPolicy
    {
        public function key(): string
        {
            return 'throwing_stub';
        }

        public function evaluate(
            Actor $actor,
            string $capability,
            ?ResourceContext $resource = null,
            array $context = []
        ): ?AuthorizationDecision {
            throw new RuntimeException('policy boom');
        }
    };

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Authorization policy evaluation failed.'
                && ($context['exception'] ?? null) === RuntimeException::class
                && ($context['message'] ?? null) === 'policy boom'
                && ($context['policy'] ?? null) === 'throwing_stub'
                && ($context['capability'] ?? null) === 'admin.user.view'
                && ($context['actor_type'] ?? null) === PrincipalType::USER->value;
        });

    $decision = (new AuthorizationEngine([$throwing]))->can(
        new Actor(PrincipalType::USER, 1, 10),
        'admin.user.view',
    );

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_POLICY_ENGINE_ERROR)
        ->and($decision->appliedPolicies)->toBe(['throwing_stub']);
});

it('keeps the authorization decision when decision-log flush persistence fails', function (): void {
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => 10,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => 91,
        'role_id' => $role->id,
    ]);

    $service = app(AuthorizationService::class);
    $logger = app(DecisionLogger::class);
    expect($logger)->toBeInstanceOf(DatabaseDecisionLogger::class);

    $decision = $service->can(
        new Actor(PrincipalType::USER, 91, 10),
        'admin.user.view',
    );

    expect($decision->allowed)->toBeTrue()
        ->and($decision->reasonCode)->toBe(AuthorizationReasonCode::ALLOWED);

    Schema::drop('base_authz_decision_logs');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Authorization decision log batch persistence failed.'
                && isset($context['exception'], $context['message'], $context['count'])
                && $context['count'] >= 1;
        });

    $method = (new ReflectionClass($logger))->getMethod('flush');
    $method->invoke($logger);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->reasonCode)->toBe(AuthorizationReasonCode::ALLOWED)
        ->and(Schema::hasTable('base_authz_decision_logs'))->toBeFalse();
});
