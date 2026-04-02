<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

use App\Modules\Core\AI\Enums\HookStage;

/**
 * Immutable payload delivered to a runtime hook at a given stage.
 *
 * Hooks receive this payload and return a HookResult with explicit
 * augmentations. They never mutate the payload directly.
 */
final readonly class HookPayload
{
    /**
     * @param  HookStage  $stage  Which stage triggered this hook
     * @param  string  $runId  Current run identifier
     * @param  int  $employeeId  Agent executing the run
     * @param  array<string, mixed>  $data  Stage-specific data (context, tool result, LLM response, etc.)
     */
    public function __construct(
        public HookStage $stage,
        public string $runId,
        public int $employeeId,
        public array $data = [],
    ) {}
}
