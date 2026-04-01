<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Workspace;

use App\Modules\Core\AI\DTO\PromptPackage;

/**
 * Renders a prompt package into a provider-ready system prompt string.
 *
 * Rendering is pure and deterministic. Section boundaries are marked
 * with labels for debugging. No side effects.
 */
class PromptRenderer
{
    /**
     * Render a prompt package into a final system prompt string.
     */
    public function render(PromptPackage $package): string
    {
        $parts = [];

        foreach ($package->sections as $section) {
            $parts[] = $section->content;
        }

        return implode("\n\n", $parts);
    }
}
