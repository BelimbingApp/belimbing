<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\LaraTaskExecutionProfile;
use App\Modules\Core\AI\Enums\ExecutionMode;

class LaraTaskExecutionProfileRegistry
{
    /**
     * @return list<LaraTaskExecutionProfile>
     */
    public function all(): array
    {
        return [
            new LaraTaskExecutionProfile(
                taskKey: 'coding',
                label: 'Coding',
                systemPromptPath: app_path('Modules/Core/AI/Resources/tasks/coding/system_prompt.md'),
                allowedToolNames: [
                    'active_page_snapshot',
                    'artisan',
                    'bash',
                    'edit_file',
                    'guide',
                    'memory_get',
                    'memory_search',
                    'memory_status',
                    'query_data',
                    'system_info',
                    'ticket_update',
                    'web_fetch',
                    'web_search',
                ],
                executionMode: ExecutionMode::Background,
            ),
        ];
    }

    public function find(string $taskKey): ?LaraTaskExecutionProfile
    {
        foreach ($this->all() as $profile) {
            if ($profile->taskKey === $taskKey) {
                return $profile;
            }
        }

        return null;
    }

    public function composeSystemPrompt(LaraTaskExecutionProfile $profile, string $basePrompt): string
    {
        $profilePrompt = $this->loadSystemPrompt($profile);

        return trim($basePrompt)."\n\nTask profile instructions:\n".$profilePrompt;
    }

    private function loadSystemPrompt(LaraTaskExecutionProfile $profile): string
    {
        $content = is_file($profile->systemPromptPath)
            ? file_get_contents($profile->systemPromptPath)
            : false;

        if (! is_string($content) || trim($content) === '') {
            return 'Focus on the assigned '.$profile->label.' task and use only tools allowed for this profile.';
        }

        return trim($content);
    }
}
