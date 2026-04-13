<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire;

use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\Enums\TaskModelSelectionMode;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraTaskRegistry;
use App\Modules\Core\AI\Services\TaskModelRecommendationService;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class TaskModels extends Component implements ProvidesLaraPageContext
{
    /** @var array<string, string> */
    public array $taskModes = [];

    /** @var array<string, int|null> */
    public array $taskProviderIds = [];

    /** @var array<string, string|null> */
    public array $taskModelIds = [];

    /** @var array<string, string> */
    public array $taskReasons = [];

    /** @var array<string, string> */
    public array $taskRecommendationErrors = [];

    public function mount(): void
    {
        $this->hydrateTaskState();
    }

    public function pageContext(): PageContext
    {
        return new PageContext(
            route: 'admin.ai.task-models',
            url: route('admin.ai.task-models'),
            title: 'Task Models',
            module: 'AI',
            resourceType: 'task-models',
            visibleActions: ['Recommend task model', 'Set manual task model'],
        );
    }

    public function updatedTaskModes(mixed $value, string $taskKey): void
    {
        $this->clearTaskRecommendationError($taskKey);

        if (! $this->laraActivated()) {
            return;
        }

        if (($this->taskModes[$taskKey] ?? null) === TaskModelSelectionMode::Manual->value) {
            $this->hydrateTaskModel($taskKey, forceDefault: true);
        }

        $this->saveTaskConfig($taskKey);
    }

    public function updatedTaskProviderIds(mixed $value, string $taskKey): void
    {
        $this->clearTaskRecommendationError($taskKey);
        $this->hydrateTaskModel($taskKey, forceDefault: true);

        if (($this->taskModes[$taskKey] ?? null) === TaskModelSelectionMode::Manual->value) {
            $this->saveTaskConfig($taskKey);
        }
    }

    public function updatedTaskModelIds(mixed $value, string $taskKey): void
    {
        $this->clearTaskRecommendationError($taskKey);

        if (($this->taskModes[$taskKey] ?? null) === TaskModelSelectionMode::Manual->value) {
            $this->saveTaskConfig($taskKey);
        }
    }

    public function recommendTask(string $taskKey): void
    {
        $this->clearTaskRecommendationError($taskKey);

        if (! $this->laraActivated()) {
            $this->taskRecommendationErrors[$taskKey] = __('Activate Lara before configuring task models.');

            return;
        }

        $result = app(TaskModelRecommendationService::class)->recommend(Employee::LARA_ID, $taskKey);

        if (isset($result['error'])) {
            $this->taskRecommendationErrors[$taskKey] = $result['error'];

            return;
        }

        $this->taskModes[$taskKey] = TaskModelSelectionMode::Recommended->value;
        $this->taskProviderIds[$taskKey] = $this->providerIdForName($result['provider']);
        $this->taskModelIds[$taskKey] = $result['model'];
        $this->taskReasons[$taskKey] = $result['reason'];

        $this->saveTaskConfig($taskKey);
    }

    public function render(): View
    {
        $registry = app(LaraTaskRegistry::class);
        $laraActivated = $this->laraActivated();
        $providers = $laraActivated ? $this->availableProviders() : collect();
        $modelsByTask = [];

        foreach ($registry->all() as $task) {
            $providerId = $this->taskProviderIds[$task->key] ?? null;
            $modelsByTask[$task->key] = $providerId !== null
                ? $this->modelsForProvider($providerId)
                : collect();
        }

        return view('livewire.admin.ai.task-models', [
            'laraActivated' => $laraActivated,
            'tasks' => $registry->all(),
            'providers' => $providers,
            'modelsByTask' => $modelsByTask,
            'currentPrimary' => $laraActivated
                ? app(ConfigResolver::class)->resolvePrimaryWithDefaultFallback(Employee::LARA_ID)
                : null,
        ]);
    }

    private function hydrateTaskState(): void
    {
        $registry = app(LaraTaskRegistry::class);
        $resolver = app(ConfigResolver::class);

        foreach ($registry->all() as $task) {
            $config = $resolver->readTaskConfig(Employee::LARA_ID, $task->key) ?? [];
            $mode = $config['mode'] ?? TaskModelSelectionMode::Recommended->value;

            if (! in_array($mode, TaskModelSelectionMode::values(), true)) {
                $mode = TaskModelSelectionMode::Recommended->value;
            }

            $this->taskModes[$task->key] = $mode;
            $this->taskProviderIds[$task->key] = $this->providerIdForName($config['provider'] ?? null);
            $this->taskModelIds[$task->key] = is_string($config['model'] ?? null) ? $config['model'] : null;
            $this->taskReasons[$task->key] = is_string($config['reason'] ?? null) ? $config['reason'] : '';
        }
    }

    private function saveTaskConfig(string $taskKey): void
    {
        if (! $this->laraActivated()) {
            return;
        }

        $mode = $this->taskModes[$taskKey] ?? TaskModelSelectionMode::Recommended->value;
        $payload = ['mode' => $mode];

        if ($mode === TaskModelSelectionMode::Primary->value) {
            app(ConfigResolver::class)->writeTaskConfig(Employee::LARA_ID, $taskKey, $payload);
            $this->dispatch("task-{$taskKey}-saved", message: __('Task now uses Lara\'s primary model.'));

            return;
        }

        $providerId = $this->taskProviderIds[$taskKey] ?? null;
        $modelId = $this->taskModelIds[$taskKey] ?? null;

        if ($providerId === null || $modelId === null) {
            app(ConfigResolver::class)->writeTaskConfig(Employee::LARA_ID, $taskKey, $payload);

            return;
        }

        $provider = AiProvider::query()
            ->whereKey($providerId)
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->first();

        if ($provider === null) {
            return;
        }

        $modelExists = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('model_id', $modelId)
            ->active()
            ->exists();

        if (! $modelExists) {
            return;
        }

        $payload['provider'] = $provider->name;
        $payload['model'] = $modelId;

        $reason = trim($this->taskReasons[$taskKey] ?? '');
        if ($reason !== '') {
            $payload['reason'] = $reason;
        }

        app(ConfigResolver::class)->writeTaskConfig(Employee::LARA_ID, $taskKey, $payload);

        $message = $mode === TaskModelSelectionMode::Manual->value
            ? __('Manual model saved: :provider/:model', ['provider' => $provider->name, 'model' => $modelId])
            : __('Recommended model saved: :provider/:model', ['provider' => $provider->name, 'model' => $modelId]);

        $this->dispatch("task-{$taskKey}-saved", message: $message);
    }

    private function availableProviders(): Collection
    {
        return AiProvider::query()
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'name']);
    }

    private function modelsForProvider(int $providerId): Collection
    {
        return AiProviderModel::query()
            ->where('ai_provider_id', $providerId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('model_id')
            ->get(['id', 'model_id', 'is_default']);
    }

    private function hydrateTaskModel(string $taskKey, bool $forceDefault = false): void
    {
        $providerId = $this->taskProviderIds[$taskKey] ?? null;

        if ($providerId === null) {
            $this->taskModelIds[$taskKey] = null;

            return;
        }

        $providerExists = AiProvider::query()
            ->whereKey($providerId)
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->exists();

        if (! $providerExists) {
            $this->taskProviderIds[$taskKey] = null;
            $this->taskModelIds[$taskKey] = null;

            return;
        }

        if (! $forceDefault && ($this->taskModelIds[$taskKey] ?? null) !== null) {
            $modelStillValid = AiProviderModel::query()
                ->where('ai_provider_id', $providerId)
                ->where('model_id', $this->taskModelIds[$taskKey])
                ->active()
                ->exists();

            if ($modelStillValid) {
                return;
            }
        }

        $this->taskModelIds[$taskKey] = AiProviderModel::query()
            ->where('ai_provider_id', $providerId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('model_id')
            ->value('model_id');
    }

    private function providerIdForName(?string $providerName): ?int
    {
        if ($providerName === null) {
            return null;
        }

        return AiProvider::query()
            ->forCompany(Company::LICENSEE_ID)
            ->where('name', $providerName)
            ->value('id');
    }

    private function clearTaskRecommendationError(string $taskKey): void
    {
        unset($this->taskRecommendationErrors[$taskKey]);
    }

    private function laraActivated(): bool
    {
        return Employee::laraActivationState() === true;
    }
}
