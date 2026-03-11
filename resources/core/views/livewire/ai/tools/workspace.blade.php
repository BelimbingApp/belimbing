<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Tools\Workspace $this */
?>
<div>
    {{-- Breadcrumb navigation --}}
    <div class="mb-4">
        <a
            href="{{ route('admin.ai.tools') }}"
            wire:navigate
            class="inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink transition-colors"
        >
            <x-icon name="heroicon-o-chevron-left" class="w-4 h-4" />
            {{ __('Back to Tools') }}
        </a>
    </div>

    @if($metadata)
        {{-- Header --}}
        <div class="mb-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-xl font-semibold text-ink">{{ $metadata->displayName }}</h2>
                        <button
                            type="button"
                            x-data="{ pinData: {{ json_encode(['pinnableId' => 'ai.tools.' . $toolName, 'label' => __('Administration') . '/' . __('AI') . '/' . __('Tools') . '/' . $metadata->displayName, 'url' => route('admin.ai.tools', ['toolName' => $toolName])], JSON_UNESCAPED_SLASHES) }} }"
                            @click="$dispatch('toggle-page-pin', pinData)"
                            class="inline-flex items-center justify-center w-6 h-6 rounded-sm text-muted hover:text-accent transition-colors"
                            title="{{ __('Pin to sidebar') }}"
                            aria-label="{{ __('Pin :page to sidebar', ['page' => $metadata->displayName]) }}"
                        >
                            <x-icon name="heroicon-o-pin" class="w-4 h-4" />
                        </button>
                    </div>
                    <p class="text-sm text-muted mt-1">{{ $metadata->summary }}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <x-ui.badge :variant="$readiness->color()">{{ $readiness->label() }}</x-ui.badge>
                    @if($lastVerified)
                        <x-ui.badge :variant="$lastVerified['success'] ? 'success' : 'danger'">
                            {{ $lastVerified['success'] ? __('Verified') : __('Failed') }}
                        </x-ui.badge>
                    @else
                        <x-ui.badge variant="default">{{ __('Not Verified') }}</x-ui.badge>
                    @endif
                </div>
            </div>
        </div>

        {{-- Main content grid --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Left column: Overview + Try It --}}
            <div class="lg:col-span-2 space-y-4">
                {{-- About this tool --}}
                <x-ui.card>
                    <h3 class="text-base font-semibold text-ink mb-2">{{ __('About this Tool') }}</h3>
                    <p class="text-sm text-muted leading-relaxed">{{ $metadata->explanation }}</p>
                </x-ui.card>

                {{-- Try It Console --}}
                @if(count($metadata->testExamples) > 0)
                    <x-ui.card>
                        <h3 class="text-base font-semibold text-ink mb-2">{{ __('Try It') }}</h3>
                        <p class="text-xs text-muted mb-3">{{ __('Run a test to verify this tool is configured correctly. Results also update the verification status.') }}</p>

                        <div class="space-y-3">
                            @foreach($metadata->testExamples as $index => $example)
                                <div class="rounded-lg bg-surface-subtle p-3 border border-border-default">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="text-sm font-medium text-ink">{{ $example['label'] }}</div>
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            wire:click="tryIt({{ $index }})"
                                            wire:loading.attr="disabled"
                                        >
                                            <x-icon name="heroicon-m-play" class="w-4 h-4" />
                                            {{ __('Run') }}
                                        </x-ui.button>
                                    </div>
                                    <code class="text-xs text-muted block whitespace-pre-wrap break-all">{{ is_string($example['input']) ? $example['input'] : json_encode($example['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code>
                                </div>
                            @endforeach
                        </div>

                        {{-- Try It Result --}}
                        @if($tryItResult !== null)
                            <div class="mt-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-sm font-semibold text-ink">{{ __('Result') }}</h4>
                                    <button
                                        wire:click="clearTryItResult"
                                        class="text-xs text-muted hover:text-ink transition-colors"
                                    >
                                        {{ __('Clear') }}
                                    </button>
                                </div>

                                @if($tryItIsError)
                                    {{-- Error result with structured display --}}
                                    <div class="rounded-lg border border-status-danger-border bg-status-danger-subtle p-3 space-y-2">
                                        <div class="flex items-start gap-2">
                                            <x-icon name="heroicon-o-exclamation-triangle" class="w-4 h-4 text-status-danger shrink-0 mt-0.5" />
                                            <pre class="text-xs text-ink whitespace-pre-wrap break-words font-mono flex-1">{{ $tryItResult }}</pre>
                                        </div>

                                        @if($tryItErrorPayload && isset($tryItErrorPayload['hint']))
                                            <p class="text-xs text-muted ml-6">{{ $tryItErrorPayload['hint'] }}</p>
                                        @endif

                                        @if($tryItErrorPayload && isset($tryItErrorPayload['action']))
                                            <div class="ml-6">
                                                <x-ui.button
                                                    variant="ghost"
                                                    size="sm"
                                                    x-data
                                                    x-on:click="$dispatch('open-lara-chat', { prompt: {{ json_encode($tryItErrorPayload['action']['suggested_prompt']) }} })"
                                                >
                                                    <x-icon name="heroicon-o-chat-bubble-left-ellipsis" class="w-4 h-4" />
                                                    {{ $tryItErrorPayload['action']['label'] }}
                                                </x-ui.button>
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    {{-- Success result --}}
                                    <div class="rounded-lg bg-surface-subtle border border-border-default p-3 max-h-64 overflow-y-auto">
                                        <pre class="text-xs text-ink whitespace-pre-wrap break-words font-mono">{{ $tryItResult }}</pre>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($verificationError)
                            <div class="mt-4 rounded-lg border border-status-warning-border bg-status-warning-subtle p-3" role="alert">
                                <p class="text-sm font-medium text-status-warning">{{ __('Verification status could not be saved') }}</p>
                                <p class="text-xs text-muted mt-1">{{ $verificationError }}</p>
                            </div>
                        @endif
                    </x-ui.card>
                @endif

                {{-- Limits --}}
                @if(count($metadata->limits) > 0)
                    <x-ui.card>
                        <h3 class="text-base font-semibold text-ink mb-2">{{ __('Limits & Guardrails') }}</h3>
                        <ul class="space-y-1.5">
                            @foreach($metadata->limits as $limit)
                                <li class="flex items-start gap-2 text-sm">
                                    <x-icon name="heroicon-o-shield-check" class="w-4 h-4 text-muted shrink-0 mt-0.5" />
                                    <span class="text-muted">{{ $limit }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif
            </div>

            {{-- Right column: Metadata + Setup + Verification --}}
            <div class="space-y-4">
                {{-- Quick facts --}}
                <x-ui.card>
                    <h3 class="text-base font-semibold text-ink mb-3">{{ __('Details') }}</h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs text-muted uppercase tracking-wider">{{ __('Machine Name') }}</dt>
                            <dd class="text-sm text-ink font-mono mt-0.5">{{ $metadata->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted uppercase tracking-wider">{{ __('Category') }}</dt>
                            <dd class="text-sm text-ink mt-0.5">{{ $metadata->category->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted uppercase tracking-wider">{{ __('Risk Class') }}</dt>
                            <dd class="mt-0.5"><x-ui.badge :variant="$metadata->riskClass->color()">{{ $metadata->riskClass->label() }}</x-ui.badge></dd>
                        </div>
                        @if($metadata->capability)
                            <div>
                                <dt class="text-xs text-muted uppercase tracking-wider">{{ __('Capability') }}</dt>
                                <dd class="text-sm text-ink font-mono mt-0.5">{{ $metadata->capability }}</dd>
                            </div>
                        @endif
                    </dl>
                </x-ui.card>

                {{-- Verification Status --}}
                <x-ui.card>
                    <h3 class="text-base font-semibold text-ink mb-2">{{ __('Verification') }}</h3>
                    @if($lastVerified)
                        <div class="space-y-2">
                            <div class="flex items-center gap-2">
                                @if($lastVerified['success'])
                                    <x-icon name="heroicon-o-check-circle" class="w-5 h-5 text-status-success" />
                                    <span class="text-sm text-ink">{{ __('Last test passed') }}</span>
                                @else
                                    <x-icon name="heroicon-o-x-circle" class="w-5 h-5 text-status-danger" />
                                    <span class="text-sm text-ink">{{ __('Last test failed') }}</span>
                                @endif
                            </div>
                            <p class="text-xs text-muted">
                                {{ \Carbon\Carbon::parse($lastVerified['at'])->diffForHumans() }}
                            </p>
                        </div>
                    @else
                        <p class="text-sm text-muted">{{ __('No verification tests have been run yet. Use the "Try It" panel to verify this tool works correctly.') }}</p>
                    @endif
                </x-ui.card>

                {{-- Configuration / Setup --}}
                @if(count($metadata->configFields) > 0)
                    <x-ui.card>
                        <h3 class="text-base font-semibold text-ink mb-2">{{ __('Configuration') }}</h3>
                        <p class="text-xs text-muted mb-3">{{ __('Configure this tool\'s settings. Secrets are encrypted at rest.') }}</p>

                        <form wire:submit="saveConfig" class="space-y-3" x-data>
                            @foreach($metadata->configFields as $field)
                                @php
                                    $showWhenMatch = true;
                                    if ($field->showWhen) {
                                        [$showKey, $showValue] = explode('=', $field->showWhen, 2);
                                        $showWhenMatch = ($configValues[$showKey] ?? '') === $showValue;
                                    }
                                @endphp

                                @if($showWhenMatch)
                                    @if($field->type === 'select')
                                        <x-ui.select
                                            wire:model.live="configValues.{{ $field->key }}"
                                            label="{{ $field->label }}"
                                        >
                                            <option value="">{{ __('— Select —') }}</option>
                                            @foreach($field->options as $optValue => $optLabel)
                                                <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    @elseif($field->type === 'secret')
                                        <x-ui.input
                                            type="password"
                                            wire:model="configValues.{{ $field->key }}"
                                            label="{{ $field->label }}"
                                            placeholder="{{ __('Enter to set, leave empty to keep current') }}"
                                            autocomplete="off"
                                        />
                                    @elseif($field->type === 'boolean')
                                        <div class="space-y-1">
                                            <label class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
                                                {{ $field->label }}
                                            </label>
                                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    wire:model="configValues.{{ $field->key }}"
                                                    value="1"
                                                    class="rounded border-border-input text-accent focus:ring-accent"
                                                >
                                                <span class="text-sm text-ink">{{ __('Enabled') }}</span>
                                            </label>
                                        </div>
                                    @else
                                        <x-ui.input
                                            type="text"
                                            wire:model="configValues.{{ $field->key }}"
                                            label="{{ $field->label }}"
                                        />
                                    @endif

                                    @if($field->help)
                                        <p class="text-xs text-muted -mt-1.5">{{ $field->help }}</p>
                                    @endif
                                @endif
                            @endforeach

                            <x-ui.button type="submit" variant="primary" size="sm" class="w-full">
                                {{ __('Save Configuration') }}
                            </x-ui.button>

                            @if($configSaved)
                                @if($configSaveError)
                                    <div class="rounded-lg border border-status-warning-border bg-status-warning-subtle p-3" role="alert">
                                        <p class="text-xs text-status-warning">{{ $configSaved }}</p>
                                    </div>
                                @else
                                    <p class="text-xs text-status-success text-center">{{ $configSaved }}</p>
                                @endif
                            @endif
                        </form>
                    </x-ui.card>
                @endif

                {{-- Setup requirements (for tools without configFields) --}}
                @if(count($metadata->configFields) === 0 && count($metadata->setupRequirements) > 0)
                    <x-ui.card>
                        <h3 class="text-base font-semibold text-ink mb-2">{{ __('Setup Checklist') }}</h3>
                        <p class="text-xs text-muted mb-3">{{ __('Requirements that must be met for this tool to work correctly.') }}</p>

                        <ul class="space-y-2">
                            @foreach($metadata->setupRequirements as $req)
                                <li class="flex items-start gap-2">
                                    <x-icon name="heroicon-o-information-circle" class="w-4 h-4 text-muted shrink-0 mt-0.5" />
                                    <span class="text-sm text-muted">{{ $req }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif
            </div>
        </div>
    @else
        {{-- Tool not found --}}
        <x-ui.card>
            <div class="text-center py-8">
                <p class="text-sm text-muted">{{ __('Tool ":name" not found in the metadata registry.', ['name' => $toolName]) }}</p>
            </div>
        </x-ui.card>
    @endif
</div>
