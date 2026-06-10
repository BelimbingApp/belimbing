<?php

use App\Base\AI\Contracts\Tool;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\ToolResult;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\RelationshipType;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../app/Modules/*/Tests/Feature', '../app/Modules/*/*/Tests/Feature', '../extensions/*/*/Tests/Feature');

/**
 * Seed configured system roles and their capabilities for feature tests.
 */
function setupAuthzRoles(): void
{
    $roles = config('authz.roles', []);

    foreach ($roles as $code => $roleDefinition) {
        $role = Role::query()->firstOrCreate(
            ['company_id' => null, 'code' => $code],
            [
                'name' => $roleDefinition['name'],
                'description' => $roleDefinition['description'] ?? null,
                'is_system' => true,
                'grant_all' => $roleDefinition['grant_all'] ?? false,
            ]
        );

        $now = now();

        foreach ($roleDefinition['capabilities'] ?? [] as $capabilityKey) {
            DB::table('base_authz_role_capabilities')->insertOrIgnore([
                'role_id' => $role->id,
                'capability_key' => strtolower($capabilityKey),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

/**
 * Create a user with core_admin role for tests that need authz capabilities.
 */
function createAdminUser(): User
{
    setupAuthzRoles();

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

/**
 * Create two companies and a default relationship type for relationship tests.
 *
 * @return array{Company, Company, RelationshipType}
 */
function createCompanyRelationshipFixture(): array
{
    return [
        Company::factory()->create(),
        Company::factory()->create(),
        RelationshipType::factory()->create(),
    ];
}

final class StubTool implements Tool
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  (callable(array<string, mixed>): ToolResult)|null  $execute
     */
    public function __construct(
        private readonly string $toolName,
        private readonly string $toolDescription = '',
        private readonly array $schema = ['type' => 'object', 'properties' => []],
        private readonly mixed $execute = null,
        private readonly ?string $capability = null,
        private readonly ToolCategory $category = ToolCategory::SYSTEM,
        private readonly ToolRiskClass $riskClass = ToolRiskClass::READ_ONLY,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function displayName(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function parametersSchema(): array
    {
        return $this->schema;
    }

    public function requiredCapability(): ?string
    {
        return $this->capability;
    }

    public function category(): ToolCategory
    {
        return $this->category;
    }

    public function riskClass(): ToolRiskClass
    {
        return $this->riskClass;
    }

    public function summary(): string
    {
        return $this->toolDescription;
    }

    public function explanation(): string
    {
        return '';
    }

    public function setupRequirements(): array
    {
        return [];
    }

    public function testExamples(): array
    {
        return [];
    }

    public function healthChecks(): array
    {
        return [];
    }

    public function limits(): array
    {
        return [];
    }

    public function execute(array $arguments): ToolResult
    {
        if ($this->execute === null) {
            return ToolResult::success('executed');
        }

        return ($this->execute)($arguments);
    }
}

/**
 * Build a minimal backup manifest payload for tests.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function makeBackupManifestPayload(string $backupId, string $artifactPath, string $payloadBytes, array $overrides = []): array
{
    return [
        'backup_id' => $backupId,
        'driver' => 'sqlite',
        'encryption_mode' => 'none',
        'finished_at' => now()->toIso8601String(),
        'size_bytes' => strlen($payloadBytes),
        'sha256' => hash('sha256', $payloadBytes),
        'status' => 'success',
        'artifact_path' => $artifactPath,
        ...$overrides,
    ];
}
