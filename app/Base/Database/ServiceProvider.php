<?php

namespace App\Base\Database;

use App\Base\Database\Console\Commands\ApplyDataSharePackageCommand;
use App\Base\Database\Console\Commands\ApproveIncubatingMigrationCommand;
use App\Base\Database\Console\Commands\AttachFreshnessTrackingCommand;
use App\Base\Database\Console\Commands\BackupCommand;
use App\Base\Database\Console\Commands\DataShareHistoryCommand;
use App\Base\Database\Console\Commands\DataShareScopesCommand;
use App\Base\Database\Console\Commands\ExportDataSharePackageCommand;
use App\Base\Database\Console\Commands\FetchDataShareTransferOfferCommand;
use App\Base\Database\Console\Commands\FreshCommand;
use App\Base\Database\Console\Commands\ImportDiagnosticDataSharePackageCommand;
use App\Base\Database\Console\Commands\InspectDataSharePackageCommand;
use App\Base\Database\Console\Commands\MigrateCommand;
use App\Base\Database\Console\Commands\MirrorTablesCommand;
use App\Base\Database\Console\Commands\PlanDataSharePackageCommand;
use App\Base\Database\Console\Commands\PruneDataSharePackagesCommand;
use App\Base\Database\Console\Commands\ReconcileDataOperationsCommand;
use App\Base\Database\Console\Commands\RefreshCommand;
use App\Base\Database\Console\Commands\RekeyCommand;
use App\Base\Database\Console\Commands\ResetCommand;
use App\Base\Database\Console\Commands\RevokeDataShareTransferOfferCommand;
use App\Base\Database\Console\Commands\RollbackCommand;
use App\Base\Database\Console\Commands\SanitizeDevelopmentDatabaseCommand;
use App\Base\Database\Console\Commands\SchemaDriftCommand;
use App\Base\Database\Console\Commands\StageBackupCommand;
use App\Base\Database\Console\Commands\StatusCommand;
use App\Base\Database\Console\Commands\WipeCommand;
use App\Base\Database\Contracts\DataShareMirrorEngine;
use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\Contracts\DataShareMirrorProvider;
use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Contracts\SchemaDriftInspection;
use App\Base\Database\Enums\HydrationGuardMode;
use App\Base\Database\Postgres\GuardedPostgresConnection;
use App\Base\Database\Services\Backup\Encryption\AppKeyEncryption;
use App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry;
use App\Base\Database\Services\Backup\Encryption\NoneEncryption;
use App\Base\Database\Services\DataOperation\LedgerDataOperationRecorder;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorEngineRegistry;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorProviderRegistry;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorTableImageEngine;
use App\Base\Database\Services\DataShare\Mirror\GenericPostgresMirrorProvider;
use App\Base\Database\Services\DataShare\Mirror\PortableDataShareMirrorEngine;
use App\Base\Database\Services\DataShare\Mirror\SupabaseMirrorProvider;
use App\Base\Database\Services\DataShare\Mirror\SymfonyDataShareMirrorProcessRunner;
use App\Base\Database\Services\DevelopmentInstanceGuard;
use App\Base\Database\Services\DevelopmentSanitizer;
use App\Base\Database\Services\HydrationGuard;
use App\Base\Database\Services\IncubatingSchemaPreflight;
use App\Base\Database\Services\ModuleMigrationDependencyChecker;
use App\Base\Database\Services\SchemaDrift\SchemaDriftInspector;
use App\Base\Database\Services\SessionStateDevelopmentSanitizer;
use App\Base\Foundation\Contracts\DataOperationRecorder;
use App\Base\Foundation\Exceptions\BlbConfigurationException;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Console\Migrations\FreshCommand as LaravelFreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand as LaravelMigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand as LaravelRefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand as LaravelResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand as LaravelRollbackCommand;
use Illuminate\Database\Console\Migrations\StatusCommand as LaravelStatusCommand;
use Illuminate\Database\Console\WipeCommand as LaravelWipeCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Psr\Log\LoggerInterface;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(ModuleMigrationDependencyChecker $migrationChecker): void
    {
        $migrationChecker->assertUniqueTableOwnership();
        $this->registerHydrationGuard();
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->configureModelStrictness();

        $this->mergeConfigFrom(__DIR__.'/Config/backup.php', 'backup');
        $this->mergeConfigFrom(__DIR__.'/Config/data_share.php', 'data_share');
        $this->mergeConfigFrom(__DIR__.'/Config/hydration_guard.php', 'hydration_guard');

        $this->app->singleton(HydrationGuard::class, fn ($app): HydrationGuard => new HydrationGuard(
            max(1, (int) $app['config']->get('hydration_guard.limit', 5000)),
            $this->hydrationGuardMode($app['config']->get('hydration_guard.mode')),
            $app->make(LoggerInterface::class),
        ));

        $this->app->bind(IncubatingSchemaInspector::class, IncubatingSchemaPreflight::class);
        $this->app->bind(SchemaDriftInspection::class, SchemaDriftInspector::class);
        $this->app->bind(DataShareMirrorProcessRunner::class, SymfonyDataShareMirrorProcessRunner::class);

        // Override Foundation's Null recorder with the real ledger recorder.
        $this->app->bind(DataOperationRecorder::class, LedgerDataOperationRecorder::class);
        $this->app->tag([
            PortableDataShareMirrorEngine::class,
            DataShareMirrorTableImageEngine::class,
        ], DataShareMirrorEngine::CONTAINER_TAG);
        $this->app->singleton(DataShareMirrorEngineRegistry::class, fn ($app) => new DataShareMirrorEngineRegistry(
            $app->tagged(DataShareMirrorEngine::CONTAINER_TAG),
        ));
        $this->app->tag([
            SupabaseMirrorProvider::class,
            GenericPostgresMirrorProvider::class,
        ], DataShareMirrorProvider::CONTAINER_TAG);
        $this->app->singleton(DataShareMirrorProviderRegistry::class, fn ($app) => new DataShareMirrorProviderRegistry(
            $app->tagged(DataShareMirrorProvider::CONTAINER_TAG),
        ));
        $this->app->singleton(DevelopmentInstanceGuard::class);
        $this->app->tag(SessionStateDevelopmentSanitizer::class, DevelopmentSanitizationContributor::CONTAINER_TAG);
        $this->app->singleton(DevelopmentSanitizer::class, fn ($app) => new DevelopmentSanitizer(
            $app->make(DevelopmentInstanceGuard::class),
            $app->tagged(DevelopmentSanitizationContributor::CONTAINER_TAG),
        ));

        Connection::resolverFor('pgsql', fn ($connection, string $database = '', string $prefix = '', array $config = []) => new GuardedPostgresConnection(
            $connection,
            $database,
            $prefix,
            $config,
        ));

        $this->app->singleton(EncryptionModeRegistry::class, function () {
            $registry = new EncryptionModeRegistry;

            $registry->register('none', fn (array $config) => new NoneEncryption);
            $registry->register('app-key', fn (array $config) => new AppKeyEncryption);

            return $registry;
        });

        // Override Laravel's MigrateCommand by extending the binding
        // Laravel's MigrationServiceProvider (deferred) binds MigrateCommand::class directly,
        // so we extend the class name, not an alias. The extend() callback runs when
        // the binding is resolved, after Laravel's MigrationServiceProvider registers it.
        $this->app->extend(LaravelMigrateCommand::class, function ($_, $app) {
            return new MigrateCommand(
                $app->make(Migrator::class),
                $app->make(Dispatcher::class)
            );
        });

        $this->app->extend(LaravelRollbackCommand::class, function ($_, $app) {
            return new RollbackCommand($app->make(Migrator::class));
        });

        $this->app->extend(LaravelStatusCommand::class, function ($_, $app) {
            return new StatusCommand($app->make(Migrator::class));
        });

        $this->app->extend(LaravelResetCommand::class, function ($_, $app) {
            return new ResetCommand($app->make(Migrator::class));
        });

        $this->app->extend(LaravelRefreshCommand::class, function () {
            return new RefreshCommand;
        });

        $this->app->extend(LaravelFreshCommand::class, function ($_, $app) {
            return new FreshCommand($app->make(Migrator::class));
        });

        $this->app->extend(LaravelWipeCommand::class, function () {
            return new WipeCommand;
        });

        $this->commands([
            ApproveIncubatingMigrationCommand::class,
            ApplyDataSharePackageCommand::class,
            AttachFreshnessTrackingCommand::class,
            ReconcileDataOperationsCommand::class,
            BackupCommand::class,
            DataShareHistoryCommand::class,
            DataShareScopesCommand::class,
            ExportDataSharePackageCommand::class,
            FetchDataShareTransferOfferCommand::class,
            ImportDiagnosticDataSharePackageCommand::class,
            InspectDataSharePackageCommand::class,
            MirrorTablesCommand::class,
            PlanDataSharePackageCommand::class,
            PruneDataSharePackagesCommand::class,
            RekeyCommand::class,
            RevokeDataShareTransferOfferCommand::class,
            SanitizeDevelopmentDatabaseCommand::class,
            SchemaDriftCommand::class,
            StageBackupCommand::class,
        ]);
    }

    /**
     * Unset: fail loudly where a developer or a test will see it, warn where
     * a user would. Anything else is a configuration mistake worth stopping on.
     */
    private function hydrationGuardMode(mixed $configured): HydrationGuardMode
    {
        if ($configured === null || $configured === '') {
            return $this->app->environment('local', 'testing')
                ? HydrationGuardMode::Throw
                : HydrationGuardMode::Log;
        }

        return HydrationGuardMode::tryFrom((string) $configured)
            ?? throw new BlbConfigurationException(sprintf(
                'hydration_guard.mode must be "throw" or "log"; got [%s].',
                is_scalar($configured) ? (string) $configured : get_debug_type($configured),
            ));
    }

    /**
     * Count every hydrated model against the innermost unit of work: the
     * request (opened by GuardRequestHydration), the queued job, or the
     * console command. Listeners are permanent; the guard's hot path is one
     * array increment, so idle overhead is a closure call per model.
     */
    private function registerHydrationGuard(): void
    {
        if (! $this->app['config']->get('hydration_guard.enabled', true)) {
            return;
        }

        $guard = $this->app->make(HydrationGuard::class);

        Event::listen('eloquent.retrieved: *', static function (string $event, array $payload) use ($guard): void {
            $guard->recordRetrieved($payload[0]::class);
        });

        Event::listen(JobProcessing::class, static fn (JobProcessing $event) => $guard->openJob($event->job, static fn (): string => $event->job->resolveName()));
        Event::listen(JobProcessed::class, static fn (JobProcessed $event) => $guard->closeJob($event->job));
        Event::listen(JobExceptionOccurred::class, static fn (JobExceptionOccurred $event) => $guard->closeJob($event->job));
        Event::listen(JobFailed::class, static fn (JobFailed $event) => $guard->closeJob($event->job));

        Event::listen(CommandStarting::class, static fn (CommandStarting $event) => $guard->openCommand($event->command));
        Event::listen(CommandFinished::class, static fn (CommandFinished $event) => $guard->closeCommand($event->command));
    }

    /**
     * Tighten Eloquent so latent bugs fail loudly in development instead of
     * degrading silently in production: N+1 lazy loads, accessing attributes
     * that were never retrieved, and silently discarding non-fillable
     * mass-assignment. Adopted in stages (see
     * docs/plans/framework-modernization.md, Phase 1); now fully enabled.
     *
     * Disabled in production so a stray violation cannot take the app down.
     *
     * Applied during register() rather than boot() so strict mode is already
     * active for every provider's boot(), not just for request handling.
     */
    private function configureModelStrictness(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
