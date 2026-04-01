<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Canonical workspace file slots for agent workspace contracts.
 *
 * Each slot maps to a known file name within an agent's workspace directory.
 * System agents have framework-provided fallbacks; user-provisioned agents
 * use workspace-only resolution.
 */
enum WorkspaceFileSlot: string
{
    /**
     * Combined identity and behavior prompt. Primary behavioral source.
     */
    case SystemPrompt = 'system_prompt';

    /**
     * Optional operator/company/supervisor context for runtime inclusion.
     */
    case Operator = 'operator';

    /**
     * Optional environment and tool notes.
     */
    case Tools = 'tools';

    /**
     * Optional append-only extension guidance.
     */
    case Extension = 'extension';

    /**
     * Reserved for memory metadata. Phase 1 reads presence only.
     */
    case Memory = 'memory';

    /**
     * The file name within a workspace directory for this slot.
     */
    public function filename(): string
    {
        return match ($this) {
            self::SystemPrompt => 'system_prompt.md',
            self::Operator => 'operator.md',
            self::Tools => 'tools.md',
            self::Extension => 'extension.md',
            self::Memory => 'memory.md',
        };
    }

    /**
     * Load order index. Lower numbers are loaded first.
     */
    public function loadOrder(): int
    {
        return match ($this) {
            self::SystemPrompt => 0,
            self::Operator => 1,
            self::Tools => 2,
            self::Extension => 3,
            self::Memory => 4,
        };
    }

    /**
     * Whether this slot is required for the given agent class.
     *
     * @param  bool  $isSystemAgent  Whether the agent is framework-owned (Lara, Kodi)
     */
    public function isRequired(bool $isSystemAgent): bool
    {
        return match ($this) {
            self::SystemPrompt => true,
            default => false,
        };
    }

    /**
     * Whether this slot maps to a prompt section or is metadata-only.
     */
    public function isPromptContent(): bool
    {
        return match ($this) {
            self::Memory => false,
            default => true,
        };
    }

    /**
     * All slots in canonical load order.
     *
     * @return list<self>
     */
    public static function inLoadOrder(): array
    {
        $slots = self::cases();

        usort($slots, fn (self $a, self $b): int => $a->loadOrder() <=> $b->loadOrder());

        return $slots;
    }
}
