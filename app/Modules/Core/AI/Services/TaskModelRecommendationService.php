<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\Enums\ExecutionMode;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\Runtime\AgenticRuntime;
use App\Modules\Core\AI\Services\Runtime\RuntimeInvocationContext;
use App\Modules\Core\Company\Models\Company;

class TaskModelRecommendationService
{
    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly AgenticRuntime $agenticRuntime,
        private readonly LaraTaskRegistry $taskRegistry,
    ) {}

    /**
     * @return array{provider: string, model: string, reason: string}|array{error: string}
     */
    public function recommend(int $employeeId, string $taskKey): array
    {
        $task = $this->taskRegistry->find($taskKey);

        if ($task === null) {
            return ['error' => 'Unknown task.'];
        }

        $config = $this->configResolver->resolveDefault($employeeId);

        if ($config === null) {
            return ['error' => 'Lara has no default model configured yet.'];
        }

        $candidates = $this->candidateList();

        if ($candidates === []) {
            return ['error' => 'No active provider models are available for recommendation.'];
        }

        $response = $this->agenticRuntime->run(
            messages: [[
                'role' => 'user',
                'content' => $this->buildPrompt($task->label, $task->type->value, $task->workloadDescription, $candidates),
            ]],
            employeeId: $employeeId,
            systemPrompt: 'Choose the single best model for the named Lara task from the provided candidates. '
                .'Return strict JSON only: {"provider":"...","model":"...","reason":"..."} '
                .'Use provider and model values exactly as listed in the candidates. '
                .'Keep reason under 18 words.',
            policy: new ExecutionPolicy(
                mode: ExecutionMode::Interactive,
                timeoutSeconds: 30,
            ),
            configOverride: $config,
            allowedToolNames: [],
            executionControlsOverride: ['limits' => ['max_output_tokens' => 120]],
            context: new RuntimeInvocationContext(
                source: 'core_ai_task_model_recommendation',
                taskKey: $taskKey,
            ),
        );

        if (isset($response['meta']['error_type'])) {
            $runtimeError = $this->runtimeErrorFromResult($response);

            $fallback = $this->fallbackRecommendationForRuntimeError(
                $runtimeError,
                $config,
                $candidates,
            );

            if ($fallback !== null) {
                return $fallback;
            }

            return ['error' => $runtimeError->userMessage];
        }

        return $this->normalizeRecommendation((string) ($response['content'] ?? ''), $candidates);
    }

    /**
     * @param  array{meta?: array<string, mixed>}  $result
     */
    private function runtimeErrorFromResult(array $result): AiRuntimeError
    {
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $type = AiErrorType::tryFrom((string) ($meta['error_type'] ?? '')) ?? AiErrorType::UnexpectedError;

        return new AiRuntimeError(
            errorType: $type,
            userMessage: is_string($meta['error'] ?? null) ? $meta['error'] : null,
            diagnostic: is_string($meta['diagnostic'] ?? null) ? $meta['diagnostic'] : '',
            latencyMs: (int) ($meta['latency_ms'] ?? 0),
        );
    }

    /**
     * @return list<array{provider: string, model: string, display: string}>
     */
    private function candidateList(): array
    {
        $providers = AiProvider::query()
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->orderBy('display_name')
            ->get(['id', 'name', 'display_name']);

        $candidates = [];

        foreach ($providers as $provider) {
            $models = AiProviderModel::query()
                ->where('ai_provider_id', $provider->id)
                ->active()
                ->orderByDesc('is_default')
                ->orderBy('model_id')
                ->get(['model_id']);

            foreach ($models as $model) {
                $candidates[] = [
                    'provider' => $provider->name,
                    'model' => $model->model_id,
                    'display' => $provider->display_name.'/'.$model->model_id,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param  list<array{provider: string, model: string, display: string}>  $candidates
     */
    private function buildPrompt(
        string $taskLabel,
        string $taskType,
        string $workloadDescription,
        array $candidates,
    ): string {
        $lines = [
            'Task: '.$taskLabel,
            'Type: '.$taskType,
            'Workload: '.$workloadDescription,
            'Candidates:',
        ];

        foreach ($candidates as $candidate) {
            $lines[] = '- provider='.$candidate['provider'].', model='.$candidate['model'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{provider: string, model: string, display: string}>  $candidates
     * @return array{provider: string, model: string, reason: string}|array{error: string}
     */
    private function normalizeRecommendation(string $content, array $candidates): array
    {
        $decoded = $this->decodeRecommendationPayload($content);

        if ($decoded === null) {
            $fallback = $this->extractRecommendationFromText($content, $candidates);

            return $fallback ?? ['error' => 'Lara could not parse the recommendation response.'];
        }

        $provider = is_string($decoded['provider'] ?? null) ? $decoded['provider'] : null;
        $model = is_string($decoded['model'] ?? null) ? $decoded['model'] : null;
        $reason = is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';

        if ($provider === null || $model === null) {
            return ['error' => 'Lara returned an incomplete recommendation.'];
        }

        foreach ($candidates as $candidate) {
            if ($candidate['provider'] === $provider && $candidate['model'] === $model) {
                return [
                    'provider' => $provider,
                    'model' => $model,
                    'reason' => $reason,
                ];
            }
        }

        return ['error' => 'Lara recommended a model that is not currently connected.'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeRecommendationPayload(string $content): ?array
    {
        $trimmed = trim($content);
        $decoded = BlbJson::decodeArray($trimmed);

        if ($decoded !== null) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $trimmed, $fenced) === 1) {
            $decoded = BlbJson::decodeArray(trim($fenced[1]));

            if ($decoded !== null) {
                return $decoded;
            }
        }

        foreach (BlbJson::braceBoundedObjectCandidates($trimmed) as $candidateJson) {
            $decoded = BlbJson::decodeArray(trim($candidateJson));

            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  list<array{provider: string, model: string, display: string}>  $candidates
     * @return array{provider: string, model: string, reason: string}|null
     */
    private function extractRecommendationFromText(string $content, array $candidates): ?array
    {
        $provider = $this->matchField($content, 'provider');
        $model = $this->matchField($content, 'model');
        $reason = $this->matchField($content, 'reason') ?? '';

        if ($provider !== null && $model !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate['provider'] === $provider && $candidate['model'] === $model) {
                    return [
                        'provider' => $provider,
                        'model' => $model,
                        'reason' => $reason,
                    ];
                }
            }
        }

        $lowerContent = mb_strtolower($content);

        foreach ($candidates as $candidate) {
            $pair = mb_strtolower($candidate['provider'].'/'.$candidate['model']);

            if (str_contains($lowerContent, $pair)) {
                return [
                    'provider' => $candidate['provider'],
                    'model' => $candidate['model'],
                    'reason' => $this->extractReasonAroundPair($content, $candidate['provider'], $candidate['model']),
                ];
            }
        }

        return null;
    }

    private function matchField(string $content, string $field): ?string
    {
        $pattern = '/'.$field.'\s*[:=]\s*("?)([^\n",;]+)\1/i';

        if (preg_match($pattern, $content, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[2]);

        return $value !== '' ? $value : null;
    }

    private function extractReasonAroundPair(string $content, string $provider, string $model): string
    {
        $reason = $this->matchField($content, 'reason');

        if ($reason !== null) {
            return $reason;
        }

        $content = trim(preg_replace(
            '/'.preg_quote($provider, '/').'\/'.preg_quote($model, '/').'/i',
            '',
            $content,
            1,
        ) ?? $content);

        $content = preg_replace('/^(i recommend|recommendation|recommended)\s*[:\-]?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;

        return trim($content, " \t\n\r\0\x0B-:.");
    }

    /**
     * @param  array{model: string, provider_name: string|null}  $config
     * @param  list<array{provider: string, model: string, display: string}>  $candidates
     * @return array{provider: string, model: string, reason: string}|null
     */
    private function fallbackRecommendationForRuntimeError(
        AiRuntimeError $runtimeError,
        array $config,
        array $candidates,
    ): ?array {
        if ($runtimeError->errorType !== AiErrorType::EmptyResponse) {
            return null;
        }

        $provider = is_string($config['provider_name'] ?? null) ? $config['provider_name'] : null;
        $model = is_string($config['model'] ?? null) ? $config['model'] : null;

        if ($provider === null || $model === null) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate['provider'] === $provider && $candidate['model'] === $model) {
                return [
                    'provider' => $provider,
                    'model' => $model,
                    'reason' => 'Kept Lara primary after empty recommendation reply.',
                ];
            }
        }

        return null;
    }
}
