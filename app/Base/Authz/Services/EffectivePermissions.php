<?php
namespace App\Base\Authz\Services;

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use Illuminate\Support\Facades\DB;

/**
 * Pre-loaded permission set for an actor.
 *
 * Loads all direct grants and role-derived capabilities in a fixed
 * number of queries, then evaluates checks in memory.
 */
final class EffectivePermissions
{
    /**
     * @param  array<string, true>  $directDenies  Capability keys explicitly denied
     * @param  array<string, true>  $directAllows  Capability keys explicitly allowed
     * @param  array<string, true>  $roleGrants  Capability keys granted via roles
     * @param  bool  $grantAll  Whether the actor has a grant_all role
     */
    private function __construct(
        private readonly array $directDenies,
        private readonly array $directAllows,
        private readonly array $roleGrants,
        private readonly bool $grantAll = false,
    ) {}

    /**
     * Load all effective permissions for an actor.
     *
     * Executes a fixed number of queries regardless of how many
     * capabilities or roles the actor has.
     */
    public static function forActor(Actor $actor): self
    {
        $companyScope = static function ($query) use ($actor): void {
            $query->where('company_id', $actor->companyId)
                ->orWhereNull('company_id');
        };

        $directEntries = PrincipalCapability::query()
            ->where('principal_type', $actor->type->value)
            ->where('principal_id', $actor->id)
            ->where($companyScope)
            ->get(['capability_key', 'is_allowed']);

        $directDenies = [];
        $directAllows = [];

        foreach ($directEntries as $entry) {
            if ($entry->is_allowed === false) {
                $directDenies[$entry->capability_key] = true;
            } elseif ($entry->is_allowed === true) {
                $directAllows[$entry->capability_key] = true;
            }
        }

        $actorRoles = PrincipalRole::query()
            ->join('base_authz_roles', 'base_authz_roles.id', '=', 'base_authz_principal_roles.role_id')
            ->where('base_authz_principal_roles.principal_type', $actor->type->value)
            ->where('base_authz_principal_roles.principal_id', $actor->id)
            ->where(static function ($query) use ($actor): void {
                $query->where('base_authz_principal_roles.company_id', $actor->companyId)
                    ->orWhereNull('base_authz_principal_roles.company_id');
            })
            ->select('base_authz_roles.id', 'base_authz_roles.grant_all')
            ->get();

        $grantAll = $actorRoles->contains('grant_all', true);

        $roleGrants = [];

        if (! $grantAll) {
            $roleIds = $actorRoles->pluck('id')->all();

            $roleGrantKeys = DB::table('base_authz_role_capabilities')
                ->whereIn('role_id', $roleIds)
                ->distinct()
                ->pluck('capability_key');

            foreach ($roleGrantKeys as $key) {
                $roleGrants[$key] = true;
            }
        }

        return new self($directDenies, $directAllows, $roleGrants, $grantAll);
    }

    /**
     * All capabilities the actor is effectively allowed.
     *
     * Merges direct allows and role grants, then subtracts explicit denies.
     *
     * @return array<int, string>
     */
    public function allowed(): array
    {
        if ($this->grantAll) {
            return array_values(array_diff(
                app(CapabilityRegistry::class)->all(),
                array_keys($this->directDenies)
            ));
        }

        $allowed = array_keys($this->directAllows) + array_keys($this->roleGrants);

        return array_values(array_unique(array_diff($allowed, array_keys($this->directDenies))));
    }

    /**
     * All capabilities explicitly denied for this actor.
     *
     * @return array<int, string>
     */
    public function denied(): array
    {
        return array_keys($this->directDenies);
    }

    /**
     * Whether the actor has a role with grant_all.
     */
    public function hasGrantAll(): bool
    {
        return $this->grantAll;
    }

    /**
     * Evaluate whether the actor has the given capability.
     *
     * Priority: explicit deny > explicit allow > grant_all > role grant > deny.
     */
    public function evaluate(string $capability): AuthorizationDecision
    {
        if (isset($this->directDenies[$capability])) {
            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_EXPLICITLY,
                ['direct_capability']
            );
        }

        if (isset($this->directAllows[$capability])) {
            return AuthorizationDecision::allow(['direct_capability']);
        }

        if ($this->grantAll) {
            return AuthorizationDecision::allow(['grant_all']);
        }

        if (isset($this->roleGrants[$capability])) {
            return AuthorizationDecision::allow(['role_capability']);
        }

        return AuthorizationDecision::deny(
            AuthorizationReasonCode::DENIED_MISSING_CAPABILITY,
            ['role_capability']
        );
    }
}
