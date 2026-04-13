<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\LaraTaskDefinition;
use App\Modules\Core\AI\Enums\LaraTaskType;

class LaraTaskRegistry
{
    /**
     * @return list<LaraTaskDefinition>
     */
    public function all(): array
    {
        return [
            new LaraTaskDefinition(
                key: 'titling',
                label: 'Titling',
                type: LaraTaskType::Simple,
                description: 'Generate concise session titles and other short conversation labels.',
                workloadDescription: 'Short summarization into a concise 3-6 word title. Prefer low latency, compact output, and stable instruction-following.',
                runtimeReady: true,
            ),
            new LaraTaskDefinition(
                key: 'research',
                label: 'Research',
                type: LaraTaskType::Agentic,
                description: 'Handle deeper multi-step research, synthesis, and tool-assisted investigation.',
                workloadDescription: 'Multi-step investigation with tool use, synthesis, and broader context handling. Prefer models that stay coherent over longer runs.',
                runtimeReady: false,
            ),
            new LaraTaskDefinition(
                key: 'coding',
                label: 'Coding',
                type: LaraTaskType::Agentic,
                description: 'Handle code-focused work such as debugging, editing, and terminal-driven implementation.',
                workloadDescription: 'Multi-step code and CLI work with tool use, careful instruction-following, and robust editing/debugging behavior. Prefer models suited to coding agents.',
                runtimeReady: false,
            ),
        ];
    }

    public function find(string $key): ?LaraTaskDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(
            static fn (LaraTaskDefinition $definition): string => $definition->key,
            $this->all(),
        );
    }
}
