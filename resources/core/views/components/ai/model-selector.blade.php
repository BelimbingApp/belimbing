@props([
    'models' => [],
    'emptyLabel' => __('Select model…'),
])

<select
    {{ $attributes->class([
        'rounded-lg border border-border-input bg-surface-card text-xs text-ink',
        'px-input-x py-input-y focus:border-accent focus:ring-0 transition-colors',
    ]) }}
>
    <option value="">{{ $emptyLabel }}</option>
    @php
        $grouped = collect($models)->groupBy('provider');
    @endphp
    @foreach($grouped as $providerName => $providerModels)
        <optgroup label="{{ $providerName }}">
            @foreach($providerModels as $model)
                <option value="{{ $model['id'] }}">{{ $model['label'] }}</option>
            @endforeach
        </optgroup>
    @endforeach
</select>
