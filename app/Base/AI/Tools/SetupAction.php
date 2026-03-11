<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools;

/**
 * Remediation action that can be presented to the user or handed off to Lara.
 *
 * Carries a human-readable label and a suggested prompt that Lara can act on.
 * The UI layer decides how to present this (overlay button, link, etc.) — this
 * DTO carries the "what", not the "where" or "how".
 */
final readonly class SetupAction
{
    /**
     * @param  string  $label  Button text (e.g. 'Ask Lara to set this up')
     * @param  string  $suggestedPrompt  Pre-filled prompt for Lara
     */
    public function __construct(
        public string $label,
        public string $suggestedPrompt,
    ) {}
}
