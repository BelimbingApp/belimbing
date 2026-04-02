<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Snapshot of a modal dialog visible on the current page.
 */
final readonly class ModalSnapshot
{
    /**
     * @param  string  $id  Modal identifier
     * @param  string|null  $title  Modal title
     * @param  bool  $open  Whether the modal is currently open
     */
    public function __construct(
        public string $id,
        public ?string $title = null,
        public bool $open = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            title: $data['title'] ?? null,
            open: $data['open'] ?? false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'title' => $this->title,
            'open' => $this->open,
        ], fn (mixed $v): bool => $v !== null);
    }
}
