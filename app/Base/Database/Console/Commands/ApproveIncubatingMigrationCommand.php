<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Services\IncubatingSchemaApprovalRepository;
use App\Base\Database\Services\IncubatingSchemaProductionPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Creates an instance-local, exact-hash approval for one pending incubating
 * migration on a non-disposable database.
 */
#[AsCommand(name: 'blb:schema:approve-incubating')]
final class ApproveIncubatingMigrationCommand extends Command
{
    protected $signature = 'blb:schema:approve-incubating
                            {migration : Migration name, filename, or relative path}
                            {--database= : Database connection name the approval applies to}
                            {--backup= : Backup ID or operator-verified backup reference}
                            {--reason= : Why this production incubating migration is necessary}
                            {--expires=24 : Approval lifetime in hours}
                            {--replace : Replace existing approvals for the same migration/path/hash}';

    protected $description = 'Create a local break-glass approval for one pending incubating migration';

    public function handle(
        IncubatingSchemaProductionPolicy $policy,
        IncubatingSchemaApprovalRepository $approvals,
    ): int {
        $backup = trim((string) $this->option('backup'));
        $reason = trim((string) $this->option('reason'));
        $connectionName = $this->selectedDatabaseConnection();

        if ($backup === '') {
            $this->components->error('Refusing approval without --backup. Create or verify a DB backup first, then pass its ID/reference.');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->components->error('Refusing approval without --reason. State why this production incubating migration is necessary.');

            return self::FAILURE;
        }

        $finding = $this->findMigration($policy, (string) $this->argument('migration'));

        if ($finding === null) {
            $this->components->error('No source-declared incubating migration matched the given name/path.');

            return self::FAILURE;
        }

        if ($this->migrationHasRun($finding['migration_name'], $connectionName)) {
            $this->components->warn('This incubating migration is already applied on this database; a pending-migration approval is not needed.');
            $this->components->twoColumnDetail('Migration', $finding['migration_name']);
            $this->components->twoColumnDetail('Path', $finding['relative_path']);

            return self::SUCCESS;
        }

        $approval = $approvals->add(
            $finding,
            $backup,
            $reason,
            (int) $this->option('expires'),
            (bool) $this->option('replace'),
            $connectionName,
        );

        $context = $approvals->currentDatabaseContext($connectionName);

        $this->components->info('Incubating migration approved locally.');
        $this->components->twoColumnDetail('Migration', $approval['migration']);
        $this->components->twoColumnDetail('Path', $approval['path']);
        $this->components->twoColumnDetail('SHA-256', $approval['sha256']);
        $this->components->twoColumnDetail('Connection', $context['connection']);
        $this->components->twoColumnDetail('Driver', $context['driver']);
        $this->components->twoColumnDetail('Database', $context['database']);
        $this->components->twoColumnDetail('Backup', $approval['backup']);
        $this->components->twoColumnDetail('Expires', $approval['expires_at']);
        $this->components->twoColumnDetail('Approval file', $approvals->path());

        return self::SUCCESS;
    }

    /**
     * @return array{path: string, relative_path: string, file: string, migration_name: string, tables: list<string>, sha256: string}|null
     */
    private function findMigration(IncubatingSchemaProductionPolicy $policy, string $needle): ?array
    {
        $normalizedNeedle = trim(str_replace('\\', '/', $needle));
        $nameNeedle = preg_replace('/\.php$/', '', basename($normalizedNeedle));

        foreach ($policy->incubatingFindings($this->migrationPaths()) as $finding) {
            if ($normalizedNeedle === $finding['relative_path']
                || $normalizedNeedle === $finding['file']
                || $nameNeedle === $finding['migration_name']) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function migrationPaths(): array
    {
        return array_values(array_filter(array_merge(
            glob(app_path('Base/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
            glob(app_path('Modules/*/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
            glob(base_path('extensions/*/*/Database/Migrations'), GLOB_ONLYDIR) ?: [],
            [database_path('migrations')],
        ), 'is_dir'));
    }

    private function selectedDatabaseConnection(): ?string
    {
        $connection = $this->option('database');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function migrationHasRun(string $migrationName, ?string $connectionName): bool
    {
        return Schema::connection($connectionName)->hasTable('migrations')
            && DB::connection($connectionName)->table('migrations')->where('migration', $migrationName)->exists();
    }
}
