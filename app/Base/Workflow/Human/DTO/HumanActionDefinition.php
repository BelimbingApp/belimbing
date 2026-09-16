<?php

namespace App\Base\Workflow\Human\DTO;

use App\Base\Workflow\Human\Contracts\HumanActionHandler;
use InvalidArgumentException;

final readonly class HumanActionDefinition
{
    /** @param class-string<HumanActionHandler> $handler */
    public function __construct(
        public string $key,
        public string $label,
        public string $capability,
        public string $handler,
        public ?string $executorKey = null,
    ) {
        if (trim($key) === '' || trim($label) === '' || trim($capability) === '') {
            throw new InvalidArgumentException('Human actions need a stable key, label, and capability.');
        }
    }
}
