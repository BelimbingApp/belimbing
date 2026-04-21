<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\ChatToolProfile;

/**
 * Registry of tool profiles for interactive chat turns.
 *
 * Profiles control which tool definitions are sent to the LLM per
 * request. The default profile (`chat-core`) includes only navigational
 * and informational tools; broader profiles add data and action tools
 * additively via inheritance.
 *
 * `chat-full` is a special profile that disables filtering (all registered
 * tools are sent).
 */
class ChatToolProfileRegistry
{
    public const DEFAULT_PROFILE = 'chat-core';

    /** @var array<string, ChatToolProfile>|null */
    private ?array $profiles = null;

    /**
     * Resolve the allowed tool names for a profile key.
     *
     * Returns null for `chat-full` (meaning all tools).
     *
     * @param  string  $profileKey  Profile identifier
     * @return list<string>|null
     */
    public function resolve(string $profileKey): ?array
    {
        if ($profileKey === 'chat-full') {
            return null;
        }

        $profiles = $this->loadProfiles();
        $profile = $profiles[$profileKey] ?? null;

        if ($profile === null) {
            return null;
        }

        return $this->collectTools($profile, $profiles);
    }

    /**
     * Get all registered profile keys.
     *
     * @return list<string>
     */
    public function profileKeys(): array
    {
        return array_keys($this->loadProfiles());
    }

    /**
     * Recursively collect tool names from a profile and its ancestors.
     *
     * @param  array<string, ChatToolProfile>  $profiles
     * @return list<string>
     */
    private function collectTools(ChatToolProfile $profile, array $profiles): array
    {
        $tools = $profile->tools;

        if ($profile->extends !== null && isset($profiles[$profile->extends])) {
            $parentTools = $this->collectTools($profiles[$profile->extends], $profiles);
            $tools = array_values(array_unique(array_merge($parentTools, $tools)));
        }

        return $tools;
    }

    /**
     * @return array<string, ChatToolProfile>
     */
    private function loadProfiles(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        $definitions = [
            new ChatToolProfile(
                key: 'chat-core',
                tools: [
                    'active_page_snapshot',
                    'guide',
                    'memory_get',
                    'memory_search',
                    'navigate',
                    'system_info',
                    'visible_nav_menu',
                    'write_js',
                ],
            ),
            new ChatToolProfile(
                key: 'chat-data',
                tools: ['artisan', 'edit_data', 'query_data'],
                extends: 'chat-core',
            ),
            new ChatToolProfile(
                key: 'chat-action',
                tools: [
                    'agent_list',
                    'delegate_task',
                    'message',
                    'notification',
                    'schedule_task',
                    'ticket_update',
                ],
                extends: 'chat-data',
            ),
            new ChatToolProfile(
                key: 'chat-full',
                tools: [],
            ),
        ];

        $this->profiles = [];

        foreach ($definitions as $profile) {
            $this->profiles[$profile->key] = $profile;
        }

        return $this->profiles;
    }
}
