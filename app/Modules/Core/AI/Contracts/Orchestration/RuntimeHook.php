<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Contracts\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\HookPayload;
use App\Modules\Core\AI\DTO\Orchestration\HookResult;
use App\Modules\Core\AI\Enums\HookStage;

/**
 * Contract for runtime extension hooks.
 *
 * Implementations receive an immutable HookPayload at a declared
 * stage and return a HookResult with explicit augmentations. Hooks
 * must not produce side effects outside the scope of their returned
 * result — the runtime enforces this by controlling what gets merged.
 *
 * Registration order and priority determine execution sequence.
 * Hooks with the same priority execute in registration order.
 */
interface RuntimeHook
{
    /**
     * Which runtime stage this hook runs at.
     */
    public function stage(): HookStage;

    /**
     * Execution priority. Lower values execute first.
     *
     * Default priority is 100. Framework hooks typically use 50-99.
     * Application hooks use 100-199. Late-stage observers use 200+.
     */
    public function priority(): int;

    /**
     * Unique identifier for this hook (used in metadata and diagnostics).
     */
    public function identifier(): string;

    /**
     * Execute the hook logic.
     *
     * Receives an immutable payload. Returns a HookResult describing
     * what augmentations (if any) should be merged into the runtime.
     */
    public function execute(HookPayload $payload): HookResult;
}
