<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props(['entries', 'formatTat' => null])

@php
    $formatTat = $formatTat ?? function (?int $seconds): string {
        if ($seconds === null) return '—';
        if ($seconds >= 86400) return round($seconds / 86400, 1) . 'd';
        if ($seconds >= 3600) return round($seconds / 3600, 1) . 'h';
        if ($seconds >= 60) return round($seconds / 60) . 'm';
        return $seconds . 's';
    };

    $agentTagStyle = function (?string $tag): ?array {
        return match ($tag) {
            'agent_progress'    => ['label' => 'Progress',    'class' => 'text-info'],
            'agent_question'    => ['label' => 'Question',    'class' => 'text-warning'],
            'agent_deliverable' => ['label' => 'Deliverable', 'class' => 'text-success'],
            'agent_error'       => ['label' => 'Error',       'class' => 'text-danger'],
            default             => null,
        };
    };
@endphp

<div class="flow-root">
    <ul class="-mb-8">
        @foreach($entries as $index => $entry)
            <li>
                <div class="relative pb-8">
                    @if(!$loop->last)
                        <span class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-border-default" aria-hidden="true"></span>
                    @endif
                    <div class="relative flex items-start space-x-3">
                        <div class="relative">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full {{ $loop->last ? 'bg-accent' : 'bg-surface-subtle' }} ring-4 ring-surface-card">
                                <x-icon name="heroicon-m-arrow-right" class="h-4 w-4 {{ $loop->last ? 'text-accent-on' : 'text-muted' }}" />
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <x-ui.badge variant="{{ $loop->last ? 'accent' : 'default' }}">{{ str_replace('_', ' ', ucfirst($entry->status)) }}</x-ui.badge>
                                @if($entry->tat !== null)
                                    <span class="text-xs text-muted tabular-nums" title="{{ __('Time in previous status') }}">{{ $formatTat($entry->tat) }}</span>
                                @endif
                            </div>
                            <div class="mt-1 flex items-center gap-2 text-xs text-muted">
                                @if($entry->actor_id)
                                    <span>{{ $entry->actorName ?? __('User #:id', ['id' => $entry->actor_id]) }}</span>
                                    @if($entry->actor_role)
                                        <span>&middot;</span>
                                        <span>{{ $entry->actor_role }}</span>
                                    @endif
                                @else
                                    <span>{{ __('System') }}</span>
                                @endif
                                <span>&middot;</span>
                                <time datetime="{{ $entry->transitioned_at->toIso8601String() }}" title="{{ $entry->transitioned_at->format('Y-m-d H:i:s') }}">
                                    {{ $entry->transitioned_at->diffForHumans() }}
                                </time>
                            </div>
                            @if($entry->comment)
                                @php $agentStyle = $agentTagStyle($entry->comment_tag); @endphp
                                <div class="mt-2 text-sm text-ink {{ $agentStyle !== null ? 'rounded-md border border-border-default bg-surface-subtle p-2' : '' }}">
                                    @if($agentStyle !== null)
                                        <span class="text-xs font-semibold uppercase tracking-wider {{ $agentStyle['class'] }}"><span aria-hidden="true">🤖</span> {{ $agentStyle['label'] }}:</span>
                                    @elseif($entry->comment_tag)
                                        <span class="text-xs font-semibold text-muted uppercase tracking-wider">{{ $entry->comment_tag }}:</span>
                                    @endif
                                    {{ $entry->comment }}
                                </div>
                            @endif
                            @if($entry->assignees)
                                <div class="mt-1 text-xs text-muted">
                                    {{ __('Assigned to:') }}
                                    @foreach($entry->assignees as $assignee)
                                        <span>{{ $assignee['name'] ?? __('User #:id', ['id' => $assignee['id']]) }}{{ !$loop->last ? ', ' : '' }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
</div>

@if($entries->isEmpty())
    <p class="text-center text-sm text-muted py-8">{{ __('No history recorded.') }}</p>
@endif
