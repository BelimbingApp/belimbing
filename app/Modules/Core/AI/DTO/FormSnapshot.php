<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Snapshot of a single form visible on the current page.
 */
final readonly class FormSnapshot
{
    /**
     * @param  string  $id  Form identifier (e.g. 'employee-edit')
     * @param  bool  $dirty  Whether the form has unsaved changes
     * @param  list<FormFieldSnapshot>  $fields  Visible fields after masking
     * @param  array<string, list<string>>  $errors  Validation errors keyed by field name
     */
    public function __construct(
        public string $id,
        public bool $dirty = false,
        public array $fields = [],
        public array $errors = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            dirty: $data['dirty'] ?? false,
            fields: array_map(FormFieldSnapshot::fromArray(...), $data['fields'] ?? []),
            errors: $data['errors'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'dirty' => $this->dirty ?: null,
            'fields' => $this->fields !== [] ? array_map(fn (FormFieldSnapshot $f): array => $f->toArray(), $this->fields) : null,
            'errors' => $this->errors !== [] ? $this->errors : null,
        ], fn (mixed $v): bool => $v !== null);
    }
}
