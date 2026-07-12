@php /** @var \App\Modules\Core\AI\Livewire\Widgets\OperationsStatus $this */ @endphp
<div>
    <x-ui.card>
        <x-ui.widget-header :title="__('AI Operations')" icon="heroicon-o-cpu-chip" />
        <x-ui.stat-strip>
            @foreach($stats as $stat)
                <x-ui.stat :label="$stat['label']">{{ $stat['value'] }}</x-ui.stat>
            @endforeach
        </x-ui.stat-strip>
    </x-ui.card>
</div>
