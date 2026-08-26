<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The safety boundary of upstream synchronization (#345, lane 2 of #339):
 * synchronization capability exists only in an explicitly enabled development
 * or staging deployment, for a user who holds the capability. Production is a
 * read-only deployment consumer, and every undecidable state fails closed —
 * an unset or unrecognised deployment role means unavailable, never available.
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
     * Roles that may hold synchronization capability. An allow-list, not a
     * production deny-list: a new or misspelled role is closed by construction.
     */
    private const SYNC_ROLES = ['development', 'staging'];

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The instance's declared deployment role, or null when unset. Read from
     * deployment configuration (BLB_DEPLOYMENT_ROLE), deliberately not from
     * APP_ENV: enabling synchronization must be its own explicit act, not a
     * side effect of inheriting a development app config.
     */
    public function role(): ?string
    {
        $role = config('software.deployment_role');

        if (! is_string($role)) {
            return null;
        }

        $role = strtolower(trim($role));

        return $role !== '' ? $role : null;
    }

    public function roleAllowsSync(): bool
    {
        return in_array($this->role(), self::SYNC_ROLES, true);
    }

    /**
     * Availability plus the operator-facing reason when closed. The page states
     * this plainly instead of hiding the concept or failing at the point of use.
     *
     * @return array{available: bool, reason: string|null}
     */
    public function state(?Authenticatable $user): array
    {
        if (! $this->roleAllowsSync()) {
            $role = $this->role();

            return [
                'available' => false,
                'reason' => $role === null
                    ? (string) __('Upstream synchronization is unavailable: this deployment declares no role (BLB_DEPLOYMENT_ROLE). It is only available on a deployment explicitly declared development or staging.')
                    : (string) __('Upstream synchronization is unavailable on a :role deployment. It is only available on a deployment explicitly declared development or staging.', ['role' => $role]),
            ];
        }

        if ($user === null || ! $this->authorization->can(Actor::forUser($user), self::CAPABILITY)->allowed) {
            return [
                'available' => false,
                'reason' => (string) __('Upstream synchronization is unavailable: your account does not hold the :capability capability.', ['capability' => self::CAPABILITY]),
            ];
        }

        return ['available' => true, 'reason' => null];
    }

    /**
     * The server-side precondition for every synchronization action. Throws on
     * any closed condition — role and capability both — so a request that
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
