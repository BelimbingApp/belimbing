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
                $this->prompt(__('Sync models'), 'Sync all provider models', 'heroicon-o-arrow-path'),
                $this->prompt(__('Add provider'), 'Help me set up a new AI provider', 'heroicon-o-plus'),
                $this->prompt(__('Compare providers'), 'Compare my configured AI providers', 'heroicon-o-scale'),
            ],
            'admin.ai.task-models' => [
                $this->prompt(__('Recommend models'), 'Recommend models for Lara\'s tasks', 'heroicon-o-sparkles'),
                $this->prompt(__('Model info'), '/models', 'heroicon-o-cpu-chip'),
            ],
            'admin.ai.tools' => [
                $this->prompt(__('List tools'), 'List all available tools and their capabilities', 'heroicon-o-wrench-screwdriver'),
            ],
            'admin.setup.lara' => [
                $this->prompt(__('Check status'), 'Show me Lara\'s current configuration status', 'heroicon-o-signal'),
                $this->prompt(__('Guide: setup'), '/guide lara setup', 'heroicon-o-book-open'),
            ],
            'admin.employees.index' => [
                $this->prompt(__('Create employee'), 'Help me create a new employee', 'heroicon-o-user-plus'),
            ],
            'admin.roles.index' => [
                $this->prompt(__('Role overview'), 'Explain the roles and capabilities system', 'heroicon-o-shield-check'),
            ],
            'admin.companies.index' => [
                $this->prompt(__('Company setup'), 'Help me configure the company settings', 'heroicon-o-building-office'),
            ],
            'admin.system.logs' => [
                $this->prompt(__('Recent errors'), 'Are there any recent errors I should know about?', 'heroicon-o-exclamation-triangle'),
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
                $this->prompt(__('AI overview'), 'Give me an overview of the AI module', 'heroicon-o-sparkles'),
                $this->prompt(__('Model info'), '/models', 'heroicon-o-cpu-chip'),
            ],
            'admin.employees.' => [
                $this->prompt(__('Employee help'), 'How do I manage employees in Belimbing?', 'heroicon-o-users'),
            ],
        ];
    }

    /**
     * @return array{label: string, prompt: string, icon: string}
     */
    private function prompt(string $label, string $prompt, string $icon): array
    {
        return [
            'label' => $label,
            'prompt' => $prompt,
            'icon' => $icon,
        ];
    }
}
