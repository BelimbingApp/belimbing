<?php

namespace App\Base\Database\Contracts;

interface DataShareMirrorProvider
{
    public const CONTAINER_TAG = 'data-share-mirror-provider';

    public function key(): string;

    public function label(): string;

    public function description(): string;

    public function connectionLabel(): string;

    /** @return array<string, mixed> */
    public function configuration(string $url): array;
}
