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
                    <dd class="mt-1"><x-ui.badge :variant="$exchange->outcome === 'success' ? 'success' : 'danger'">{{ $exchange->outcome }}</x-ui.badge></dd>
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
                @foreach([
                    __('Request Headers') => $exchange->request_headers,
                    __('Request Body') => $exchange->request_body,
                    __('Response Headers') => $exchange->response_headers,
                    __('Response Body') => $exchange->response_body,
                    __('Metadata') => $exchange->metadata,
                ] as $label => $payload)
                    <x-ui.card>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="text-sm font-medium text-ink">{{ $label }}</h2>
                            @if(str_contains($label, 'Body'))
                                <x-ui.badge>{{ str_contains($label, 'Request') ? ($exchange->request_body_truncated ? __('Truncated') : __('Retained')) : ($exchange->response_body_truncated ? __('Truncated') : __('Retained')) }}</x-ui.badge>
                            @endif
                        </div>
                        @if($payload === null)
                            <p class="text-sm text-muted">{{ __('No retained payload.') }}</p>
                        @else
                            <pre class="max-h-[32rem] overflow-auto rounded border border-border-default bg-surface-subtle p-3 text-xs text-ink">{{ $this->formattedPayload($payload) }}</pre>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    </div>
</div>
