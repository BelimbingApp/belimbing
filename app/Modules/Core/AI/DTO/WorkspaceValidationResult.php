<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\WorkspaceFileSlot;

/**
 * Result of validating a workspace manifest against runtime policy.
 *
 * Immutable once constructed. Reusable by tests, setup pages, and runtime.
 */
final readonly class WorkspaceValidationResult
{
    /**
     * @param  bool  $valid  Whether the workspace passes all required checks
     * @param  list<string>  $errors  Hard failures (missing required files, etc.)
     * @param  list<string>  $warnings  Non-blocking issues (missing optional files, etc.)
     * @param  list<WorkspaceFileSlot>  $loadOrder  Ordered slots that should be included in prompt assembly
     */
    public function __construct(
        public bool $valid,
        public array $errors,
        public array $warnings,
        public array $loadOrder,
    ) {}

    /**
     * Diagnostic array representation.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, load_order: list<string>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'load_order' => array_map(
                fn (WorkspaceFileSlot $slot): string => $slot->value,
                $this->loadOrder,
            ),
        ];
    }
}
