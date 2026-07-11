<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Exceptions\DataShareImportException;
use App\Base\Database\Exceptions\DevelopmentInstanceRequiredException;
use App\Base\Database\Services\DataShare\DiagnosticPackageImporter;
use App\Base\Database\Services\DataShare\DiagnosticPackageInbox;
use Illuminate\Console\Command;

class ImportDiagnosticDataSharePackageCommand extends Command
{
    protected $signature = 'blb:db:share:import-diagnostic
        {path : Relative path of a captured diagnostic package on the private Data Share disk}
        {--commit : Apply the inspected package to this development database}';

    protected $description = 'Receive, inspect, and optionally apply a diagnostic Data Share package on development';

    public function handle(DiagnosticPackageInbox $inbox, DiagnosticPackageImporter $importer): int
    {
        try {
            $receipt = $inbox->receiveLocal((string) $this->argument('path'));
            $inspection = $importer->inspect($receipt['package_id']);
        } catch (DataShareImportException|DevelopmentInstanceRequiredException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__('Received :id into protected Incoming storage.', ['id' => $inspection['package_id']]));
        $this->line(__('Destination SHA-256: :hash', ['hash' => $inspection['package_sha256']]));
        $this->table(
            [__('Table'), __('Rows'), __('Insert'), __('Update')],
            array_map(fn (array $table): array => [
                $table['table'],
                $table['rows'],
                $table['inserts'],
                $table['updates'],
            ], $inspection['tables']),
        );

        if (! $this->option('commit')) {
            $this->components->warn(__('Inspection only. No domain rows changed; rerun with --commit to apply this exact hash.'));

            return self::SUCCESS;
        }

        try {
            $result = $importer->apply($inspection['package_id'], $inspection['package_sha256']);
        } catch (DataShareImportException|DevelopmentInstanceRequiredException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__('Diagnostic package applied: :inserted inserted, :updated updated.', [
            'inserted' => $result['inserted'],
            'updated' => $result['updated'],
        ]));

        return self::SUCCESS;
    }
}
