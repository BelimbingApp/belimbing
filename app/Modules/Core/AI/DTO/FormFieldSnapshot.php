<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * A single form field visible to Lara.
 *
 * Built by FieldVisibilityResolver after applying #[LaraVisible] masking.
 */
final readonly class FormFieldSnapshot
{
    /**
     * @param  string  $name  Property/field name
     * @param  string  $type  PHP type or input type (e.g. 'string', 'int', 'boolean')
     * @param  mixed  $value  Current value (masked if applicable)
     * @param  bool  $masked  Whether the value has been masked
     * @param  bool  $dirty  Whether the field has unsaved changes
     */
    public function __construct(
        public string $name,
        public string $type,
        public mixed $value = null,
        public bool $masked = false,
        public bool $dirty = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type'],
            value: $data['value'] ?? null,
            masked: $data['masked'] ?? false,
            dirty: $data['dirty'] ?? false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->masked ? '••••••' : $this->value,
            'masked' => $this->masked ?: null,
            'dirty' => $this->dirty ?: null,
        ], fn (mixed $v): bool => $v !== null);
    }
}
