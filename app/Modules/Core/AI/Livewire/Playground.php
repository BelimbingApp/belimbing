<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire;

use App\Base\Support\Str as BlbStr;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\AgentRuntime;
use App\Modules\Core\AI\Services\ChatMarkdownRenderer;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Playground extends Component
{
    public string $messageInput = '';

    public ?string $selectedSessionId = null;

    public ?int $selectedAgentId = null;

    public bool $isLoading = false;

    /** @var array<string, mixed>|null */
    public ?array $lastRunMeta = null;

    public bool $showLlmConfig = false;

    /** @var list<array{provider: string, model: string, max_tokens: int, temperature: float}> */
    public array $llmModels = [];

    public function mount(): void
    {
        $agent = $this->getDefaultAgent();

        if ($agent) {
            $this->selectedAgentId = $agent->id;

            $sessions = app(SessionManager::class)->list($agent->id);

            if (! empty($sessions)) {
                $this->selectedSessionId = $sessions[0]->id;
            }
        }
    }

    public function createSession(): void
    {
        if (! $this->selectedAgentId) {
            return;
        }

        $session = app(SessionManager::class)->create($this->selectedAgentId);
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
        if (! $this->selectedAgentId) {
            return;
        }

        app(SessionManager::class)->delete($this->selectedAgentId, $sessionId);

        if ($this->selectedSessionId === $sessionId) {
            $sessions = app(SessionManager::class)->list($this->selectedAgentId);
            $this->selectedSessionId = empty($sessions) ? null : $sessions[0]->id;
        }

        $this->lastRunMeta = null;
    }

    public function sendMessage(): void
    {
        if (! $this->selectedAgentId || ! $this->selectedSessionId || trim($this->messageInput) === '') {
            return;
        }

        $this->isLoading = true;
        $content = trim($this->messageInput);
        $this->messageInput = '';

        $messageManager = app(MessageManager::class);
        $runtime = app(AgentRuntime::class);

        // Append user message
        $messageManager->appendUserMessage(
            $this->selectedAgentId,
            $this->selectedSessionId,
            $content,
        );

        // Get conversation history for context
        $messages = $messageManager->read(
            $this->selectedAgentId,
            $this->selectedSessionId,
        );

        // Get the agent's job description for the system prompt.
        $agent = Employee::query()->find($this->selectedAgentId);
        if ($agent?->isLara()) {
            $factory = app(LaraPromptFactory::class);
            $package = $factory->buildPackage();
            $systemPrompt = app(PromptRenderer::class)->render($package);
        } else {
            $systemPrompt = $agent?->job_description
                ? __('You are an agent. Your role: :role', ['role' => $agent->job_description])
                : __('You are a helpful agent assistant.');
        }

        // Run LLM
        $result = $runtime->run($messages, $this->selectedAgentId, $systemPrompt);

        // Append assistant message
        $messageManager->appendAssistantMessage(
            $this->selectedAgentId,
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
        $session = $sessionManager->get($this->selectedAgentId, $this->selectedSessionId);

        if ($session && $session->title === null) {
            $title = BlbStr::truncate($content, 60);
            $sessionManager->updateTitle($this->selectedAgentId, $this->selectedSessionId, $title);
        }

        $this->isLoading = false;
    }

    public function openLlmConfig(): void
    {
        if (! $this->selectedAgentId) {
            return;
        }

        $configResolver = app(ConfigResolver::class);
        $config = $configResolver->readWorkspaceConfig($this->selectedAgentId);
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

            $provider = AiProvider::query()
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
        if (! $this->selectedAgentId) {
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
        $existingConfig = $configResolver->readWorkspaceConfig($this->selectedAgentId) ?? [];

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
        $configResolver->writeWorkspaceConfig($this->selectedAgentId, $existingConfig);

        $this->showLlmConfig = false;
    }

    public function selectAgent(int $agentId): void
    {
        $this->selectedAgentId = $agentId;
        $this->selectedSessionId = null;
        $this->lastRunMeta = null;

        $sessions = app(SessionManager::class)->list($agentId);

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
        $defaultModel = AiProviderModel::query()
            ->where('ai_provider_id', $providerId)
            ->active()
            ->default()
            ->first();

        if ($defaultModel === null) {
            $defaultModel = AiProviderModel::query()
                ->where('ai_provider_id', $providerId)
                ->active()
                ->orderBy('model_id')
                ->first();
        }

        return $defaultModel?->model_id;
    }

    public function render(): View
    {
        $sessions = [];
        $messages = [];
        $agents = collect();
        $availableProviders = collect();
        $providerModelsMap = [];

        $user = auth()->user();

        if ($user) {
            // Get agents supervised by the current user's employee record.
            $userEmployee = $user->employee;

            if ($userEmployee) {
                $agents = Employee::query()
                    ->agent()
                    ->where('supervisor_id', $userEmployee->id)
                    ->active()
                    ->get();
            }

            if ($user->employee && $user->employee->company_id) {
                $availableProviders = AiProvider::query()
                    ->forCompany((int) $user->employee->company_id)
                    ->active()
                    ->get(['id', 'name', 'display_name']);

                // Build provider → models map for the LLM config modal
                $providerModelsMap = [];
                foreach ($availableProviders as $p) {
                    $providerModelsMap[$p->name] = AiProviderModel::query()
                        ->where('ai_provider_id', $p->id)
                        ->active()
                        ->orderBy('model_id')
                        ->pluck('model_id')
                        ->all();
                }
            }
        }

        if ($this->selectedAgentId) {
            $sessions = app(SessionManager::class)->list($this->selectedAgentId);
        }

        if ($this->selectedAgentId && $this->selectedSessionId) {
            $messages = app(MessageManager::class)->read(
                $this->selectedAgentId,
                $this->selectedSessionId,
            );
        }

        return view('livewire.admin.ai.playground', [
            'agents' => $agents,
            'sessions' => $sessions,
            'messages' => $messages,
            'availableProviders' => $availableProviders,
            'providerModelsMap' => $providerModelsMap,
            'markdown' => app(ChatMarkdownRenderer::class),
        ]);
    }

    /**
     * Get the default Agent for the current user.
     */
    private function getDefaultAgent(): ?Employee
    {
        $user = auth()->user();

        if (! $user || ! $user->employee) {
            return null;
        }

        return Employee::query()
            ->agent()
            ->where('supervisor_id', $user->employee->id)
            ->active()
            ->first();
    }
}
