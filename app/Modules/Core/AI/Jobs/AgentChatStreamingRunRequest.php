<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ChatRunPersister;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\AI\Services\TurnStreamBridge;

/**
 * Bundles dependencies and identifiers for {@see RunAgentChatJob::executeStreamingRun()}.
 */
final readonly class AgentChatStreamingRunRequest
{
    /**
     * @param  list<mixed>  $messages
     * @param  array<string, mixed>|null  $promptMeta
     */
    public function __construct(
        public AgenticRuntime $runtime,
        public MessageManager $messageManager,
        public ChatRunPersister $persister,
        public TurnStreamBridge $bridge,
        public TurnEventPublisher $turnPublisher,
        public ChatTurn $turn,
        public OperationDispatch $dispatch,
        public int $employeeId,
        public string $sessionId,
        public array $messages,
        public ?string $systemPrompt,
        public ?array $promptMeta,
        public ?string $modelOverride,
    ) {}
}
