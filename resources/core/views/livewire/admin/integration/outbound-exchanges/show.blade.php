<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Integration\Livewire\OutboundExchanges\Show $this */
?>
<div>
    <x-slot name="title">{{ __('Outbound Exchange') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Outbound Exchange')" :subtitle="$exchange->id">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.integration.outbound-exchanges.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="h-4 w-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <dl class="grid gap-4 md:grid-cols-3 text-sm">
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('System') }}</dt>
                    <dd class="mt-1 text-ink">{{ $exchange->system }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Provider') }}</dt>
                    <dd class="mt-1 text-ink">{{ $exchange->provider ?? __('n/a') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Outcome') }}</dt>
                    <dd class="mt-1">
                        <x-ui.badge
                            :variant="$outcomeBadge['variant']"
                            :tooltip="$outcomeBadge['tooltip']"
                        >
                            {{ $outcomeBadge['label'] }}
                        </x-ui.badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Operation') }}</dt>
                    <dd class="mt-1 font-mono text-xs text-ink">{{ $exchange->operation }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Protocol') }}</dt>
                    <dd class="mt-1 text-ink">{{ $exchange->transport }} / {{ $exchange->protocol }} / {{ $exchange->protocol_operation ?? __('n/a') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Status') }}</dt>
                    <dd class="mt-1 tabular-nums text-ink">{{ $exchange->response_status ?? __('n/a') }}</dd>
                </div>
                <div class="md:col-span-3">
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Endpoint') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-ink">{{ $exchange->endpoint }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Owner') }}</dt>
                    <dd class="mt-1 text-ink">{{ $exchange->owner_type ?? __('n/a') }} {{ $exchange->owner_id ?? '' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Timing') }}</dt>
                    <dd class="mt-1 text-ink tabular-nums">{{ $exchange->duration_ms ?? __('n/a') }} ms, {{ __(':count retries', ['count' => $exchange->retry_count]) }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Occurred') }}</dt>
                    <dd class="mt-1 text-ink"><x-ui.datetime :value="$exchange->occurred_at" /></dd>
                </div>
                @if($exchange->error_message)
                    <div class="md:col-span-3">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Error') }}</dt>
                        <dd class="mt-1 text-status-danger">{{ $exchange->error_class }}: {{ $exchange->error_message }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        @if(! $canViewPayload)
            <x-ui.alert variant="warning">{{ __('Retained payload inspection requires the admin.integration_payload.view capability.') }}</x-ui.alert>
        @else
            <div class="grid gap-6 xl:grid-cols-2">
                @foreach($payloadSections as $section)
                    <x-ui.card>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="text-sm font-medium text-ink">{{ $section['label'] }}</h2>
                            <div class="flex items-center gap-2">
                                @if($section['badge'] !== null)
                                    <x-ui.badge :tooltip="$section['badge']['tooltip']">{{ $section['badge']['label'] }}</x-ui.badge>
                                @endif
                                @if($section['payload'] !== null)
                                    <button
                                        type="button"
                                        x-data="{ copied: false }"
                                        @click="navigator.clipboard.writeText(@js($section['display'])); copied = true; setTimeout(() => copied = false, 1500);"
                                        class="relative inline-flex size-7 items-center justify-center rounded-md text-ink hover:bg-surface-subtle focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                        :aria-label="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"
                                        title="{{ __('Copy') }}"
                                    >
                                        <x-icon name="mdi-content-copy" class="size-3.5" />
                                        <span x-show="copied" x-cloak class="absolute -mt-8 rounded border border-border-default bg-surface-card px-1.5 py-0.5 text-[11px] text-status-success shadow-sm">{{ __('Copied!') }}</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                        @if($section['payload'] === null)
                            <p class="text-sm text-muted">{{ __('No retained payload.') }}</p>
                        @else
                            <pre class="max-h-[32rem] overflow-auto rounded border border-border-default bg-surface-subtle p-3 text-xs text-ink">{{ $section['display'] }}</pre>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    </div>
</div>
