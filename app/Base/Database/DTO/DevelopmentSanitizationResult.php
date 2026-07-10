<?php

namespace App\Base\Database\DTO;

final readonly class DevelopmentSanitizationResult
{
    public function __construct(
        public string $key,
        public string $label,
        public int $affected,
        public string $detail,
    ) {}
}
