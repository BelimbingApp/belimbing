<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Services\KnowledgeNavigator;
use App\Base\AI\Services\ModelCatalogQueryService;
use App\Base\Foundation\Exceptions\BlbDataContractException;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\AI\DTO\Orchestration\RoutingRequest;
use App\Modules\Core\AI\Enums\RoutingTarget;
use App\Modules\Core\AI\Services\Orchestration\TaskRoutingService;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Str;

class LaraOrchestrationService
{
    public function __construct(
        private readonly ModelCatalogQueryService $modelCatalogQuery,
        private readonly KnowledgeNavigator $knowledgeNavigator,
        private readonly TaskRoutingService $router,
        private readonly LaraTaskDispatcher $taskDispatcher,
        private readonly LaraTaskProfileSelector $taskProfileSelector,
        private readonly LaraNavigationRouter $navigationRouter,
    ) {}

    /**
     * Parse and process Lara orchestration commands.
     *
     * Contract:
     * - Returns null when message is not a supported orchestration command.
     * - Returns orchestration response payload when command is handled.
     *
     * Supported command formats:
     *   /go <target>
     *   /models <filter expression>
     *   /guide <topic>
     *   /delegate <task description>
     *
     * @return array{assistant_content: string, run_id: string, meta: array<string, mixed>}|null
     */
    public function dispatchFromMessage(string $message): ?array
    {
        $response = $this->dispatchNavigationCommand($message)
            ?? $this->dispatchModelsCommand($message)
            ?? $this->dispatchGuideCommand($message);

        if ($response === null) {
            $task = $this->extractDelegationTask($message);

            if ($task === '') {
                $response = $this->response(
                    __('Use "/go <target>", "/models <filter>", "/guide <topic>", or "/delegate <task>".'),
                    ['status' => 'invalid_command'],
                );
            } elseif ($task !== null) {
                $response = $this->routeAndDispatchDelegation($task);
            }
        }

        return $response;
    }

    /**
     * @return array{assistant_content: string, run_id: string, meta: array<string, mixed>}
     */
    private function response(string $assistantContent, array $orchestrationMeta): array
    {
        return [
            'assistant_content' => $assistantContent,
            'run_id' => 'run_'.Str::random(12),
            'meta' => [
                'orchestration' => $orchestrationMeta,
            ],
        ];
    }

    private function extractDelegationTask(string $message): ?string
    {
        $trimmed = trim($message);

        if (! str_starts_with($trimmed, '/delegate')) {
            return null;
        }

        return BlbStr::afterPrefix($trimmed, '/delegate');
    }

    /**
     * Route a delegation task through TaskRoutingService and dispatch if matched.
     *
     * Replaces the legacy LaraCapabilityMatcher::matchBestForTask() path
     * with structured routing. Lara is always the requesting agent for
     * slash-command delegation.
     *
     * @return array{assistant_content: string, run_id: string, meta: array<string, mixed>}
     */
    private function routeAndDispatchDelegation(string $task): array
    {
        $request = new RoutingRequest(
            task: $task,
            requestingEmployeeId: Employee::LARA_ID,
            actingForUserId: auth()->id(),
            taskType: 'general',
            sourceContext: 'slash_delegate',
        );

        $decision = $this->router->route($request);

        if ($decision->target !== RoutingTarget::Agent || $decision->agentEmployeeId === null) {
            return $this->dispatchTaskProfile($task, 'general', $decision->reasons);
        }

        $dispatch = $this->taskDispatcher->dispatchForCurrentUser(
            $decision->agentEmployeeId,
            'general',
            $task,
        );

        return $this->response(
            __('Delegation queued to :agent (dispatch: :dispatch_id).', [
                'agent' => $decision->agentName ?? data_get($dispatch->meta, 'employee_name', 'Agent #'.$decision->agentEmployeeId),
                'dispatch_id' => $dispatch->id,
            ]),
            [
                'status' => 'queued',
                'selected_agent' => $decision->toArray(),
                'dispatch_id' => $dispatch->id,
            ],
        );
    }

    /**
     * @param  list<string>  $routingReasons
     * @return array{assistant_content: string, run_id: string, meta: array<string, mixed>}
     */
    private function dispatchTaskProfile(string $task, ?string $taskType, array $routingReasons): array
    {
        $selection = $this->taskProfileSelector->select($task, $taskType);

        if ($selection === null) {
            return $this->response(
                __('No Lara task profile is available to handle this delegation right now.'),
                [
                    'status' => 'no_task_profile_available',
                    'routing_reasons' => $routingReasons,
                ],
            );
        }

        $definition = $selection['definition'];
        $dispatch = $this->taskDispatcher->dispatchTaskProfileForCurrentUser(
            $definition->key,
            $task,
            ['source' => 'slash_delegate'],
        );

        return $this->response(
            __('Delegation queued to Lara :task (dispatch: :dispatch_id).', [
                'task' => $definition->label,
                'dispatch_id' => $dispatch->id,
            ]),
            [
                'status' => 'queued',
                'selected_task_profile' => [
                    'task_key' => $definition->key,
                    'label' => $definition->label,
                    'confidence' => $selection['confidence'],
                ],
                'routing_reasons' => [...$routingReasons, ...$selection['reasons']],
                'dispatch_id' => $dispatch->id,
            ],
        );
    }

    /**
     * @return list<array{title: string, path: string, summary: string}>
     */
    private function guideReferences(string $topic): array
    {
        return $this->knowledgeNavigator->search($topic);
    }

    /**
     * @param  list<array{title: string, path: string, summary: string}>  $references
     */
    private function guideResponseContent(string $topic, array $references): string
    {
        $lines = [__('Here are the most relevant BLB references for ":topic":', ['topic' => $topic])];

        foreach ($references as $reference) {
            $lines[] = '- '.$reference['title'].' ('.$reference['path'].'): '.$reference['summary'];
        }

        return implode("\n", $lines);
    }

    private function dispatchGuideReferences(string $topic): array
    {
        if ($topic === '') {
            return $this->response(
                __('Use "/guide <topic>" to find relevant BLB architecture and module references.'),
                ['status' => 'invalid_guide_command'],
            );
        }

        $references = $this->guideReferences($topic);
        if (count($references) === 0) {
            return $this->response(
                __('No BLB references matched ":topic". Try a broader topic such as "authorization", "database", or "Lara".', [
                    'topic' => $topic,
                ]),
                [
                    'status' => 'no_guide_matches',
                    'topic' => $topic,
                ],
            );
        }

        return $this->response(
            $this->guideResponseContent($topic, $references),
            [
                'status' => 'guide_references',
                'topic' => $topic,
                'references' => $references,
            ],
        );
    }

    private function extractGuideTopic(string $message): ?string
    {
        $trimmed = trim($message);

        return str_starts_with($trimmed, '/guide')
            ? BlbStr::afterPrefix($trimmed, '/guide')
            : null;
    }

    private function extractModelExpression(string $message): ?string
    {
        $trimmed = trim($message);

        return str_starts_with($trimmed, '/models')
            ? BlbStr::afterPrefix($trimmed, '/models')
            : null;

    }

    /**
     * @return list<array{provider: string, provider_display_name: string, model: string, name: string, family: string, reasoning: bool, tools: bool, open_weights: bool, input: list<string>, output: list<string>, category: list<string>, region: list<string>}>
     */
    private function modelMatches(string $expression): array
    {
        return $this->modelCatalogQuery->query($expression);
    }

    /**
     * @param  list<array{provider: string, provider_display_name: string, model: string, name: string, family: string, reasoning: bool, tools: bool, open_weights: bool, input: list<string>, output: list<string>, category: list<string>, region: list<string>}>  $matches
     */
    private function modelQueryResponseContent(string $expression, array $matches): string
    {
        $lines = [__('Found :count model(s) for filter ":filter":', [
            'count' => count($matches),
            'filter' => $expression,
        ])];

        foreach ($matches as $match) {
            $lines[] = '- ['.$match['provider'].'] '.$match['model']
                .' | family='.$match['family']
                .' | reasoning='.($match['reasoning'] ? 'yes' : 'no')
                .' | tools='.($match['tools'] ? 'yes' : 'no')
                .' | input='.implode(',', $match['input'])
                .' | output='.implode(',', $match['output']);
        }

        return implode("\n", $lines);
    }

    private function dispatchModelQuery(string $expression): array
    {
        $response = null;

        if ($expression === '') {
            $response = $this->response(
                __('Use "/models <filter>" with boolean operators, e.g. "/models reasoning:true AND (family:gpt OR family:claude)".'),
                ['status' => 'invalid_models_command'],
            );
        }

        if ($response === null) {
            try {
                $matches = $this->modelMatches($expression);
            } catch (BlbDataContractException $exception) {
                $response = $this->response(
                    $exception->getMessage(),
                    [
                        'status' => 'invalid_models_filter',
                        'filter' => $expression,
                        'context' => $exception->context,
                    ],
                );
            }
        }

        if ($response === null) {
            $response = count($matches) === 0
                ? $this->response(
                    __('No models matched ":filter".', ['filter' => $expression]),
                    [
                        'status' => 'no_model_matches',
                        'filter' => $expression,
                    ],
                )
                : $this->response(
                    $this->modelQueryResponseContent($expression, $matches),
                    [
                        'status' => 'model_query',
                        'filter' => $expression,
                        'matches' => $matches,
                    ],
                );
        }

        return $response;
    }

    private function dispatchNavigationCommand(string $message): ?array
    {
        $navigation = $this->navigationRouter->resolve($message);

        return $navigation === null
            ? null
            : $this->response($navigation['message'], $navigation);
    }

    private function dispatchModelsCommand(string $message): ?array
    {
        $modelExpression = $this->extractModelExpression($message);

        return $modelExpression === null
            ? null
            : $this->dispatchModelQuery($modelExpression);
    }

    private function dispatchGuideCommand(string $message): ?array
    {
        $guideTopic = $this->extractGuideTopic($message);

        return $guideTopic === null
            ? null
            : $this->dispatchGuideReferences($guideTopic);
    }
}
