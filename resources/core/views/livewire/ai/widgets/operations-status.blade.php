@php /** @var \App\Modules\Core\AI\Livewire\Widgets\OperationsStatus $this */ @endphp
<div>
    <x-ui.card>
        <div class="mb-3 flex items-center justify-between gap-2">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('AI Operations') }}</span>
            <x-icon name="heroicon-o-cpu-chip" class="w-4 h-4 text-muted" />
        </div>
        <x-ui.stat-strip>
            @foreach($stats as $stat)
                <x-ui.stat :label="$stat['label']">{{ $stat['value'] }}</x-ui.stat>
            @endforeach
        </x-ui.stat-strip>
    </x-ui.card>
</div>
