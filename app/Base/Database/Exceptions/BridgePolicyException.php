<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class BridgePolicyException extends RuntimeException
{
    public static function invalidRole(string $role): self
    {
        return new self(__('Unknown Data Bridge instance role: :role.', ['role' => $role]));
    }

    public static function directionDenied(string $source, string $target): self
    {
        return new self(__('Data Bridge direction :source → :target is denied by default policy.', [
            'source' => $source,
            'target' => $target,
        ]));
    }

    public static function grantNotRevocable(string $status): self
    {
        return new self(__('A Data Bridge receive grant in :status status cannot be revoked.', ['status' => $status]));
    }

    public static function invalidReceiveBaseUrl(string $url): self
    {
        return new self(__('Data Bridge receive endpoint configuration :url must use HTTPS without credentials, query, or fragment.', [
            'url' => $url,
        ]));
    }

    public static function tooManyReceiveBaseUrls(int $maximum): self
    {
        return new self(__('Data Bridge may advertise at most :maximum receive base URLs.', ['maximum' => $maximum]));
    }

    public static function invalidSetting(string $key): self
    {
        return new self(__('Data Bridge setting :key has an invalid value. Correct it in Data Bridge Settings.', [
            'key' => $key,
        ]));
    }
}
