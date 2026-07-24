<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\Contracts\DataShareMirrorProvider;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Enums\DataShareMirrorDirection;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

class DataShareMirrorConnectionManager
{
    public const CONNECTION = 'data_share_mirror';

    public const SETTING_KEY = 'data_share.mirror.url';

    public const PROVIDER_SETTING_KEY = 'data_share.mirror.provider';

    private const CANDIDATE_CONNECTION = 'data_share_mirror_candidate';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DatabaseManager $database,
        private readonly DataShareInstanceIdentityResolver $identity,
        private readonly DataShareMirrorProcessRunner $processes,
        private readonly DataShareMirrorProviderRegistry $providers,
    ) {}

    public function status(): DataShareMirrorConnectionStatus
    {
        try {
            $provider = $this->provider();
        } catch (Throwable) {
            return $this->unavailable(
                configured: false,
                reachable: false,
                reasonCode: 'provider_unavailable',
                message: __('Choose an installed mirror provider in Data Share Settings.'),
            );
        }

        try {
            $url = $this->storedUrl();
        } catch (Throwable) {
            $this->purge();

            return $this->unavailable(
                configured: true,
                reachable: false,
                reasonCode: 'credential_unreadable',
                message: __('The saved mirror credential cannot be decrypted with this instance APP_KEY. Replace it in Data Share Settings.'),
                provider: $provider,
            );
        }

        if ($url === null) {
            $this->purge();

            return $this->unavailable(
                configured: false,
                reachable: false,
                reasonCode: 'not_configured',
                message: __('Configure a :provider database connection in Data Share Settings.', ['provider' => $provider->label()]),
                provider: $provider,
            );
        }

        return $this->inspectUrl($provider, $url, self::CONNECTION, purgeAfter: false);
    }

    public function testConnection(string $candidateUrl, ?string $providerKey = null): DataShareMirrorConnectionStatus
    {
        try {
            $provider = $providerKey === null ? $this->provider() : $this->providers->get($providerKey);
        } catch (Throwable) {
            return $this->unavailable(false, false, 'provider_unavailable', __('Choose an installed mirror provider in Data Share Settings.'));
        }

        return $this->inspectUrl($provider, trim($candidateUrl), self::CANDIDATE_CONNECTION, purgeAfter: true);
    }

    public function provider(): DataShareMirrorProvider
    {
        $value = $this->settings->get(self::PROVIDER_SETTING_KEY);
        $key = is_string($value) && trim($value) !== '' ? trim($value) : 'supabase';

        return $this->providers->get($key);
    }

    /** @return array<string, string> */
    public function providerOptions(): array
    {
        return $this->providers->options();
    }

    public function configurationFingerprint(): string
    {
        return hash('sha256', implode("\0", [
            (string) $this->settings->get(self::PROVIDER_SETTING_KEY),
            (string) ($this->storedUrl() ?? ''),
        ]));
    }

    /**
     * Stable, non-secret identity for the configured endpoint without opening a
     * connection. Unknown/unreadable configuration returns null rather than
     * sharing observations under a provider-wide fallback key.
     */
    public function endpointIdentity(): ?string
    {
        try {
            $url = $this->storedUrl();
            if ($url === null) {
                return null;
            }

            $config = $this->provider()->configuration($url);
            $host = strtolower(trim((string) ($config['host'] ?? '')));
            $port = (string) ($config['port'] ?? 5432);
            $database = trim((string) ($config['database'] ?? ''));

            if ($host === '' || $database === '') {
                return null;
            }

            return 'remote:v1:'.substr(hash('sha256', $host.':'.$port.'/'.$database), 0, 20);
        } catch (Throwable) {
            return null;
        }
    }

    public function purge(): void
    {
        $this->database->purge(self::CONNECTION);
    }

    public function assertAvailable(): DataShareMirrorConnectionStatus
    {
        $status = $this->status();

        if (! $status->available) {
            throw DataShareMirrorException::unavailable($status->message);
        }

        return $status;
    }

    public function local(): Connection
    {
        return $this->database->connection();
    }

    public function mirror(): Connection
    {
        $this->assertAvailable();

        return $this->database->connection(self::CONNECTION);
    }

    public function mirrorForInitialization(): Connection
    {
        $status = $this->status();

        if (! $status->reachable) {
            throw DataShareMirrorException::unavailable($status->message);
        }

        if ($status->available) {
            throw DataShareMirrorException::alreadyInitialized();
        }

        if (! $status->initializable) {
            throw DataShareMirrorException::unavailable($status->message);
        }

        return $this->database->connection(self::CONNECTION);
    }

    public function source(DataShareMirrorDirection $direction): DataShareMirrorEndpoint
    {
        return $direction === DataShareMirrorDirection::Push
            ? $this->localEndpoint()
            : $this->mirrorEndpoint();
    }

    public function target(DataShareMirrorDirection $direction): DataShareMirrorEndpoint
    {
        return $direction === DataShareMirrorDirection::Push
            ? $this->mirrorEndpoint()
            : $this->localEndpoint();
    }

    /** @return array<string, mixed> */
    public function processConfiguration(Connection $connection): array
    {
        // DatabaseManager has already normalized URL-backed configurations before
        // constructing a Connection. Reparsing here would give a query parameter
        // named `url` a second chance to replace the endpoint already inspected by
        // PDO, allowing PostgreSQL client tools to target a different database.
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

    private function inspectUrl(DataShareMirrorProvider $provider, string $url, string $connectionName, bool $purgeAfter): DataShareMirrorConnectionStatus
    {
        if ($url === '') {
            return $this->unavailable(true, false, 'invalid_url', __('Enter a :provider database URL.', ['provider' => $provider->label()]), provider: $provider);
        }

        try {
            $config = $provider->configuration($url);
        } catch (Throwable) {
            return $this->unavailable(true, false, 'invalid_url', __('The mirror connection URL is not valid for :provider.', ['provider' => $provider->label()]), provider: $provider);
        }

        $driver = (string) ($config['driver'] ?? '');

        if ($driver !== 'pgsql') {
            return $this->unavailable(true, false, 'unsupported_driver', __('This provider adapter requires PostgreSQL.'), $driver ?: null, provider: $provider);
        }

        // Check the PDO driver before attempting a connection. Without this,
        // a missing pdo_pgsql surfaces as PDOException "could not find driver"
        // deep inside the connection attempt and reads like a database-side
        // failure — it cost a long debugging detour before this check existed.
        if (! in_array($driver, $this->availablePdoDrivers(), true)) {
            return $this->unavailable(
                true,
                false,
                'driver_unloaded',
                __("PHP's PostgreSQL driver (pdo_pgsql) is not loaded in this server process. Enable it in the loaded php.ini, then restart the application server process — reloading workers cannot load extensions."),
                $driver,
                provider: $provider,
            );
        }

        $localRole = null;
        $localDriver = null;

        try {
            if (! in_array((string) config('app.env'), ['local', 'testing'], true)) {
                return $this->unavailable(true, false, 'local_not_development', __('Table mirroring is available only on a development instance.'), $driver, provider: $provider);
            }

            $local = $this->local();
            $localDriver = $local->getDriverName();
            if (! in_array($localDriver, ['sqlite', 'pgsql'], true)) {
                return $this->unavailable(true, false, 'local_driver_unsupported', __('The local mirror database must use SQLite or PostgreSQL.'), $driver, localDriver: $localDriver, provider: $provider);
            }

            $localRole = $this->identity->role()->value;
            if ($localRole !== DataShareInstanceRole::Development->value) {
                return $this->unavailable(true, false, 'local_role_denied', __('The local Data Share role must be Development.'), $driver, $localRole, localDriver: $localDriver, provider: $provider);
            }
        } catch (Throwable $exception) {
            $failure = DataShareMirrorException::unexpected('connection', $exception);

            return $this->unavailable(true, false, 'local_policy_unavailable', $failure->getMessage(), $driver, $localRole, localDriver: $localDriver, provider: $provider);
        }

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

            if ($localDriver === 'pgsql') {
                $localInfo = $this->serverInfo($this->local());
                if ($this->sameEndpoint($localInfo, $remoteInfo, $this->processConfiguration($this->local()), $config)) {
                    return $this->unavailable(true, true, 'self_target', __('The mirror connection points to the local database. Choose a different development database.'), $driver, $localRole, serverVersion: (string) $remoteInfo['version'], localDriver: $localDriver, provider: $provider);
                }
            }

            $remoteInfrastructureReady = $this->remoteInfrastructureReady($remote);
            $remoteRole = $this->remoteRole($remote);
            if ($remoteRole === null) {
                $initializable = $remoteInfrastructureReady || $this->publicSchemaIsEmpty($remote);

                return $this->unavailable(
                    true,
                    true,
                    $initializable ? 'remote_not_initialized' : 'remote_incompatible',
                    $initializable
                        ? __('The provider connection is valid. Initialize its Belimbing schema before mirroring data.')
                        : __('The provider public schema already contains non-Belimbing relations. Use an empty development database or inspect it manually.'),
                    $driver,
                    $localRole,
                    $remoteRole,
                    (string) $remoteInfo['version'],
                    localDriver: $localDriver,
                    provider: $provider,
                    initializable: $initializable,
                );
            }

            if ($remoteRole !== DataShareInstanceRole::Development->value) {
                return $this->unavailable(
                    true,
                    true,
                    'remote_role_denied',
                    __('The connected Data Share role must be Development.'),
                    $driver,
                    $localRole,
                    $remoteRole,
                    (string) $remoteInfo['version'],
                    localDriver: $localDriver,
                    provider: $provider,
                );
            }

            if (! $remoteInfrastructureReady) {
                return $this->unavailable(
                    true,
                    true,
                    'remote_incompatible',
                    __('The connected Belimbing database has incomplete mirror infrastructure. Inspect its migration state before using it.'),
                    $driver,
                    $localRole,
                    $remoteRole,
                    (string) $remoteInfo['version'],
                    localDriver: $localDriver,
                    provider: $provider,
                );
            }

            $localInstanceId = $this->identity->current()->id;
            $remoteInstanceId = $this->remoteSettingString($remote, 'data_share.instance.id');
            if ($remoteInstanceId === null) {
                return $this->unavailable(
                    true,
                    true,
                    'remote_instance_id_missing',
                    __('The mirror database needs its own Data Share instance ID before it can be used.'),
                    $driver,
                    $localRole,
                    $remoteRole,
                    (string) $remoteInfo['version'],
                    localDriver: $localDriver,
                    provider: $provider,
                    initializable: true,
                );
            }

            if (hash_equals($localInstanceId, $remoteInstanceId)) {
                return $this->unavailable(
                    true,
                    true,
                    'self_target',
                    __('The mirror database has the same Data Share instance ID as Local. Assign the mirror endpoint a distinct development instance ID.'),
                    $driver,
                    $localRole,
                    $remoteRole,
                    (string) $remoteInfo['version'],
                    localDriver: $localDriver,
                    provider: $provider,
                );
            }

            if ($localDriver === 'sqlite') {
                return new DataShareMirrorConnectionStatus(
                    configured: true,
                    available: true,
                    reachable: true,
                    driver: $driver,
                    localRole: $localRole,
                    remoteRole: $remoteRole,
                    serverVersion: (string) $remoteInfo['version'],
                    pgDumpVersion: null,
                    psqlVersion: null,
                    reasonCode: null,
                    message: __('The :provider mirror is ready for portable SQLite-to-PostgreSQL data transfer.', ['provider' => $provider->label()]),
                    providerKey: $provider->key(),
                    providerLabel: $provider->label(),
                    localDriver: $localDriver,
                    transferMode: 'portable',
                );
            }

            $tooling = $this->tooling();
            if ($tooling['pg_dump'] === null || $tooling['psql'] === null) {
                return new DataShareMirrorConnectionStatus(
                    configured: true,
                    available: true,
                    reachable: true,
                    driver: $driver,
                    localRole: $localRole,
                    remoteRole: $remoteRole,
                    serverVersion: (string) $remoteInfo['version'],
                    pgDumpVersion: $tooling['pg_dump'],
                    psqlVersion: $tooling['psql'],
                    reasonCode: null,
                    message: __('The :provider mirror is ready for portable PostgreSQL data transfer.', ['provider' => $provider->label()]),
                    providerKey: $provider->key(),
                    providerLabel: $provider->label(),
                    localDriver: $localDriver,
                    transferMode: 'portable',
                );
            }

            $localInfo = $this->serverInfo($this->local());
            $localServerMajor = $this->majorVersion((string) $localInfo['version']);
            $remoteServerMajor = $this->majorVersion((string) $remoteInfo['version']);
            $pgDumpMajor = $this->majorVersion($tooling['pg_dump']);
            $psqlMajor = $this->majorVersion($tooling['psql']);

            if ($localServerMajor === null
                || $remoteServerMajor === null
                || $localServerMajor !== $remoteServerMajor
                || $pgDumpMajor !== $localServerMajor
                || $psqlMajor === null
                || $psqlMajor < $localServerMajor) {
                return new DataShareMirrorConnectionStatus(
                    configured: true,
                    available: true,
                    reachable: true,
                    driver: $driver,
                    localRole: $localRole,
                    remoteRole: $remoteRole,
                    serverVersion: (string) $remoteInfo['version'],
                    pgDumpVersion: $tooling['pg_dump'],
                    psqlVersion: $tooling['psql'],
                    reasonCode: null,
                    message: __('The :provider mirror is ready for portable PostgreSQL data transfer.', ['provider' => $provider->label()]),
                    providerKey: $provider->key(),
                    providerLabel: $provider->label(),
                    localDriver: $localDriver,
                    transferMode: 'portable',
                );
            }

            return new DataShareMirrorConnectionStatus(
                configured: true,
                available: true,
                reachable: true,
                driver: $driver,
                localRole: $localRole,
                remoteRole: $remoteRole,
                serverVersion: (string) $remoteInfo['version'],
                pgDumpVersion: $tooling['pg_dump'],
                psqlVersion: $tooling['psql'],
                reasonCode: null,
                message: __('The :provider mirror is ready for native PostgreSQL transfer.', ['provider' => $provider->label()]),
                providerKey: $provider->key(),
                providerLabel: $provider->label(),
                localDriver: $localDriver,
                transferMode: 'native',
            );
        } catch (Throwable $exception) {
            $failure = DataShareMirrorException::unexpected('connection', $exception);

            return $this->unavailable(
                true,
                false,
                'connection_failed',
                $this->connectionFailureMessage($provider, $exception, $failure->diagnosticReference),
                $driver,
                $localRole,
                localDriver: $localDriver,
                provider: $provider,
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

    /** @return list<string> */
    protected function availablePdoDrivers(): array
    {
        return PDO::getAvailableDrivers();
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

    private function localEndpoint(): DataShareMirrorEndpoint
    {
        $connection = $this->local();

        return new DataShareMirrorEndpoint(__('Local'), $connection, $this->processConfiguration($connection), $connection->getDriverName());
    }

    private function mirrorEndpoint(): DataShareMirrorEndpoint
    {
        $connection = $this->mirror();

        return new DataShareMirrorEndpoint($this->provider()->connectionLabel(), $connection, $this->processConfiguration($connection), $connection->getDriverName());
    }

    private function storedUrl(): ?string
    {
        $value = $this->settings->get(self::SETTING_KEY);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function unavailable(
        bool $configured,
        bool $reachable,
        string $reasonCode,
        string $message,
        ?string $driver = null,
        ?string $localRole = null,
        ?string $remoteRole = null,
        ?string $serverVersion = null,
        ?string $pgDumpVersion = null,
        ?string $psqlVersion = null,
        ?string $localDriver = null,
        ?DataShareMirrorProvider $provider = null,
        bool $initializable = false,
    ): DataShareMirrorConnectionStatus {
        return new DataShareMirrorConnectionStatus(
            configured: $configured,
            available: false,
            reachable: $reachable,
            driver: $driver,
            localRole: $localRole,
            remoteRole: $remoteRole,
            serverVersion: $serverVersion,
            pgDumpVersion: $pgDumpVersion,
            psqlVersion: $psqlVersion,
            reasonCode: $reasonCode,
            message: $message,
            providerKey: $provider?->key(),
            providerLabel: $provider?->label(),
            localDriver: $localDriver,
            transferMode: $localDriver === 'sqlite' ? 'portable' : ($localDriver === 'pgsql' ? 'native' : null),
            initializable: $initializable,
        );
    }
}
