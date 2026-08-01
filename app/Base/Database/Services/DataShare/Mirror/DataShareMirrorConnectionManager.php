<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\Contracts\DataShareMirrorProvider;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionContext;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
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
            return DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(false, false),
                'provider_unavailable',
                __('Choose an installed mirror provider in Data Share Settings.'),
            );
        }

        try {
            $url = $this->storedUrl();
        } catch (Throwable) {
            $this->purge();

            return DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(true, false),
                'credential_unreadable',
                __('The saved mirror credential cannot be decrypted with this instance APP_KEY. Replace it in Data Share Settings.'),
                $provider,
            );
        }

        if ($url === null) {
            $this->purge();

            return DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(false, false),
                'not_configured',
                __('Configure a :provider database connection in Data Share Settings.', ['provider' => $provider->label()]),
                $provider,
            );
        }

        return $this->inspectUrl($provider, $url, self::CONNECTION, purgeAfter: false);
    }

    public function testConnection(string $candidateUrl, ?string $providerKey = null): DataShareMirrorConnectionStatus
    {
        try {
            $provider = $providerKey === null ? $this->provider() : $this->providers->get($providerKey);
        } catch (Throwable) {
            return DataShareMirrorConnectionStatusFactory::unavailable(
                new DataShareMirrorConnectionContext(false, false),
                'provider_unavailable',
                __('Choose an installed mirror provider in Data Share Settings.'),
            );
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
        return (new DataShareMirrorConnectionInspector(
            $this->database,
            $this->identity,
            $this->processes,
        ))->inspect(
            $provider,
            $url,
            $connectionName,
            $purgeAfter,
            $this->availablePdoDrivers(),
        );
    }

    /** @return list<string> */
    protected function availablePdoDrivers(): array
    {
        return PDO::getAvailableDrivers();
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
}
