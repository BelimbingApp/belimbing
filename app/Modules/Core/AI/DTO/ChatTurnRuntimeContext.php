<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Runtime inputs needed to execute and persist a chat turn.
 */
final readonly class ChatTurnRuntimeContext
{
    /**
     * @param  list<mixed>  $messages
     * @param  array<string, mixed>|null  $promptMeta
     * @param  list<string>|null  $allowedToolNames  Tool profile allowlist (null = all tools)
     * @param  array<string, mixed>|null  $executionControlsOverride  Per-session execution-controls overlay (null = no override)
     */
    public function __construct(
        public int $employeeId,
        public string $sessionId,
        public array $messages,
        public ?string $systemPrompt,
        public ?string $modelOverride,
        public ExecutionPolicy $policy,
        public ?array $promptMeta,
        public ?array $allowedToolNames = null,
        public ?array $executionControlsOverride = null,
    ) {}
}
