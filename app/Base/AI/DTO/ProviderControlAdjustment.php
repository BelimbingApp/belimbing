<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Enums\ProviderControlAdjustmentType;

final readonly class ProviderControlAdjustment
{
    public function __construct(
        public ProviderControlAdjustmentType $type,
        public string $control,
        public mixed $requestedValue = null,
        public mixed $appliedValue = null,
        public ?string $reason = null,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     control: string,
     *     requested_value: mixed,
     *     applied_value: mixed,
     *     reason: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'control' => $this->control,
            'requested_value' => $this->requestedValue,
            'applied_value' => $this->appliedValue,
            'reason' => $this->reason,
        ];
    }
}
