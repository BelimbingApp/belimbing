<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

use App\Base\Database\Enums\DataShareMirrorDirection;
use Illuminate\Database\Connection;

final readonly class DataShareMirrorReviewContext
{
    public function __construct(
        public DataShareMirrorDirection $direction,
        public Connection $source,
        public Connection $target,
        public bool $portable,
        public ?array $portableOrder,
        public array $selected,
        public DataShareMirrorReviewPrerequisites $prerequisites,
    ) {}
}
