<?php

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Services\ModelCatalogService;
use App\Base\Support\File as BlbFile;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Resolves LLM configuration for an Agent.
 *
 * Returns a single resolved config from the agent's company default provider+model
 * (priority-ordered). Runtime fallback across providers is intentionally not
 * supported — failures surface honestly. Per-task overrides cascade through
 * {@see resolveTask()}; per-session overrides are layered by the runtime caller.
 */
class ConfigResolver
{
    /**
     * Resolve the default LLM configuration for an agent.
     *
     * Looks up the agent's company and resolves its priority-winning
     * provider/model. Returns null when no configuration is available.
     *
     * @param  int  $employeeId  Agent employee ID
     * @return array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null, api_type: AiApiType}|null
     */
    public function resolveDefault(int $employeeId): ?array
    {
        $employee = Employee::query()->find($employeeId);
        $companyId = $employee?->company_id ? (int) $employee->company_id : null;

        return $companyId === null ? null : $this->resolveCompanyDefault($companyId);
    }

    /**
     * Read Lara task model configuration from workspace config.
     *
     * @return array<string, array<string, mixed>>
     */
    public function readTaskConfigs(int $employeeId): array
    {
        $workspaceConfig = $this->readWorkspaceConfig($employeeId);
        $tasks = $workspaceConfig['llm']['tasks'] ?? [];

        return is_array($tasks) ? $tasks : [];
    }

    /**
     * Read a single Lara task model configuration entry.
     *
     * @return array<string, mixed>|null
     */
    public function readTaskConfig(int $employeeId, string $taskKey): ?array
    {
        $tasks = $this->readTaskConfigs($employeeId);
        $task = $tasks[$taskKey] ?? null;

        return is_array($task) ? $task : null;
    }

    /**
     * Persist one Lara task model configuration while preserving chat model config.
     *
     * @param  array<string, mixed>  $taskConfig
     */
    public function writeTaskConfig(int $employeeId, string $taskKey, array $taskConfig): void
    {
        $workspaceConfig = $this->readWorkspaceConfig($employeeId) ?? [];
        $llm = is_array($workspaceConfig['llm'] ?? null) ? $workspaceConfig['llm'] : [];
        $tasks = is_array($llm['tasks'] ?? null) ? $llm['tasks'] : [];
        $tasks[$taskKey] = $taskConfig;
        $llm['tasks'] = $tasks;
        $workspaceConfig['llm'] = $llm;

        $this->writeWorkspaceConfig($employeeId, $workspaceConfig);
    }

    /**
     * Resolve the configured model for a Lara task, falling back to the agent's default model.
     *
     * Tasks have two modes: `recommended` (saved provider/model from a recommendation
     * pass) and `manual` (operator-picked provider/model). When the saved selection is
     * missing or no longer points to an active connected model, the resolver falls
     * through to {@see resolveDefault()} so the task still runs.
     *
     * @return array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null, api_type: AiApiType}|null
     */
    public function resolveTask(int $employeeId, string $taskKey): ?array
    {
        $taskConfig = $this->readTaskConfig($employeeId, $taskKey);

        if (! is_array($taskConfig)) {
            return $this->resolveDefault($employeeId);
        }

        $taskExecutionControls = is_array($taskConfig['execution_controls'] ?? null) ? $taskConfig['execution_controls'] : [];

        $providerName = is_string($taskConfig['provider'] ?? null) ? $taskConfig['provider'] : null;
        $modelId = is_string($taskConfig['model'] ?? null) ? $taskConfig['model'] : null;

        if ($providerName === null || $modelId === null) {
            return $this->applyExecutionControlsOverride(
                $this->resolveDefault($employeeId),
                $taskExecutionControls,
            );
        }

        $companyId = $this->findCompanyIdForFallback($employeeId);

        if ($companyId === null) {
            return $this->applyExecutionControlsOverride(
                $this->resolveDefault($employeeId),
                $taskExecutionControls,
            );
        }

        $provider = AiProvider::query()
            ->forCompany($companyId)
            ->llm()
            ->active()
            ->where('name', $providerName)
            ->first();

        if ($provider === null) {
            return $this->applyExecutionControlsOverride(
                $this->resolveDefault($employeeId),
                $taskExecutionControls,
            );
        }

        $modelExists = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('model_id', $modelId)
            ->active()
            ->exists();

        if (! $modelExists) {
            return $this->applyExecutionControlsOverride(
                $this->resolveDefault($employeeId),
                $taskExecutionControls,
            );
        }

        return $this->resolveModelConfig($taskConfig, $companyId, $this->runtimeDefaults());
    }

    /**
     * Read the workspace config.json for a Agent.
     *
     * @param  int  $employeeId  Agent employee ID
     * @return array<string, mixed>|null
     */
    public function readWorkspaceConfig(int $employeeId): ?array
    {
        $path = config('ai.workspace_path').'/'.$employeeId.'/config.json';

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : BlbJson::decodeArray($content);
    }

    /**
     * Write workspace config.json for a Agent.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  array<string, mixed>  $config  Configuration to write
     */
    public function writeWorkspaceConfig(int $employeeId, array $config): void
    {
        $dir = config('ai.workspace_path').'/'.$employeeId;

        BlbFile::put(
            $dir.'/config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Resolve a single model config entry against company providers and runtime defaults.
     *
     * @param  array<string, mixed>  $modelConfig  Per-model config from workspace
     * @param  int|null  $companyId  Company ID for provider lookup
     * @param  array{execution_controls: ExecutionControls, timeout: int}  $runtimeDefaults  Fallback runtime parameters
     * @return array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null, provider_id: int|null, credentials: array<string, mixed>, connection_config: array<string, mixed>, api_type: AiApiType}
     */
    private function resolveModelConfig(array $modelConfig, ?int $companyId, array $runtimeDefaults): array
    {
        $modelId = $modelConfig['model'] ?? '';
        $providerName = $modelConfig['provider'] ?? null;
        $controlsConfig = is_array($modelConfig['execution_controls'] ?? null) ? $modelConfig['execution_controls'] : [];

        $resolved = [
            'api_key' => '',
            'base_url' => '',
            'model' => $modelId,
            'execution_controls' => $runtimeDefaults['execution_controls'],
            'timeout' => (int) ($modelConfig['timeout'] ?? $runtimeDefaults['timeout']),
            'provider_name' => null,
            'provider_id' => null,
            'credentials' => [],
            'connection_config' => [],
            'api_type' => app(ModelCatalogService::class)->resolveApiType($providerName, $modelId),
        ];

        $modelControls = $runtimeDefaults['execution_controls'];

        if ($providerName !== null && $companyId !== null) {
            $provider = AiProvider::query()
                ->forCompany($companyId)
                ->llm()
                ->active()
                ->where('name', $providerName)
                ->first();

            if ($provider) {
                $resolved['api_key'] = $provider->credentials['api_key'] ?? '';
                $resolved['base_url'] = $provider->base_url;
                $resolved['provider_name'] = $provider->name;
                $resolved['provider_id'] = $provider->id;
                $resolved['credentials'] = is_array($provider->credentials) ? $provider->credentials : [];
                $resolved['connection_config'] = is_array($provider->connection_config) ? $provider->connection_config : [];

                $modelRow = AiProviderModel::query()
                    ->where('ai_provider_id', $provider->id)
                    ->where('model_id', $modelId)
                    ->first();

                if ($modelRow !== null && is_array($modelRow->execution_controls) && $modelRow->execution_controls !== []) {
                    $modelControls = ExecutionControls::fromConfig($modelRow->execution_controls, $modelControls);
                }
            }
        }

        $resolved['execution_controls'] = ExecutionControls::fromConfig($controlsConfig, $modelControls);

        return $resolved;
    }

    /**
     * Resolve the default LLM configuration for a company.
     *
     * Uses the highest-priority active provider (lowest priority number > 0)
     * and its default model. Falls back to the first active provider if none
     * are prioritized.
     *
     * Used for non-agent AI inferences (summarization, translation, etc.) and
     * as the company-level default consumed by {@see resolveDefault()}.
     *
     * @param  int  $companyId  Company ID
     * @return array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null, provider_id: int|null, credentials: array<string, mixed>, connection_config: array<string, mixed>, api_type: AiApiType}|null
     */
    public function resolveCompanyDefault(int $companyId): ?array
    {
        // Try prioritized providers first (priority > 0, ordered ascending)
        $provider = AiProvider::query()
            ->forCompany($companyId)
            ->llm()
            ->active()
            ->prioritized()
            ->first();

        // Fall back to any active provider
        if ($provider === null) {
            $provider = AiProvider::query()
                ->forCompany($companyId)
                ->llm()
                ->active()
                ->orderBy('display_name')
                ->first();
        }

        if ($provider === null) {
            return null;
        }

        $model = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->active()
            ->default()
            ->first();

        if ($model === null) {
            $model = AiProviderModel::query()
                ->where('ai_provider_id', $provider->id)
                ->active()
                ->orderBy('model_id')
                ->first();
        }

        if ($model === null) {
            return null;
        }

        $defaults = $this->runtimeDefaults();
        $controls = $defaults['execution_controls'];

        if (is_array($model->execution_controls) && $model->execution_controls !== []) {
            $controls = ExecutionControls::fromConfig($model->execution_controls, $controls);
        }

        return [
            'api_key' => $provider->credentials['api_key'] ?? '',
            'base_url' => $provider->base_url,
            'model' => $model->model_id,
            'execution_controls' => $controls,
            'timeout' => $defaults['timeout'],
            'provider_name' => $provider->name,
            'provider_id' => $provider->id,
            'credentials' => is_array($provider->credentials) ? $provider->credentials : [],
            'connection_config' => is_array($provider->connection_config) ? $provider->connection_config : [],
            'api_type' => app(ModelCatalogService::class)->resolveApiType($provider->name, $model->model_id),
        ];
    }

    /**
     * Resolve LLM config for a specific provider and model by provider ID.
     *
     * Used when a model selector provides a composite "providerId:::modelId"
     * override that should target a different provider than the primary config.
     *
     * @param  int  $providerId  Provider database ID
     * @param  string  $modelId  Model identifier
     * @return array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null, provider_id: int|null, credentials: array<string, mixed>, connection_config: array<string, mixed>, api_type: AiApiType}|null
     */
    public function resolveForProvider(int $providerId, string $modelId): ?array
    {
        $provider = AiProvider::query()
            ->llm()
            ->where('id', $providerId)
            ->active()
            ->first();

        if ($provider === null) {
            return null;
        }

        $defaults = $this->runtimeDefaults();
        $controls = $defaults['execution_controls'];

        $modelRow = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('model_id', $modelId)
            ->first();

        if ($modelRow !== null && is_array($modelRow->execution_controls) && $modelRow->execution_controls !== []) {
            $controls = ExecutionControls::fromConfig($modelRow->execution_controls, $controls);
        }

        return [
            'api_key' => $provider->credentials['api_key'] ?? '',
            'base_url' => $provider->base_url,
            'model' => $modelId,
            'execution_controls' => $controls,
            'timeout' => $defaults['timeout'],
            'provider_name' => $provider->name,
            'provider_id' => $provider->id,
            'credentials' => is_array($provider->credentials) ? $provider->credentials : [],
            'connection_config' => is_array($provider->connection_config) ? $provider->connection_config : [],
            'api_type' => app(ModelCatalogService::class)->resolveApiType($provider->name, $modelId),
        ];
    }

    /**
     * Get runtime parameter defaults from application config.
     *
     * @return array{execution_controls: ExecutionControls, timeout: int}
     */
    private function runtimeDefaults(): array
    {
        return [
            'execution_controls' => ExecutionControls::fromConfig(
                config('ai.llm.execution_controls', []),
                ExecutionControls::defaults(),
            ),
            'timeout' => (int) config('ai.llm.timeout', 60),
        ];
    }

    /**
     * Apply task-level execution controls onto a resolved model configuration.
     *
     * @param  array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null, api_type: AiApiType}|null  $resolvedConfig
     * @param  array<string, mixed>  $controlsConfig
     * @return array{api_key: string, base_url: string, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null, api_type: AiApiType}|null
     */
    private function applyExecutionControlsOverride(?array $resolvedConfig, array $controlsConfig): ?array
    {
        if ($resolvedConfig === null || $controlsConfig === []) {
            return $resolvedConfig;
        }

        $resolvedConfig['execution_controls'] = ExecutionControls::fromConfig(
            $controlsConfig,
            $resolvedConfig['execution_controls'],
        );

        return $resolvedConfig;
    }

    /**
     * Find the employee's company ID while preserving runtime fallback behavior on lookup failures.
     *
     * @param  int  $employeeId  Agent employee ID
     */
    private function findCompanyIdForFallback(int $employeeId): ?int
    {
        try {
            $employee = Employee::query()->find($employeeId);
        } catch (\Throwable) {
            return null;
        }

        return $employee?->company_id ? (int) $employee->company_id : null;
    }
}
