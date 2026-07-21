<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorEngine;
use App\Base\Database\Exceptions\DataShareMirrorException;

class DataShareMirrorEngineRegistry
{
    /** @var array<string, DataShareMirrorEngine>|null */
    private ?array $engines = null;

    /** @param iterable<DataShareMirrorEngine> $taggedEngines */
    public function __construct(private readonly iterable $taggedEngines) {}

    public function forMode(?string $mode): DataShareMirrorEngine
    {
        $engine = $this->all()[$mode ?? ''] ?? null;
        if (! $engine instanceof DataShareMirrorEngine) {
            throw DataShareMirrorException::unavailable(__('No mirror engine is available for the negotiated transfer mode.'));
        }

        return $engine;
    }

    /** @return array<string, DataShareMirrorEngine> */
    private function all(): array
    {
        if ($this->engines !== null) {
            return $this->engines;
        }

        $engines = [];
        foreach ($this->taggedEngines as $engine) {
            $engines[$engine->mode()] = $engine;
        }

        return $this->engines = $engines;
    }
}
