<?php
namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\ChatTurnRuntimeContext;
use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\Enums\ExecutionMode;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\Runtime\AgenticRuntime;
use App\Modules\Core\AI\Services\Runtime\RuntimeInvocationContext;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Shared run logic for executing a chat-originated run through the agentic runtime.
 *
 * Drives the streaming execution pipeline for a chat run: hydrate
 * context → resolve prompt → stream events → persist transcript.
 *
 * Callers are responsible for authentication and run creation —
 * this service operates on an existing AiRun.
 */
class ChatTurnRunner
{
    public function __construct(
        private readonly AgenticRuntime $runtime,
        private readonly MessageManager $messageManager,
        private readonly ChatRunPersister $persister,
        private readonly RunStreamBridge $bridge,
        private readonly RunEventPublisher $turnPublisher,
        private readonly SessionManager $sessionManager,
    ) {}

    /**
     * Minimal default tool surface for interactive agents.
     *
     * Authz and tool guardrails still filter execution. This list only avoids
     * sending duplicate or non-essential tools to the model by default.
     *
     * @var list<string>
     */
    public const DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES = [
        'bash',
        'browser',
    ];

    /**
     * Execute a chat run through the streaming runtime pipeline.
     *
     * Hydrates page context, resolves the prompt package and execution
     * policy, then drives the runtime stream. Each yielded event payload
     * is forwarded to the optional $onEvent callback. Checks for
     * cancellation between events and materializes the transcript on
     * completion.
     *
     * @param  AiRun  $turn  A run in Queued/Booting status
     * @param  callable(array<string, mixed>): void|null  $onEvent  Optional callback for each event payload
     */
    public function run(AiRun $turn, ?callable $onEvent = null): void
    {
        $employeeId = (int) $turn->employee_id;
        $sessionId = (string) $turn->session_id;
        $runtimeMeta = $turn->runtime_meta ?? [];
        $modelOverride = $runtimeMeta['model_override'] ?? null;

        $this->hydratePageContext($turn);

        $messages = $this->messageManager->read($employeeId, $sessionId);
        [$systemPrompt, $promptMeta] = $this->resolvePromptPackage($employeeId, $messages);
        $runtimeContext = new ChatTurnRuntimeContext(
            employeeId: $employeeId,
            sessionId: $sessionId,
            messages: $messages,
            systemPrompt: $systemPrompt,
            modelOverride: $modelOverride,
            policy: $this->resolveExecutionPolicy($turn),
            promptMeta: $promptMeta,
            allowedToolNames: self::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES,
            executionControlsOverride: $this->sessionManager->getExecutionControlsOverride($employeeId, $sessionId),
        );

        try {
            $this->executeRuntimeStream($turn, $runtimeContext, $onEvent);
        } catch (\Throwable $e) {
            $this->handleRuntimeFailure($turn, $e, $runtimeContext);

            throw $e;
        }
    }

    /**
     * @param  callable(array<string, mixed>): void|null  $onEvent
     */
    private function executeRuntimeStream(
        AiRun $turn,
        ChatTurnRuntimeContext $runtimeContext,
        ?callable $onEvent,
    ): void {
        $runtimeStream = $this->runtime->runStream(
            $runtimeContext->messages,
            $runtimeContext->employeeId,
            $turn->id,
            $runtimeContext->systemPrompt,
            $runtimeContext->modelOverride,
            $runtimeContext->policy,
            $runtimeContext->sessionId,
            allowedToolNames: $runtimeContext->allowedToolNames,
            executionControlsOverride: $runtimeContext->executionControlsOverride,
            context: RuntimeInvocationContext::forChat(),
        );

        foreach ($this->bridge->wrap($turn, $runtimeStream) as $payload) {
            if ($onEvent !== null) {
                $onEvent($payload);
            }
        }

        $turn->refresh();

        $this->persister->materializeFromTurn(
            $turn,
            $this->messageManager,
            $runtimeContext->employeeId,
            $runtimeContext->sessionId,
            $this->promptPackageMeta($runtimeContext),
        );
    }

    private function handleRuntimeFailure(
        AiRun $turn,
        \Throwable $e,
        ChatTurnRuntimeContext $runtimeContext,
    ): void {
        report($e);

        $turn->refresh();

        if (! $turn->isTerminal()) {
            $this->turnPublisher->turnFailed($turn, 'runtime_exception', $e->getMessage());
        }

        try {
            $this->persister->materializeFromTurn(
                $turn->refresh(),
                $this->messageManager,
                $runtimeContext->employeeId,
                $runtimeContext->sessionId,
                $this->promptPackageMeta($runtimeContext),
            );
        } catch (\Throwable) {
            // Best-effort materialization failed — swallow to preserve original exception
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function promptPackageMeta(ChatTurnRuntimeContext $runtimeContext): array
    {
        return $runtimeContext->promptMeta !== null
            ? ['prompt_package' => $runtimeContext->promptMeta]
            : [];
    }

    /**
     * Hydrate the request-scoped PageContextHolder from the run's runtime_meta.
     *
     * When a run originates from a page-aware context, the page snapshot
     * and consent level are stored in runtime_meta so the runtime can
     * access page-specific tools and prompts.
     */
    private function hydratePageContext(AiRun $turn): void
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
     * Resolve execution policy from the run's runtime_meta.
     *
     * Reads `execution_mode` and converts to an ExecutionPolicy.
     * Falls back to interactive policy when absent.
     *
     * @param  AiRun  $turn  The run with runtime_meta
     */
    private function resolveExecutionPolicy(AiRun $turn): ExecutionPolicy
    {
        $modeValue = data_get($turn->runtime_meta, 'execution_mode');

        if ($modeValue !== null) {
            $mode = ExecutionMode::from($modeValue);

            return ExecutionPolicy::forMode($mode);
        }

        return ExecutionPolicy::interactive();
    }

}
