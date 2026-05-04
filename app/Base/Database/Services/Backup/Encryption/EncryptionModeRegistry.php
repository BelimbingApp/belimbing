<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup\Encryption;

use App\Base\Database\Exceptions\BackupException;

/**
 * Registry for {@see EncryptionMode} implementations keyed by `backup.encryption.mode`.
 *
 * Core registers `none` and `app-key`. Extensions (under `extensions/`) may call
 * {@see register()} from a service provider `boot()` method to add modes such as
 * cloud KMS wrappers. The last registration for a given mode name wins.
 */
final class EncryptionModeRegistry
{
    /**
     * @var array<string, callable(array<string, mixed>): EncryptionMode>
     */
    private array $factories = [];

    /**
     * @param  callable(array<string, mixed>): EncryptionMode  $factory
     *    Receives the resolved `config('backup')` array.
     */
    public function register(string $mode, callable $factory): void
    {
        if ($mode === '') {
            throw new \InvalidArgumentException('Encryption mode name must be non-empty.');
        }

        $this->factories[$mode] = $factory;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function has(string $mode): bool
    {
        return isset($this->factories[$mode]);
    }

    /**
     * All registered mode names, in registration order.
     *
     * @return list<string>
     */
    public function modes(): array
    {
        return array_values(array_keys($this->factories));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function resolve(string $mode, array $config): EncryptionMode
    {
        if (! isset($this->factories[$mode])) {
            throw BackupException::configurationInvalid(
                "Unknown encryption mode '{$mode}'. Core ships 'none' and 'app-key'. Register other modes via EncryptionModeRegistry::register() from an extension service provider.",
            );
        }

        return ($this->factories[$mode])($config);
    }
}
