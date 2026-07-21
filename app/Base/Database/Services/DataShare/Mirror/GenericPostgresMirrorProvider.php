<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorProvider;

final readonly class GenericPostgresMirrorProvider implements DataShareMirrorProvider
{
    public function __construct(private PostgresMirrorConnectionUrl $urls) {}

    public function key(): string
    {
        return 'postgresql';
    }

    public function label(): string
    {
        return __('PostgreSQL');
    }

    public function description(): string
    {
        return __('A PostgreSQL database managed by the licensee or another cloud provider.');
    }

    public function connectionLabel(): string
    {
        return __('PostgreSQL mirror');
    }

    public function configuration(string $url): array
    {
        return $this->urls->parse($url);
    }
}
