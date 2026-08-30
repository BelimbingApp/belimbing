<?php

namespace App\Core\Company\Services;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Foundation\Contracts\FrameworkPrimitivesProvisioner as FrameworkPrimitivesProvisionerContract;
use App\Base\Foundation\Exceptions\FrameworkPrimitivesNotConfiguredException;
use App\Base\Support\Str as BlbStr;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Core implementation of the Base-owned installation contract.
 *
 * Base knows only that framework primitives can be provisioned. Core owns the
 * company, user, and employee coordination needed to implement that contract.
 */
class FrameworkPrimitivesProvisioner implements FrameworkPrimitivesProvisionerContract
{
    /** @var callable(string): void */
    private $outputCallback;

    private ?string $bootstrapAdminFile;

    /** @param callable(string): void|null $outputCallback */
    public function __construct(
        private readonly PrimaryCompanyManager $primaryCompanies,
        ?callable $outputCallback = null,
        ?string $bootstrapAdminFile = null,
    ) {
        $this->outputCallback = $outputCallback ?? static function (string $message): void {
            unset($message);
        };
        $this->bootstrapAdminFile = $bootstrapAdminFile;
    }

    public function provision(
        ?string $companyName = null,
        ?string $companyCode = null,
        ?string $bootstrapAdminFile = null,
        ?callable $output = null,
    ): void {
        if ($bootstrapAdminFile !== null) {
            $this->bootstrapAdminFile = $bootstrapAdminFile;
        }

        if ($output !== null) {
            $this->outputCallback = $output;
        }

        DB::transaction(function () use ($companyName, $companyCode): void {
            $this->provisionPlatformOperatorTenant($companyName);
            $company = $this->provisionPlatformOperatorCompany($companyName, $companyCode);
            $this->provisionAdminUser($company);
            $this->provisionLara();
        });
    }

    public function provisionPlatformOperatorTenant(?string $companyName = null): bool
    {
        $wasCreated = Tenant::provisionPlatformOperator($companyName);

        if ($wasCreated) {
            $this->log('Created platform-operator tenant: '.($companyName ?: 'Platform operator'));
        }

        return $wasCreated;
    }

    public function provisionPlatformOperatorCompany(?string $name = null, ?string $code = null): Company
    {
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;
        $code = is_string($code) && trim($code) !== '' ? BlbStr::code($code) : null;

        return DB::transaction(function () use ($name, $code): Company {
            $operator = Tenant::requirePlatformOperator();
            $tenant = Tenant::query()
                ->whereKey($operator->id)
                ->lockForUpdate()
                ->firstOrFail();
            $company = $this->primaryCompanies->findForTenant($tenant);

            if ($company === null) {
                if ($name === null) {
                    throw FrameworkPrimitivesNotConfiguredException::missingPlatformOperatorCompany();
                }

                $company = Company::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'code' => $code,
                    'status' => 'active',
                ]);
                $this->primaryCompanies->assign($tenant, $company);
                $this->log("Created platform-operator primary company: {$company->name}");

                return $company;
            }

            $updates = [];

            if ($name !== null) {
                $updates['name'] = $name;
            }

            if ($code !== null) {
                $updates['code'] = $code;
            }

            if ($updates !== []) {
                $company->forceFill([...$updates, 'status' => 'active'])->save();
                $this->log("Updated platform-operator primary company: {$company->name}");
            }

            return $company;
        });
    }

    public function provisionAdminUser(?Company $operatorCompany = null): ?User
    {
        $operatorCompany ??= $this->primaryCompanies->findForTenant(Tenant::requirePlatformOperator());

        if ($operatorCompany === null) {
            return null;
        }

        $bootstrap = $this->resolveBootstrapAdminPayload();

        if ($bootstrap !== null) {
            $user = User::query()->firstOrNew(['email' => $bootstrap['email']]);

            if ($user->exists && (int) $user->company_id !== (int) $operatorCompany->id) {
                throw FrameworkPrimitivesNotConfiguredException::bootstrapAdminBelongsToAnotherCompany(
                    $bootstrap['email'],
                );
            }

            $user->forceFill([
                'company_id' => $operatorCompany->id,
                'name' => $bootstrap['name'],
                'password' => $bootstrap['password'],
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
            $wasCreated = ! $user->exists;
            $user->save();

            $operatorCompany->assignAdminUser($user);
            $this->ensureSystemRoleAssigned($user, 'core_admin');
            $this->log(($wasCreated ? 'Created' : 'Updated')." admin user: {$bootstrap['email']}");

            return $user;
        }

        $existingAdmin = $operatorCompany->resolveAdminUser();

        if ($existingAdmin !== null) {
            $this->ensureSystemRoleAssigned($existingAdmin, 'core_admin');

            return $existingAdmin;
        }

        $candidate = $this->resolveExistingAdminCandidate($operatorCompany);

        if ($candidate !== null) {
            $operatorCompany->assignAdminUser($candidate);
            $this->ensureSystemRoleAssigned($candidate, 'core_admin');

            return $candidate;
        }

        if ($this->usersTableIsIncubating()) {
            $this->log('Skipping admin user provisioning — users schema is incubating; re-run setup to bootstrap admin.');

            return null;
        }

        throw FrameworkPrimitivesNotConfiguredException::missingAdminBootstrap();
    }

    public function provisionLara(): bool
    {
        $wasCreated = Employee::provisionLara();

        if ($wasCreated) {
            $this->log('Created Lara (system Agent — orchestrator)');
        }

        return $wasCreated;
    }

    private function usersTableIsIncubating(): bool
    {
        try {
            return app(IncubatingSchemaInspector::class)->tableIsIncubating('users');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{name: string, email: string, password: string}|null */
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

    private function resolveExistingAdminCandidate(Company $company): ?User
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
                        ->where('base_authz_principal_roles.principal_type', PrincipalType::USER->value)
                        ->where('base_authz_principal_roles.role_id', $coreAdminRole->id);
                })
                ->where('users.company_id', $company->id)
                ->orderBy('users.id')
                ->select('users.*')
                ->get();

            if ($coreAdmins->count() === 1) {
                return $coreAdmins->first();
            }
        }

        $fallback = User::query()->where('company_id', $company->id)->orderBy('id')->first();

        if ($fallback !== null) {
            $this->log("Adopted oldest platform-operator company user as admin candidate: {$fallback->email}");
        }

        return $fallback;
    }

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

        $assignment = PrincipalRole::query()->firstOrCreate([
            'company_id' => $user->company_id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'role_id' => $role->id,
        ]);

        if ($assignment->wasRecentlyCreated) {
            $this->log("Assigned {$roleCode} role to admin user: {$user->email}");
        }
    }

    private function log(string $message): void
    {
        ($this->outputCallback)($message);
    }
}
