<?php

namespace Tests\Support;

use App\Base\AI\Contracts\Tool;
use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\AiRuntimeSettings;
use App\Base\AI\Services\LlmClient;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Services\AgenticExecutionControlResolver;
use App\Modules\Core\AI\Services\AgentToolRegistry;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorder;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\AI\Services\Orchestration\RuntimeHookRegistry;
use App\Modules\Core\AI\Services\Orchestration\RuntimeHookRunner;
use App\Modules\Core\AI\Services\Runtime\AgenticRuntime;
use App\Modules\Core\AI\Services\Runtime\AgenticToolLoopStreamReader;
use App\Modules\Core\AI\Services\Runtime\RuntimeCredentialResolver;
use App\Modules\Core\AI\Services\Runtime\RuntimeHookCoordinator;
use App\Modules\Core\AI\Services\Runtime\RuntimeMessageBuilder;
use App\Modules\Core\AI\Services\Runtime\RuntimeResponseFactory;
use App\Modules\Core\AI\Services\Runtime\RuntimeSessionContext;
use DateTimeImmutable;
use Psr\Log\NullLogger;

trait MakesRuntimeResponses
{
    protected function makeConfig(
        string $provider,
        string $model,
        string $apiKey = 'sk-test',
        string $baseUrl = 'https://api.example.com/v1'
    ): array {
        return [
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
            'model' => $model,
            'execution_controls' => ExecutionControls::defaults(
                maxOutputTokens: 2048,
            ),
            'timeout' => 60,
            'provider_name' => $provider,
        ];
    }

    protected function makeSuccessResponse(string $content, int $latencyMs = 200): array
    {
        return [
            'content' => $content,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'latency_ms' => $latencyMs,
        ];
    }

    protected function makeErrorResponse(AiErrorType $errorType, string $diagnostic, int $latencyMs): array
    {
        return [
            'runtime_error' => AiRuntimeError::fromType($errorType, $diagnostic, latencyMs: $latencyMs),
            'latency_ms' => $latencyMs,
        ];
    }

    protected function makeMessage(string $role, string $content): Message
    {
        return new Message(
            role: $role,
            content: $content,
            timestamp: new DateTimeImmutable,
        );
    }

    protected function mockResolvedConfigResolver(array $configs): ConfigResolver
    {
        $configResolver = \Mockery::mock(ConfigResolver::class);
        $configResolver->shouldReceive('resolveDefault')->andReturn($configs[0] ?? null);

        return $configResolver;
    }

    protected function makeAllowAllAuthzMock(): AuthorizationService
    {
        $mock = \Mockery::mock(AuthorizationService::class);
        $mock->shouldReceive('can')->andReturn(AuthorizationDecision::allow());

        return $mock;
    }

    protected function makeToolRegistry(Tool ...$tools): AgentToolRegistry
    {
        $registry = new AgentToolRegistry($this->makeAllowAllAuthzMock());

        foreach ($tools as $tool) {
            $registry->register($tool);
        }

        return $registry;
    }

    /**
     * Build a RuntimeCredentialResolver that validates api_key / base_url
     * from config without hitting the database. Used by unit tests that test
     * runtime orchestration, not credential resolution.
     */
    protected function makePassthroughCredentialResolver(): RuntimeCredentialResolver
    {
        $resolver = \Mockery::mock(RuntimeCredentialResolver::class);
        $resolver->shouldReceive('resolve')
            ->andReturnUsing(function (array $config) {
                if (empty($config['api_key'])) {
                    return [
                        'runtime_error' => AiRuntimeError::fromType(
                            AiErrorType::ConfigError,
                            'API key is not configured for provider '.($config['provider_name'] ?? 'default'),
                        ),
                    ];
                }

                if (empty($config['base_url'])) {
                    return [
                        'runtime_error' => AiRuntimeError::fromType(
                            AiErrorType::ConfigError,
                            'Base URL is not configured for provider '.($config['provider_name'] ?? 'default'),
                        ),
                    ];
                }

                return [
                    'api_key' => $config['api_key'],
                    'base_url' => $config['base_url'],
                ];
            });

        return $resolver;
    }

    protected function makeAgenticRuntime(
        LlmClient $llmClient,
        ?ConfigResolver $configResolver = null,
        ?AgentToolRegistry $toolRegistry = null,
        ?RunRecorder $runRecorder = null,
        ?AiRuntimeSettings $runtimeSettings = null,
    ): AgenticRuntime {
        $runRecorder ??= \Mockery::mock(RunRecorder::class)->shouldIgnoreMissing();
        $responseFactory = new RuntimeResponseFactory;

        return new AgenticRuntime(
            $configResolver ?? $this->mockResolvedConfigResolver([$this->makeConfig('test-provider', 'gpt-4', 'test-key')]),
            $llmClient,
            $toolRegistry ?? $this->makeToolRegistry(),
            $this->makePassthroughCredentialResolver(),
            new RuntimeMessageBuilder,
            $responseFactory,
            new RuntimeHookCoordinator(new RuntimeHookRunner(new RuntimeHookRegistry, new NullLogger)),
            $runRecorder,
            new AgenticToolLoopStreamReader(
                $llmClient,
                \Mockery::mock(WireLogger::class)->shouldIgnoreMissing(),
                app(AgenticExecutionControlResolver::class),
                $runRecorder,
            ),
            app(RuntimeSessionContext::class),
            \Mockery::mock(WireLogger::class)->shouldIgnoreMissing(),
            app(AgenticExecutionControlResolver::class),
            $runtimeSettings ?? $this->makeAiRuntimeSettings(),
        );
    }

    protected function makeAiRuntimeSettings(?int $maxToolRounds = null): AiRuntimeSettings
    {
        $definitions = app(SettingDefinitionRegistry::class);
        $settings = \Mockery::mock(SettingsService::class);
        $settings->shouldReceive('has')
            ->andReturnUsing(fn (string $key): bool => $maxToolRounds !== null
                && $key === AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY);
        $settings->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default = null) use ($definitions, $maxToolRounds): mixed {
                if ($key === AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY) {
                    return $maxToolRounds ?? $definitions->get($key)->default;
                }

                return config($key, $default);
            });

        return new AiRuntimeSettings($settings, $definitions);
    }

    protected function makeToolCallResponse(string $callId, string $toolName, string $arguments): array
    {
        return [
            'content' => null,
            'latency_ms' => 200,
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 15],
            'tool_calls' => [
                [
                    'id' => $callId,
                    'type' => 'function',
                    'function' => [
                        'name' => $toolName,
                        'arguments' => $arguments,
                    ],
                ],
            ],
        ];
    }

    protected function makeFinalResponse(string $content): array
    {
        return [
            'content' => $content,
            'latency_ms' => 150,
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
        ];
    }
}
