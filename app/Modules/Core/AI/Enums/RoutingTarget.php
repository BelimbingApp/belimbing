<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Classifies where a routing decision directs work.
 *
 * The routing engine returns one of these targets to indicate
 * whether work stays local, delegates to another agent, or
 * uses a specialized skill pack.
 */
enum RoutingTarget: string
{
    /** Execute locally within the current agent's runtime. */
    case Local = 'local';

    /** Delegate to another agent via spawn or dispatch. */
    case Agent = 'agent';

    /** Route through a skill pack's bundled execution path. */
    case SkillPack = 'skill_pack';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local Execution',
            self::Agent => 'Agent Delegation',
            self::SkillPack => 'Skill Pack',
        };
    }
}
