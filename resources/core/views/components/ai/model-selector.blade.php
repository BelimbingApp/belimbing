@props([
    'id' => null,
    'label' => null,
    'models' => [],
])

@php
    $wireModel = $attributes->wire('model')->value();
    $selectId = $id
        ?? $attributes->get('id')
        ?? 'ai-model-selector'.($wireModel ? '-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $wireModel) : '');
    $labelText = $label ?? $attributes->get('aria-label') ?? __('AI model');
@endphp

<label class="sr-only" for="{{ $selectId }}">{{ $labelText }}</label>
<select
    {{ $attributes->merge(['id' => $selectId, 'aria-label' => $labelText])->class([
        'rounded-lg border border-border-input bg-surface-card text-xs text-ink',
        'px-input-x py-input-y focus:border-accent focus:ring-0 transition-colors',
    ]) }}
>
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
