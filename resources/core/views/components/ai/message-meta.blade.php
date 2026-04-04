<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'timestamp',
    'provider' => null,
    'model' => null,
    'runId' => null,
    'tone' => 'muted',
    'tokens' => null,
    'latencyMs' => null,
    'timeoutSeconds' => null,
    'retryAttempts' => null,
    'fallbackAttempts' => null,
    'errorType' => null,
    'errorMessage' => null,
    'runStatus' => null,
])

@php
    $providerLabel = is_string($provider) && $provider !== '' && $provider !== 'unknown'
        ? $provider
        : null;
    $modelLabel = is_string($model) && $model !== '' && $model !== 'unknown'
        ? $model
        : null;

    $llmLabel = match (true) {
        $providerLabel !== null && $modelLabel !== null => $providerLabel.'/'.$modelLabel,
        $providerLabel !== null => $providerLabel,
        $modelLabel !== null => $modelLabel,
        default => null,
    };
    $runIdLabel = is_string($runId) && $runId !== '' ? $runId : null;
    $runDetailsId = $runIdLabel !== null ? 'run-details-'.$runIdLabel : null;
    $runDetailsTitleId = $runIdLabel !== null ? 'run-details-title-'.$runIdLabel : null;

    $toneClasses = match ($tone) {
        'inverse' => 'text-accent-on/70 focus-visible:ring-accent-on/40',
        default => 'text-muted focus-visible:ring-accent/40',
    };

    $hasRunMeta = $runIdLabel !== null && ($tokens !== null || $latencyMs !== null || $retryAttempts !== null || $fallbackAttempts !== null || $errorType !== null);

    $canAccessControlPlane = false;
    if ($hasRunMeta && auth()->check()) {
        $actor = \App\Base\Authz\DTO\Actor::forUser(auth()->user());
        $canAccessControlPlane = app(\App\Base\Authz\Contracts\AuthorizationService::class)
            ->can($actor, 'admin.ai.control-plane')
            ->allowed;
    }

    $promptTokens = $tokens['prompt'] ?? null;
    $completionTokens = $tokens['completion'] ?? null;
@endphp

<div x-data="{ tooltipOpen: false }" class="relative mt-1 inline-flex max-w-full text-[10px]">
    <span
        class="{{ $toneClasses }} inline-flex max-w-full items-center gap-1 rounded-md tabular-nums outline-none transition-colors focus-visible:ring-2 focus-visible:ring-offset-0"
    >
        <button
            type="button"
            tabindex="0"
            @mouseenter="tooltipOpen = true"
            @mouseleave="tooltipOpen = false"
            @focus="tooltipOpen = true"
            @blur="tooltipOpen = false"
            class="shrink-0 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-accent/40 focus-visible:ring-offset-0"
        >
            <x-ui.datetime :value="$timestamp" format="time" />
        </button>

        @if ($llmLabel !== null)
            <span aria-hidden="true" class="shrink-0">·</span>
            <span class="truncate">
                {{ $llmLabel }}
            </span>
        @endif

        @if ($runIdLabel !== null)
            <span aria-hidden="true" class="shrink-0">·</span>
            <span class="relative inline-flex" x-data="{ popoverOpen: false }">
                <button
                    type="button"
                    @click="popoverOpen = !popoverOpen"
                    @keydown.escape="popoverOpen = false"
                    tabindex="0"
                    class="truncate outline-none focus-visible:ring-2 focus-visible:ring-accent/40 focus-visible:ring-offset-0 hover:text-ink transition-colors"
                    :aria-expanded="popoverOpen"
                    @if ($runDetailsId !== null)
                        aria-controls="{{ $runDetailsId }}"
                        aria-haspopup="dialog"
                    @endif
                >
                    {{ \Illuminate\Support\Str::limit($runIdLabel, 8, '…') }}
                </button>
                <div
                    @if ($runDetailsId !== null)
                        id="{{ $runDetailsId }}"
                    @endif
                    x-show="popoverOpen"
                    x-cloak
                    @click.outside="popoverOpen = false"
                    @keydown.escape.window="popoverOpen = false"
                    x-transition.opacity.duration.100ms
                    role="dialog"
                    aria-modal="false"
                    @if ($runDetailsTitleId !== null)
                        aria-labelledby="{{ $runDetailsTitleId }}"
                    @endif
                    class="absolute bottom-full left-0 z-30 mb-1 w-56 rounded-xl border border-border-default bg-surface-card shadow-lg p-2.5 text-[11px] text-ink"
                >
                    <div
                        @if ($runDetailsTitleId !== null)
                            id="{{ $runDetailsTitleId }}"
                        @endif
                        class="font-medium text-muted mb-1.5"
                    >
                        {{ __('Run') }}
                    </div>
                    <div class="space-y-1.5">
                        <div class="flex justify-between">
                            <span class="text-muted">{{ __('ID') }}</span>
                            <span class="font-mono truncate max-w-[10rem]" title="{{ $runIdLabel }}">{{ $runIdLabel }}</span>
                        </div>

                        @if ($runStatus !== null)
                            <div class="flex justify-between">
                                <span class="text-muted">{{ __('Status') }}</span>
                                <span>{{ $runStatus }}</span>
                            </div>
                        @endif

                        @if ($promptTokens !== null || $completionTokens !== null)
                            <div class="flex justify-between">
                                <span class="text-muted">{{ __('Tokens') }}</span>
                                <span class="tabular-nums">{{ number_format($promptTokens ?? 0) }} → {{ number_format($completionTokens ?? 0) }}</span>
                            </div>
                        @endif

                        @if ($latencyMs !== null)
                            <div class="flex justify-between">
                                <span class="text-muted">{{ __('Latency') }}</span>
                                <span class="tabular-nums">
                                    {{ number_format($latencyMs / 1000, 1) }}s
                                    @if ($timeoutSeconds !== null)
                                        <span class="text-muted">/ {{ $timeoutSeconds }}s</span>
                                    @endif
                                </span>
                            </div>
                        @endif

                        @if (is_array($retryAttempts) && count($retryAttempts) > 0)
                            <div class="flex justify-between">
                                <span class="text-muted">{{ __('Retries') }}</span>
                                <span>{{ count($retryAttempts) }}</span>
                            </div>
                        @endif

                        @if (is_array($fallbackAttempts) && count($fallbackAttempts) > 0)
                            <div class="border-t border-border-default pt-1.5 mt-1.5">
                                <div class="flex justify-between">
                                    <span class="text-amber-500">{{ __('Fallbacks') }}</span>
                                    <span class="text-amber-500">{{ count($fallbackAttempts) }}</span>
                                </div>
                                @foreach ($fallbackAttempts as $attempt)
                                    @php
                                        $attemptError = ($canAccessControlPlane && ! empty($attempt['diagnostic']))
                                            ? $attempt['diagnostic']
                                            : ($attempt['error'] ?? __('unknown error'));
                                    @endphp
                                    <div class="text-muted mt-0.5 text-[10px]">
                                        {{ $attempt['provider'] ?? '?' }}/{{ $attempt['model'] ?? '?' }}
                                        — <span class="text-red-400 line-clamp-3">{{ $attemptError }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($errorType !== null)
                            <div class="border-t border-border-default pt-1.5 mt-1.5">
                                <div class="flex justify-between">
                                    <span class="text-red-500">{{ __('Error') }}</span>
                                    <span class="text-red-500">{{ $errorType }}</span>
                                </div>
                                @if ($errorMessage !== null)
                                    <div class="text-muted mt-0.5 line-clamp-2">{{ $errorMessage }}</div>
                                @endif
                            </div>
                        @endif

                        @if ($canAccessControlPlane)
                            <div class="border-t border-border-default pt-1.5 mt-1.5">
                                <a
                                    href="{{ route('admin.ai.control-plane', ['inspectRunId' => $runIdLabel]) }}"
                                    wire:navigate
                                    class="text-[10px] text-accent hover:underline"
                                >
                                    {{ __('View in Control Plane') }} →
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </span>
        @endif
    </span>

    <span
        x-show="tooltipOpen"
        x-cloak
        x-transition.opacity.duration.100ms
        role="tooltip"
        class="pointer-events-none absolute bottom-full left-0 z-20 mb-1 whitespace-nowrap rounded-lg border border-border-default bg-surface-card px-2 py-1 text-[10px] text-ink shadow-sm"
    >
        <x-ui.datetime :value="$timestamp" format="datetime" />
    </span>
</div>
