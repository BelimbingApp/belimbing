<?php

namespace App\Base\Database;

use App\Base\Database\Console\Commands\ApplyBridgePackageCommand;
use App\Base\Database\Console\Commands\ApproveIncubatingMigrationCommand;
use App\Base\Database\Console\Commands\BackupCommand;
use App\Base\Database\Console\Commands\BridgeScopesCommand;
use App\Base\Database\Console\Commands\ExportBridgePackageCommand;
use App\Base\Database\Console\Commands\FreshCommand;
use App\Base\Database\Console\Commands\ImportDiagnosticBridgePackageCommand;
use App\Base\Database\Console\Commands\InspectBridgePackageCommand;
use App\Base\Database\Console\Commands\IssueBridgeReceiveGrantCommand;
use App\Base\Database\Console\Commands\MigrateCommand;
use App\Base\Database\Console\Commands\PlanBridgePackageCommand;
use App\Base\Database\Console\Commands\PruneBridgePackagesCommand;
use App\Base\Database\Console\Commands\RefreshCommand;
use App\Base\Database\Console\Commands\RekeyCommand;
use App\Base\Database\Console\Commands\ResetCommand;
use App\Base\Database\Console\Commands\RevokeBridgeReceiveGrantCommand;
use App\Base\Database\Console\Commands\RollbackCommand;
use App\Base\Database\Console\Commands\SanitizeDevelopmentDatabaseCommand;
use App\Base\Database\Console\Commands\StatusCommand;
use App\Base\Database\Console\Commands\WipeCommand;
use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Postgres\GuardedPostgresConnection;
use App\Base\Database\Services\Backup\Encryption\AppKeyEncryption;
use App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry;
use App\Base\Database\Services\Backup\Encryption\NoneEncryption;
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
        $this->mergeConfigFrom(__DIR__.'/Config/bridge.php', 'bridge');

        $this->app->bind(IncubatingSchemaInspector::class, IncubatingSchemaPreflight::class);
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
            ApplyBridgePackageCommand::class,
            BackupCommand::class,
            BridgeScopesCommand::class,
            ExportBridgePackageCommand::class,
            ImportDiagnosticBridgePackageCommand::class,
            InspectBridgePackageCommand::class,
            IssueBridgeReceiveGrantCommand::class,
            PlanBridgePackageCommand::class,
            PruneBridgePackagesCommand::class,
            RekeyCommand::class,
            RevokeBridgeReceiveGrantCommand::class,
            SanitizeDevelopmentDatabaseCommand::class,
        ]);
    }
}
