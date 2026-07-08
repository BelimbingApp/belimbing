@php /** @var \App\Base\Scheduling\Livewire\Index $this */ @endphp
<?php
/** @var list<\App\Base\Scheduling\DTO\UpcomingRun> $upcoming */
/** @var list<\App\Base\Scheduling\DTO\RecordedRun> $runs */
$statusVariant = fn (?string $status): string => match ($status) {
    'succeeded' => 'success',
    'running', 'queued' => 'info',
    'failed' => 'danger',
    default => 'default',
};
$duration = function ($start, $end): string {
    if ($start === null || $end === null) {
        return '—';
    }
    $seconds = max(1, (int) $start->diffInSeconds($end));

    return $seconds >= 90 ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : $seconds.'s';
};
?>

<div>
    <x-slot name="title">{{ __('Scheduling') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Scheduling')"
            :subtitle="__('Everything scheduled to fire across the system, and how the last runs went.')"
        />

        <x-ui.card>
            <h2 class="text-lg font-semibold text-ink">{{ __('Upcoming') }}</h2>
            <x-ui.table class="mt-4" :caption="__('Next scheduled runs')" :empty="$upcoming === []" :empty-colspan="5" empty-message="{{ __('Nothing is scheduled.') }}">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('Name') }}</x-ui.th>
                        <x-ui.th>{{ __('Source') }}</x-ui.th>
                        <x-ui.th>{{ __('Cron') }}</x-ui.th>
                        <x-ui.th>{{ __('Next run') }}</x-ui.th>
                        <x-ui.th>{{ __('Last status') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @foreach($upcoming as $item)
                        <tr wire:key="upcoming-{{ $item->source }}-{{ md5($item->name.$item->cron) }}">
                            <td class="px-table-cell-x py-table-cell-y font-mono text-sm text-ink">
                                @if($item->url)
                                    <a class="text-accent hover:underline" href="{{ $item->url }}">{{ $item->name }}</a>
                                @else
                                    {{ $item->name }}
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm"><x-ui.badge variant="{{ $item->source === 'scheduler' ? 'default' : 'info' }}">{{ $item->source }}</x-ui.badge></td>
                            <td class="px-table-cell-x py-table-cell-y font-mono text-sm text-muted">{{ $item->cron }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                @if($item->nextRunAt !== null)
                                    <x-ui.datetime :value="$item->nextRunAt" /> <span class="text-xs text-muted">({{ $item->nextRunAt->diffForHumans() }})</span>
                                @else
                                    <span class="text-muted">{{ __('paused') }}</span>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                @if($item->lastStatus !== null)
                                    <x-ui.badge variant="{{ $statusVariant($item->lastStatus) }}">{{ $item->lastStatus }}</x-ui.badge>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-slot:body>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-lg font-semibold text-ink">{{ __('Run history') }}</h2>
            <x-ui.table class="mt-4" :caption="__('Recent scheduled runs')" :empty="$runs === []" :empty-colspan="6" empty-message="{{ __('No runs recorded yet.') }}">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('Started') }}</x-ui.th>
                        <x-ui.th>{{ __('Name') }}</x-ui.th>
                        <x-ui.th>{{ __('Source') }}</x-ui.th>
                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Duration') }}</x-ui.th>
                        <x-ui.th>{{ __('Detail') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @foreach($runs as $run)
                        <tr wire:key="run-{{ md5($run->source.$run->name.$run->startedAt->timestamp) }}-{{ $loop->index }}" @if($run->detail) title="{{ mb_substr($run->detail, 0, 700) }}" @endif>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-muted"><x-ui.datetime :value="$run->startedAt" /></td>
                            <td class="px-table-cell-x py-table-cell-y font-mono text-sm text-ink">{{ $run->name }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm"><x-ui.badge variant="{{ $run->source === 'scheduler' ? 'default' : 'info' }}">{{ $run->source }}</x-ui.badge></td>
                            <td class="px-table-cell-x py-table-cell-y text-sm"><x-ui.badge variant="{{ $statusVariant($run->status) }}">{{ $run->status }}</x-ui.badge></td>
                            <td class="px-table-cell-x py-table-cell-y text-right text-sm text-muted tabular-nums">{{ $duration($run->startedAt, $run->finishedAt) }}</td>
                            <td class="px-table-cell-x py-table-cell-y max-w-md truncate font-mono text-xs text-muted">{{ $run->detail ? str()->limit($run->detail, 90) : '—' }}</td>
                        </tr>
                    @endforeach
                </x-slot:body>
            </x-ui.table>
        </x-ui.card>
    </div>
</div>
