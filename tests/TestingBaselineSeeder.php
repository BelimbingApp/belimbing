<?php

namespace Tests;

use App\Base\Database\Models\SeederRegistry;
use Illuminate\Database\Seeder;

/**
 * Baseline seed data for automated tests.
 *
 * Runs production seeders only for modules listed in tests/Support/testing-seed-modules.php.
 * Registry is populated by migrations before this seeder runs.
 */
class TestingBaselineSeeder extends Seeder
{
    /**
     * Seed by running registry seeders for modules that opted in to testing.
     */
    public function run(): void
    {
        $modules = $this->testingSeedModules();

        if ($modules === []) {
            return;
        }

        $seedersToRun = SeederRegistry::query()
            ->runnable()
            ->whereIn('module_name', $modules)
            ->inMigrationOrder()
            ->get();

        foreach ($seedersToRun as $seeder) {
            $seeder->markAsRunning();

            try {
                $this->call($seeder->seeder_class);
                $seeder->markAsCompleted();
            } catch (\Throwable $e) {
                $seeder->markAsFailed($e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Load module names from tests/Support/testing-seed-modules.php.
     *
     * @return array<int, string>
     */
    private function testingSeedModules(): array
    {
        $path = base_path('tests/Support/testing-seed-modules.php');

        if (! file_exists($path)) {
            return [];
        }

        $modules = require $path;

        return is_array($modules) ? array_values($modules) : [];
    }
}
