<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class DataSharePolicyException extends RuntimeException
{
    public static function invalidRole(string $role): self
    {
        return new self(__('Unknown Data Share instance role: :role.', ['role' => $role]));
    }

    public static function directionDenied(string $source, string $target): self
    {
        return new self(__('Data Share direction :source → :target is denied by default policy.', [
            'source' => $source,
            'target' => $target,
        ]));
    }

    public static function offerNotRevocable(string $status): self
    {
        return new self(__('A Data Share transfer offer in :status status cannot be revoked.', ['status' => $status]));
    }

    public static function offerNotCopyable(string $status): self
    {
        return new self(__('A Data Share transfer offer in :status status cannot be copied.', ['status' => $status]));
    }

    public static function invalidMaximumDownloads(int $maximum): self
    {
        return new self(__('Data Share maximum fetches must be between 1 and :maximum, or unlimited.', [
            'maximum' => $maximum,
        ]));
    }

    public static function invalidOfferBaseUrl(string $url): self
    {
        return new self(__('Data Share offer endpoint configuration :url must use HTTPS without credentials, query, or fragment.', [
            'url' => $url,
        ]));
    }

    public static function tooManyOfferBaseUrls(int $maximum): self
    {
        return new self(__('Data Share may advertise at most :maximum receive base URLs.', ['maximum' => $maximum]));
    }

    public static function invalidSetting(string $key): self
    {
        return new self(__('Data Share setting :key has an invalid value. Correct it in Data Share Settings.', [
            'key' => $key,
        ]));
    }
}
