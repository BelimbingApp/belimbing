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
                routingKeywords: [
                    'research',
                    'investigate',
                    'investigation',
                    'look up',
                    'find out',
                    'compare',
                    'survey',
                    'synthesize',
                    'docs',
                    'documentation',
                    'latest',
                    'summarize findings',
                ],
                routingTaskTypes: [
                    'research',
                    'investigation',
                    'analysis',
                    'deep_research',
                ],
                runtimeReady: true,
            ),
            new LaraTaskDefinition(
                key: 'photo-cleanup',
                label: 'Photo Cleanup',
                type: LaraTaskType::Simple,
                description: 'Clean product photos, including background removal, before operator review.',
                workloadDescription: 'Image cleanup and background-removal work. Prefer multimodal or image-capable models with strong visual segmentation behavior, predictable cost, and batch-friendly latency.',
                runtimeReady: false,
            ),
            new LaraTaskDefinition(
                key: 'describe-item',
                label: 'Describe Item',
                type: LaraTaskType::Simple,
                description: 'Draft item titles, descriptions, and category suggestions from photos and catalog attributes.',
                workloadDescription: 'Commerce listing copy from item photos, attributes, and seller notes. Prefer models with strong vision-language grounding, structured output, and concise buyer-facing writing.',
                runtimeReady: false,
            ),
            new LaraTaskDefinition(
                key: 'coding',
                label: 'Coding',
                type: LaraTaskType::Agentic,
                description: 'Handle code-focused work such as debugging, editing, and terminal-driven implementation.',
                workloadDescription: 'Multi-step code and CLI work with tool use, careful instruction-following, and robust editing/debugging behavior. Prefer models suited to coding agents.',
                routingKeywords: [
                    'build',
                    'code',
                    'debug',
                    'fix',
                    'implement',
                    'refactor',
                    'test',
                    'php',
                    'laravel',
                    'livewire',
                    'blade',
                    'migration',
                    'module',
                    'patch',
                    'cli',
                    'terminal',
                ],
                routingTaskTypes: [
                    'coding',
                    'implementation',
                    'debugging',
                    'code_review',
                ],
                runtimeReady: true,
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
