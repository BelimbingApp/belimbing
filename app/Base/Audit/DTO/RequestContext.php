<?php

namespace App\Base\Audit\DTO;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Support\TraceId;
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
        public string $traceId,
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
            traceId: TraceId::current(),
            ipAddress: $request->ip(),
            url: $request->fullUrl(),
            userAgent: self::clientLabel($request->userAgent()),
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
            traceId: TraceId::generate(),
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
            traceId: TraceId::generate(),
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
            traceId: TraceId::generate(),
            ipAddress: null,
            url: $jobClass !== null ? 'queue:'.$jobClass : null,
            userAgent: null,
            actorType: PrincipalType::QUEUE->value,
            actorId: $actor?->id ?? 0,
            companyId: $actor?->companyId,
            actorRole: $actor?->attributes['role'] ?? null,
        );
    }

    private static function clientLabel(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => self::matchVersion($userAgent, 'Edg') ?? 'Edge',
            str_contains($userAgent, 'Chrome/') && ! str_contains($userAgent, 'Chromium/') => self::matchVersion($userAgent, 'Chrome') ?? 'Chrome',
            str_contains($userAgent, 'Firefox/') => self::matchVersion($userAgent, 'Firefox') ?? 'Firefox',
            str_contains($userAgent, 'Safari/') && str_contains($userAgent, 'Version/') => self::matchVersion($userAgent, 'Version', 'Safari') ?? 'Safari',
            str_contains($userAgent, 'curl/') => self::matchVersion($userAgent, 'curl') ?? 'curl',
            default => 'unknown',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return Str::limit($platform !== null ? $browser.' / '.$platform : $browser, 80, '');
    }

    private static function matchVersion(string $userAgent, string $token, ?string $label = null): ?string
    {
        if (! preg_match('/'.preg_quote($token, '/').'\/(\d+)/', $userAgent, $matches)) {
            return null;
        }

        return ($label ?? $token).' '.$matches[1];
    }
}
