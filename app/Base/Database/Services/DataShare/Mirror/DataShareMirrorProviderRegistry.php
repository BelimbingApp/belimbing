<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorProvider;
use App\Base\Database\Exceptions\DataShareMirrorException;

final class DataShareMirrorProviderRegistry
{
    /** @var array<string, DataShareMirrorProvider>|null */
    private ?array $providers = null;

    /** @param iterable<DataShareMirrorProvider> $taggedProviders */
    public function __construct(private readonly iterable $taggedProviders) {}

    public function get(string $key): DataShareMirrorProvider
    {
        $provider = $this->all()[$key] ?? null;

        if (! $provider instanceof DataShareMirrorProvider) {
            throw DataShareMirrorException::unavailable(__('The selected mirror provider is not installed.'));
        }

        return $provider;
    }

    /** @return array<string, DataShareMirrorProvider> */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];
        foreach ($this->taggedProviders as $provider) {
            $providers[$provider->key()] = $provider;
        }

        return $this->providers = $providers;
    }

    /** @return array<string, string> */
    public function options(): array
    {
        $options = [];
        foreach ($this->all() as $key => $provider) {
            $options[$key] = $provider->label();
        }

        return $options;
    }
}
