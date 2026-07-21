<?php

namespace App\Base\Database;

use App\Base\Database\Console\Commands\ApplyDataSharePackageCommand;
use App\Base\Database\Console\Commands\ApproveIncubatingMigrationCommand;
use App\Base\Database\Console\Commands\BackupCommand;
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
use App\Base\Database\Console\Commands\RefreshCommand;
use App\Base\Database\Console\Commands\RekeyCommand;
use App\Base\Database\Console\Commands\ResetCommand;
use App\Base\Database\Console\Commands\RevokeDataShareTransferOfferCommand;
use App\Base\Database\Console\Commands\RollbackCommand;
use App\Base\Database\Console\Commands\SanitizeDevelopmentDatabaseCommand;
use App\Base\Database\Console\Commands\StatusCommand;
use App\Base\Database\Console\Commands\WipeCommand;
use App\Base\Database\Contracts\DataShareMirrorEngine;
use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\Contracts\DataShareMirrorProvider;
use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Postgres\GuardedPostgresConnection;
use App\Base\Database\Services\Backup\Encryption\AppKeyEncryption;
use App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry;
use App\Base\Database\Services\Backup\Encryption\NoneEncryption;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorEngineRegistry;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorProviderRegistry;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorTableImageEngine;
use App\Base\Database\Services\DataShare\Mirror\GenericPostgresMirrorProvider;
use App\Base\Database\Services\DataShare\Mirror\PortableDataShareMirrorEngine;
use App\Base\Database\Services\DataShare\Mirror\SupabaseMirrorProvider;
use App\Base\Database\Services\DataShare\Mirror\SymfonyDataShareMirrorProcessRunner;
use App\Base\Database\Services\DevelopmentInstanceGuard;
use App\Base\Database\Services\DevelopmentSanitizer;
use App\Base\Database\Services\IncubatingSchemaPreflight;
use App\Base\Database\Services\SessionStateDevelopmentSanitizer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Console\Migrations\FreshCommand as LaravelFreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand as LaravelMigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand as LaravelRefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand as LaravelResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand as LaravelRollbackCommand;
use Illuminate\Database\Console\Migrations\StatusCommand as LaravelStatusCommand;
use Illuminate\Database\Console\WipeCommand as LaravelWipeCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/backup.php', 'backup');
        $this->mergeConfigFrom(__DIR__.'/Config/data_share.php', 'data_share');

        $this->app->bind(IncubatingSchemaInspector::class, IncubatingSchemaPreflight::class);
        $this->app->bind(DataShareMirrorProcessRunner::class, SymfonyDataShareMirrorProcessRunner::class);
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
            BackupCommand::class,
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
        ]);
    }
}
