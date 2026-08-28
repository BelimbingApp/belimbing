<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The safety boundary of upstream synchronization (#345, lane 2 of #339):
 * synchronization capability exists only in the resolved local or staging
 * application environment, for a user who holds the capability. Production is
 * a read-only deployment consumer, and every undecidable state fails closed —
 * an unset or unrecognised APP_ENV means unavailable, never available.
 *
 * Read-only upstream visibility (#344) never consults this gate: seeing is
 * not synchronizing.
 *
 * Every later synchronization action (lane 3) must call authorize() at the
 * server before acting. A hidden button is not a boundary (#307).
 */
final class UpstreamSyncGate
{
    public const CAPABILITY = 'admin.system.software.upstream-sync.manage';

    /**
     * Environments that may hold synchronization capability. An allow-list,
     * not a production deny-list: a new or misspelled APP_ENV is closed by
     * construction.
     */
    private const SYNC_ENVIRONMENTS = ['local', 'staging'];

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The resolved Laravel application environment, or null when unavailable.
     * APP_ENV is already the platform's development/production boundary; a
     * second value in the same environment file would not be independent.
     */
    public function environment(): ?string
    {
        $environment = app()->environment();

        if (! is_string($environment)) {
            return null;
        }

        $environment = strtolower(trim($environment));

        return $environment !== '' ? $environment : null;
    }

    public function environmentAllowsSync(): bool
    {
        return in_array($this->environment(), self::SYNC_ENVIRONMENTS, true);
    }

    /**
     * Availability plus the operator-facing reason when closed. The page states
     * this plainly instead of hiding the concept or failing at the point of use.
     *
     * @return array{available: bool, environment: string|null, reason: string|null}
     */
    public function state(?Authenticatable $user): array
    {
        $environment = $this->environment();

        if (! $this->environmentAllowsSync()) {

            return [
                'available' => false,
                'environment' => $environment,
                'reason' => $environment === null
                    ? (string) __('Upstream synchronization is unavailable because APP_ENV is not resolved. It is only available when APP_ENV is local or staging.')
                    : (string) __('Upstream synchronization is unavailable when APP_ENV is :environment. It is only available when APP_ENV is local or staging.', ['environment' => $environment]),
            ];
        }

        if ($user === null || ! $this->authorization->can(Actor::forUser($user), self::CAPABILITY)->allowed) {
            return [
                'available' => false,
                'environment' => $environment,
                'reason' => (string) __('Upstream synchronization is unavailable: your account does not hold the :capability capability.', ['capability' => self::CAPABILITY]),
            ];
        }

        return ['available' => true, 'environment' => $environment, 'reason' => null];
    }

    /**
     * The server-side precondition for every synchronization action. Throws on
     * any closed condition — environment and capability both — so a request that
     * bypasses the UI still stops here.
     *
     * @throws AuthorizationException
     */
    public function authorize(?Authenticatable $user): void
    {
        $state = $this->state($user);

        if (! $state['available']) {
            throw new AuthorizationException($state['reason'] ?? (string) __('Upstream synchronization is unavailable.'));
        }
    }
}
