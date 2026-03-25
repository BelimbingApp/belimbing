<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Business\IT\Livewire\Tickets\Show $this */
?>

<div>
    <x-slot name="title">{{ __('Ticket #:id', ['id' => $ticket->id]) }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$ticket->title" :subtitle="__('Ticket #:id', ['id' => $ticket->id])">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('it.tickets.index') }}" wire:navigate>
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
            {{-- Left column: Details + Actions --}}
            <div class="lg:col-span-2 space-y-6">
                {{-- Ticket details --}}
                <x-ui.card>
                    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</dt>
                            <dd class="mt-1">
                                <x-ui.badge :variant="$this->statusVariant($ticket->status)">{{ str_replace('_', ' ', ucfirst($ticket->status)) }}</x-ui.badge>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Priority') }}</dt>
                            <dd class="mt-1">
                                <x-ui.badge :variant="$this->priorityVariant($ticket->priority)">{{ ucfirst($ticket->priority) }}</x-ui.badge>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Category') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $ticket->category ? ucfirst($ticket->category) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Location') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $ticket->location ?? '—' }}</dd>
                        </div>
                    </dl>

                    <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Reporter') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $ticket->reporter?->displayName() ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Assignee') }}</dt>
                            <dd class="mt-1 text-sm text-ink">{{ $ticket->assignee?->displayName() ?? __('Unassigned') }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created') }}</dt>
                            <dd class="mt-1 text-sm text-ink" title="{{ $ticket->created_at?->format('Y-m-d H:i:s') }}">{{ $ticket->created_at?->diffForHumans() }}</dd>
                        </div>
                    </dl>

                    @if($ticket->description)
                        <dl class="mt-4 pt-4 border-t border-border-default">
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider mb-1">{{ __('Description') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $ticket->description }}</dd>
                        </dl>
                    @endif
                </x-ui.card>

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
