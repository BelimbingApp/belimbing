<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Support\Number;

/** @var array<string, mixed> $block */
/** @var string $runId */
$durationLabel = isset($block['duration_ms']) && $block['duration_ms'] !== null
    ? number_format($block['duration_ms'] / 1000, 2).'s'
    : '---';
?>
<details
    id="wire-log-entry-{{ $block['first_entry_number'] }}"
    class="rounded-xl border border-border-default/60 bg-surface-subtle/40"
>
    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 p-3 text-sm text-ink">
        <div class="flex flex-wrap items-center gap-2 text-xs text-muted">
            <x-ui.badge variant="info">
                {{ __(':count chunks', ['count' => Number::format($block['chunk_count'])]) }}
            </x-ui.badge>
            <span class="font-mono text-[11px]">#{{ $block['first_entry_number'] }}–#{{ $block['last_entry_number'] }}</span>
            <span class="tabular-nums">{{ $durationLabel }}</span>
            @if ($block['finish_reason'])
                <x-ui.badge :variant="$block['finish_reason_severity']">
                    {{ __('finish=:reason', ['reason' => $block['finish_reason']]) }}
                </x-ui.badge>
            @endif
            @if (! empty($block['unknown_keys']))
                <x-ui.badge variant="warning">
                    {{ __('unknown keys: :keys', ['keys' => implode(', ', $block['unknown_keys'])]) }}
                </x-ui.badge>
            @endif
        </div>
    </summary>

    @if ($block['reassembled_content'] !== '' || $block['reassembled_reasoning'] !== '' || ! empty($block['tool_calls']))
        <div class="mx-3 mb-3 space-y-2 border-t border-border-default/40 pt-3">
            @if ($block['reassembled_content'] !== '')
                <div class="rounded-lg border border-border-default/60 bg-surface-card p-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                        {{ __('Reassembled content') }}
                        <span class="ml-1 font-normal lowercase text-muted/80">
                            ({{ Number::format(strlen($block['reassembled_content'])) }} {{ __('chars') }})
                        </span>
                    </p>
                    <div x-data="{ copied: false }" class="relative mt-1.5">
                        <button
                            type="button"
                            @click="navigator.clipboard.writeText($refs.content.textContent); copied = true; setTimeout(() => copied = false, 1500);"
                            class="absolute right-2 top-2 inline-flex items-center gap-1 rounded-md border border-border-default bg-surface-subtle px-1.5 py-0.5 text-[11px] text-ink shadow-sm hover:bg-surface-card"
                        >
                            <x-icon name="heroicon-o-clipboard-document-list" class="size-3" />
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak class="text-status-success">{{ __('Copied!') }}</span>
                        </button>
                        <pre x-ref="content" class="max-h-48 overflow-auto whitespace-pre-wrap break-words rounded bg-surface-subtle/40 p-2 pr-14 text-xs text-ink">{{ $block['reassembled_content'] }}</pre>
                    </div>
                </div>
            @endif

            @if ($block['reassembled_reasoning'] !== '')
                <div class="rounded-lg border border-status-info-subtle/40 bg-status-info-subtle/10 p-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-status-info">
                        {{ __('Reasoning content') }}
                        <span class="ml-1 font-normal lowercase">
                            ({{ Number::format(strlen($block['reassembled_reasoning'])) }} {{ __('chars') }})
                        </span>
                    </p>
                    <div x-data="{ copied: false }" class="relative mt-1.5">
                        <button
                            type="button"
                            @click="navigator.clipboard.writeText($refs.reasoning.textContent); copied = true; setTimeout(() => copied = false, 1500);"
                            class="absolute right-2 top-2 inline-flex items-center gap-1 rounded-md border border-border-default bg-surface-subtle px-1.5 py-0.5 text-[11px] text-ink shadow-sm hover:bg-surface-card"
                        >
                            <x-icon name="heroicon-o-clipboard-document-list" class="size-3" />
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak class="text-status-success">{{ __('Copied!') }}</span>
                        </button>
                        <pre x-ref="reasoning" class="max-h-48 overflow-auto whitespace-pre-wrap break-words rounded bg-surface-subtle/30 p-2 pr-14 text-xs italic text-status-info">{{ $block['reassembled_reasoning'] }}</pre>
                    </div>
                </div>
            @endif

            @foreach ($block['tool_calls'] as $toolCall)
                <div class="rounded-lg border border-border-default/60 bg-surface-card p-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <x-ui.badge variant="accent">{{ __('Tool call') }}</x-ui.badge>
                        <span class="font-mono text-ink">{{ $toolCall['name'] ?? __('(no name)') }}</span>
                        @if ($toolCall['arguments_valid_json'])
                            <x-ui.badge variant="success">{{ __('valid JSON') }}</x-ui.badge>
                        @elseif ($toolCall['arguments'] !== '')
                            <x-ui.badge variant="danger">{{ __('invalid JSON') }}</x-ui.badge>
                        @endif
                    </div>
                    @if ($toolCall['arguments_parse_error'])
                        <p class="mt-1 text-[11px] text-status-danger">{{ $toolCall['arguments_parse_error'] }}</p>
                    @endif
                    @if ($toolCall['arguments'] !== '')
                        <div x-data="{ copied: false }" class="relative mt-1.5">
                            <button
                                type="button"
                                @click="navigator.clipboard.writeText($refs.args.textContent); copied = true; setTimeout(() => copied = false, 1500);"
                                class="absolute right-2 top-2 inline-flex items-center gap-1 rounded-md border border-border-default bg-surface-subtle px-1.5 py-0.5 text-[11px] text-ink shadow-sm hover:bg-surface-card"
                            >
                                <x-icon name="heroicon-o-clipboard-document-list" class="size-3" />
                                <span x-show="!copied">{{ __('Copy args') }}</span>
                                <span x-show="copied" x-cloak class="text-status-success">{{ __('Copied!') }}</span>
                            </button>
                            <pre x-ref="args" class="max-h-48 overflow-auto rounded bg-surface-subtle/60 p-2 pr-20 text-[11px] text-ink">{{ $toolCall['arguments_pretty'] !== '' ? $toolCall['arguments_pretty'] : $toolCall['arguments'] }}</pre>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="{{ $block['reassembled_content'] !== '' || $block['reassembled_reasoning'] !== '' || ! empty($block['tool_calls']) ? 'mx-3 mb-3' : 'mx-3 mb-3 border-t border-border-default/40 pt-3' }}">
        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted">
            {{ __('Fragments') }}
            <span class="ml-1 font-normal lowercase text-muted/80">
                ({{ Number::format(count($block['fragments'])) }})
            </span>
        </p>
        <div class="flex flex-wrap gap-1">
            @foreach ($block['fragments'] as $fragment)
                @include('livewire.admin.ai.control-plane.partials.wire-log-readable.fragment', [
                    'fragment' => $fragment,
                    'runId' => $runId,
                ])
            @endforeach
        </div>
    </div>
</details>
