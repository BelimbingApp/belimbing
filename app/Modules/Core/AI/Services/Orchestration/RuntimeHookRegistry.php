<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\Contracts\Orchestration\RuntimeHook;
use App\Modules\Core\AI\Enums\HookStage;

/**
 * Registry for runtime extension hooks.
 *
 * Hooks are registered per-stage with explicit priorities. The registry
 * stores hooks and provides ordered retrieval. It does NOT execute
 * hooks — that is RuntimeHookRunner's responsibility.
 *
 * Hooks with the same priority execute in registration order (stable).
 * Framework hooks typically use priority 50-99, application hooks
 * use 100-199, and late-stage observers use 200+.
 */
class RuntimeHookRegistry
{
    /**
     * Registered hooks grouped by stage.
     *
     * @var array<string, list<RuntimeHook>>
     */
    private array $hooks = [];

    /**
     * Whether the per-stage hook lists are currently sorted.
     *
     * @var array<string, bool>
     */
    private array $sorted = [];

    /**
     * Register a runtime hook.
     *
     * @throws \InvalidArgumentException When a hook with the same identifier is already registered
     */
    public function register(RuntimeHook $hook): void
    {
        $stage = $hook->stage()->value;

        // Check for duplicate identifiers across all stages
        if ($this->hasIdentifier($hook->identifier())) {
            throw new \InvalidArgumentException(
                'Runtime hook "'.$hook->identifier().'" is already registered.',
            );
        }

        $this->hooks[$stage][] = $hook;
        $this->sorted[$stage] = false;
    }

    /**
     * Unregister a hook by its identifier.
     *
     * Returns true if found and removed, false otherwise.
     */
    public function unregister(string $identifier): bool
    {
        foreach ($this->hooks as $stage => &$hooks) {
            foreach ($hooks as $index => $hook) {
                if ($hook->identifier() === $identifier) {
                    array_splice($hooks, $index, 1);

                    // Re-index to maintain list semantics
                    $hooks = array_values($hooks);
                    $this->sorted[$stage] = false;

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all hooks for a given stage, sorted by priority.
     *
     * @return list<RuntimeHook>
     */
    public function forStage(HookStage $stage): array
    {
        $stageKey = $stage->value;

        if (! isset($this->hooks[$stageKey]) || $this->hooks[$stageKey] === []) {
            return [];
        }

        if (! ($this->sorted[$stageKey] ?? false)) {
            usort(
                $this->hooks[$stageKey],
                fn (RuntimeHook $a, RuntimeHook $b): int => $a->priority() <=> $b->priority(),
            );
            $this->sorted[$stageKey] = true;
        }

        return $this->hooks[$stageKey];
    }

    /**
     * Whether any hooks are registered for a given stage.
     */
    public function hasHooksFor(HookStage $stage): bool
    {
        return isset($this->hooks[$stage->value]) && $this->hooks[$stage->value] !== [];
    }

    /**
     * Whether a hook with the given identifier is registered.
     */
    public function hasIdentifier(string $identifier): bool
    {
        foreach ($this->hooks as $hooks) {
            foreach ($hooks as $hook) {
                if ($hook->identifier() === $identifier) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Total number of registered hooks across all stages.
     */
    public function count(): int
    {
        $total = 0;

        foreach ($this->hooks as $hooks) {
            $total += count($hooks);
        }

        return $total;
    }

    /**
     * Get a diagnostic summary of registered hooks.
     *
     * @return array<string, list<array{identifier: string, priority: int}>>
     */
    public function summary(): array
    {
        $result = [];

        foreach (HookStage::cases() as $stage) {
            $hooks = $this->forStage($stage);

            if ($hooks === []) {
                continue;
            }

            $result[$stage->value] = array_map(
                fn (RuntimeHook $hook): array => [
                    'identifier' => $hook->identifier(),
                    'priority' => $hook->priority(),
                ],
                $hooks,
            );
        }

        return $result;
    }
}
