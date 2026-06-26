<?php

use App\Modules\Core\AI\Livewire\Providers\Providers;

/** @var Providers $this */
/** @var bool $laraActivated */
/** @var array<string, bool> $imageProviderTestable */
/** @var array<string, string> $imageProviderStatusLines */
?>
<div>
    <x-slot name="title">{{ __('AI Providers') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('AI Providers')" :subtitle="__('Connect and manage external AI providers, grouped by family. The LLM tab powers Agents; the Image tab powers photo operations such as background removal.')">
            <x-slot name="help">
                <div class="space-y-3">
                    <p>{{ __('Each family has its own tab. The LLM tab lists the language-model providers and models your organization has connected — Agents use these to think, reason, and respond, so at least one active provider with one active model is required. In each model table, use the Access column: ★ / ☆ for the provider default, the checkbox to offer or withhold a model from Agents, and sliders for optional execution overrides.') }}</p>

                    <div>
                        <p class="font-medium text-ink">{{ __('Priority') }}</p>
                        <ul class="list-disc list-inside space-y-1 text-muted mt-1">
                            <li>{{ __('Priority decides which provider/model is the default — the highest-priority active provider with a default model wins.') }}</li>
                            <li>{{ __('Lower numbers mean higher priority — provider #1 is the default.') }}</li>
                            <li>{{ __('Click the ↑ arrow to move a provider up one position.') }}</li>
                            <li>{{ __('Priority is not a runtime fallback chain. When the chosen provider fails, the failure is surfaced honestly — pick another model in the chat or fix the upstream issue.') }}</li>
                        </ul>
                    </div>

                    @include('livewire.admin.ai.providers.partials.model-help')

                    <p>{!! __('Once providers and models are set up here, configure Lara and her task models from the :link.', ['link' => '<a href="' . route('admin.setup.lara') . '" class="text-accent hover:underline">' . e(__('Lara setup')) . '</a>']) !!}</p>
                </div>
            </x-slot>
        </x-ui.page-header>

        @if (! $laraActivated)
            <x-ui.alert variant="info">
                <p>{{ __('Lara stays inactive until one connected provider has an active model available to Agents.') }}</p>
                <p class="mt-2">
                    {!! __('Connect a provider below and enable at least one model. If Lara still needs provisioning afterward, finish it on the :link page.', ['link' => '<a href="' . route('admin.setup.lara') . '" wire:navigate class="text-accent hover:underline">' . e(__('Lara')) . '</a>']) !!}
                </p>
            </x-ui.alert>
        @endif

        <x-ui.session-flash />

        {{-- ═══════════════════════════════════════════════════
             Primary content: AI provider families as tabs. Each family
             shows two cards — connected (activated) providers and providers
             ready for connection. See docs/plans/ai-provider-families.md.
             ═══════════════════════════════════════════════════ --}}
        <x-ui.tabs
            default="llm"
            :tabs="[
                ['id' => 'llm', 'label' => __('LLM'), 'icon' => 'heroicon-o-chat-bubble-left-right'],
                ['id' => 'image', 'label' => __('Vision'), 'icon' => 'heroicon-o-photo'],
            ]"
        >
            {{-- ───────────────── LLM family ───────────────── --}}
            <x-ui.tab id="llm">
                <div class="space-y-section-gap">
                    {{-- Per-family add: a custom LLM provider not in the catalog below. --}}
                    <div class="flex justify-end">
                        <x-ui.button variant="ghost" wire:click="openCreateProvider">
                            <x-icon name="heroicon-m-plus" class="w-4 h-4" />
                            {{ __('Add LLM provider') }}
                        </x-ui.button>
                    </div>

        {{-- Card 1: Connected (activated) providers --}}
        @if($providers->isNotEmpty())
            <x-ui.card>
                <h3 class="text-lg font-medium tracking-tight text-ink mb-3">{{ __('Connected providers') }}</h3>
                <x-ui.table container="flush" :caption="__('Connected providers')">
                    <x-slot name="head">
                            <tr>
                                <x-ui.th class="w-8"><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                                <x-ui.th class="hidden md:table-cell">{{ __('Name') }}</x-ui.th>
                                <x-ui.th>{{ __('Display Name') }}</x-ui.th>
                                <x-ui.th>{{ __('Priority') }}</x-ui.th>
                                <x-ui.th class="hidden md:table-cell">{{ __('Base URL') }}</x-ui.th>
                                <x-ui.th>{{ __('Models') }}</x-ui.th>
                                <x-ui.th>{{ __('Status') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                            </tr>
                        </x-slot>

                            @foreach($providers as $provider)
                                <tr
                                    wire:key="provider-{{ $provider->id }}"
                                    wire:click="toggleProvider({{ $provider->id }})"
                                    x-data="{ flash: false }"
                                    x-init="$wire.$on('priority-changed', (detail) => { const id = Array.isArray(detail) ? detail[0] : detail; if (id == {{ $provider->id }}) { flash = true; setTimeout(() => flash = false, 1800) } })"
                                    :class="flash ? 'bg-accent/10' : ''"
                                    class="cursor-pointer duration-800"
                                >
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-icon
                                            :name="$expandedProviderId === $provider->id ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'"
                                            class="w-4 h-4 text-muted"
                                        />
                                    </td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                        @include('livewire.admin.ai.providers.partials.provider-help-trigger', [
                                            'label' => $provider->name,
                                            'providerKey' => $provider->name,
                                            'authType' => $provider->auth_type->value,
                                            'scope' => 'connected',
                                        ])
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $provider->display_name }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-left" @click.stop>
                                        <div class="inline-flex items-center gap-1">
                                            <span class="text-xs text-muted tabular-nums">{{ $provider->priority }}</span>
                                            @if($provider->priority > 1)
                                                <button
                                                    wire:click="movePriorityUp({{ $provider->id }})"
                                                    class="text-muted hover:text-ink hover:bg-surface-subtle p-0.5 rounded transition-colors"
                                                    type="button"
                                                    title="{{ __('Move up') }}"
                                                    aria-label="{{ __('Move up') }}"
                                                >
                                                    <x-icon name="heroicon-m-arrow-up" class="w-3.5 h-3.5" />
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-xs text-muted font-mono truncate max-w-[200px]">{{ $provider->base_url }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $provider->models_count }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        <div class="flex items-center gap-1.5">
                                            @if($provider->is_active)
                                                <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                        <x-ui.icon-action-group>
                                            <x-ui.icon-action
                                                icon="heroicon-o-pencil"
                                                :label="__('Edit provider')"
                                                :title="__('Edit')"
                                                wire:click.stop="openEditProvider({{ $provider->id }})"
                                            />
                                            <x-ui.icon-action
                                                icon="heroicon-o-link-slash"
                                                :label="__('Disconnect provider')"
                                                :title="__('Disconnect')"
                                                wire:click.stop="confirmDeleteProvider({{ $provider->id }})"
                                            />
                                        </x-ui.icon-action-group>
                                    </td>
                                </tr>

                                {{-- Provider help panel --}}
                                @include('livewire.admin.ai.providers.partials.provider-help-inline-panel', [
                                    'matchKey' => $provider->name,
                                    'scope' => 'connected',
                                    'wireKey' => 'provider-'.$provider->id.'-help',
                                    'providerName' => $provider->display_name,
                                    'colspan' => 8,
                                    'visibilityExpression' => null,
                                ])

                                {{-- Expanded models sub-table --}}
                                @if($expandedProviderId === $provider->id)
                                    <tr wire:key="provider-{{ $provider->id }}-models">
                                        <td colspan="8" class="p-0">
                                            @include('livewire.admin.ai.providers.partials.model-table', [
                                                'provider' => $provider,
                                                'models' => $expandedModels,
                                            ])
                                        </td>
                                    </tr>
                                @endif
                            @endforeach

                </x-ui.table>
            </x-ui.card>
        @endif

        {{-- ═══════════════════════════════════════════════════
             Section 2: Provider Catalog (discovery) — lazy island.
             The ~100-provider models.dev catalog renders after first
             paint so it never blocks the connected-providers view.
             See docs/plans/performance-page-rendering.md (Phase 4).
             ═══════════════════════════════════════════════════ --}}
        <livewire:admin.ai.providers.catalog-browser />

        {{-- Empty state: shown when no providers connected and catalog is the only section --}}
        @if($providers->isEmpty())
            <div class="text-center py-2">
                <p class="text-sm text-muted">{{ __('No providers connected yet. Browse the catalog above and click "Connect" to get started.') }}</p>
                @if (! $laraActivated)
                    <p class="text-xs text-muted mt-2">
                        {{ __('Once you\'ve connected a provider,') }}
                        <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-accent hover:underline">{{ __('activate Lara') }}</a>
                        {{ __('to start chatting.') }}
                    </p>
                @endif
            </div>
        @endif
                </div>{{-- /space-y (LLM tab) --}}
            </x-ui.tab>

            {{-- ───────────────── Image family ───────────────── --}}
            <x-ui.tab id="image">
                <x-ui.card>
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Vision providers') }}</h3>
                    <p class="text-sm text-muted mt-0.5">{{ __('Generate, edit, and process images. Connect one to store its credentials.') }}</p>

                    <x-ui.table container="flush" :caption="__('Vision providers')" class="mt-3">
                        <x-slot name="head">
                            <tr>
                                <x-ui.th>{{ __('Provider') }}</x-ui.th>
                                <x-ui.th class="hidden md:table-cell">{{ __('Capabilities') }}</x-ui.th>
                                <x-ui.th>{{ __('Status') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                            </tr>
                        </x-slot>

                        @forelse($imageProviders as $summary)
                            @php
                                $statusLine = $imageProviderStatusLines[$summary->providerKey] ?? null;
                                $testable = $imageProviderTestable[$summary->providerKey] ?? false;
                            @endphp
                            <tr wire:key="image-provider-{{ $summary->providerKey }}">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">{{ $summary->displayName }}</td>
                                <td class="hidden md:table-cell px-table-cell-x py-table-cell-y text-sm text-muted">{{ $summary->description }}</td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-1.5 whitespace-nowrap">
                                            @if($summary->active)
                                                {{-- The operator's chosen photo-cleanup provider. --}}
                                                <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                            @elseif($summary->connected)
                                                {{-- Usable now: credentials stored AND a working client wired. --}}
                                                <x-ui.badge variant="default">{{ __('Ready') }}</x-ui.badge>
                                            @elseif($summary->configured)
                                                {{-- Credentials stored, but no cleanup client built yet. --}}
                                                <x-ui.badge variant="default">{{ __('Key stored') }}</x-ui.badge>
                                            @else
                                                <span class="text-xs text-muted">{{ __('No key stored') }}</span>
                                            @endif
                                        </div>

                                        @if($statusLine)
                                            <p class="max-w-[18rem] whitespace-normal text-xs text-muted">
                                                {{ __('Last test: :status', ['status' => $statusLine]) }}
                                            </p>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    @if($summary->configured)
                                        <x-ui.icon-action-group>
                                            @if($summary->connected)
                                                @unless($summary->active)
                                                    <x-ui.icon-action
                                                        icon="heroicon-o-check-circle"
                                                        :label="__('Use :provider for photo cleanup', ['provider' => $summary->displayName])"
                                                        :title="__('Set active')"
                                                        wire:click="setActiveImageProvider('{{ $summary->providerKey }}')"
                                                    />
                                                @endunless
                                            @endif
                                            @if($testable)
                                                <x-ui.icon-action
                                                    icon="heroicon-o-signal"
                                                    :label="__('Test :provider connection', ['provider' => $summary->displayName])"
                                                    :title="__('Test connection')"
                                                    wire:click="testImageConnection('{{ $summary->providerKey }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="testImageConnection('{{ $summary->providerKey }}')"
                                                />
                                            @endif
                                            <x-ui.icon-action
                                                icon="heroicon-o-pencil"
                                                :label="__('Edit :provider', ['provider' => $summary->displayName])"
                                                :title="__('Edit')"
                                                wire:click="$dispatch('open-image-setup', { providerKey: '{{ $summary->providerKey }}' })"
                                            />
                                            <x-ui.icon-action
                                                icon="heroicon-o-link-slash"
                                                :label="__('Remove :provider key', ['provider' => $summary->displayName])"
                                                :title="__('Remove key')"
                                                wire:click="$dispatch('confirm-remove-image-provider', { providerKey: '{{ $summary->providerKey }}' })"
                                            />
                                        </x-ui.icon-action-group>
                                    @else
                                        <x-ui.button
                                            type="button"
                                            size="sm"
                                            variant="primary"
                                            wire:click="$dispatch('open-image-setup', { providerKey: '{{ $summary->providerKey }}' })"
                                        >
                                            {{ __('Add key') }}
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No image providers available yet.') }}</td>
                            </tr>
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            </x-ui.tab>
        </x-ui.tabs>
    </div>

    {{-- Image-provider setup modal (opened via the Vision tab's Connect button / edit icon) --}}
    <livewire:admin.ai.providers.image-setup />

    {{-- Provider Create/Edit Modal (manual add) --}}
    <x-ui.modal wire:model="showProviderForm" class="max-w-lg">
        <div @class([
            'p-card-inner',
            // Keep modal height stable across tabs (General is taller than Advanced).
            // This must be >= the General tab height, otherwise switching to Advanced will shrink.
            'min-h-[30rem]' => $isEditingProvider && ($this->advancedSettingsSchema ?? []) !== [],
        ])>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">
                    {{ $isEditingProvider ? __('Edit Provider') : __('Add Provider') }}
                </h3>
                <button wire:click="$set('showProviderForm', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <x-ui.session-flash class="mb-4" />

            @php
                $advancedAvailable = $isEditingProvider && ($this->advancedSettingsSchema ?? []) !== [];
            @endphp

            @if($advancedAvailable)
                <x-ui.tabs :tabs="[
                    ['id' => 'general', 'label' => __('General')],
                    ['id' => 'advanced', 'label' => __('Advanced')],
                ]" default="general" persistence="none">
                    <x-ui.tab id="general">
                        @include('livewire.admin.ai.providers.partials.provider-form')
                    </x-ui.tab>

                    <x-ui.tab id="advanced">
                        <div class="space-y-4">
                            @foreach(($this->advancedSettingsSchema ?? []) as $setting)
                                @php
                                    $stateKey = $setting['state_key'] ?? '';
                                    $label = $setting['label'] ?? $stateKey;
                                    $help = $setting['help'] ?? null;
                                    $type = $setting['input_type'] ?? 'text';
                                @endphp

                                @if(is_string($stateKey) && $stateKey !== '')
                                    <div>
                                        <x-ui.input
                                            :id="'provider-advanced-'.$stateKey"
                                            wire:model="advancedSettings.{{ $stateKey }}"
                                            :type="$type"
                                            :label="$label"
                                            :error="$errors->first('advancedSettings.'.$stateKey)"
                                        />
                                        @if(is_string($help) && $help !== '')
                                            <p class="text-xs text-muted mt-1">{{ $help }}</p>
                                        @endif
                                    </div>
                                @endif
                            @endforeach

                            <div class="flex justify-end gap-2 pt-2">
                                <x-ui.button
                                    variant="ghost"
                                    type="button"
                                    wire:click="resetAdvancedSettings"
                                >
                                    {{ __('Reset to default') }}
                                </x-ui.button>
                                <x-ui.button
                                    variant="primary"
                                    type="button"
                                    wire:click="saveAdvancedSettings"
                                >
                                    {{ __('Save') }}
                                </x-ui.button>
                            </div>
                        </div>
                    </x-ui.tab>
                </x-ui.tabs>
            @else
                @include('livewire.admin.ai.providers.partials.provider-form')
            @endif
        </div>
    </x-ui.modal>

    {{-- Provider Disconnect Confirmation --}}
    <x-ui.modal wire:model="showDeleteProvider" class="max-w-sm">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Disconnect Provider') }}</h3>
                <button wire:click="$set('showDeleteProvider', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <p class="text-sm text-muted mb-4">
                {{ __('Are you sure you want to disconnect :name? All associated models will also be removed.', ['name' => $deletingProviderName]) }}
            </p>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="$set('showDeleteProvider', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="deleteProvider">{{ __('Disconnect') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Model Add Modal (simplified — no cost fields, no edit) --}}
    <x-ui.modal wire:model="showModelForm" class="max-w-sm">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Model') }}</h3>
                <button wire:click="$set('showModelForm', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <x-ui.session-flash class="mb-4" />

            <form wire:submit="saveModel" class="space-y-4">
                <x-ui.input
                    id="providers-model-name"
                    wire:model="modelModelName"
                    label="{{ __('Model ID') }}"
                    required
                    placeholder="{{ __('e.g. gpt-4o') }}"
                    :error="$errors->first('modelModelName')"
                />

                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button variant="ghost" wire:click="$set('showModelForm', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Add') }}</x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.modal>

    {{-- Per-model execution controls editor --}}
    @php
        $editingControlsSchema = $editingControlsModelId !== null ? $this->editingModelExecutionControlSchema() : null;
    @endphp
    <x-ui.modal wire:model="showExecutionControlsModal" class="max-w-2xl">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Execution Controls') }}</h3>
                    @if (is_array($editingControlsSchema))
                        <p class="text-xs text-muted mt-0.5 font-mono">{{ ($editingControlsSchema['provider_name'] ?? '—') . '/' . ($editingControlsSchema['model'] ?? '—') }}</p>
                    @endif
                </div>
                <button wire:click="closeModelExecutionControls" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            @if (is_array($editingControlsSchema))
                @include('livewire.admin.ai.partials.execution-controls', [
                    'schema' => $editingControlsSchema,
                    'statePath' => 'editingExecutionControls',
                    'subtitle' => __('Per-model defaults. Saved automatically as you change values; agents using this model inherit these settings unless a session override is set.'),
                ])
            @else
                <p class="text-sm text-muted">{{ __('No editable controls available for this model.') }}</p>
            @endif

            <div class="mt-6 flex justify-between gap-2">
                <x-ui.button variant="ghost" wire:click="clearModelExecutionControls" class="text-red-500 hover:text-red-600">
                    <x-icon name="heroicon-o-arrow-uturn-left" class="w-3.5 h-3.5" />
                    {{ __('Reset to system defaults') }}
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="closeModelExecutionControls">{{ __('Done') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
