<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class DataShareApplyResult
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>|null  $backup
     */
    public function __construct(
        public string $packageId,
        public string $planHash,
        public array $counts,
        public ?array $backup,
    ) {}
}
