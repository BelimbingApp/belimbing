<?php

namespace App\Base\Foundation\Services;

use App\Base\Settings\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detects database residue left behind by uninstalled modules.
 *
 * Code presence is the source of truth: anything in the database that no
 * currently-discovered migration or settings declaration claims is residue.
 * Uninstalling a domain repo (removing its checkout) orphans its tables,
 * its migration-ledger rows, and its settings rows; this scanner finds
 * them and executes the cleanup the user chooses. Detection never relies
 * on table-name prefixes — modules don't name tables uniformly.
 *
 * All mutation methods re-validate against a fresh scan so a claimed
 * table, ledger row, or setting can never be removed even if requested.
 */
class DomainResidueScanner
{
    /**
     * Tables owned by the framework runtime rather than module migrations.
     */
    private const RUNTIME_TABLES = ['migrations', 'sqlite_sequence'];

    /**
     * Canonical migration discovery globs (mirrors Base/Database services).
     *
     * @return list<string>
     */
    private function migrationPathPatterns(): array
    {
        return [
            app_path('Base/*/Database/Migrations/*.php'),
            app_path('Modules/*/*/Database/Migrations/*.php'),
            database_path('migrations/*.php'),
            base_path('extensions/*/*/Database/Migrations/*.php'),
        ];
    }

    /**
     * Full residue report.
     *
     * @return array{
     *     orphanTables: list<array{table: string, rows: int}>,
     *     orphanLedger: list<string>,
     *     orphanSettings: list<array{key: string, rows: int}>,
     * }
     */
    public function scan(): array
    {
        return [
            'orphanTables' => $this->orphanTables(),
            'orphanLedger' => $this->orphanLedger(),
            'orphanSettings' => $this->orphanSettings(),
        ];
    }

    /**
     * Drop the requested tables, limited to currently-orphaned ones.
     *
     * @param  list<string>  $tables
     * @return array{dropped: list<string>, skipped: list<string>}
     */
    public function dropTables(array $tables): array
    {
        $orphans = array_column($this->orphanTables(), 'table');
        $dropped = [];
        $skipped = [];

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                if (! in_array($table, $orphans, true)) {
                    $skipped[] = $table;

                    continue;
                }

                Schema::dropIfExists($table);
                $dropped[] = $table;
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return ['dropped' => $dropped, 'skipped' => $skipped];
    }

    /**
     * Delete migration-ledger rows, limited to currently-orphaned ones.
     *
     * @param  list<string>  $migrations
     * @return int Number of rows deleted
     */
    public function pruneLedger(array $migrations): int
    {
        $allowed = array_values(array_intersect($migrations, $this->orphanLedger()));

        if ($allowed === []) {
            return 0;
        }

        return DB::table('migrations')->whereIn('migration', $allowed)->delete();
    }

    /**
     * Delete settings rows (all scopes), limited to currently-orphaned keys.
     *
     * @param  list<string>  $keys
     * @return int Number of rows deleted
     */
    public function deleteSettings(array $keys): int
    {
        $allowed = array_values(array_intersect($keys, array_column($this->orphanSettings(), 'key')));

        if ($allowed === []) {
            return 0;
        }

        return Setting::query()->whereIn('key', $allowed)->delete();
    }

    /**
     * Database tables no discovered migration claims.
     *
     * @return list<array{table: string, rows: int}>
     */
    private function orphanTables(): array
    {
        $claimed = $this->claimedTables();
        $orphans = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, $claimed, true) || in_array($table, self::RUNTIME_TABLES, true)) {
                continue;
            }

            $orphans[] = [
                'table' => $table,
                'rows' => (int) DB::table($table)->count(),
            ];
        }

        return $orphans;
    }

    /**
     * Migration-ledger entries whose file no longer exists in any
     * discovered migration path.
     *
     * @return list<string>
     */
    private function orphanLedger(): array
    {
        $present = [];

        foreach ($this->migrationFiles() as $file) {
            $present[basename($file, '.php')] = true;
        }

        return DB::table('migrations')
            ->orderBy('migration')
            ->pluck('migration')
            ->reject(fn (string $migration): bool => isset($present[$migration]))
            ->values()
            ->all();
    }

    /**
     * Settings rows whose key no discovered Config/settings.php declares.
     *
     * @return list<array{key: string, rows: int}>
     */
    private function orphanSettings(): array
    {
        $declared = $this->declaredSettingKeys();

        return Setting::query()
            ->select('key')
            ->selectRaw('count(*) as row_count')
            ->groupBy('key')
            ->orderBy('key')
            ->get()
            ->reject(fn (Setting $row): bool => isset($declared[$row->key]))
            ->map(fn (Setting $row): array => [
                'key' => $row->key,
                'rows' => (int) $row->getAttribute('row_count'),
            ])
            ->values()
            ->all();
    }

    /**
     * Table names created by currently-discovered migration files.
     *
     * @return list<string>
     */
    private function claimedTables(): array
    {
        return self::tablesCreatedIn($this->migrationFiles());
    }

    /**
     * Table names the given migration files create.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function tablesCreatedIn(array $files): array
    {
        $tables = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match_all("/Schema::create\\(\\s*['\"]([A-Za-z0-9_]+)['\"]/", $contents, $matches)) {
                foreach ($matches[1] as $table) {
                    $tables[] = $table;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Setting keys the given Config/settings.php files declare.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function settingKeysDeclaredIn(array $files): array
    {
        $keys = [];

        foreach ($files as $file) {
            $config = require $file;

            if (! is_array($config)) {
                continue;
            }

            foreach ((array) ($config['editable'] ?? []) as $group) {
                foreach ((array) ($group['fields'] ?? []) as $field) {
                    if (isset($field['key']) && is_string($field['key'])) {
                        $keys[] = $field['key'];
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = [];

        foreach ($this->migrationPathPatterns() as $pattern) {
            $files = array_merge($files, glob($pattern) ?: []);
        }

        return $files;
    }

    /**
     * Declared settings keys from every Config/settings.php on disk,
     * keyed for O(1) lookup.
     *
     * Reads the files directly rather than the merged settings config:
     * the merge excludes disabled domains, but a disabled domain still
     * claims its settings — only deleting the code orphans them.
     *
     * @return array<string, true>
     */
    private function declaredSettingKeys(): array
    {
        $files = array_merge(
            glob(app_path('Base/*/Config/settings.php')) ?: [],
            glob(app_path('Modules/*/*/Config/settings.php')) ?: [],
            glob(base_path('extensions/*/*/Config/settings.php')) ?: [],
        );

        return array_fill_keys(self::settingKeysDeclaredIn($files), true);
    }
}
