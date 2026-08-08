<?php

namespace App\Base\Database\Services\SchemaDrift;

use App\Base\Database\Contracts\SchemaDriftInspection;
use App\Base\Database\Exceptions\SchemaDriftInspectionException;
use App\Base\Database\Services\ModuleMigrationDependencyChecker;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class SchemaDriftInspector implements SchemaDriftInspection
{
    public function __construct(
        private ModuleMigrationDependencyChecker $dependencies,
        private MigrationSourceParser $parser,
        private SchemaDriftComparator $comparator,
        private DatabaseManager $database,
    ) {}

    public function inspect(?string $connectionName = null): SchemaDriftReport
    {
        try {
            $this->dependencies->assertReadyForMigration();
            $files = $this->migrationFiles();
            $migrations = array_map($this->parser->parse(...), $files);
            $declared = DeclaredSchema::fromMigrations($migrations);
            $connection = $this->database->connection($connectionName);

            $unreadable = $declared->unreadable;
            usort($unreadable, fn (array $left, array $right): int => [
                $left['migration'],
                $left['line'],
                $left['reason'],
            ] <=> [
                $right['migration'],
                $right['line'],
                $right['reason'],
            ]);

            return new SchemaDriftReport(
                (string) ($connection->getName() ?? config('database.default', 'default')),
                $connection->getDriverName(),
                (string) $connection->getDatabaseName(),
                count($migrations),
                count($declared->tables),
                $this->comparator->compare($declared, $connection->getSchemaBuilder()),
                $unreadable,
            );
        } catch (SchemaDriftInspectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SchemaDriftInspectionException(
                'Schema drift inspection could not complete: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Reproduce Laravel's global filename ordering while refusing the duplicate
     * migration names Laravel would otherwise silently collapse.
     *
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $paths = [...$this->dependencies->migrationPaths(), database_path('migrations')];
        $filesByName = [];

        foreach (array_values(array_unique(array_filter($paths, 'is_dir'))) as $path) {
            foreach (glob($path.'/*_*.php') ?: [] as $file) {
                $filesByName[basename($file, '.php')][] = $file;
            }
        }

        foreach ($filesByName as $name => $files) {
            if (count($files) > 1) {
                throw new SchemaDriftInspectionException(sprintf(
                    'Duplicate migration name [%s] prevents deterministic source replay: %s',
                    $name,
                    implode(', ', $files),
                ));
            }
        }

        ksort($filesByName);

        return array_values(array_map(
            fn (array $files): string => $files[0],
            $filesByName,
        ));
    }
}
