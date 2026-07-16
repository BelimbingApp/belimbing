<?php

namespace App\Base\Workflow\Process;

use App\Base\Workflow\Process\Definitions\ProcessDefinition;
use DomainException;

class ProcessDefinitionRegistry
{
    /** @var array<string, array<int, ProcessDefinition>> */
    private array $definitions = [];

    public function register(ProcessDefinition $definition): void
    {
        $existing = $this->definitions[$definition->key][$definition->version] ?? null;

        if ($existing !== null && $existing !== $definition) {
            throw new DomainException("Process definition [{$definition->key}:{$definition->version}] is already registered.");
        }

        $this->definitions[$definition->key][$definition->version] = $definition;
        ksort($this->definitions[$definition->key]);
    }

    public function get(string $key, ?int $version = null): ProcessDefinition
    {
        $versions = $this->definitions[$key] ?? [];
        $version ??= $versions === [] ? null : max(array_keys($versions));

        return ($version === null ? null : ($versions[$version] ?? null))
            ?? throw new DomainException("Process definition [{$key}".($version === null ? '' : ":{$version}").'] is not registered.');
    }
}
