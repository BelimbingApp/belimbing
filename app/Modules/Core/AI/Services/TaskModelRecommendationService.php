<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\Company\Models\Company;

class TaskModelRecommendationService
{
    public function __construct(
        private readonly ConfigResolver $configResolver,
        private readonly LlmClient $llmClient,
        private readonly LaraTaskRegistry $taskRegistry,
        private readonly RuntimeCredentialResolver $credentialResolver,
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

        $config = $this->configResolver->resolvePrimaryWithDefaultFallback($employeeId);

        if ($config === null) {
            return ['error' => 'Lara does not have a primary model configured yet.'];
        }

        $credentials = $this->credentialResolver->resolve($config);

        if (isset($credentials['runtime_error'])) {
            return ['error' => $credentials['runtime_error']->userMessage ?? $credentials['runtime_error']->diagnostic];
        }

        $candidates = $this->candidateList();

        if ($candidates === []) {
            return ['error' => 'No active provider models are available for recommendation.'];
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'Choose the single best model for the named Lara task from the provided candidates. '
                    .'Return strict JSON only: {"provider":"...","model":"...","reason":"..."} '
                    .'Use provider and model values exactly as listed in the candidates. '
                    .'Keep reason under 18 words.',
            ],
            [
                'role' => 'user',
                'content' => $this->buildPrompt($task->label, $task->type->value, $task->workloadDescription, $candidates),
            ],
        ];

        $response = $this->llmClient->chat(new ChatRequest(
            $credentials['base_url'],
            $credentials['api_key'],
            $config['model'],
            $messages,
            executionControls: ExecutionControls::defaults(
                maxOutputTokens: 120,
            ),
            timeout: 30,
            providerName: $config['provider_name'] ?? null,
            apiType: $config['api_type'] ?? AiApiType::OpenAiChatCompletions,
            providerHeaders: $credentials['headers'] ?? [],
        ));

        if (isset($response['runtime_error'])) {
            $fallback = $this->fallbackRecommendationForRuntimeError(
                $response['runtime_error'],
                $config,
                $candidates,
            );

            if ($fallback !== null) {
                return $fallback;
            }

            return ['error' => $response['runtime_error']->userMessage ?? $response['runtime_error']->diagnostic];
        }

        return $this->normalizeRecommendation((string) ($response['content'] ?? ''), $candidates);
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
