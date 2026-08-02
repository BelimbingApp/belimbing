<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorProvider;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionContext;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;

final class DataShareMirrorConnectionStatusFactory
{
    public static function unavailable(
        DataShareMirrorConnectionContext $context,
        string $reasonCode,
        string $message,
        ?DataShareMirrorProvider $provider = null,
        bool $initializable = false,
    ): DataShareMirrorConnectionStatus {
        if ($context->localDriver === 'sqlite') {
            $transferMode = 'portable';
        } elseif ($context->localDriver === 'pgsql') {
            $transferMode = 'native';
        } else {
            $transferMode = null;
        }

        return new DataShareMirrorConnectionStatus(
            configured: $context->configured,
            available: false,
            reachable: $context->reachable,
            driver: $context->driver,
            localRole: $context->localRole,
            remoteRole: $context->remoteRole,
            serverVersion: $context->serverVersion,
            pgDumpVersion: $context->pgDumpVersion,
            psqlVersion: $context->psqlVersion,
            reasonCode: $reasonCode,
            message: $message,
            providerKey: $provider?->key(),
            providerLabel: $provider?->label(),
            localDriver: $context->localDriver,
            transferMode: $transferMode,
            initializable: $initializable,
        );
    }
}
