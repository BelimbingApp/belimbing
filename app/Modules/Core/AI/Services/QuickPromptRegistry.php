<?php

namespace App\Modules\Core\AI\Services;

class QuickPromptRegistry
{
    /**
     * Get contextual quick prompts for the given route.
     *
     * @param  string|null  $routeName  Current route name
     * @return list<array{label: string, prompt: string, icon: string}>
     */
    public function forRoute(?string $routeName): array
    {
        if ($routeName === null) {
            return $this->defaults();
        }

        $prompts = $this->routePrompts()[$routeName] ?? null;

        if ($prompts !== null) {
            return $prompts;
        }

        // Try prefix matching (e.g., 'admin.employees.*')
        foreach ($this->prefixPrompts() as $prefix => $prefixPrompts) {
            if (str_starts_with($routeName, $prefix)) {
                return $prefixPrompts;
            }
        }

        return $this->defaults();
    }

    /**
     * Default quick prompts shown on any page.
     *
     * @return list<array{label: string, prompt: string, icon: string}>
     */
    private function defaults(): array
    {
        return [];
    }

    /**
     * Route-specific quick prompts (exact match).
     *
     * @return array<string, list<array{label: string, prompt: string, icon: string}>>
     */
    private function routePrompts(): array
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
     * Prefix-based quick prompts (matches route names starting with prefix).
     *
     * @return array<string, list<array{label: string, prompt: string, icon: string}>>
     */
    private function prefixPrompts(): array
    {
        return [
            'admin.ai.' => [
                ['label' => __('AI overview'), 'prompt' => 'Give me an overview of the AI module', 'icon' => 'heroicon-o-sparkles'],
                ['label' => __('Model info'), 'prompt' => '/models', 'icon' => 'heroicon-o-cpu-chip'],
            ],
            'admin.employees.' => [
                ['label' => __('Employee help'), 'prompt' => 'How do I manage employees in Belimbing?', 'icon' => 'heroicon-o-users'],
            ],
        ];
    }
}
