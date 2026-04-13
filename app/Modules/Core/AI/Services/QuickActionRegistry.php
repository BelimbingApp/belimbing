<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\PageContext;
use Illuminate\Support\Str;

class QuickActionRegistry
{
    /**
     * Get contextual quick actions for the given route.
     *
     * @param  string|null  $routeName  Current route name
     * @return list<array{label: string, prompt: string, icon: string}>
     */
    public function forRoute(?string $routeName): array
    {
        if ($routeName === null) {
            return $this->defaults();
        }

        $actions = $this->routeActions()[$routeName] ?? null;

        if ($actions !== null) {
            return $actions;
        }

        // Try prefix matching (e.g., 'admin.employees.*')
        foreach ($this->prefixActions() as $prefix => $prefixActions) {
            if (str_starts_with($routeName, $prefix)) {
                return $prefixActions;
            }
        }

        return $this->defaults();
    }

    /**
     * Get context-enhanced quick actions using page awareness data.
     *
     * When a PageContext is available, this method generates richer,
     * resource-specific prompts (e.g., "Tell me about John Doe" instead of
     * generic "Employee help"). Falls back to route-based resolution.
     *
     * @return list<array{label: string, prompt: string, icon: string}>
     */
    public function forContext(?PageContext $context): array
    {
        if ($context === null) {
            return $this->defaults();
        }

        $contextual = $this->contextualActions($context);

        if ($contextual !== []) {
            return $contextual;
        }

        return $this->forRoute($context->route);
    }

    /**
     * Build resource-specific actions from page context metadata.
     *
     * @return list<array{label: string, prompt: string, icon: string}>
     */
    private function contextualActions(PageContext $context): array
    {
        $actions = [];

        // Show-page actions: "Tell me about <resource>"
        if ($context->resourceId !== null && $context->title !== null) {
            $actions[] = [
                'label' => __('About this :type', ['type' => $context->resourceType ?? 'item']),
                'prompt' => 'Tell me about '.$context->title,
                'icon' => 'heroicon-o-information-circle',
            ];
        }

        // Surface visible actions as quick prompts
        foreach (array_slice($context->visibleActions, 0, 2) as $action) {
            $actions[] = [
                'label' => $action,
                'prompt' => 'Help me '.Str::lower($action),
                'icon' => 'heroicon-o-cursor-arrow-rays',
            ];
        }

        // Search-aware action for index pages
        if ($context->searchQuery !== null) {
            $actions[] = [
                'label' => __('Refine search'),
                'prompt' => 'I\'m searching for "'.$context->searchQuery.'" — help me find what I need',
                'icon' => 'heroicon-o-magnifying-glass',
            ];
        }

        return $actions;
    }

    /**
     * Default quick actions shown on any page.
     *
     * @return list<array{label: string, prompt: string, icon: string}>
     */
    private function defaults(): array
    {
        return [
            ['label' => __('What can you do?'), 'prompt' => 'What can you help me with?', 'icon' => 'heroicon-o-question-mark-circle'],
            ['label' => __('Navigate to...'), 'prompt' => '/go ', 'icon' => 'heroicon-o-arrow-top-right-on-square'],
        ];
    }

    /**
     * Route-specific quick actions (exact match).
     *
     * @return array<string, list<array{label: string, prompt: string, icon: string}>>
     */
    private function routeActions(): array
    {
        return [
            'admin.ai.providers' => [
                ['label' => __('Sync models'), 'prompt' => 'Sync all provider models', 'icon' => 'heroicon-o-arrow-path'],
                ['label' => __('Add provider'), 'prompt' => 'Help me set up a new AI provider', 'icon' => 'heroicon-o-plus'],
                ['label' => __('Compare providers'), 'prompt' => 'Compare my configured AI providers', 'icon' => 'heroicon-o-scale'],
            ],
            'admin.ai.task-models' => [
                ['label' => __('Recommend models'), 'prompt' => 'Recommend models for Lara\'s tasks', 'icon' => 'heroicon-o-sparkles'],
                ['label' => __('Model info'), 'prompt' => '/models', 'icon' => 'heroicon-o-cpu-chip'],
            ],
            'admin.ai.tools' => [
                ['label' => __('List tools'), 'prompt' => 'List all available tools and their capabilities', 'icon' => 'heroicon-o-wrench-screwdriver'],
            ],
            'admin.setup.lara' => [
                ['label' => __('Check status'), 'prompt' => 'Show me Lara\'s current configuration status', 'icon' => 'heroicon-o-signal'],
                ['label' => __('Guide: setup'), 'prompt' => '/guide lara setup', 'icon' => 'heroicon-o-book-open'],
            ],
            'admin.employees.index' => [
                ['label' => __('Create employee'), 'prompt' => 'Help me create a new employee', 'icon' => 'heroicon-o-user-plus'],
                ['label' => __('Employee overview'), 'prompt' => 'Give me an overview of the employee management module', 'icon' => 'heroicon-o-users'],
            ],
            'admin.roles.index' => [
                ['label' => __('Role overview'), 'prompt' => 'Explain the roles and capabilities system', 'icon' => 'heroicon-o-shield-check'],
            ],
            'admin.companies.index' => [
                ['label' => __('Company setup'), 'prompt' => 'Help me configure the company settings', 'icon' => 'heroicon-o-building-office'],
            ],
            'admin.system.logs' => [
                ['label' => __('Recent errors'), 'prompt' => 'Are there any recent errors I should know about?', 'icon' => 'heroicon-o-exclamation-triangle'],
            ],
        ];
    }

    /**
     * Prefix-based quick actions (matches route names starting with prefix).
     *
     * @return array<string, list<array{label: string, prompt: string, icon: string}>>
     */
    private function prefixActions(): array
    {
        return [
            'admin.ai.' => [
                ['label' => __('AI overview'), 'prompt' => 'Give me an overview of the AI module', 'icon' => 'heroicon-o-sparkles'],
                ['label' => __('Model info'), 'prompt' => '/models', 'icon' => 'heroicon-o-cpu-chip'],
            ],
            'admin.employees.' => [
                ['label' => __('Employee help'), 'prompt' => 'How do I manage employees in BLB?', 'icon' => 'heroicon-o-users'],
            ],
        ];
    }
}
