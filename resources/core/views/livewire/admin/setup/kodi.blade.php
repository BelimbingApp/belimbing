<div class="space-y-6">
    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
    @endif

    <div>
        <h1 class="text-xl font-semibold text-ink">{{ __('Kodi Setup') }}</h1>
        <p class="text-sm text-muted mt-1">{{ __('Configure the company’s default primary and backup models for delegated tasks.') }}</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="space-y-6">
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Current Configuration') }}</h3>
                <div class="space-y-2 text-sm">
                    @if ($activeModelId !== null)
                        <div class="flex items-baseline gap-3">
                            <span class="text-sm text-muted">{{ __('Primary') }}</span>
                            <span class="text-sm font-medium text-ink font-mono">{{ ($activeProviderName ?? '—') . '/' . ($activeModelId ?? '—') }}</span>
                            <x-ui.badge variant="primary">{{ __('Active') }}</x-ui.badge>
                        </div>
                    @else
                        <div class="flex items-center gap-2 text-warning">
                            <x-icon name="heroicon-o-exclamation-triangle" class="w-4 h-4" />
                            <span>{{ __('Kodi does not have a primary model configured yet.') }}</span>
                        </div>
                    @endif
                </div>

                @if ($activeBackupModelId !== null)
                    <div class="flex items-baseline gap-3 mt-2">
                        <span class="text-sm text-muted">{{ __('Backup') }}</span>
                        <span class="text-sm font-medium text-ink font-mono">{{ $activeBackupProviderName ?? '—' }}/{{ $activeBackupModelId }}</span>
                    </div>
                @endif

                <div class="mt-4 text-xs text-muted space-y-2">
                    <p>{{ __('Kodi uses the company’s primary model by default. If that model cannot be used, the backup model is applied automatically when available.') }}</p>
                    <p>{{ __('The primary model must stay delegated-agent enabled and active so task routing remains available.') }}</p>
                </div>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Primary Model') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Select the default primary model for delegated task execution.') }}</p>

                @if ($providerId === null)
                    <div class="rounded-2xl border border-dashed border-line/70 px-4 py-6 text-sm text-muted">
                        {{ __('No delegated-agent providers are currently enabled. Enable at least one provider and delegated model before configuring Kodi.') }}
                    </div>
                @else
                    <div class="space-y-3">
                        <x-ui.select wire:model.live="providerId" label="{{ __('Provider') }}">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider['id'] }}">{{ $provider['display_name'] }}</option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.select wire:model="modelId" label="{{ __('Model') }}">
                            @forelse ($models as $model)
                                <option value="{{ $model['id'] }}">{{ $model['display_name'] }}</option>
                            @empty
                                <option value="">{{ __('No delegated models available for this provider.') }}</option>
                            @endforelse
                        </x-ui.select>

                        <div class="flex items-center justify-end">
                            <x-ui.button wire:click="save" :disabled="$models->isEmpty() || blank($modelId)">
                                {{ __('Save primary model') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Backup Model') }} <span class="normal-case tracking-normal font-normal">{{ __('(Optional)') }}</span></h3>
                <p class="text-xs text-muted mb-4">{{ __('If the primary model fails, the system will automatically retry with this model.') }}</p>

                @if ($backupProviderId !== null)
                    <div class="mb-3 flex items-center justify-end">
                        <x-ui.button variant="ghost" size="sm" wire:click="removeBackup" class="text-red-500 hover:text-red-600">
                            <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                            {{ __('Clear backup') }}
                        </x-ui.button>
                    </div>
                @endif

                @if ($providerId === null)
                    <div class="rounded-2xl border border-dashed border-line/70 px-4 py-6 text-sm text-muted">
                        {{ __('Configure a primary model first before selecting a backup.') }}
                    </div>
                @else
                    <div class="space-y-3">
                        <x-ui.select wire:model.live="backupProviderId" label="{{ __('Provider') }}" placeholder="{{ __('No backup provider selected') }}">
                            @foreach ($providers as $provider)
                                <option value="{{ $provider['id'] }}">{{ $provider['display_name'] }}</option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.select wire:model="backupModelId" label="{{ __('Model') }}" placeholder="{{ __('No backup model selected') }}">
                            @foreach ($backupModels as $model)
                                <option value="{{ $model['id'] }}">{{ $model['display_name'] }}</option>
                            @endforeach
                        </x-ui.select>

                        <div class="flex items-center justify-end">
                            <x-ui.button variant="secondary" wire:click="saveBackup" :disabled="$backupModels->isEmpty() || blank($backupModelId)">
                                {{ __('Save backup model') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card class="h-fit">
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('How Kodi uses this') }}</h3>
            <ol class="space-y-3 text-sm text-muted list-decimal list-inside">
                <li>{{ __('Tasks delegated from Lara default to this primary model.') }}</li>
                <li>{{ __('If the primary model is unavailable, Kodi retries with the configured backup model.') }}</li>
                <li>{{ __('Only delegated-agent enabled models appear in these lists.') }}</li>
            </ol>
        </x-ui.card>
    </div>
</div>