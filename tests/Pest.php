<?php

use App\Base\AI\Contracts\Tool;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\ToolResult;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Media\Models\MediaAsset;
use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;
use App\Base\Media\PhotoCleanup\PhotoRoomConfiguration;
use App\Base\Media\Services\MediaAssetStore;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\RelationshipType;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
 * Create a non-platform tenant owner user for tenant-boundary tests.
 */
function createTenantOwnerUser(?int $companyId = null): User
{
    setupAuthzRoles();

    $companyId ??= Company::factory()->create()->id;
    $role = Role::query()->where('code', 'tenant_owner')->whereNull('company_id')->firstOrFail();
    $user = User::factory()->create(['company_id' => $companyId]);

    PrincipalRole::query()->create([
        'company_id' => $companyId,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

/**
 * Create a user authorized to access the Kiat investment extension.
 */
function createKiatUser(?int $companyId = null): User
{
    setupAuthzRoles();

    $companyId ??= Company::factory()->create()->id;

    $user = User::factory()->create(['company_id' => $companyId]);

    foreach (['kiat.investment.view', 'kiat.investment.manage'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $companyId,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

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

/**
 * Configure a PhotoRoom key for photo-cleanup tests. Returns the company id
 * the credentials were stored under.
 */
function configurePhotoRoom(string $apiKey = 'sandbox-key-123', ?int $companyId = null): int
{
    $companyId ??= Company::factory()->create()->id;

    app(ImageProviderCredentialStore::class)->upsert($companyId, PhotoRoomConfiguration::PROVIDER, [
        'display_name' => PhotoRoomConfiguration::PROVIDER_LABEL,
        'base_url' => PhotoRoomConfiguration::API_BASE_URL,
        'credentials' => ['api_key' => $apiKey],
        'connection_config' => [],
    ]);

    return $companyId;
}

/** @deprecated Use configurePhotoRoom() */
function configurePhotoRoomSandbox(string $apiKey = 'sandbox-key-123'): int
{
    return configurePhotoRoom($apiKey);
}

/**
 * Store an image-family provider key for a company. Generic over the known
 * Vision providers used in tests; keeps display_name/base_url honest per
 * provider so the family summary and credential store see consistent state.
 */
function configureImageProviderKey(string $providerKey, int $companyId, string $apiKey = 'test-key'): void
{
    $meta = [
        'photoroom' => ['PhotoRoom', 'https://sdk.photoroom.com'],
        'poof' => ['Poof', 'https://api.poof.bg/v1'],
        'claid' => ['Claid AI', 'https://api.claid.ai/v1'],
        'stability' => ['Stability AI', 'https://api.stability.ai/v2beta/stable-image'],
    ][$providerKey] ?? ['Provider', 'https://example.test'];

    app(ImageProviderCredentialStore::class)->upsert($companyId, $providerKey, [
        'display_name' => $meta[0],
        'base_url' => $meta[1],
        'credentials' => ['api_key' => $apiKey],
        'connection_config' => [],
    ]);
}

/**
 * Build a `background_removed` derivative of an original asset that mirrors a
 * real photo-cleanup run (deterministic storage key + provenance metadata),
 * without calling the provider. Stand-in for an already-cleaned photo.
 */
function backgroundRemovedDerivative(
    MediaAsset $original,
    string $bytes = 'CLEANED-PNG-BYTES',
    string $provider = PhotoRoomConfiguration::PROVIDER,
    string $providerLabel = PhotoRoomConfiguration::PROVIDER_LABEL,
): MediaAsset {
    $storageKey = Str::beforeLast($original->storage_key, '.').'.'.Str::slug($provider).'.background_removed.png';

    return app(MediaAssetStore::class)->putDerivativeBytes(
        $original,
        MediaAsset::KIND_BACKGROUND_REMOVED,
        $original->disk,
        $storageKey,
        $bytes,
        [
            'original_filename' => Str::beforeLast((string) $original->original_filename, '.').'.background_removed.png',
            'mime_type' => 'image/png',
            'metadata' => [
                'provider' => $provider,
                'provider_label' => $providerLabel,
                'source_asset_id' => $original->id,
                'status' => 'ready',
                'cleaned_at' => now()->toIso8601String(),
            ],
        ],
    );
}

/**
 * Return a git process command without leading `-c name=value` config pairs.
 *
 * GitRepository always scopes operational config such as safe.directory before
 * the verb. Most feature tests care about the verb contract, not that plumbing.
 *
 * @param  list<string>  $command
 * @return list<string>
 */
function gitCommandWithoutConfig(array $command): array
{
    if (($command[0] ?? null) !== 'git') {
        return $command;
    }

    $args = array_slice($command, 1);

    while (($args[0] ?? null) === '-c') {
        array_splice($args, 0, 2);
    }

    return ['git', ...$args];
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
 * Create a throwaway fake domain checkout under app/Modules.
 *
 * The checkout carries one Sample module with a runnable migration that
 * claims a table, a settings declaration, and (optionally) a discoverable
 * ServiceProvider, a menu file, or a git repo. Callers must delete the
 * directory before the test ends (the path is gitignored either way).
 *
 * @param  array{withProvider?: bool, withMenu?: bool, withGit?: bool}  $options
 * @return string Absolute path of the created domain directory
 */
function createFakeDomainCheckout(string $domain, string $table, string $settingKey, array $options = []): string
{
    $base = app_path('Modules/'.$domain);
    $module = $base.'/Sample';

    File::ensureDirectoryExists($module.'/Database/Migrations');
    File::ensureDirectoryExists($module.'/Config');

    file_put_contents(
        $module.'/Database/Migrations/2099_01_01_000000_create_'.$table.'_table.php',
        <<<PHP
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$table}', function (Blueprint \$table): void {
                    \$table->id();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP,
    );

    file_put_contents(
        $module.'/Config/settings.php',
        <<<PHP
        <?php
        return [
            'editable' => [
                'zz_fake' => [
                    'label' => 'Fake',
                    'fields' => [
                        ['key' => '{$settingKey}', 'label' => 'Option', 'type' => 'text'],
                    ],
                ],
            ],
        ];
        PHP,
    );

    if ($options['withProvider'] ?? false) {
        file_put_contents(
            $module.'/ServiceProvider.php',
            <<<PHP
            <?php

            namespace App\Modules\\{$domain}\Sample;

            use Illuminate\Support\ServiceProvider as BaseServiceProvider;

            class ServiceProvider extends BaseServiceProvider {}
            PHP,
        );
    }

    if ($options['withMenu'] ?? false) {
        file_put_contents(
            $module.'/Config/menu.php',
            <<<'PHP'
            <?php
            return [
                'items' => [
                    ['id' => 'zz-fake-domain-root', 'label' => 'Fake Domain'],
                ],
            ];
            PHP,
        );
    }

    if ($options['withGit'] ?? false) {
        Process::path($base)->run(['git', 'init', '-q']);
    }

    return $base;
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

/**
 * Write a throwaway incubating migration into a test extension path and return its file path.
 *
 * Used to exercise the incubating-schema guard without shipping a real migration.
 */
function writeIncubatingTestMigration(string $relativeDir, string $file, string $table): string
{
    $dir = base_path($relativeDir);

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir.'/'.$file;

    file_put_contents($path, <<<PHP
    <?php
    use App\\Base\\Database\\Concerns\\IncubatingSchema;
    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        use IncubatingSchema;

        public function up(): void
        {
            Schema::create('{$table}', function (Blueprint \$t): void {
                \$t->id();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('{$table}');
        }
    };
    PHP);

    return $path;
}

/**
 * Remove a throwaway incubating migration and any schema/registry rows it created.
 */
function cleanupIncubatingTestMigration(string $relativeDir, string $file, string $table): void
{
    Schema::dropIfExists($table);
    DB::table('migrations')
        ->where('migration', str_replace('.php', '', $file))
        ->delete();

    if (Schema::hasTable('base_database_migration_sources')) {
        DB::table('base_database_migration_sources')
            ->where('migration_name', str_replace('.php', '', $file))
            ->delete();
    }

    $path = base_path($relativeDir.'/'.$file);

    if (is_file($path)) {
        @unlink($path);
    }

    $dir = dirname($path);
    @rmdir($dir);
    @rmdir(dirname($dir));
    @rmdir(dirname($dir, 2));
}
