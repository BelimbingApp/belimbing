<?php
namespace App\Base\Audit\DTO;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use Illuminate\Support\Str;

/**
 * Immutable per-request metadata for audit logging.
 *
 * Populated once at the start of a request and shared across
 * all audit entries within that request lifecycle. Captures
 * actor identity for humans, agents, and process types
 * (console, scheduler, queue).
 */
final readonly class RequestContext
{
    public function __construct(
        public string $correlationId,
        public ?string $ipAddress = null,
        public ?string $url = null,
        public ?string $userAgent = null,
        public ?string $actorType = null,
        public ?int $actorId = null,
        public ?int $companyId = null,
        public ?string $actorRole = null,
    ) {}

    /**
     * Build context from the current HTTP request and actor.
     */
    public static function fromRequest(?Actor $actor = null): self
    {
        $request = request();

        return new self(
            correlationId: (string) Str::uuid(),
            ipAddress: $request->ip(),
            url: $request->fullUrl(),
            userAgent: $request->userAgent() !== null
                ? Str::limit($request->userAgent(), 512, '')
                : null,
            actorType: $actor?->type->value,
            actorId: $actor?->id,
            companyId: $actor?->companyId,
            actorRole: $actor?->attributes['role'] ?? null,
        );
    }

    /**
     * Build context for an artisan console command.
     *
     * If an authenticated user is running the command, the actor is
     * preserved and the principal type is set to CONSOLE. Otherwise,
     * actor_id defaults to 0 (no user).
     */
    public static function forConsole(?Actor $actor = null, ?string $command = null): self
    {
        return new self(
            correlationId: (string) Str::uuid(),
            ipAddress: null,
            url: $command !== null ? 'artisan:'.$command : null,
            userAgent: null,
            actorType: PrincipalType::CONSOLE->value,
            actorId: $actor?->id ?? 0,
            companyId: $actor?->companyId,
            actorRole: $actor?->attributes['role'] ?? null,
        );
    }

    /**
     * Build context for a scheduled task.
     */
    public static function forScheduler(?string $taskDescription = null): self
    {
        return new self(
            correlationId: (string) Str::uuid(),
            ipAddress: null,
            url: $taskDescription !== null ? 'schedule:'.$taskDescription : null,
            userAgent: null,
            actorType: PrincipalType::SCHEDULER->value,
            actorId: 0,
            companyId: null,
            actorRole: null,
        );
    }

    /**
     * Build context for a queued job.
     *
     * When a job was dispatched by a known user, pass their actor
     * to preserve the chain of responsibility.
     */
    public static function forQueue(?Actor $actor = null, ?string $jobClass = null): self
    {
        return new self(
            correlationId: (string) Str::uuid(),
            ipAddress: null,
            url: $jobClass !== null ? 'queue:'.$jobClass : null,
            userAgent: null,
            actorType: PrincipalType::QUEUE->value,
            actorId: $actor?->id ?? 0,
            companyId: $actor?->companyId,
            actorRole: $actor?->attributes['role'] ?? null,
        );
    }
}
