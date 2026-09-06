<?php

namespace App\Base\Integration\Services;

use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/** Limits consequential commands without coupling Base to a business Domain. */
final readonly class SubjectCommandRateLimiter
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Invoke a command unless its configured tenant-and-subject bucket is full.
     *
     * Undeclared operations pass through, which keeps query/read paths outside
     * command throttling. A refused callback is never invoked.
     */
    public function execute(
        string $operation,
        string|int $subjectId,
        callable $command,
    ): SubjectCommandExecution {
        $operation = trim($operation);
        $subjectId = trim((string) $subjectId);

        if ($operation === '' || $subjectId === '') {
            throw new \InvalidArgumentException('A subject command requires an operation and subject identifier.');
        }

        $tenantId = $this->tenantContext->requireTenantId();
        $limit = $this->configuredLimit($operation);

        if ($limit === null) {
            return SubjectCommandExecution::executed($command());
        }

        $key = 'subject-command:'.hash('sha256', implode("\0", [
            (string) $tenantId,
            $operation,
            $subjectId,
        ]));

        if (RateLimiter::tooManyAttempts($key, $limit['max_attempts'])) {
            return SubjectCommandExecution::refusedBeforeDispatch(RateLimiter::availableIn($key));
        }

        // Consume admission before dispatch. If transport times out after it
        // starts, its outcome is unknown, but it still belongs to this burst.
        RateLimiter::hit($key, $limit['decay_seconds']);

        return SubjectCommandExecution::executed($command());
    }

    /**
     * @return array{max_attempts: int, decay_seconds: int}|null
     */
    private function configuredLimit(string $operation): ?array
    {
        $limits = config('integration.subject_command_limits', []);

        if (! is_array($limits)) {
            throw new BlbConfigurationException('integration.subject_command_limits must be an array.');
        }

        if (! array_key_exists($operation, $limits)) {
            return null;
        }

        $limit = $limits[$operation];
        if (! is_array($limit)
            || ! is_int($limit['max_attempts'] ?? null)
            || $limit['max_attempts'] < 1
            || ! is_int($limit['decay_seconds'] ?? null)
            || $limit['decay_seconds'] < 1) {
            throw new BlbConfigurationException(
                "integration.subject_command_limits.{$operation} requires positive integer max_attempts and decay_seconds values.",
            );
        }

        return [
            'max_attempts' => $limit['max_attempts'],
            'decay_seconds' => $limit['decay_seconds'],
        ];
    }
}
