<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Menu;

readonly class MenuItem
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $icon = null,
        public ?string $route = null,
        public ?string $url = null,
        public ?string $parent = null,
        public ?string $permission = null,
        public ?string $condition = null,
        public ?string $sourceModule = null,
        public ?string $sourceFile = null,
    ) {}

    /**
     * Create MenuItem from array definition.
     *
     * @param  array  $data  Menu item array from menu.php (may include `_source`
     *                       metadata injected by MenuDiscoveryService)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            label: $data['label'],
            icon: $data['icon'] ?? null,
            route: $data['route'] ?? null,
            url: $data['url'] ?? null,
            parent: $data['parent'] ?? null,
            permission: $data['permission'] ?? null,
            condition: $data['condition'] ?? null,
            sourceModule: $data['_source']['module_name'] ?? $data['sourceModule'] ?? null,
            sourceFile: $data['_source']['file'] ?? $data['sourceFile'] ?? null,
        );
    }

    /**
     * Whether this item was provided by an extension (vs core).
     */
    public function isFromExtension(): bool
    {
        return $this->sourceFile !== null && str_starts_with($this->sourceFile, 'extensions/');
    }

    /**
     * Check if this item has a route (is navigable).
     */
    public function hasRoute(): bool
    {
        return ! is_null($this->route) || ! is_null($this->url);
    }

    /**
     * Check if this item is a container (no route).
     */
    public function isContainer(): bool
    {
        return ! $this->hasRoute();
    }
}
