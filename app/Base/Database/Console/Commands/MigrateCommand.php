<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Concerns\InteractsWithModuleMigrations;
use App\Base\Database\Exceptions\CircularSeederDependencyException;
use App\Base\Database\Models\SeederRegistry;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Console\Migrations\MigrateCommand as IlluminateMigrateCommand;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'migrate')]
class MigrateCommand extends IlluminateMigrateCommand
{
    use InteractsWithModuleMigrations;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the database migrations (with module support)';

    /**
     * Configure the command options by adding --dev to the parent definition.
     *
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        parent::configure();

        $this->getDefinition()->addOption(
            new InputOption(
                'dev',
                null,
                InputOption::VALUE_NONE,
                'Run dev seeders after production seeders (APP_ENV=local only). Implies --seed.',
            ),
        );

        $this->getDefinition()->addOption(
            new InputOption(
                'unstable',
                null,
                InputOption::VALUE_NONE,
                'Register newly discovered tables as unstable (is_stable=false) so migrate:fresh will rebuild them.',
            ),
        );
    }

    /**
     * Execute the console command.
     *
     * Loads all module migrations before running.
     */
    public function handle(): int
    {
        // --dev implies --seed (dev seeders need production seeders to have run first)
        if ($this->option('dev') && ! $this->option('seed')) {
            $this->input->setOption('seed', true);
        }

        $this->loadAllModuleMigrations();

        return parent::handle();
    }

    /**
     * Run the pending migrations.
     *
     * Overrides parent to handle module-aware seeding and framework primitives.
     *
     * Ordering: migrations → production seeders → framework primitives → dev seeders.
     * Framework primitives (Licensee, Lara) run after production seeders so that
     * any seeder-created dependencies exist, and before dev seeders so that dev
     * data can reference them.
     */
    protected function runMigrations(): void
    {
        $this->migrator->usingConnection(
            $this->option('database'),
            function () {
                $this->prepareDatabase();

                // Next, we will check to see if a path option has been defined. If it has
                // we will use the path relative to the root of this installation folder
                // so that migrations may be run for any path within the applications.
                $this->migrator
                    ->setOutput($this->output)
                    ->run($this->getMigrationPaths(), [
                        'pretend' => $this->option('pretend'),
                        'step' => $this->option('step'),
                    ]);

                if ($this->option('pretend')) {
                    return;
                }

                $existingRegistry = [];
                if ($this->option('unstable') && Schema::hasTable('base_database_tables')) {
                    $existingRegistry = TableRegistry::query()->pluck('table_name')->all();
                }

                // Auto-discover and register tables from migration files
                TableRegistry::ensureDiscoveredRegistered();

                if ($this->option('unstable') && Schema::hasTable('base_database_tables')) {
                    $newTables = array_values(array_diff(
                        TableRegistry::query()->pluck('table_name')->all(),
                        $existingRegistry,
                    ));

                    $newTables = array_values(array_diff($newTables, TableRegistry::INFRASTRUCTURE_TABLES));

                    if ($newTables !== []) {
                        TableRegistry::query()
                            ->whereIn('table_name', $newTables)
                            ->update([
                                'is_stable' => false,
                                'stabilized_at' => null,
                                'stabilized_by' => null,
                            ]);
                    }
                }

                // Handle seeding with module-aware auto-discovery
                if ($this->option('seed')) {
                    $this->runModuleSeeders();
                }

                // Ensure framework primitives exist (Licensee company, Lara agent).
                // Runs after production seeders, before dev seeders, in all environments.
                $this->ensureFrameworkPrimitives();

                // Handle dev seeders (--dev flag)
                if ($this->option('dev')) {
                    $this->runDevSeeders();
                }
            },
        );
    }

    /**
     * Run seeders with registry-based execution.
     *
     * If --seeder is provided, uses that seeder (overrides registry).
     * Otherwise, runs all pending seeders from registry in migration order.
     */
    protected function runModuleSeeders(): void
    {
        // If --seeder is explicitly provided, use it (overrides registry)
        if ($this->option('seeder')) {
            $class = $this->normalizeSeederClass($this->option('seeder'));
            $this->call('db:seed', [
                '--class' => $class,
                '--force' => true,
            ]);

            return;
        }

        SeederRegistry::ensureDiscoveredRegistered();

        // Query registry for runnable seeders (pending or failed)
        // Order by migration_file to ensure correct execution order
        $seedersToRun = SeederRegistry::runnable()
            ->inMigrationOrder()
            ->get();

        // Run each seeder with status tracking
        foreach ($seedersToRun as $seeder) {
            // Mark as running, clear previous error if retrying
            $seeder->markAsRunning();

            try {
                $this->call('db:seed', [
                    '--class' => $seeder->seeder_class,
                    '--force' => true,
                ]);

                // Mark as completed
                $seeder->markAsCompleted();
            } catch (\Exception $e) {
                // Mark as failed
                $seeder->markAsFailed($e->getMessage());

                // Re-throw to stop execution
                throw $e;
            }
        }
    }

    /**
     * Run dev seeders for local development.
     *
     * Only allowed when APP_ENV=local — DevSeeder base class enforces
     * this guard, but we also check here for a clear early error.
     * Framework primitives (Licensee, Lara) are already ensured by
     * ensureFrameworkPrimitives() before this method is called.
     */
    protected function runDevSeeders(): void
    {
        if (! app()->environment('local')) {
            $this->error('--dev may only be used when APP_ENV=local. Current: '.app()->environment());

            return;
        }

        $this->info('Running dev seeders…');

        $seeders = $this->discoverDevSeeders();

        foreach ($seeders as $class) {
            $this->line("  → {$class}");
            $this->call('db:seed', [
                '--class' => $class,
                '--force' => true,
            ]);
        }

        $this->newLine();
        $this->info('✓ Dev seeders complete.');
        $admin = User::query()->where('company_id', Company::LICENSEE_ID)->first();
        if ($admin) {
            $this->line("  Admin: {$admin->email}");
        }
    }

    /**
     * Ensure framework primitives exist: Licensee company (id=1), admin user, and Lara (employee id=1).
     *
     * All three are idempotent — safe to call on every migrate. Ordering matters:
     * Licensee must exist before admin user (user belongs to licensee company),
     * and Lara runs last (Lara belongs to the licensee company).
     *
     * Values are read from env vars (passed transiently by the setup script).
     * Defaults allow day-to-day `migrate:fresh --seed --dev` to work without
     * env vars since `is_stable` preserves the users table across fresh runs.
     */
    private function ensureFrameworkPrimitives(): void
    {
        $companyName = env('LICENSEE_COMPANY_NAME', 'My Company');

        if (Company::provisionLicensee($companyName)) {
            $this->line("  Created licensee company: {$companyName}");
        }

        $this->ensureAdminUser();

        if (Employee::provisionLara()) {
            $this->line('  Created Lara (system Agent)');
        }
    }

    /**
     * Ensure the initial admin user exists in the licensee company.
     *
     * Reads ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASSWORD from env vars.
     * On first setup these are provided transiently by 60-migrations.sh.
     * On subsequent migrate:fresh runs the users table is stable (is_stable=true)
     * so the row survives and this is a no-op.
     */
    private function ensureAdminUser(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $name = env('ADMIN_NAME', 'Administrator');
        $password = env('ADMIN_PASSWORD', 'password');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'company_id' => Company::LICENSEE_ID,
                'name' => $name,
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );

        if ($user->wasRecentlyCreated) {
            $this->line("  Created admin user: {$email}");
        }
    }

    /**
     * Auto-discover dev seeders and return them in dependency order.
     *
     * Scans Dev/ seeder directories, finds classes extending DevSeeder,
     * then topologically sorts them by their declared $dependencies.
     *
     * @return array<int, class-string<DevSeeder>>
     *
     * @throws \App\Base\Database\Exceptions\CircularSeederDependencyException If a circular dependency is detected.
     */
    private function discoverDevSeeders(): array
    {
        $classes = [];
        $patterns = [
            app_path('Modules/*/*/Database/Seeders/Dev/*.php'),
            app_path('Base/*/Database/Seeders/Dev/*.php'),
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) as $file) {
                $class = $this->classFromPath($file);

                if (class_exists($class) && is_subclass_of($class, DevSeeder::class)) {
                    $classes[] = $class;
                }
            }
        }

        return $this->topologicalSort($classes);
    }

    /**
     * Topologically sort dev seeder classes by their declared dependencies.
     *
     * Uses Kahn's algorithm (BFS). Seeders with no dependencies run first;
     * seeders whose dependencies have all been scheduled run next.
     *
     * @param  array<int, class-string<DevSeeder>>  $classes
     * @return array<int, class-string<DevSeeder>>
     *
     * @throws \App\Base\Database\Exceptions\CircularSeederDependencyException If a circular dependency is detected.
     */
    private function topologicalSort(array $classes): array
    {
        $graph = [];
        $inDegree = [];

        foreach ($classes as $class) {
            $graph[$class] ??= [];
            $inDegree[$class] ??= 0;
        }

        // Build edges: dependency → dependent
        foreach ($classes as $class) {
            $deps = (new \ReflectionClass($class))
                ->getDefaultProperties()['dependencies'] ?? [];

            foreach ($deps as $dep) {
                if (! isset($graph[$dep])) {
                    continue; // dependency not in discovered set, skip
                }

                $graph[$dep][] = $class;
                $inDegree[$class]++;
            }
        }

        // Start with seeders that have no dependencies
        $queue = array_keys(array_filter($inDegree, fn (int $deg) => $deg === 0));
        sort($queue); // deterministic order for zero-dep seeders

        $sorted = [];

        while ($queue) {
            $current = array_shift($queue);
            $sorted[] = $current;

            foreach ($graph[$current] as $dependent) {
                $inDegree[$dependent]--;

                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                    sort($queue);
                }
            }
        }

        if (count($sorted) !== count($classes)) {
            $stuck = array_diff($classes, $sorted);
            throw CircularSeederDependencyException::forClasses(array_values($stuck));
        }

        return $sorted;
    }

    /**
     * Derive FQCN from a file path under app/.
     *
     * @param  string  $path  Absolute path to a PHP file under app/
     */
    private function classFromPath(string $path): string
    {
        $relative = substr($path, strlen(app_path()) + 1, -4);

        return 'App\\'.str_replace('/', '\\', $relative);
    }

    /**
     * Normalize seeder class so FQCN reaches db:seed (avoids shell stripping backslashes).
     *
     * Supports shorthand formats:
     * - Module/SeederClass → App\Modules\Core\Module\Database\Seeders\SeederClass
     * - Module/Sub/SeederClass → App\Modules\Core\Module\Database\Seeders\Sub\SeederClass
     */
    private function normalizeSeederClass(string $value): string
    {
        if (str_contains($value, '\\')) {
            return $value;
        }

        $parts = explode('/', $value);

        if (count($parts) >= 2) {
            $module = array_shift($parts);
            $remaining = implode('\\', $parts);

            return 'App\\Modules\\Core\\'.$module.'\\Database\\Seeders\\'.$remaining;
        }

        return $value;
    }
}
