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
    /** The narrow rail: one column of the three-column dashboard grid. */
    public const SIZE_NARROW = 1;

    /** The wide column: two columns of the three-column dashboard grid. */
    public const SIZE_WIDE = 2;

    public function __construct(
        public string $id,
        public string $label,
        public string $component,
        public string $icon = 'heroicon-o-squares-2x2',
        public ?string $description = null,
        public ?string $permission = null,
        public int $size = self::SIZE_NARROW,
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

        $size = $data['size'] ?? self::SIZE_NARROW;

        return new self(
            id: $id,
            label: is_string($data['label'] ?? null) ? $data['label'] : $id,
            component: $component,
            icon: is_string($data['icon'] ?? null) ? $data['icon'] : 'heroicon-o-squares-2x2',
            description: is_string($data['description'] ?? null) ? $data['description'] : null,
            permission: is_string($data['permission'] ?? null) ? $data['permission'] : null,
            size: is_int($size) ? max(self::SIZE_NARROW, min(self::SIZE_WIDE, $size)) : self::SIZE_NARROW,
        );
    }
}
