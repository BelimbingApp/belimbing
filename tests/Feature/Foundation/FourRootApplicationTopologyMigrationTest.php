<?php

use App\Core\User\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function fourRootApplicationTopologyMigration(): Migration
{
    return require app_path(
        'Base/Foundation/Database/Migrations/2026_08_05_000000_normalize_four_root_application_topology.php',
    );
}

function ensureFourRootKiatReferenceTables(): void
{
    if (! Schema::hasTable('kiat_investment_agent_tasks')) {
        Schema::create('kiat_investment_agent_tasks', function (Blueprint $table): void {
            $table->id();
            $table->text('prompt');
        });
    }

    if (! Schema::hasTable('kiat_investment_globalwits_source_updates')) {
        Schema::create('kiat_investment_globalwits_source_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('raw_reference')->nullable();
        });
    }
}

function insertFourRootKiatAgentTask(string $prompt): int
{
    if (! Schema::hasColumn('kiat_investment_agent_tasks', 'name')) {
        return (int) DB::table('kiat_investment_agent_tasks')->insertGetId([
            'prompt' => $prompt,
        ]);
    }

    return (int) DB::table('kiat_investment_agent_tasks')->insertGetId([
        'name' => 'topology-normalization-probe',
        'description' => 'Topology normalization probe',
        'prompt' => $prompt,
        'cron_expression' => '0 0 * * *',
        'timezone' => 'Asia/Kuala_Lumpur',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5',
        'enabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertFourRootKiatRawReference(string $reference): int
{
    if (! Schema::hasColumn('kiat_investment_globalwits_source_updates', 'database_name')) {
        return (int) DB::table('kiat_investment_globalwits_source_updates')->insertGetId([
            'raw_reference' => $reference,
        ]);
    }

    return (int) DB::table('kiat_investment_globalwits_source_updates')->insertGetId([
        'source_name' => 'GlobalWits',
        'database_name' => 'topology-normalization-probe',
        'observed_at' => now(),
        'status' => 'observed',
        'raw_reference' => $reference,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function fourRootLegacySerializedCommand(): string
{
    $class = 'App\\Modules\\Core\\User\\Jobs\\TopologyProbe';
    $property = 'path';
    $path = 'extensions/sb-group/ibp/Database/Seeders/TopologyProbeSeeder.php';

    return 'O:'.strlen($class).':"'.$class.'":1:{'
        .'s:'.strlen($property).':"'.$property.'";'
        .'s:'.strlen($path).':"'.$path.'";'
        .'}';
}

it('normalizes persisted topology identities without rerunning completed seeders', function (): void {
    ensureFourRootKiatReferenceTables();

    $user = User::factory()->create();
    $now = now();
    $ranAt = '2026-08-05 01:02:03';
    $sourceHash = str_repeat('a', 64);

    foreach ([
        ['topology_probe_core', 'app/Modules/Core/User'],
        ['topology_probe_domain', 'app/Modules/People/Payroll'],
        ['topology_probe_extension', 'extensions/sb-group/ibp'],
    ] as [$tableName, $modulePath]) {
        DB::table('base_database_tables')->insert([
            'table_name' => $tableName,
            'module_name' => 'TopologyProbe',
            'module_path' => $modulePath,
            'migration_file' => $modulePath.'/Database/Migrations/topology_probe.php',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $legacySeederClass = 'Extensions\\SbGroup\\Ibp\\Database\\Seeders\\TopologyProbeSeeder';
    $canonicalSeederClass = 'App\\Extensions\\SbGroup\\Ibp\\Database\\Seeders\\TopologyProbeSeeder';
    $legacySeederId = DB::table('base_database_seeders')->insertGetId([
        'seeder_class' => $legacySeederClass,
        'module_name' => 'Ibp',
        'module_path' => 'extensions/sb-group/ibp',
        'migration_file' => 'extensions/sb-group/ibp/Database/Migrations/topology_probe.php',
        'status' => 'completed',
        'ran_at' => $ranAt,
        'error_message' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('base_database_seeders')->insert([
        'seeder_class' => $canonicalSeederClass,
        'module_name' => 'Ibp',
        'module_path' => 'app/Extensions/SbGroup/Ibp',
        'migration_file' => 'app/Extensions/SbGroup/Ibp/Database/Migrations/topology_probe.php',
        'status' => 'pending',
        'ran_at' => null,
        'error_message' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('base_database_migration_sources')->insert([
        'migration_name' => 'topology_probe_migration',
        'migration_file' => 'extensions/sb-group/ibp/Database/Migrations/topology_probe.php',
        'relative_path' => 'extensions/sb-group/ibp/Database/Migrations/topology_probe.php',
        'source_sha256' => $sourceHash,
        'source_state' => 'incubating',
        'first_observed_at' => $now,
        'last_observed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('base_workflow')->insert([
        'code' => 'topology-probe',
        'label' => 'Topology probe',
        'module' => 'kiat/investment',
        'model_class' => 'Extensions\\Kiat\\Investment\\Models\\CaseCompany',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('base_workflow_status_transitions')->insert([
        'flow' => 'topology-probe',
        'from_code' => 'draft',
        'to_code' => 'review',
        'capability' => 'admin.system.software.modules.manage',
        'guard_class' => 'App\\Modules\\Operation\\Quality\\Workflow\\TopologyGuard',
        'action_class' => 'App\\Modules\\Core\\User\\Workflow\\TopologyAction',
        'position' => 0,
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('base_audit_mutations')->insert([
        'company_id' => $user->company_id,
        'actor_type' => 'user',
        'actor_id' => $user->id,
        'auditable_type' => 'App\\Modules\\Core\\User\\Models\\User',
        'auditable_id' => (string) $user->id,
        'source' => 'listener',
        'event' => 'updated',
        'occurred_at' => $now,
    ]);

    $serializedCommand = fourRootLegacySerializedCommand();
    $queuePayload = json_encode([
        'displayName' => 'App\\Modules\\Core\\User\\Jobs\\TopologyProbe',
        'data' => [
            'commandName' => 'App\\Modules\\Core\\User\\Jobs\\TopologyProbe',
            'command' => $serializedCommand,
        ],
    ], JSON_THROW_ON_ERROR);

    DB::table('base_workflow_transition_outbox')->insert([
        'event_key' => 'topology-probe',
        'event_type' => 'topology.probe',
        'payload' => json_encode([
            'model_class' => 'Extensions\\Kiat\\Investment\\Models\\CaseCompany',
            'module_path' => 'app/Modules/People/Payroll',
        ], JSON_THROW_ON_ERROR),
        'attempts' => 0,
        'available_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $jobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => $queuePayload,
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $now->getTimestamp(),
        'created_at' => $now->getTimestamp(),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $queuePayload,
        'exception' => 'Topology probe',
        'failed_at' => $now,
    ]);

    $agentTaskId = insertFourRootKiatAgentTask(
        'Follow extensions/kiat/investment/AGENTS.md, run investment/Config/radar.php, '
        .'then use extensions/kiat/.agents/skills/radar-triage/SKILL.md.',
    );
    $rawReferenceId = insertFourRootKiatRawReference(
        'extensions/kiat/investment/Database/Seeders/topology-probe.csv',
    );

    DB::table('base_settings')->insert([
        [
            'key' => 'ui.landing_menu_id',
            'value' => json_encode('admin.system.software.modules', JSON_THROW_ON_ERROR),
            'is_encrypted' => false,
            'scope_type' => 'user',
            'scope_id' => 900001,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'key' => 'system.update.deployment.last_run',
            'value' => json_encode([
                'run_id' => 'topology-probe',
                'target_keys' => [
                    'app-Modules-People',
                    'app-Domains-Commerce-Marketplace',
                    'extensions-sb-group',
                    'app-Extensions-Kiat',
                ],
            ], JSON_THROW_ON_ERROR),
            'is_encrypted' => false,
            'scope_type' => 'user',
            'scope_id' => 900002,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $oldPinUrl = '/admin/system/software/modules?b=2&a=1#overview';
    $pinId = DB::table('user_pins')->insertGetId([
        'user_id' => $user->id,
        'label' => 'Modules',
        'url' => $oldPinUrl,
        'url_hash' => md5('/admin/system/software/modules?a=1&b=2'),
        'icon' => 'heroicon-o-puzzle-piece',
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $roleId = DB::table('base_authz_roles')->insertGetId([
        'company_id' => null,
        'name' => 'Topology probe',
        'code' => 'topology-probe',
        'is_system' => false,
        'grant_all' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('base_authz_role_capabilities')->insert([
        'role_id' => $roleId,
        'capability_key' => 'admin.system.software.modules.view',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('base_authz_principal_capabilities')->insert([
        'company_id' => $user->company_id,
        'principal_type' => 'user',
        'principal_id' => $user->id,
        'capability_key' => 'admin.system.software.modules.manage',
        'is_allowed' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    fourRootApplicationTopologyMigration()->up();

    expect(DB::table('base_database_tables')->where('table_name', 'topology_probe_core')->value('module_path'))
        ->toBe('app/Core/User')
        ->and(DB::table('base_database_tables')->where('table_name', 'topology_probe_domain')->value('module_path'))
        ->toBe('app/Domains/People/Payroll')
        ->and(DB::table('base_database_tables')->where('table_name', 'topology_probe_extension')->value('module_path'))
        ->toBe('app/Extensions/SbGroup/Ibp');

    $seeder = DB::table('base_database_seeders')->where('seeder_class', $canonicalSeederClass)->first();
    expect($seeder)->not->toBeNull()
        ->and((int) $seeder->id)->toBe((int) $legacySeederId)
        ->and($seeder->status)->toBe('completed')
        ->and((string) $seeder->ran_at)->toContain('2026-08-05 01:02:03')
        ->and(DB::table('base_database_seeders')
            ->whereIn('seeder_class', [$legacySeederClass, $canonicalSeederClass])
            ->count())->toBe(1);

    $source = DB::table('base_database_migration_sources')
        ->where('migration_name', 'topology_probe_migration')
        ->first();
    expect($source->relative_path)->toBe(
        'app/Extensions/SbGroup/Ibp/Database/Migrations/topology_probe.php',
    )
        ->and($source->migration_file)->toBe(
            'app/Extensions/SbGroup/Ibp/Database/Migrations/topology_probe.php',
        )
        ->and($source->source_sha256)->toBe($sourceHash);

    expect(DB::table('base_workflow')->where('code', 'topology-probe')->value('model_class'))
        ->toBe('App\\Extensions\\Kiat\\Investment\\Models\\CaseCompany')
        ->and(DB::table('base_workflow_status_transitions')
            ->where('flow', 'topology-probe')
            ->value('guard_class'))
        ->toBe('App\\Domains\\Operation\\Quality\\Workflow\\TopologyGuard')
        ->and(DB::table('base_workflow_status_transitions')
            ->where('flow', 'topology-probe')
            ->value('action_class'))
        ->toBe('App\\Core\\User\\Workflow\\TopologyAction')
        ->and(DB::table('base_audit_mutations')
            ->where('auditable_id', (string) $user->id)
            ->value('auditable_type'))
        ->toBe('App\\Core\\User\\Models\\User');

    $outbox = json_decode(
        DB::table('base_workflow_transition_outbox')
            ->where('event_key', 'topology-probe')
            ->value('payload'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($outbox)->toMatchArray([
        'model_class' => 'App\\Extensions\\Kiat\\Investment\\Models\\CaseCompany',
        'module_path' => 'app/Domains/People/Payroll',
    ]);

    $normalizedQueuePayload = json_decode(
        DB::table('jobs')->where('id', $jobId)->value('payload'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($normalizedQueuePayload['displayName'])
        ->toBe('App\\Core\\User\\Jobs\\TopologyProbe')
        ->and($normalizedQueuePayload['data']['commandName'])
        ->toBe('App\\Core\\User\\Jobs\\TopologyProbe')
        ->and($normalizedQueuePayload['data']['command'])
        ->toContain('App\\Core\\User\\Jobs\\TopologyProbe')
        ->toContain('app/Extensions/SbGroup/Ibp/Database/Seeders/TopologyProbeSeeder.php')
        ->and(@unserialize(
            $normalizedQueuePayload['data']['command'],
            ['allowed_classes' => false],
        ))->not->toBeFalse();

    expect(DB::table('kiat_investment_agent_tasks')->where('id', $agentTaskId)->value('prompt'))
        ->toBe(
            'Follow app/Extensions/Kiat/Investment/AGENTS.md, run Investment/Config/radar.php, '
            .'then use app/Extensions/Kiat/.agents/skills/radar-triage/SKILL.md.',
        )
        ->and(DB::table('kiat_investment_globalwits_source_updates')
            ->where('id', $rawReferenceId)
            ->value('raw_reference'))
        ->toBe('app/Extensions/Kiat/Investment/Database/Seeders/topology-probe.csv');

    $landing = json_decode(
        DB::table('base_settings')
            ->where('key', 'ui.landing_menu_id')
            ->where('scope_id', 900001)
            ->value('value'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $deploymentRun = json_decode(
        DB::table('base_settings')
            ->where('key', 'system.update.deployment.last_run')
            ->where('scope_id', 900002)
            ->value('value'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($landing)->toBe('admin.system.software.domains')
        ->and($deploymentRun['target_keys'])->toBe([
            'domain-people',
            'module-commerce-marketplace',
            'extension-sb-group',
            'extension-kiat',
        ]);

    $pin = DB::table('user_pins')->where('id', $pinId)->first();
    expect($pin->label)->toBe('Domains')
        ->and($pin->url)->toBe('/admin/system/software/domains?b=2&a=1#overview')
        ->and($pin->url_hash)->toBe(md5('/admin/system/software/domains?a=1&b=2'))
        ->and(DB::table('base_authz_role_capabilities')
            ->where('role_id', $roleId)
            ->value('capability_key'))
        ->toBe('admin.system.software.domains.view')
        ->and(DB::table('base_authz_principal_capabilities')
            ->where('principal_id', $user->id)
            ->where('company_id', $user->company_id)
            ->value('capability_key'))
        ->toBe('admin.system.software.domains.manage')
        ->and(DB::table('base_workflow_status_transitions')
            ->where('flow', 'topology-probe')
            ->value('capability'))
        ->toBe('admin.system.software.domains.manage');
});

it('is idempotent and deliberately does not reverse canonical provenance', function (): void {
    $hash = str_repeat('b', 64);
    $now = now();

    DB::table('base_database_tables')->insert([
        'table_name' => 'topology_idempotency_probe',
        'module_name' => 'TopologyProbe',
        'module_path' => 'app/Modules/Commerce/Catalog',
        'migration_file' => 'app/Modules/Commerce/Catalog/Database/Migrations/probe.php',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('base_database_migration_sources')->insert([
        'migration_name' => 'topology_idempotency_probe',
        'migration_file' => 'app/Modules/Commerce/Catalog/Database/Migrations/probe.php',
        'relative_path' => 'app/Modules/Commerce/Catalog/Database/Migrations/probe.php',
        'source_sha256' => $hash,
        'source_state' => 'stable',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration = fourRootApplicationTopologyMigration();
    $migration->up();

    $first = [
        'registry' => DB::table('base_database_tables')
            ->where('table_name', 'topology_idempotency_probe')
            ->first(['module_path', 'migration_file']),
        'source' => DB::table('base_database_migration_sources')
            ->where('migration_name', 'topology_idempotency_probe')
            ->first(['migration_file', 'relative_path', 'source_sha256']),
    ];

    $migration->up();
    $migration->down();

    $second = [
        'registry' => DB::table('base_database_tables')
            ->where('table_name', 'topology_idempotency_probe')
            ->first(['module_path', 'migration_file']),
        'source' => DB::table('base_database_migration_sources')
            ->where('migration_name', 'topology_idempotency_probe')
            ->first(['migration_file', 'relative_path', 'source_sha256']),
    ];

    expect($second)->toEqual($first)
        ->and($second['registry']->module_path)->toBe('app/Domains/Commerce/Catalog')
        ->and($second['source']->relative_path)
        ->toBe('app/Domains/Commerce/Catalog/Database/Migrations/probe.php')
        ->and($second['source']->source_sha256)->toBe($hash);
});

it('keeps canonical Extension classes idempotent and repairs double-prefixed residue', function (): void {
    $now = now();
    $canonicalClass = 'App\\Extensions\\Ham\\AutoParts\\Database\\Seeders\\TopologyProbeSeeder';
    $malformedClass = 'App\\App\\Extensions\\SbGroup\\Ibp\\Database\\Seeders\\TopologyProbeSeeder';
    $repairedClass = 'App\\Extensions\\SbGroup\\Ibp\\Database\\Seeders\\TopologyProbeSeeder';

    foreach ([$canonicalClass, $malformedClass] as $seederClass) {
        DB::table('base_database_seeders')->insert([
            'seeder_class' => $seederClass,
            'module_name' => 'TopologyProbe',
            'module_path' => 'app/Extensions/TopologyProbe',
            'migration_file' => 'topology_probe.php',
            'status' => 'pending',
            'ran_at' => null,
            'error_message' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $migration = fourRootApplicationTopologyMigration();
    $migration->up();
    $migration->up();

    expect(DB::table('base_database_seeders')->where('seeder_class', $canonicalClass)->count())->toBe(1)
        ->and(DB::table('base_database_seeders')->where('seeder_class', $repairedClass)->count())->toBe(1)
        ->and(DB::table('base_database_seeders')->where('seeder_class', $malformedClass)->exists())->toBeFalse();
});

it('preserves JSON shapes and leaves opaque payloads byte-for-byte unchanged', function (): void {
    $numericObjectPayload = json_encode((object) [
        '0' => 'app/Modules/People/Payroll',
        '1' => (object) [
            'class' => 'App\\Modules\\Core\\User\\Models\\User',
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $opaquePayload = <<<'JSON'
{
  "0": "opaque\/path",
  "1": {"nested": "untouched"}
}
JSON;
    $now = now()->getTimestamp();

    $numericObjectJobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => $numericObjectPayload,
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $now,
        'created_at' => $now,
    ]);
    $opaqueJobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => $opaquePayload,
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $now,
        'created_at' => $now,
    ]);

    fourRootApplicationTopologyMigration()->up();

    $normalizedPayload = DB::table('jobs')->where('id', $numericObjectJobId)->value('payload');
    $normalized = json_decode($normalizedPayload, false, flags: JSON_THROW_ON_ERROR);

    expect($normalizedPayload)->toStartWith('{')
        ->and($normalized)->toBeInstanceOf(stdClass::class)
        ->and($normalized->{'0'})->toBe('app/Domains/People/Payroll')
        ->and($normalized->{'1'}->class)->toBe('App\\Core\\User\\Models\\User')
        ->and(DB::table('jobs')->where('id', $opaqueJobId)->value('payload'))
        ->toBe($opaquePayload);
});
