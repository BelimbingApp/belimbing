<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\Enums\ExecutionMode;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Shared run logic for executing a chat turn through the agentic runtime.
 *
 * Drives the streaming execution pipeline for a chat turn: hydrate
 * context → resolve prompt → stream events → persist transcript.
 *
 * Callers are responsible for authentication and turn creation —
 * this service operates on an existing ChatTurn.
 */
class ChatTurnRunner
{
    public function __construct(
        private readonly AgenticRuntime $runtime,
        private readonly MessageManager $messageManager,
        private readonly ChatRunPersister $persister,
        private readonly TurnStreamBridge $bridge,
        private readonly TurnEventPublisher $turnPublisher,
    ) {}

    /**
     * Execute a chat turn through the streaming runtime pipeline.
     *
     * Hydrates page context, resolves the prompt package and execution
     * policy, then drives the runtime stream. Each yielded event payload
     * is forwarded to the optional $onEvent callback. Checks for
     * cancellation between events and materializes the transcript on
     * completion.
     *
     * @param  ChatTurn  $turn  A turn in Queued/Booting status
     * @param  callable(array<string, mixed>): void|null  $onEvent  Optional callback for each event payload
     */
    public function run(ChatTurn $turn, ?callable $onEvent = null): void
    {
        $employeeId = (int) $turn->employee_id;
        $sessionId = (string) $turn->session_id;
        $runtimeMeta = $turn->runtime_meta ?? [];
        $modelOverride = $runtimeMeta['model_override'] ?? null;

        $this->hydratePageContext($turn);

        $messages = $this->messageManager->read($employeeId, $sessionId);
        [$systemPrompt, $promptMeta] = $this->resolvePromptPackage($employeeId, $messages);

        $policy = $this->resolveExecutionPolicy($turn);

        try {
            $runtimeStream = $this->runtime->runStream(
                $messages, $employeeId, $systemPrompt, $modelOverride,
                $policy, $sessionId, turnId: $turn->id,
            );

            $cancelled = false;

            foreach ($this->bridge->wrap($turn, $runtimeStream) as $payload) {
                if ($onEvent !== null) {
                    $onEvent($payload);
                }

                $turn->refresh();

                if ($turn->isCancelRequested()) {
                    if (! $turn->isTerminal()) {
                        $this->turnPublisher->turnCancelled($turn, 'User cancelled');
                    }

                    $cancelled = true;

                    break;
                }
            }

            if ($cancelled) {
                return;
            }

            $extraMeta = $promptMeta !== null ? ['prompt_package' => $promptMeta] : [];
            $this->persister->materializeFromTurn($turn->refresh(), $this->messageManager, $employeeId, $sessionId, $extraMeta);
        } catch (\Throwable $e) {
            report($e);

            $turn->refresh();

            if (! $turn->isTerminal()) {
                $this->turnPublisher->turnFailed($turn, 'runtime_exception', $e->getMessage());
            }

            $extraMeta = $promptMeta !== null ? ['prompt_package' => $promptMeta] : [];

            try {
                $this->persister->materializeFromTurn($turn->refresh(), $this->messageManager, $employeeId, $sessionId, $extraMeta);
            } catch (\Throwable) {
                // Best-effort materialization failed — swallow to preserve original exception
            }

            throw $e;
        }
    }

    /**
     * Hydrate the request-scoped PageContextHolder from the turn's runtime_meta.
     *
     * When a run originates from a page-aware context, the page snapshot
     * and consent level are stored in runtime_meta so the runtime can
     * access page-specific tools and prompts.
     */
    private function hydratePageContext(ChatTurn $turn): void
    {
        $pageContext = data_get($turn->runtime_meta, 'page_context');

        if (! is_array($pageContext)) {
            return;
        }

        $holder = app(PageContextHolder::class);
        $holder->setConsentLevel($pageContext['consent'] ?? 'page');

        if (isset($pageContext['context']) && is_array($pageContext['context'])) {
            $holder->setContext(PageContext::fromArray($pageContext['context']));
        }

        if (isset($pageContext['snapshot']) && is_array($pageContext['snapshot'])) {
            $holder->setSnapshot(PageSnapshot::fromArray($pageContext['snapshot']));
        }
    }

    /**
     * Build the system prompt and prompt metadata from persisted messages.
     *
     * Uses the last message content to build the prompt package so both
     * background and streaming paths produce identical system prompts.
     *
     * Returns [systemPrompt, promptMeta] or [null, null] for employees
     * without a dedicated prompt factory.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  list<mixed>  $messages  Persisted conversation messages
     * @return array{?string, ?array<string, mixed>}
     */
    private function resolvePromptPackage(int $employeeId, array $messages): array
    {
        if ($employeeId !== Employee::LARA_ID) {
            return [null, null];
        }

        $factory = app(LaraPromptFactory::class);
        $package = $factory->buildPackage($messages[count($messages) - 1]->content ?? '');

        return [
            app(Workspace\PromptRenderer::class)->render($package),
            $package->describe(),
        ];
    }

    /**
     * Resolve execution policy from the turn's runtime_meta.
     *
     * Reads `execution_mode` and converts to an ExecutionPolicy.
     * Falls back to interactive policy when absent.
     *
     * @param  ChatTurn  $turn  The turn with runtime_meta
     */
    private function resolveExecutionPolicy(ChatTurn $turn): ExecutionPolicy
    {
        $modeValue = data_get($turn->runtime_meta, 'execution_mode');

        if ($modeValue !== null) {
            $mode = ExecutionMode::from($modeValue);

            return ExecutionPolicy::forMode($mode);
        }

        return ExecutionPolicy::interactive();
    }
}
