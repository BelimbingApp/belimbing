<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final readonly class DataShareMirrorRunCompletion
{
    /**
     * @param  array<string, string>  $expectedManifest
     * @param  array<string, int>  $capturedGenerations
     */
    public function __construct(
        public DataShareMirrorExecutionResult $result,
        public array $expectedManifest,
        public int $runId,
        public ?string $localInstanceId,
        public ?string $remoteInstanceId,
        public bool $isPush,
        public array $capturedGenerations,
    ) {}
}
