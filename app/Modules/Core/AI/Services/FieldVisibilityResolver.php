<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Attributes\LaraVisible;
use App\Modules\Core\AI\DTO\FormFieldSnapshot;
use Livewire\Component;
use ReflectionClass;
use ReflectionProperty;
use SensitiveParameterValue;

/**
 * Resolves visible fields from a Livewire component's public properties.
 *
 * Applies #[LaraVisible] attribute rules and default masking conventions.
 * All masking is server-side — the resulting DTOs are safe for LLM consumption.
 */
class FieldVisibilityResolver
{
    /** Property names that are masked by default (no attribute needed). */
    private const SENSITIVE_NAMES = ['password', 'secret', 'token', 'api_key', 'apiKey'];

    /** Livewire internal properties excluded from snapshots. */
    private const LIVEWIRE_INTERNALS = [
        'id', 'paginators', 'page', 'queryString',
    ];

    /**
     * Resolve visible fields from a Livewire component's public properties.
     *
     * @return list<FormFieldSnapshot>
     */
    public static function resolveFields(Component $component): array
    {
        $reflection = new ReflectionClass($component);
        $fields = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (self::isLivewireInternal($property)) {
                continue;
            }

            $attribute = self::getAttribute($property);

            // Explicitly hidden via #[LaraVisible(false)]
            if ($attribute !== null && ! $attribute->visible) {
                continue;
            }

            $name = $property->getName();
            $value = $property->getValue($component);
            $type = self::resolveType($property, $value);
            $masked = self::shouldMask($property, $attribute, $value);
            $dirty = method_exists($component, 'isDirty') && $component->isDirty($name);

            $fields[] = new FormFieldSnapshot(
                name: $name,
                type: $type,
                value: $value,
                masked: $masked,
                dirty: $dirty,
            );
        }

        return $fields;
    }

    private static function getAttribute(ReflectionProperty $property): ?LaraVisible
    {
        $attributes = $property->getAttributes(LaraVisible::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    private static function shouldMask(ReflectionProperty $property, ?LaraVisible $attribute, mixed $value): bool
    {
        // Explicit attribute takes precedence
        if ($attribute !== null) {
            return $attribute->masked;
        }

        // Sensitive type
        if ($value instanceof SensitiveParameterValue) {
            return true;
        }

        // Sensitive name convention
        $name = $property->getName();

        foreach (self::SENSITIVE_NAMES as $sensitive) {
            if (strcasecmp($name, $sensitive) === 0) {
                return true;
            }

            if (str_contains(strtolower($name), strtolower($sensitive))) {
                return true;
            }
        }

        return false;
    }

    private static function isLivewireInternal(ReflectionProperty $property): bool
    {
        if ($property->getDeclaringClass()->getName() === Component::class) {
            return true;
        }

        return in_array($property->getName(), self::LIVEWIRE_INTERNALS, true);
    }

    private static function resolveType(ReflectionProperty $property, mixed $value): string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        return get_debug_type($value);
    }
}
