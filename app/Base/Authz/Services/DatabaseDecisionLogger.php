<?php
namespace App\Base\Authz\Services;

use App\Base\Authz\Contracts\DecisionLogger;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Models\DecisionLog;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Buffers decision log entries and flushes them in a single
 * batch INSERT after the response is sent.
 */
class DatabaseDecisionLogger implements DecisionLogger
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $pendingLogs = [];

    private bool $flushRegistered = false;

    public function __construct(private readonly Application $app) {}

    /**
     * Buffer a decision log entry for deferred persistence.
     *
     * @param  array<string, mixed>  $context
     */
    public function log(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        AuthorizationDecision $decision,
        array $context = []
    ): void {
        $now = now();

        $this->pendingLogs[] = [
            'company_id' => $actor->companyId,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'acting_for_user_id' => $actor->actingForUserId,
            'capability' => $capability,
            'resource_type' => $resource?->type,
            'resource_id' => $resource?->id !== null ? (string) $resource->id : null,
            'allowed' => $decision->allowed,
            'reason_code' => $decision->reasonCode->value,
            'applied_policies' => json_encode($decision->appliedPolicies),
            'context' => json_encode($context),
            'correlation_id' => isset($context['correlation_id']) ? (string) $context['correlation_id'] : null,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            $this->app->terminating(function (): void {
                $this->flush();
            });
        }
    }

    /**
     * Flush all buffered decision logs in a single batch insert.
     */
    private function flush(): void
    {
        if (empty($this->pendingLogs)) {
            return;
        }

        $logs = $this->pendingLogs;
        $this->pendingLogs = [];

        try {
            foreach (array_chunk($logs, 500) as $chunk) {
                DecisionLog::query()->insert($chunk);
            }
        } catch (Throwable $exception) {
            logger()->error('Authorization decision log batch persistence failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'count' => count($logs),
            ]);
        }
    }
}
