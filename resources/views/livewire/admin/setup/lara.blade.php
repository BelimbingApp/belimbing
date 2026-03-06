<?php

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $selectedProviderId = null;

    public ?string $selectedModelId = null;

    public function mount(): void
    {
        $laraExists = Employee::query()->where('id', Employee::LARA_ID)->exists();
        $licenseeExists = Company::query()->where('id', Company::LICENSEE_ID)->exists();

        if ($laraExists && $licenseeExists) {
            $resolver = app(ConfigResolver::class);
            $configs = $resolver->resolve(Employee::LARA_ID);
            $activated = count($configs) > 0;

            if (! $activated) {
                $default = $resolver->resolveDefault(Company::LICENSEE_ID);
                $activated = $default !== null;
            }

            if ($activated) {
                $this->redirect(route('admin.ai.playground'), navigate: true);
            }
        }
    }

    /**
     * Provision the Lara employee record.
     *
     * Delegates to Employee::provisionLara() — the single source of truth.
     */
    public function provisionLara(): void
    {
        if (Employee::provisionLara()) {
            Session::flash('success', __('Lara has been provisioned.'));
        }
    }

    /**
     * Activate Lara by writing workspace config with selected provider and model.
     */
    public function activateLara(): void
    {
        $this->validate([
            'selectedProviderId' => ['required', 'integer', 'exists:ai_providers,id'],
            'selectedModelId' => ['required', 'string'],
        ]);

        $provider = AiProvider::query()->findOrFail($this->selectedProviderId);

        $config = [
            'llm' => [
                'models' => [
                    [
                        'provider' => $provider->name,
                        'model' => $this->selectedModelId,
                    ],
                ],
            ],
        ];

        $resolver = app(ConfigResolver::class);
        $resolver->writeWorkspaceConfig(Employee::LARA_ID, $config);

        Session::flash('success', __('Lara has been activated.'));
        $this->redirect(route('admin.ai.playground'), navigate: true);
    }

    /**
     * Provide data to the Blade template.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $laraExists = Employee::query()->where('id', Employee::LARA_ID)->exists();
        $licenseeExists = Company::query()->where('id', Company::LICENSEE_ID)->exists();

        $laraActivated = false;
        if ($laraExists && $licenseeExists) {
            $resolver = app(ConfigResolver::class);
            $configs = $resolver->resolve(Employee::LARA_ID);
            if (count($configs) > 0) {
                $laraActivated = true;
            } else {
                $default = $resolver->resolveDefault(Company::LICENSEE_ID);
                $laraActivated = $default !== null;
            }
        }

        $providers = collect();
        $models = collect();

        if ($licenseeExists) {
            $providers = AiProvider::query()
                ->forCompany(Company::LICENSEE_ID)
                ->active()
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'name']);
        }

        if ($this->selectedProviderId) {
            $models = AiProviderModel::query()
                ->where('ai_provider_id', $this->selectedProviderId)
                ->active()
                ->orderBy('model_id')
                ->get(['id', 'model_id']);
        }

        return [
            'laraExists' => $laraExists,
            'licenseeExists' => $licenseeExists,
            'laraActivated' => $laraActivated,
            'providers' => $providers,
            'models' => $models,
        ];
    }
}; ?>

<div>
    <x-slot name="title">{{ __('Set Up Lara') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Set Up Lara')" :subtitle="__('Activate BLB\'s built-in AI assistant')">
            <x-slot name="actions">
                <a href="{{ route('admin.ai.playground') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        <x-ui.alert variant="info">
            {{ __('Lara Belimbing is BLB\'s built-in AI assistant — your guide to setup, configuration, and daily operations. She needs an AI provider to function.') }}
        </x-ui.alert>

        @if (! $licenseeExists)
            <x-ui.alert variant="warning">
                {{ __('The Licensee company must be set up before Lara can be provisioned.') }}
                <a href="{{ route('admin.setup.licensee') }}" wire:navigate class="text-accent hover:underline">{{ __('Set up Licensee') }}</a>
            </x-ui.alert>
        @elseif (! $laraExists)
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Provision Lara') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Lara\'s employee record does not exist yet. Provision her to create the system Digital Worker record for the Licensee company.') }}</p>

                <form wire:submit="provisionLara">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Provision Lara') }}
                    </x-ui.button>
                </form>
            </x-ui.card>
        @elseif (! $laraActivated)
            @if ($providers->isEmpty())
                <x-ui.alert variant="info">
                    {{ __('No AI providers are configured for the Licensee company. You need to set up at least one provider before activating Lara.') }}
                    <br><br>
                    <a href="{{ route('admin.ai.providers') }}" wire:navigate class="text-accent hover:underline">{{ __('Configure AI Providers') }}</a>
                </x-ui.alert>
            @else
                <x-ui.card>
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Activate Lara') }}</h3>
                    <p class="text-xs text-muted mb-4">{{ __('Select an AI provider and model for Lara. Frontier models (Claude Opus, GPT-5 class) are recommended for the best experience with orchestration and reasoning.') }}</p>

                    <form wire:submit="activateLara" class="space-y-4 max-w-md">
                        <x-ui.select wire:model.live="selectedProviderId" label="{{ __('Provider') }}" :error="$errors->first('selectedProviderId')">
                            <option value="">{{ __('Select a provider...') }}</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}">{{ $provider->display_name }}</option>
                            @endforeach
                        </x-ui.select>

                        @if ($selectedProviderId)
                            @if ($models->isEmpty())
                                <p class="text-xs text-muted">{{ __('No active models found for this provider. Please add models in the provider settings.') }}</p>
                            @else
                                <x-ui.select wire:model="selectedModelId" label="{{ __('Model') }}" :error="$errors->first('selectedModelId')">
                                    <option value="">{{ __('Select a model...') }}</option>
                                    @foreach($models as $model)
                                        <option value="{{ $model->model_id }}">{{ $model->model_id }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endif
                        @endif

                        <div class="flex items-center gap-4">
                            <x-ui.button type="submit" variant="primary">
                                {{ __('Activate Lara') }}
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif
        @endif
    </div>
</div>
