<?php

namespace App\Base\Authz\Capability;

use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;

/**
 * Every declared capability, with who granted it and whether it exists.
 *
 * Two facts an operator cannot get anywhere else, and both are easy to get
 * wrong in opposite directions:
 *
 * The owning module is not in config('authz'). That config is a flat merge of
 * every module's file, so provenance is gone by the time anything can read it
 * — the declarations have to be rescanned from the same paths the service
 * provider walks.
 *
 * A capability declared in a module config is not necessarily a capability
 * that exists. CapabilityCatalog drops any key with an unknown domain or verb,
 * and KnownCapabilityPolicy then denies every check against it. Such a row is
 * marked with the catalog's own reason and reports no holder count: counting
 * the principals whose roles grant it would report an access nobody has.
 */
final class CapabilityInventory
{
    public const VIEW = 'admin.system.capabilities.view';

    public function __construct(
        private readonly TenantContext $tenants,
    ) {}

    /** @return list<CapabilityInventoryRow> */
    public function rows(): array
    {
        $catalog = CapabilityCatalog::fromConfig((array) config('authz'));
        $catalog->validate();

        return $this->rowsFrom($this->declarations(), $catalog->rejected());
    }

    /**
     * Build the rows from an explicit declaration map.
     *
     * Separated from discovery so the rendering rules — conflict, rejection,
     * holder counting — can be exercised without planting config files on
     * disk, and so a caller can ask "what would this look like" of a set it
     * has in hand.
     *
     * @param  array<string, list<string>>  $declarations  capability => modules that declared it
     * @param  array<string, string>  $rejected  capability => catalog's reason
     * @return list<CapabilityInventoryRow>
     */
    public function rowsFrom(array $declarations, array $rejected): array
    {
        $grants = $this->grantingRoles();
        // The values are role codes, not keys: array_keys here would hand
        // holderCounts a list of integer indices and silently count nothing.
        $holders = $this->holderCounts(
            $grants === [] ? [] : array_values(array_unique(array_merge(...array_values($grants)))),
        );

        $rows = [];

        foreach ($declarations as $capability => $modules) {
            $modules = array_values(array_unique($modules));
            sort($modules);
            $roles = $grants[$capability] ?? [];
            sort($roles);
            $reason = $rejected[$capability] ?? null;

            $rows[] = new CapabilityInventoryRow(
                capability: $capability,
                modules: $modules,
                conflicted: count($modules) > 1,
                roles: $roles,
                // Denied to everybody, so there is no holder count to report.
                holders: $reason === null ? $this->sumHolders($roles, $holders) : null,
                rejectedReason: $reason,
            );
        }

        usort($rows, static fn (CapabilityInventoryRow $a, CapabilityInventoryRow $b): int => strcmp($a->capability, $b->capability));

        return $rows;
    }

    /**
     * Which module declared each capability, by rescanning the config files.
     *
     * @return array<string, list<string>>
     */
    public function declarations(): array
    {
        $configFile = 'Config/authz.php';
        $patterns = [
            ApplicationTopology::baseComponentPattern($configFile),
            ApplicationTopology::coreModulePattern($configFile),
            ApplicationTopology::domainModulePattern($configFile),
            ApplicationTopology::extensionSourcePattern($configFile),
            ApplicationTopology::extensionModulePattern($configFile),
        ];

        $declarations = [];

        foreach ($patterns as $pattern) {
            foreach (DomainState::filterPaths(glob($pattern) ?: []) as $file) {
                $config = require $file;

                foreach ((array) ($config['capabilities'] ?? []) as $capability) {
                    $declarations[strtolower((string) $capability)][] = $this->moduleLabel($file);
                }
            }
        }

        return $declarations;
    }

    /**
     * The module a config file belongs to, as a path relative to app/.
     *
     * e.g. /…/app/Base/Authz/Config/authz.php becomes Base/Authz.
     */
    private function moduleLabel(string $file): string
    {
        $path = str_replace('\\', '/', $file);
        $marker = '/app/';
        $at = strrpos($path, $marker);
        $relative = $at === false ? $path : substr($path, $at + strlen($marker));

        return str_replace('/Config/authz.php', '', $relative);
    }

    /**
     * Every role grant, keyed by capability.
     *
     * Not filtered to the declared set: rowsFrom() iterates the declarations,
     * so a grant for an undeclared key is never looked up. A filter here would
     * read like a guard and could not change what the page shows.
     *
     * @return array<string, list<string>> capability => role codes
     */
    private function grantingRoles(): array
    {
        $grants = [];

        foreach (Role::query()->with('capabilities')->get() as $role) {
            foreach ($role->capabilities as $grant) {
                $grants[strtolower((string) $grant->capability_key)][] = (string) $role->code;
            }
        }

        return $grants;
    }

    /**
     * Principals holding each role, counted inside the ambient tenant only.
     *
     * A role is often global (company_id null), so its assignments span every
     * tenant; the count has to come from the assignment's company, not from
     * the role.
     *
     * @param  list<string>  $roleCodes
     * @return array<string, int>
     */
    private function holderCounts(array $roleCodes): array
    {
        if ($roleCodes === []) {
            return [];
        }

        $tenantId = $this->tenants->currentTenantId();

        if ($tenantId === null) {
            return [];
        }

        $companies = Company::query()->where('tenant_id', $tenantId)->pluck('id');
        $roles = Role::query()->whereIn('code', $roleCodes)->pluck('code', 'id');

        if ($roles->isEmpty() || $companies->isEmpty()) {
            return [];
        }

        $counts = [];

        foreach (PrincipalRole::query()
            ->whereIn('role_id', $roles->keys())
            ->whereIn('company_id', $companies)
            ->get() as $assignment) {
            $code = (string) $roles[$assignment->role_id];
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, int>  $holders
     */
    private function sumHolders(array $roles, array $holders): int
    {
        $total = 0;

        foreach ($roles as $role) {
            $total += $holders[$role] ?? 0;
        }

        return $total;
    }
}
