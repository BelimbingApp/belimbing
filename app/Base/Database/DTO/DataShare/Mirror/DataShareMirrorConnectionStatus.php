<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final readonly class DataShareMirrorConnectionStatus
{
    public function __construct(
        public bool $configured,
        public bool $available,
        public bool $reachable,
        public ?string $driver,
        public ?string $localRole,
        public ?string $remoteRole,
        public ?string $serverVersion,
        public ?string $pgDumpVersion,
        public ?string $psqlVersion,
        public ?string $reasonCode,
        public string $message,
        public ?string $providerKey = null,
        public ?string $providerLabel = null,
        public ?string $localDriver = null,
        public ?string $transferMode = null,
        public bool $initializable = false,
    ) {}

    /**
     * @return array{configured: bool, available: bool, reachable: bool, driver: string|null, local_role: string|null, remote_role: string|null, server_version: string|null, pg_dump_version: string|null, psql_version: string|null, reason_code: string|null, message: string, provider_key: string|null, provider_label: string|null, local_driver: string|null, transfer_mode: string|null, initializable: bool}
     */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'available' => $this->available,
            'reachable' => $this->reachable,
            'driver' => $this->driver,
            'local_role' => $this->localRole,
            'remote_role' => $this->remoteRole,
            'server_version' => $this->serverVersion,
            'pg_dump_version' => $this->pgDumpVersion,
            'psql_version' => $this->psqlVersion,
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'provider_key' => $this->providerKey,
            'provider_label' => $this->providerLabel,
            'local_driver' => $this->localDriver,
            'transfer_mode' => $this->transferMode,
            'initializable' => $this->initializable,
        ];
    }
}
