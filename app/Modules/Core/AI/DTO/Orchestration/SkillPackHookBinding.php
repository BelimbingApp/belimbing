<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

use App\Modules\Core\AI\Enums\HookStage;

/**
 * A hook registration bundled within a skill pack.
 */
final readonly class SkillPackHookBinding
{
    /**
     * @param  HookStage  $stage  Which runtime stage this hook targets
     * @param  string  $hookClass  Fully qualified class name of the RuntimeHook implementation
     * @param  int  $priority  Execution priority (lower = earlier)
     */
    public function __construct(
        public HookStage $stage,
        public string $hookClass,
        public int $priority = 100,
    ) {}

    /**
     * @return array{stage: string, hook_class: string, priority: int}
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'hook_class' => $this->hookClass,
            'priority' => $this->priority,
        ];
    }
}
