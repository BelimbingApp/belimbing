<?php

namespace App\Base\Settings\Services;

use App\Base\Settings\DTO\SettingDefinition;
use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;

final class SettingDefinitionRegistry
{
    /**
     * @var array<string, SettingDefinition>|null
     */
    private ?array $definitions = null;

    public function find(string $key): ?SettingDefinition
    {
        $definitions = $this->all();

        if (isset($definitions[$key])) {
            return $definitions[$key];
        }

        $patterns = array_filter(
            $definitions,
            fn (SettingDefinition $definition): bool => str_contains($definition->key, '*'),
        );
        uasort(
            $patterns,
            fn (SettingDefinition $left, SettingDefinition $right): int => strlen($right->key) <=> strlen($left->key),
        );

        foreach ($patterns as $definition) {
            if ($definition->matches($key)) {
                return $definition;
            }
        }

        return null;
    }

    public function get(string $key): SettingDefinition
    {
        return $this->find($key)
            ?? throw new InvalidSettingDefinitionException(
                "Setting [{$key}] has no discovered definition.",
            );
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $resolved = [];

        foreach ((array) config('settings.definitions', []) as $key => $attributes) {
            if (! is_string($key) || ! is_array($attributes)) {
                throw new InvalidSettingDefinitionException(
                    'Settings definitions must be keyed arrays.',
                );
            }

            $resolved[$key] = SettingDefinition::fromArray($key, $attributes);
        }

        return $this->definitions = $resolved;
    }

    public function refresh(): void
    {
        $this->definitions = null;
    }
}
