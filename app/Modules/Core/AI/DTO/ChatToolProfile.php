<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * A named tool subset for interactive chat turns.
 *
 * Profiles compose additively via `extends` — a profile inherits
 * all tools from its parent before adding its own.
 */
final readonly class ChatToolProfile
{
    /**
     * @param  string  $key  Profile identifier (e.g. 'chat-core')
     * @param  list<string>  $tools  Tool names owned by this profile (excludes inherited)
     * @param  string|null  $extends  Parent profile key to inherit tools from
     */
    public function __construct(
        public string $key,
        public array $tools,
        public ?string $extends = null,
    ) {}
}
