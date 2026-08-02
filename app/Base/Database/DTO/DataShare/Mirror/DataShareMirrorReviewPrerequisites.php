<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final readonly class DataShareMirrorReviewPrerequisites
{
    public function __construct(
        public array $sourceForeignKeys,
        public array $targetForeignKeys,
        public array $targetUniqueKeys,
        public array $sourceTypes,
        public array $targetTypes,
        public array $sourceFunctions,
        public array $targetFunctions,
    ) {}
}
