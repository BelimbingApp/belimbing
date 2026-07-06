<?php

namespace App\Base\Dashboard\DTO;

/**
 * A module-contributed dashboard widget definition.
 *
 * Declared in a module's `Config/dashboard.php` and discovered by
 * WidgetDiscoveryService. The `component` is the Livewire component name
 * (as registered by Base\Livewire component discovery) that renders the
 * widget; visibility is gated by `permission` against the authz service.
 */
final readonly class WidgetDefinition
{
    public function __construct(
        public string $id,
        public string $label,
        public string $component,
        public string $icon = 'heroicon-o-squares-2x2',
        public ?string $description = null,
        public ?string $permission = null,
        public int $size = 1,
    ) {}

    /**
     * Build a definition from a raw config array.
     *
     * Returns null when the entry is structurally unusable (missing id or
     * component); callers decide how to report that.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $component = $data['component'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($component) || $component === '') {
            return null;
        }

        $size = $data['size'] ?? 1;

        return new self(
            id: $id,
            label: is_string($data['label'] ?? null) ? $data['label'] : $id,
            component: $component,
            icon: is_string($data['icon'] ?? null) ? $data['icon'] : 'heroicon-o-squares-2x2',
            description: is_string($data['description'] ?? null) ? $data['description'] : null,
            permission: is_string($data['permission'] ?? null) ? $data['permission'] : null,
            size: is_int($size) ? max(1, min(3, $size)) : 1,
        );
    }
}
