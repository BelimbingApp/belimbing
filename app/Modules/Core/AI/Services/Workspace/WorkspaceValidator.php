<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Workspace;

use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\DTO\WorkspaceValidationResult;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;

/**
 * Validates a resolved workspace manifest against BLB runtime policy.
 *
 * Checks required files, produces errors and warnings, and determines
 * the effective load order for prompt assembly.
 */
class WorkspaceValidator
{
    /**
     * Validate a workspace manifest.
     */
    public function validate(WorkspaceManifest $manifest): WorkspaceValidationResult
    {
        $errors = [];
        $warnings = [];
        $loadOrder = [];

        foreach (WorkspaceFileSlot::inLoadOrder() as $slot) {
            $entry = $manifest->entry($slot);
            $exists = $entry !== null && $entry->exists;
            $required = $slot->isRequired();

            if ($required && ! $exists) {
                $errors[] = "Required workspace file missing: {$slot->filename()} (slot: {$slot->value})";
            }

            if (! $required && ! $exists && $slot->isPromptContent()) {
                $warnings[] = "Optional workspace file absent: {$slot->filename()} (slot: {$slot->value})";
            }

            if ($exists && $slot->isPromptContent()) {
                $loadOrder[] = $slot;
            }
        }

        return new WorkspaceValidationResult(
            valid: $errors === [],
            errors: $errors,
            warnings: $warnings,
            loadOrder: $loadOrder,
        );
    }
}
