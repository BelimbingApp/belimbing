<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\Contracts\DataShareMirrorProvider;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionContext;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class DataShareMirrorConnectionInspector
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly DataShareInstanceIdentityResolver $identity,
        private readonly DataShareMirrorProcessRunner $processes,
    ) {}

    public function inspect(DataShareMirrorProvider $provider, string $url, string $connectionName, bool $purgeAfter, array $availablePdoDrivers): DataShareMirrorConnectionStatus
    {
        if ($url === '') {
            return DataShareMirrorConnectionStatusFactory::unavailable(new DataShareMirrorConnectionContext(true, false), 'invalid_url', __('Enter a :provider database URL.', ['provider' => $provider->label()]), $provider);
        }

        try {
            $config = $provider->configuration($url);
        } catch (Throwable) {
            return DataShareMirrorConnectionStatusFactory::unavailable(new DataShareMirrorConnectionContext(true, false), 'invalid_url', __('The mirror connection URL is not valid for :provider.', ['provider' => $provider->label()]), $provider);
        }

        $driver = (string) ($config['driver'] ?? '');

        if ($driver !== 'pgsql') {
            return DataShareMirrorConnectionStatusFactory::unavailable(new DataShareMirrorConnectionContext(true, false, $driver ?: null), 'unsupported_driver', __('This provider adapter requires PostgreSQL.'), $provider);
        }

        // Check the PDO driver before attempting a connection. Without this,
        // a missing pdo_pgsql surfaces as PDOException "could not find driver"
        // deep inside the connection attempt and reads like a database-side
        // failure — it cost a long debugging detour before this check existed.
        if (! in_array($driver, $availablePdoDrivers, true)) {
            return DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(true, false, $driver),
                'driver_unloaded',
                __("PHP's PostgreSQL driver (pdo_pgsql) is not loaded in this server process. Enable it in the loaded php.ini, then restart the application server process — reloading workers cannot load extensions."),
                $provider,
            );
        }

        $localRole = null;
        $localDriver = null;

        try {
            [$localRole, $localDriver, $localFailure] = $this->inspectLocalPolicy($provider, $driver);
            if ($localFailure !== null) {
                return $localFailure;
            }
        } catch (Throwable $exception) {
            $failure = DataShareMirrorException::unexpected('connection', $exception);

            return DataShareMirrorConnectionStatusFactory::unavailable(new DataShareMirrorConnectionContext(true, false, $driver, $localRole, localDriver: $localDriver), 'local_policy_unavailable', $failure->getMessage(), $provider);
        }

        return $this->inspectRemoteConnection(
            $provider,
            $connectionName,
            $purgeAfter,
            $config,
            new DataShareMirrorConnectionContext(true, false, $driver, $localRole, localDriver: $localDriver),
        );
    }

    /** @param array<string, mixed> $config */
    private function inspectRemoteConnection(DataShareMirrorProvider $provider, string $connectionName, bool $purgeAfter, array $config, DataShareMirrorConnectionContext $context): DataShareMirrorConnectionStatus
    {
        $driver = (string) $context->driver;
        $localRole = $context->localRole;
        $localDriver = $context->localDriver;

        try {
            $this->database->purge($connectionName);
            // Register the configuration instead of connectUsing(): connectUsing
            // builds the connection without recording its config, so when Laravel
            // classifies a connect failure as a lost connection and retries via
            // reconnect(), the DatabaseManager cannot find the config and throws
            // "Database connection [...] not configured" — masking the real
            // failure (DNS, timeout) behind a generic diagnostic message.
            config(["database.connections.{$connectionName}" => $config]);
            $remote = $this->database->connection($connectionName);
            $remoteInfo = $this->serverInfo($remote);
            $policyFailure = $this->inspectRemotePolicy($provider, $context, $remote, $remoteInfo, $config);
            if ($policyFailure !== null) {
                return $policyFailure;
            }

            return $this->readyStatus($provider, $context, DataShareInstanceRole::Development->value, $remoteInfo);
        } catch (Throwable $exception) {
            $failure = DataShareMirrorException::unexpected('connection', $exception);

            return DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(true, false, $driver, $localRole, localDriver: $localDriver),
                'connection_failed',
                $this->connectionFailureMessage($provider, $exception, $failure->diagnosticReference),
                $provider,
            );
        } finally {
            if ($purgeAfter) {
                $this->database->purge($connectionName);
                // Drop the candidate credential from the config repository; the
                // persistent mirror connection keeps its config so mid-operation
                // reconnects can rebuild it.
                config(["database.connections.{$connectionName}" => null]);
            }
        }
    }

    /**
     * @param  array{database: string, address: string, port: string, version: string}  $remoteInfo
     * @param  array<string, mixed>  $remoteConfig
     */
    private function inspectRemotePolicy(DataShareMirrorProvider $provider, DataShareMirrorConnectionContext $context, Connection $remote, array $remoteInfo, array $remoteConfig): ?DataShareMirrorConnectionStatus
    {
        $connected = new DataShareMirrorConnectionContext(true, true, (string) $context->driver, $context->localRole, serverVersion: (string) $remoteInfo['version'], localDriver: $context->localDriver);
        if ($context->localDriver === 'pgsql') {
            $local = $this->database->connection();
            if ($this->sameEndpoint($this->serverInfo($local), $remoteInfo, $this->processConfiguration($local), $remoteConfig)) {
                return DataShareMirrorConnectionStatusFactory::unavailable($connected, 'self_target', __('The mirror connection points to the local database. Choose a different development database.'), $provider);
            }
        }

        $infrastructureReady = $this->remoteInfrastructureReady($remote);
        $remoteRole = $this->remoteRole($remote);
        $connected = new DataShareMirrorConnectionContext(true, true, (string) $context->driver, $context->localRole, $remoteRole, (string) $remoteInfo['version'], localDriver: $context->localDriver);
        if ($remoteRole === null) {
            $initializable = $infrastructureReady || $this->publicSchemaIsEmpty($remote);

            return DataShareMirrorConnectionStatusFactory::unavailable(
                $connected,
                $initializable ? 'remote_not_initialized' : 'remote_incompatible',
                $initializable
                    ? __('The provider connection is valid. Initialize its Belimbing schema before mirroring data.')
                    : __('The provider public schema already contains non-Belimbing relations. Use an empty development database or inspect it manually.'),
                $provider,
                $initializable,
            );
        }
        if ($remoteRole !== DataShareInstanceRole::Development->value) {
            return DataShareMirrorConnectionStatusFactory::unavailable($connected, 'remote_role_denied', __('The connected Data Share role must be Development.'), $provider);
        }
        if (! $infrastructureReady) {
            return DataShareMirrorConnectionStatusFactory::unavailable($connected, 'remote_incompatible', __('The connected Belimbing database has incomplete mirror infrastructure. Inspect its migration state before using it.'), $provider);
        }

        $localInstanceId = $this->identity->current()->id;
        $remoteInstanceId = $this->remoteSettingString($remote, 'data_share.instance.id');
        if ($remoteInstanceId === null) {
            return DataShareMirrorConnectionStatusFactory::unavailable($connected, 'remote_instance_id_missing', __('The mirror database needs its own Data Share instance ID before it can be used.'), $provider, true);
        }
        if (hash_equals($localInstanceId, $remoteInstanceId)) {
            return DataShareMirrorConnectionStatusFactory::unavailable($connected, 'self_target', __('The mirror database has the same Data Share instance ID as Local. Assign the mirror endpoint a distinct development instance ID.'), $provider);
        }

        return null;
    }

    /** @param array{database: string, address: string, port: string, version: string} $remoteInfo */
    private function readyStatus(DataShareMirrorProvider $provider, DataShareMirrorConnectionContext $context, string $remoteRole, array $remoteInfo): DataShareMirrorConnectionStatus
    {
        if ($context->localDriver === 'sqlite') {
            return $this->transferStatus($provider, $context, $remoteRole, $remoteInfo, ['pg_dump' => null, 'psql' => null], 'portable', __('The :provider mirror is ready for portable SQLite-to-PostgreSQL data transfer.', ['provider' => $provider->label()]));
        }

        $tooling = $this->tooling();
        $message = __('The :provider mirror is ready for portable PostgreSQL data transfer.', ['provider' => $provider->label()]);
        $mode = 'portable';
        if ($tooling['pg_dump'] !== null && $tooling['psql'] !== null) {
            $localServerMajor = $this->majorVersion((string) $this->serverInfo($this->database->connection())['version']);
            $remoteServerMajor = $this->majorVersion((string) $remoteInfo['version']);
            $pgDumpMajor = $this->majorVersion($tooling['pg_dump']);
            $psqlMajor = $this->majorVersion($tooling['psql']);
            if ($localServerMajor !== null
                && $remoteServerMajor !== null
                && $localServerMajor === $remoteServerMajor
                && $pgDumpMajor === $localServerMajor
                && $psqlMajor !== null
                && $psqlMajor >= $localServerMajor) {
                $message = __('The :provider mirror is ready for native PostgreSQL transfer.', ['provider' => $provider->label()]);
                $mode = 'native';
            }
        }

        return $this->transferStatus($provider, $context, $remoteRole, $remoteInfo, $tooling, $mode, $message);
    }

    /**
     * @param  array{database: string, address: string, port: string, version: string}  $remoteInfo
     * @param  array{pg_dump: ?string, psql: ?string}  $tooling
     */
    private function transferStatus(DataShareMirrorProvider $provider, DataShareMirrorConnectionContext $context, string $remoteRole, array $remoteInfo, array $tooling, string $mode, string $message): DataShareMirrorConnectionStatus
    {
        return new DataShareMirrorConnectionStatus(
            configured: true,
            available: true,
            reachable: true,
            driver: (string) $context->driver,
            localRole: $context->localRole,
            remoteRole: $remoteRole,
            serverVersion: (string) $remoteInfo['version'],
            pgDumpVersion: $tooling['pg_dump'],
            psqlVersion: $tooling['psql'],
            reasonCode: null,
            message: $message,
            providerKey: $provider->key(),
            providerLabel: $provider->label(),
            localDriver: $context->localDriver,
            transferMode: $mode,
        );
    }

    /** @return array{0: string|null, 1: string|null, 2: DataShareMirrorConnectionStatus|null} */
    private function inspectLocalPolicy(DataShareMirrorProvider $provider, string $remoteDriver): array
    {
        if (! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            return [null, null, DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(true, false, $remoteDriver),
                'local_not_development',
                __('Table mirroring is available only on a development instance.'),
                $provider,
            )];
        }

        $localDriver = $this->database->connection()->getDriverName();
        if (! in_array($localDriver, ['sqlite', 'pgsql'], true)) {
            return [null, $localDriver, DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(true, false, $remoteDriver, localDriver: $localDriver),
                'local_driver_unsupported',
                __('The local mirror database must use SQLite or PostgreSQL.'),
                $provider,
            )];
        }

        $localRole = $this->identity->role()->value;
        if ($localRole !== DataShareInstanceRole::Development->value) {
            return [$localRole, $localDriver, DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(true, false, $remoteDriver, $localRole, localDriver: $localDriver),
                'local_role_denied',
                __('The local Data Share role must be Development.'),
                $provider,
            )];
        }

        return [$localRole, $localDriver, null];
    }

    private function connectionFailureMessage(DataShareMirrorProvider $provider, Throwable $exception, ?string $reference): string
    {
        $diagnostic = mb_strtolower($exception->getMessage());
        $message = match (true) {
            str_contains($diagnostic, 'could not find driver') => __("PHP's PostgreSQL driver (pdo_pgsql) is not loaded in this server process. Enable it in the loaded php.ini, then restart the application server process — reloading workers cannot load extensions."),
            str_contains($diagnostic, 'password authentication failed'),
            str_contains($diagnostic, 'authentication failed') => __(':provider rejected the database username or password. Enter the project’s Database Password, not a personal access token or API key.', ['provider' => $provider->label()]),
            str_contains($diagnostic, 'could not translate host name'),
            str_contains($diagnostic, 'name or service not known'),
            str_contains($diagnostic, 'nodename nor servname provided') => __('The database hostname could not be resolved. Check the project URL and this machine’s DNS connection.'),
            str_contains($diagnostic, 'network is unreachable'),
            str_contains($diagnostic, 'no route to host') => __('This machine has no network route to the database host. Use the Supabase session-pooler URL when direct IPv6 connectivity is unavailable.'),
            str_contains($diagnostic, 'connection timed out'),
            str_contains($diagnostic, 'timeout expired') => __('The database host did not respond before the connection timed out. Check the network, host, and port.'),
            str_contains($diagnostic, 'connection refused') => __('The database host refused the connection. Check that the URL uses an active direct or session-pooler endpoint and the correct port.'),
            default => __('Belimbing reached the connection check, but an unexpected database error prevented verification.'),
        };

        return $reference === null
            ? $message
            : $message.' '.__('Diagnostic reference: :reference.', ['reference' => $reference]);
    }

    /** @return array{database: string, address: string, port: string, version: string} */
    private function serverInfo(Connection $connection): array
    {
        $row = $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database,
                   COALESCE(inet_server_addr()::text, '') AS address,
                   COALESCE(inet_server_port()::text, '') AS port,
                   current_setting('server_version') AS version
            SQL);

        return [
            'database' => (string) ($row->database ?? ''),
            'address' => (string) ($row->address ?? ''),
            'port' => (string) ($row->port ?? ''),
            'version' => (string) ($row->version ?? ''),
        ];
    }

    /**
     * @param  array{database: string, address: string, port: string, version: string}  $local
     * @param  array{database: string, address: string, port: string, version: string}  $remote
     * @param  array<string, mixed>  $localConfig
     * @param  array<string, mixed>  $remoteConfig
     */
    private function sameEndpoint(array $local, array $remote, array $localConfig, array $remoteConfig): bool
    {
        if ($local['database'] !== $remote['database']) {
            return false;
        }

        if ($local['address'] !== '' && $local['address'] === $remote['address'] && $local['port'] === $remote['port']) {
            return true;
        }

        return mb_strtolower((string) ($localConfig['host'] ?? '')) === mb_strtolower((string) ($remoteConfig['host'] ?? ''))
            && (string) ($localConfig['port'] ?? '5432') === (string) ($remoteConfig['port'] ?? '5432')
            && (string) ($localConfig['database'] ?? '') === (string) ($remoteConfig['database'] ?? '');
    }

    private function remoteRole(Connection $connection): ?string
    {
        if (! filter_var(
            $connection->selectOne(<<<'SQL'
                SELECT to_regclass('public.base_settings') IS NOT NULL
                   AND to_regclass('public.base_database_tables') IS NOT NULL AS present
                SQL)->present ?? false,
            FILTER_VALIDATE_BOOL,
        )) {
            return null;
        }

        return $this->remoteSettingString($connection, 'data_share.instance.role');
    }

    private function remoteInfrastructureReady(Connection $connection): bool
    {
        $required = [
            'base_database_tables' => [
                'table_name',
                'module_name',
                'module_path',
                'migration_file',
                'stabilized_at',
                'stabilized_by',
                'created_at',
                'updated_at',
            ],
            'base_settings' => [
                'key',
                'value',
                'is_encrypted',
                'scope_type',
                'scope_id',
                'created_at',
                'updated_at',
            ],
        ];

        $columns = $connection->select(<<<'SQL'
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name IN ('base_database_tables', 'base_settings')
            SQL);
        $present = [];
        foreach ($columns as $column) {
            $present[(string) $column->table_name][(string) $column->column_name] = true;
        }

        foreach ($required as $table => $tableColumns) {
            foreach ($tableColumns as $column) {
                if (! isset($present[$table][$column])) {
                    return false;
                }
            }
        }

        return filter_var($connection->selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM pg_index AS index_definition
                JOIN pg_class AS relation ON relation.oid = index_definition.indrelid
                JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
                WHERE namespace.nspname = 'public'
                  AND relation.relname = 'base_database_tables'
                  AND index_definition.indisunique
                  AND index_definition.indnkeyatts = 1
                  AND pg_get_indexdef(index_definition.indexrelid) LIKE '%(table_name)%'
            ) AS present
            SQL)->present ?? false, FILTER_VALIDATE_BOOL);
    }

    private function publicSchemaIsEmpty(Connection $connection): bool
    {
        return ! filter_var($connection->selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM pg_class AS relation
                JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
                WHERE namespace.nspname = 'public'
                  AND relation.relkind IN ('r', 'p', 'v', 'm', 'f')
            ) AS present
            SQL)->present ?? false, FILTER_VALIDATE_BOOL);
    }

    private function remoteSettingString(Connection $connection, string $key): ?string
    {
        $row = $connection->selectOne(<<<'SQL'
            SELECT value #>> '{}' AS setting_value, is_encrypted
            FROM public.base_settings
            WHERE key = ?
              AND scope_type IS NULL
              AND scope_id IS NULL
            LIMIT 1
            SQL, [$key]);

        if ($row === null || filter_var($row->is_encrypted, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $value = is_string($row->setting_value ?? null) ? trim($row->setting_value) : '';

        return $value !== '' ? $value : null;
    }

    /** @return array{pg_dump: string|null, psql: string|null} */
    private function tooling(): array
    {
        return [
            'pg_dump' => $this->toolVersion('pg_dump'),
            'psql' => $this->toolVersion('psql'),
        ];
    }

    private function toolVersion(string $tool): ?string
    {
        $path = $this->processes->find($tool);
        if ($path === null) {
            return null;
        }

        $result = $this->processes->run([$path, '--version']);
        if (! $result->successful() || preg_match('/(\d+(?:\.\d+)+)/', $result->output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function majorVersion(string $version): ?int
    {
        return preg_match('/^(\d+)/', trim($version), $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /** @return array<string, mixed> */
    private function processConfiguration(Connection $connection): array
    {
        $config = $connection->getConfig();

        return [
            'driver' => (string) ($config['driver'] ?? ''),
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (string) ($config['port'] ?? '5432'),
            'database' => (string) ($config['database'] ?? $connection->getDatabaseName()),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            'sslmode' => (string) ($config['sslmode'] ?? 'prefer'),
            'connect_timeout' => (string) ($config['connect_timeout'] ?? '15'),
        ];
    }
}
