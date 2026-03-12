<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire;

use App\Modules\Core\AI\Services\ChatMarkdownRenderer;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\DigitalWorkerRuntime;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Employee\Models\Employee;
use Livewire\Component;

class Playground extends Component
{
    public string $messageInput = '';

    public ?string $selectedSessionId = null;

    public ?int $selectedEmployeeId = null;

    public bool $isLoading = false;

    /** @var array<string, mixed>|null */
    public ?array $lastRunMeta = null;

    public bool $showLlmConfig = false;

    /** @var list<array{provider: string, model: string, max_tokens: int, temperature: float}> */
    public array $llmModels = [];

    public function mount(): void
    {
        $employee = $this->getDefaultDigitalWorker();

        if ($employee) {
            $this->selectedEmployeeId = $employee->id;

            $sessions = app(SessionManager::class)->list($employee->id);

            if (! empty($sessions)) {
                $this->selectedSessionId = $sessions[0]->id;
            }
        }
    }

    public function createSession(): void
    {
        if (! $this->selectedEmployeeId) {
            return;
        }

        $session = app(SessionManager::class)->create($this->selectedEmployeeId);
        $this->selectedSessionId = $session->id;
        $this->lastRunMeta = null;
    }

    public function selectSession(string $sessionId): void
    {
        $this->selectedSessionId = $sessionId;
        $this->lastRunMeta = null;
    }

    public function deleteSession(string $sessionId): void
    {
        if (! $this->selectedEmployeeId) {
            return;
        }

        app(SessionManager::class)->delete($this->selectedEmployeeId, $sessionId);

        if ($this->selectedSessionId === $sessionId) {
            $sessions = app(SessionManager::class)->list($this->selectedEmployeeId);
            $this->selectedSessionId = empty($sessions) ? null : $sessions[0]->id;
        }

        $this->lastRunMeta = null;
    }

    public function sendMessage(): void
    {
        if (! $this->selectedEmployeeId || ! $this->selectedSessionId || trim($this->messageInput) === '') {
            return;
        }

        $this->isLoading = true;
        $content = trim($this->messageInput);
        $this->messageInput = '';

        $messageManager = app(MessageManager::class);
        $runtime = app(DigitalWorkerRuntime::class);

        // Append user message
        $messageManager->appendUserMessage(
            $this->selectedEmployeeId,
            $this->selectedSessionId,
            $content,
        );

        // Get conversation history for context
        $messages = $messageManager->read(
            $this->selectedEmployeeId,
            $this->selectedSessionId,
        );

        // Get Digital Worker's job description for system prompt
        $employee = Employee::query()->find($this->selectedEmployeeId);
        if ($employee?->isLara()) {
            $systemPrompt = app(LaraPromptFactory::class)->buildForCurrentUser();
        } else {
            $systemPrompt = $employee?->job_description
                ? __('You are a Digital Worker. Your role: :role', ['role' => $employee->job_description])
                : __('You are a helpful Digital Worker assistant.');
        }

        // Run LLM
        $result = $runtime->run($messages, $this->selectedEmployeeId, $systemPrompt);

        // Append assistant message
        $messageManager->appendAssistantMessage(
            $this->selectedEmployeeId,
            $this->selectedSessionId,
            $result['content'],
            $result['run_id'],
            $result['meta'],
        );

        $this->lastRunMeta = [
            'run_id' => $result['run_id'],
            ...$result['meta'],
        ];

        // Auto-title the session from the first user message
        $sessionManager = app(SessionManager::class);
        $session = $sessionManager->get($this->selectedEmployeeId, $this->selectedSessionId);

        if ($session && $session->title === null) {
            $title = mb_substr($content, 0, 60);

            if (mb_strlen($content) > 60) {
                $title .= '…';
            }

            $sessionManager->updateTitle($this->selectedEmployeeId, $this->selectedSessionId, $title);
        }

        $this->isLoading = false;
    }

    public function openLlmConfig(): void
    {
        if (! $this->selectedEmployeeId) {
            return;
        }

        $configResolver = app(ConfigResolver::class);
        $config = $configResolver->readWorkspaceConfig($this->selectedEmployeeId);
        $models = $config['llm']['models'] ?? [];

        if ($models === []) {
            // Seed from company's default provider + model
            $user = auth()->user();
            $companyId = $user?->employee?->company_id ? (int) $user->employee->company_id : null;
            $default = $companyId ? $configResolver->resolveDefault($companyId) : null;

            $this->llmModels = [[
                'provider' => $default['provider_name'] ?? '',
                'model' => $default['model'] ?? '',
                'max_tokens' => (int) ($default['max_tokens'] ?? config('ai.llm.max_tokens', 2048)),
                'temperature' => (float) ($default['temperature'] ?? config('ai.llm.temperature', 0.7)),
            ]];
        } else {
            $this->llmModels = array_map(fn ($m) => [
                'provider' => $m['provider'] ?? '',
                'model' => $m['model'] ?? '',
                'max_tokens' => (int) ($m['max_tokens'] ?? config('ai.llm.max_tokens', 2048)),
                'temperature' => (float) ($m['temperature'] ?? config('ai.llm.temperature', 0.7)),
            ], $models);
        }

        $this->showLlmConfig = true;
    }

    public function addLlmModel(): void
    {
        $this->llmModels[] = [
            'provider' => '',
            'model' => '',
            'max_tokens' => (int) config('ai.llm.max_tokens', 2048),
            'temperature' => (float) config('ai.llm.temperature', 0.7),
        ];
    }

    public function removeLlmModel(int $index): void
    {
        if (count($this->llmModels) <= 1) {
            return;
        }

        array_splice($this->llmModels, $index, 1);
        $this->llmModels = array_values($this->llmModels);
    }

    public function moveLlmModel(int $index, string $direction): void
    {
        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($targetIndex < 0 || $targetIndex >= count($this->llmModels)) {
            return;
        }

        [$this->llmModels[$index], $this->llmModels[$targetIndex]] =
            [$this->llmModels[$targetIndex], $this->llmModels[$index]];
        $this->llmModels = array_values($this->llmModels);
    }

    /**
     * Auto-select the provider's default model when the provider dropdown changes.
     *
     * Livewire calls updated{Property} whenever a bound property changes.
     * The key format is "llmModels.{index}.provider".
     *
     * @param  string  $value  New provider name
     */
    public function updatedLlmModels(mixed $value, string $key): void
    {
        if (str_ends_with($key, '.provider')) {
            $index = (int) explode('.', $key)[0];

            if (! isset($this->llmModels[$index])) {
                return;
            }

            $providerName = $this->llmModels[$index]['provider'] ?? '';

            if ($providerName === '') {
                $this->llmModels[$index]['model'] = '';

                return;
            }

            $companyId = $this->currentCompanyId();

            if ($companyId === null) {
                return;
            }

            $provider = \App\Modules\Core\AI\Models\AiProvider::query()
                ->forCompany($companyId)
                ->active()
                ->where('name', $providerName)
                ->first();

            if ($provider !== null) {
                $defaultModel = $this->resolveDefaultProviderModelId($provider->id);
                $this->llmModels[$index]['model'] = $defaultModel ?? '';
            }
        }
    }

    public function saveLlmConfig(): void
    {
        if (! $this->selectedEmployeeId) {
            return;
        }

        $this->validate([
            'llmModels.*.provider' => ['required', 'string'],
            'llmModels.*.model' => ['required', 'string'],
        ], [
            'llmModels.*.provider.required' => __('Provider is required.'),
            'llmModels.*.model.required' => __('Model is required.'),
        ]);

        $configResolver = app(ConfigResolver::class);
        $existingConfig = $configResolver->readWorkspaceConfig($this->selectedEmployeeId) ?? [];

        $models = array_map(function ($m) {
            $entry = ['model' => $m['model']];

            if (! empty($m['provider'])) {
                $entry['provider'] = $m['provider'];
            }

            if (isset($m['max_tokens'])) {
                $entry['max_tokens'] = (int) $m['max_tokens'];
            }

            if (isset($m['temperature'])) {
                $entry['temperature'] = (float) $m['temperature'];
            }

            return $entry;
        }, $this->llmModels);

        $existingConfig['llm'] = ['models' => $models];
        $configResolver->writeWorkspaceConfig($this->selectedEmployeeId, $existingConfig);

        $this->showLlmConfig = false;
    }

    public function selectDigitalWorker(int $employeeId): void
    {
        $this->selectedEmployeeId = $employeeId;
        $this->selectedSessionId = null;
        $this->lastRunMeta = null;

        $sessions = app(SessionManager::class)->list($employeeId);

        if (! empty($sessions)) {
            $this->selectedSessionId = $sessions[0]->id;
        }
    }

    private function currentCompanyId(): ?int
    {
        $user = auth()->user();

        return $user?->employee?->company_id ? (int) $user->employee->company_id : null;
    }

    private function resolveDefaultProviderModelId(int $providerId): ?string
    {
        $defaultModel = \App\Modules\Core\AI\Models\AiProviderModel::query()
            ->where('ai_provider_id', $providerId)
            ->active()
            ->default()
            ->first();

        if ($defaultModel === null) {
            $defaultModel = \App\Modules\Core\AI\Models\AiProviderModel::query()
                ->where('ai_provider_id', $providerId)
                ->active()
                ->orderBy('model_id')
                ->first();
        }

        return $defaultModel?->model_id;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $sessions = [];
        $messages = [];
        $digitalWorkers = collect();
        $availableProviders = collect();
        $providerModelsMap = [];

        $user = auth()->user();

        if ($user) {
            // Get Digital Workers supervised by the current user's employee record
            $userEmployee = $user->employee;

            if ($userEmployee) {
                $digitalWorkers = Employee::query()
                    ->digitalWorker()
                    ->where('supervisor_id', $userEmployee->id)
                    ->active()
                    ->get();
            }

            if ($user->employee && $user->employee->company_id) {
                $availableProviders = \App\Modules\Core\AI\Models\AiProvider::query()
                    ->forCompany((int) $user->employee->company_id)
                    ->active()
                    ->get(['id', 'name', 'display_name']);

                // Build provider → models map for the LLM config modal
                $providerModelsMap = [];
                foreach ($availableProviders as $p) {
                    $providerModelsMap[$p->name] = \App\Modules\Core\AI\Models\AiProviderModel::query()
                        ->where('ai_provider_id', $p->id)
                        ->active()
                        ->orderBy('model_id')
                        ->pluck('model_id')
                        ->all();
                }
            }
        }

        if ($this->selectedEmployeeId) {
            $sessions = app(SessionManager::class)->list($this->selectedEmployeeId);
        }

        if ($this->selectedEmployeeId && $this->selectedSessionId) {
            $messages = app(MessageManager::class)->read(
                $this->selectedEmployeeId,
                $this->selectedSessionId,
            );
        }

        return view('livewire.ai.playground', [
            'digitalWorkers' => $digitalWorkers,
            'sessions' => $sessions,
            'messages' => $messages,
            'availableProviders' => $availableProviders,
            'providerModelsMap' => $providerModelsMap,
            'markdown' => app(ChatMarkdownRenderer::class),
        ]);
    }

    /**
     * Get the default Digital Worker for the current user.
     */
    private function getDefaultDigitalWorker(): ?Employee
    {
        $user = auth()->user();

        if (! $user || ! $user->employee) {
            return null;
        }

        return Employee::query()
            ->digitalWorker()
            ->where('supervisor_id', $user->employee->id)
            ->active()
            ->first();
    }
}
