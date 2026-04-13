<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\LaraTaskType;

final readonly class LaraTaskDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public LaraTaskType $type,
        public string $description,
        public string $workloadDescription,
        public bool $runtimeReady = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'description' => $this->description,
            'workload_description' => $this->workloadDescription,
            'runtime_ready' => $this->runtimeReady,
        ];
    }
}
