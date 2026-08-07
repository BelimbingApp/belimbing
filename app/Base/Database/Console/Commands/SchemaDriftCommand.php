<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Contracts\SchemaDriftInspection;
use App\Base\Database\Exceptions\SchemaDriftInspectionException;
use App\Base\Database\Services\SchemaDrift\SchemaDriftReport;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:schema:drift')]
final class SchemaDriftCommand extends Command
{
    protected $signature = 'blb:schema:drift {--database= : The database connection to inspect}';

    protected $description = 'Compare migration-declared tables, columns, and indexes with a database';

    public function handle(SchemaDriftInspection $inspector): int
    {
        try {
            $database = $this->option('database');
            $report = $inspector->inspect(is_string($database) && $database !== '' ? $database : null);
        } catch (SchemaDriftInspectionException $exception) {
            $this->line('ERROR reason='.$this->quoted($exception->getMessage()));
            $this->line('RESULT INCOMPLETE');

            return 2;
        }

        $this->writeReport($report);

        if ($report->unreadable !== []) {
            $this->line('RESULT INCOMPLETE');

            return 2;
        }

        if ($report->findings !== []) {
            $this->line('RESULT DRIFT');

            return self::FAILURE;
        }

        $this->line('RESULT CLEAN');

        return self::SUCCESS;
    }

    private function writeReport(SchemaDriftReport $report): void
    {
        $this->line(sprintf(
            'SCHEMA_DRIFT connection=%s driver=%s database=%s scope=%s',
            $this->quoted($report->connection),
            $this->quoted($report->driver),
            $this->quoted($report->database),
            $this->quoted('tables,columns,indexes'),
        ));

        foreach ($report->findings as $finding) {
            $this->line(sprintf(
                'DRIFT kind=%s table=%s object=%s source=%s',
                $finding->kind->value,
                $this->quoted($finding->table),
                $this->quoted($finding->object),
                $this->quoted($finding->migration.':'.$finding->line),
            ));
        }

        foreach ($report->unreadable as $unreadable) {
            $this->line(sprintf(
                'UNREADABLE source=%s reason=%s',
                $this->quoted($unreadable['migration'].':'.$unreadable['line']),
                $this->quoted($unreadable['reason']),
            ));
        }

        $this->line(sprintf(
            'SUMMARY migrations=%d tables=%d findings=%d unreadable=%d',
            $report->migrationCount,
            $report->tableCount,
            count($report->findings),
            count($report->unreadable),
        ));
    }

    private function quoted(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
