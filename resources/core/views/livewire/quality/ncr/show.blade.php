<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\Quality\Livewire\Ncr\Show $this */
?>

<div>
    <x-slot name="title">{{ __('NCR :no', ['no' => $ncr->ncr_no]) }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$ncr->title" :subtitle="$ncr->ncr_no">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('quality.ncr.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
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
            {{-- Left column: Details + CAPA + Actions --}}
            <div class="lg:col-span-2 space-y-6">
                {{-- NCR details --}}
                <x-ui.card>
                    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</dt>
                            <dd class="mt-1">
                                <x-ui.badge :variant="$this->statusVariant($ncr->status)">{{ str_replace('_', ' ', ucfirst($ncr->status)) }}</x-ui.badge>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Severity') }}</dt>
                            <dd class="mt-1">
                                @if($ncr->severity)
                                    <x-ui.badge :variant="$this->severityVariant($ncr->severity)">{{ ucfirst($ncr->severity) }}</x-ui.badge>
                                @else
                                    <span class="text-sm text-muted">—</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Kind') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ config('quality.ncr_kinds.' . $ncr->ncr_kind, $ncr->ncr_kind) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Classification') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $ncr->classification ?? '—' }}</dd>
                        </div>
                    </dl>

                    <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Reporter') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $ncr->reported_by_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Owner') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $ncr->currentOwner?->name ?? __('Unassigned') }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Product') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $ncr->product_name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created') }}</dt>
                            <dd class="mt-1 text-sm text-ink" title="{{ $ncr->created_at?->format('Y-m-d H:i:s') }}">{{ $ncr->created_at?->diffForHumans() }}</dd>
                        </div>
                    </dl>

                    @if($ncr->summary)
                        <dl class="mt-4 pt-4 border-t border-border-default">
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider mb-1">{{ __('Summary') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $ncr->summary }}</dd>
                        </dl>
                    @endif
                </x-ui.card>

                {{-- CAPA details --}}
                @if($ncr->capa)
                    <x-ui.card>
                        <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('CAPA Details') }}</h2>
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            @if($ncr->capa->triage_summary)
                                <div class="sm:col-span-2">
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Triage Summary') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $ncr->capa->triage_summary }}</dd>
                                </div>
                            @endif
                            @if($ncr->capa->containment_action)
                                <div class="sm:col-span-2">
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Containment Action') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $ncr->capa->containment_action }}</dd>
                                </div>
                            @endif
                            @if($ncr->capa->root_cause_occurred)
                                <div>
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Root Cause (Occurred)') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $ncr->capa->root_cause_occurred }}</dd>
                                </div>
                            @endif
                            @if($ncr->capa->root_cause_leakage)
                                <div>
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Root Cause (Leakage)') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $ncr->capa->root_cause_leakage }}</dd>
                                </div>
                            @endif
                            @if($ncr->capa->corrective_action_occurred)
                                <div>
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Corrective Action (Occurred)') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $ncr->capa->corrective_action_occurred }}</dd>
                                </div>
                            @endif
                            @if($ncr->capa->corrective_action_leakage)
                                <div>
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Corrective Action (Leakage)') }}</dt>
                                    <dd class="mt-1 text-sm text-ink whitespace-pre-wrap">{{ $ncr->capa->corrective_action_leakage }}</dd>
                                </div>
                            @endif
                            @if($ncr->capa->verification_result)
                                <div>
                                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Verification Result') }}</dt>
                                    <dd class="mt-1 text-sm text-ink">{{ ucfirst(str_replace('_', ' ', $ncr->capa->verification_result)) }}</dd>
                                </div>
                            @endif
                        </dl>
                    </x-ui.card>
                @endif

                {{-- Linked SCARs --}}
                @if($ncr->scars->isNotEmpty())
                    <x-ui.card>
                        <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('Linked SCARs') }}</h2>
                        <div class="space-y-2">
                            @foreach($ncr->scars as $scar)
                                <a href="{{ route('quality.scar.show', $scar) }}" wire:navigate wire:key="scar-{{ $scar->id }}" class="flex items-center justify-between p-3 rounded-lg border border-border-default hover:bg-surface-subtle/50 transition-colors">
                                    <div>
                                        <span class="text-sm font-medium text-accent">{{ $scar->scar_no }}</span>
                                        <span class="text-sm text-muted ml-2">{{ $scar->supplier_name }}</span>
                                    </div>
                                    <x-ui.badge :variant="$this->statusVariant($scar->status)">{{ str_replace('_', ' ', ucfirst($scar->status)) }}</x-ui.badge>
                                </a>
                            @endforeach
                        </div>
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
