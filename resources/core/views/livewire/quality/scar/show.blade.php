<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\Quality\Livewire\Scar\Show $this */
?>

<div>
    <x-slot name="title">{{ __('SCAR :no', ['no' => $scar->scar_no]) }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$scar->scar_no" :subtitle="$scar->supplier_name">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('quality.ncr.show', $scar->ncr) }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('NCR :no', ['no' => $scar->ncr?->ncr_no]) }}
                </x-ui.button>
                <x-ui.button variant="ghost" as="a" href="{{ route('quality.scar.index') }}" wire:navigate>
                    {{ __('All SCARs') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Left column: Details + Responses + Actions --}}
            <div class="lg:col-span-2 space-y-6">
                {{-- SCAR details --}}
                <x-ui.card>
                    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</dt>
                            <dd class="mt-1">
                                <x-ui.badge :variant="$this->statusVariant($scar->status)">{{ str_replace('_', ' ', ucfirst($scar->status)) }}</x-ui.badge>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Severity') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $scar->severity ? ucfirst($scar->severity) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Request Type') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $scar->request_type ? config('quality.scar_request_types.' . $scar->request_type, $scar->request_type) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Issued') }}</dt>
                            <dd class="mt-1 text-sm text-ink" title="{{ $scar->issuing_date?->format('Y-m-d') }}">{{ $scar->issuing_date?->diffForHumans() ?? '—' }}</dd>
                        </div>
                    </dl>

                    <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Supplier') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $scar->supplier_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Contact') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $scar->supplier_contact_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Product') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $scar->product_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Owner') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $scar->issueOwner?->name ?? '—' }}</dd>
                        </div>
                    </dl>

                    @if($scar->problem_description)
                        <dl class="mt-4 pt-4 border-t border-border-default">
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider mb-1">{{ __('Problem Description') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $scar->problem_description }}</dd>
                        </dl>
                    @endif
                </x-ui.card>

                {{-- Supplier responses --}}
                @if($scar->containment_response || $scar->root_cause_response || $scar->corrective_action_response)
                    <x-ui.card>
                        <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('Supplier Response') }}</h2>
                        <dl class="space-y-4">
                            @if($scar->containment_response)
                                <div>
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Containment') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $scar->containment_response }}</dd>
                                </div>
                            @endif
                            @if($scar->root_cause_response)
                                <div>
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Root Cause') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $scar->root_cause_response }}</dd>
                                </div>
                            @endif
                            @if($scar->corrective_action_response)
                                <div>
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Corrective Action') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $scar->corrective_action_response }}</dd>
                                </div>
                            @endif
                        </dl>
                    </x-ui.card>
                @endif

                {{-- Transition actions --}}
                @if($availableTransitions->isNotEmpty())
                    <x-ui.card>
                        <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('Actions') }}</h2>

                        <div class="space-y-4">
                            <x-ui.textarea
                                id="transition-comment"
                                wire:model="transitionComment"
                                label="{{ __('Comment') }}"
                                rows="2"
                                placeholder="{{ __('Optional comment for this action...') }}"
                            />

                            <div class="flex flex-wrap gap-2">
                                @foreach($availableTransitions as $transition)
                                    <x-ui.button
                                        variant="outline"
                                        wire:click="transitionTo('{{ $transition->to_code }}')"
                                        wire:confirm="{{ __('Transition to :status?', ['status' => $transition->resolveLabel()]) }}"
                                    >
                                        {{ $transition->resolveLabel() }}
                                    </x-ui.button>
                                @endforeach
                            </div>
                        </div>
                    </x-ui.card>
                @endif
            </div>

            {{-- Right column: Timeline --}}
            <div>
                <x-ui.card>
                    <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('Timeline') }}</h2>
                    <x-workflow.status-timeline :entries="$timeline" />
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
