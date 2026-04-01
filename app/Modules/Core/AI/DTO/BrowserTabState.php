<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Represents the state of a single browser tab within a session.
 *
 * Immutable snapshot of a tab's current URL, title, and position.
 */
final readonly class BrowserTabState
{
    public function __construct(
        public string $tabId,
        public string $url,
        public string $title,
        public bool $isActive,
    ) {}

    /**
     * @param  array{tab_id: string, url: string, title: string, is_active: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tabId: $data['tab_id'],
            url: $data['url'],
            title: $data['title'] ?? '',
            isActive: $data['is_active'] ?? false,
        );
    }

    /**
     * @return array{tab_id: string, url: string, title: string, is_active: bool}
     */
    public function toArray(): array
    {
        return [
            'tab_id' => $this->tabId,
            'url' => $this->url,
            'title' => $this->title,
            'is_active' => $this->isActive,
        ];
    }
}
