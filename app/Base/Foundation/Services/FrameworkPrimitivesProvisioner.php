<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\Services;

use App\Base\Foundation\Exceptions\FrameworkPrimitivesNotConfiguredException;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

/**
 * Provision framework primitives: licensee company, admin user, and Lara.
 *
 * This service coordinates cross-domain setup during migrations and installation.
 * It is idempotent — safe to call repeatedly.
 */
class FrameworkPrimitivesProvisioner
{
    /**
     * Callback for logging output.
     *
     * @var callable(string): void
     */
    private $outputCallback;

    /**
     * Path to a transient file containing the admin bootstrap payload
     * (name, email, password — one per line). Null when no bootstrap
     * file is available (e.g. subsequent runs after initial setup).
     */
    private ?string $bootstrapAdminFile;

    /**
     * Create a new provisioner instance.
     *
     * @param  callable(string): void|null  $outputCallback
     * @param  string|null  $bootstrapAdminFile  Path to transient admin bootstrap file
     */
    public function __construct(?callable $outputCallback = null, ?string $bootstrapAdminFile = null)
    {
        $this->outputCallback = $outputCallback ?? static function (): void {
            // Intentionally silent: headless provisioning when no output sink is configured.
        };
        $this->bootstrapAdminFile = $bootstrapAdminFile;
    }

    /**
     * Provision all framework primitives.
     *
     * Ordering: Licensee company → admin user → Lara employee.
     * Each step depends on the previous being complete.
     *
     * @param  string|null  $companyName  Display name for the licensee company
     * @param  string|null  $companyCode  Optional company code
     */
    public function provision(?string $companyName = null, ?string $companyCode = null): void
    {
        $this->provisionLicensee($companyName, $companyCode);
        $this->provisionAdminUser();
        $this->provisionLara();
    }

    /**
     * Provision the licensee company at id=1.
     *
     * @param  string|null  $name  Display name for the licensee company
     * @param  string|null  $code  Optional company code (normalized to snake_case)
     * @return bool Whether the company was created (false if updated)
     */
    public function provisionLicensee(?string $name = null, ?string $code = null): bool
    {
        $licenseeExists = Company::query()->whereKey(Company::LICENSEE_ID)->exists();

        if (! $licenseeExists && (! is_string($name) || trim($name) === '')) {
            throw FrameworkPrimitivesNotConfiguredException::missingLicenseeCompany();
        }

        if ($licenseeExists && (! is_string($name) || trim($name) === '')) {
            // Already configured; nothing to update.
            return false;
        }

        $wasCreated = Company::provisionLicensee($name, $code);

        if ($wasCreated) {
            $this->log("Created licensee company: {$name}");
        } else {
            $this->log("Updated licensee company: {$name}");
        }

        return $wasCreated;
    }

    /**
     * Provision the admin user in the licensee company.
     *
     * Priority: canonical admin anchor (metadata) → bootstrap file → heuristic
     * candidate adoption → default admin. The bootstrap file, when provided via
     * the constructor, always wins over heuristic fallbacks.
     *
     * @return User|null The admin user, or null if licensee doesn't exist
     */
    public function provisionAdminUser(): ?User
    {
        $licensee = Company::query()->find(Company::LICENSEE_ID);

        if ($licensee === null) {
            return null;
        }

        $bootstrap = $this->resolveBootstrapAdminPayload();

        if ($bootstrap !== null) {
            $user = User::query()->firstOrNew(['email' => $bootstrap['email']]);

            $user->forceFill([
                'company_id' => Company::LICENSEE_ID,
                'name' => $bootstrap['name'],
                'password' => $bootstrap['password'],
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            $wasCreated = ! $user->exists;
            $user->save();

            $licensee->assignAdminUser($user);
            $this->ensureSystemRoleAssigned($user, 'core_admin');

            if ($wasCreated) {
                $this->log("Created admin user: {$bootstrap['email']}");
            } else {
                $this->log("Updated admin user: {$bootstrap['email']}");
            }

            return $user;
        }

        $existingAdmin = $licensee->resolveAdminUser();

        if ($existingAdmin !== null) {
            $this->ensureSystemRoleAssigned($existingAdmin, 'core_admin');

            return $existingAdmin;
        }

        // No bootstrap payload and no canonical anchor — fall back to
        // best-effort candidate adoption from existing licensee users.
        $existingCandidate = $this->resolveExistingAdminCandidate();

        if ($existingCandidate !== null) {
            $licensee->assignAdminUser($existingCandidate);
            $this->ensureSystemRoleAssigned($existingCandidate, 'core_admin');

            return $existingCandidate;
        }

        throw FrameworkPrimitivesNotConfiguredException::missingAdminBootstrap();
    }

    /**
     * Provision Lara (employee id=1), the system Agent orchestrator.
     *
     * @return bool Whether Lara was created (false if already existed)
     */
    public function provisionLara(): bool
    {
        $wasCreated = Employee::provisionLara();

        if ($wasCreated) {
            $this->log('Created Lara (system Agent — orchestrator)');
        }

        return $wasCreated;
    }

    /**
     * Resolve the bootstrap admin payload from the transient setup file.
     *
     * The file path is provided via the constructor so the provisioner has
     * no hidden coupling to process environment variables.
     *
     * @return array{name: string, email: string, password: string}|null
     */
    private function resolveBootstrapAdminPayload(): ?array
    {
        $path = $this->bootstrapAdminFile;

        if (! is_string($path) || trim($path) === '' || ! is_file($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines)) {
            return null;
        }

        $name = isset($lines[0]) ? trim($lines[0]) : 'Administrator';
        $email = isset($lines[1]) ? trim($lines[1]) : '';
        $password = isset($lines[2]) ? $lines[2] : 'password';

        if ($email === '') {
            return null;
        }

        return [
            'name' => $name !== '' ? $name : 'Administrator',
            'email' => $email,
            'password' => $password !== '' ? $password : 'password',
        ];
    }

    /**
     * Resolve a best-effort existing admin candidate when no canonical anchor exists yet.
     *
     * Prefers a single core_admin assignee in the licensee company. If there are
     * multiple candidates, falls back to the oldest licensee user so repeated
     * provision runs stabilize on existing data instead of creating a default user.
     */
    private function resolveExistingAdminCandidate(): ?User
    {
        $coreAdminRole = Role::query()
            ->whereNull('company_id')
            ->where('is_system', true)
            ->where('code', 'core_admin')
            ->first();

        if ($coreAdminRole !== null) {
            $coreAdmins = User::query()
                ->join('base_authz_principal_roles', function ($join) use ($coreAdminRole): void {
                    $join->on('base_authz_principal_roles.principal_id', '=', 'users.id')
                        ->where('base_authz_principal_roles.principal_type', '=', PrincipalType::USER->value)
                        ->where('base_authz_principal_roles.role_id', '=', $coreAdminRole->id);
                })
                ->where('users.company_id', Company::LICENSEE_ID)
                ->orderBy('users.id')
                ->select('users.*')
                ->get();

            if ($coreAdmins->count() === 1) {
                return $coreAdmins->first();
            }
        }

        $fallback = User::query()
            ->where('company_id', Company::LICENSEE_ID)
            ->orderBy('id')
            ->first();

        if ($fallback !== null) {
            $this->log("Adopted oldest licensee user as admin candidate: {$fallback->email}");
        }

        return $fallback;
    }

    /**
     * Ensure the given user has the requested system role assignment.
     */
    private function ensureSystemRoleAssigned(User $user, string $roleCode): void
    {
        $role = Role::query()
            ->whereNull('company_id')
            ->where('is_system', true)
            ->where('code', $roleCode)
            ->first();

        if ($role === null) {
            return;
        }

        $principalRole = PrincipalRole::query()->firstOrCreate([
            'company_id' => $user->company_id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'role_id' => $role->id,
        ]);

        if ($principalRole->wasRecentlyCreated) {
            $this->log("Assigned {$roleCode} role to admin user: {$user->email}");
        }
    }

    /**
     * Log a message via the output callback.
     */
    private function log(string $message): void
    {
        ($this->outputCallback)($message);
    }
}
