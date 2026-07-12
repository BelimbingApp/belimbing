<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Exceptions\DataShareImportException;
use App\Base\Database\Services\DataShare\Concerns\AppliesDiagnosticPackages;
use App\Base\Database\Services\DataShare\Concerns\ValidatesDiagnosticPackages;
use App\Base\Database\Services\DevelopmentInstanceGuard;
use App\Base\Database\Services\TableInspector;
use Illuminate\Support\Facades\DB;

/**
 * Validates and applies byte-exact diagnostic packages on development only.
 *
 * Domain models and events are bypassed deliberately: this is a diagnostic
 * state reproduction tool, not a domain import. Every write is bounded,
 * schema-checked, primary-key addressed, parent-first, and transactional.
 */
class DiagnosticPackageImporter
{
    use AppliesDiagnosticPackages;
    use ValidatesDiagnosticPackages;

    public function __construct(
        private readonly DiagnosticPackageInbox $inbox,
        private readonly TableInspector $inspector,
        private readonly DevelopmentInstanceGuard $environment,
        private readonly DataShareSettings $settings,
    ) {}

    protected function diagnosticTableInspector(): TableInspector
    {
        return $this->inspector;
    }

    protected function diagnosticSettings(): DataShareSettings
    {
        return $this->settings;
    }

    /**
     * @return array{package_id: string, package_sha256: string, size_bytes: int, tables: list<array{table: string, rows: int, inserts: int, updates: int}>, total_rows: int}
     */
    public function inspect(string $packageId): array
    {
        $this->environment->assertDevelopment(__('Diagnostic package import'));

        $incoming = $this->inbox->open($packageId);
        $tables = $this->validatedTables($incoming['contents'], $packageId);
        $summary = [];

        foreach ($tables as $entry) {
            $inserts = 0;
            $updates = 0;

            foreach ($entry['rows'] as $row) {
                $this->rowExists($entry['table'], $entry['primary_keys'], $row)
                    ? $updates++
                    : $inserts++;
            }

            $summary[] = [
                'table' => $entry['table'],
                'rows' => count($entry['rows']),
                'inserts' => $inserts,
                'updates' => $updates,
            ];
        }

        return [
            'package_id' => $packageId,
            'package_sha256' => $incoming['receipt']['package_sha256'],
            'size_bytes' => $incoming['receipt']['size_bytes'],
            'tables' => $summary,
            'total_rows' => array_sum(array_column($summary, 'rows')),
        ];
    }

    /**
     * @return array{package_id: string, package_sha256: string, inserted: int, updated: int, tables: list<array{table: string, inserted: int, updated: int}>}
     */
    public function apply(string $packageId, string $expectedPackageSha256): array
    {
        $this->environment->assertDevelopment(__('Diagnostic package import'));

        $incoming = $this->inbox->open($packageId);

        if (! hash_equals($expectedPackageSha256, $incoming['receipt']['package_sha256'])) {
            throw DataShareImportException::previewChanged();
        }

        $tables = $this->validatedTables($incoming['contents'], $packageId);

        $result = DB::transaction(fn (): array => $this->applyTables($tables));

        return [
            'package_id' => $packageId,
            'package_sha256' => $incoming['receipt']['package_sha256'],
            ...$result,
        ];
    }
}
