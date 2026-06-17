@php
    /** @var array<string, mixed> $field */
    /** @var array<string, bool> $configured */
    $key = $field['key'];
    $fieldId = 'image-setup-'.$providerKey.'-'.$key;
@endphp

@if ($field['type'] === 'select')
    <x-ui.select
        :id="$fieldId"
        wire:model.live="values.{{ $key }}"
        :label="$field['label']"
        :help="$field['help'] ?? null"
        :error="$errors->first('values.'.$key)"
    >
        @foreach ($field['options'] as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
        @endforeach
    </x-ui.select>
@elseif ($field['type'] === 'secret')
    <x-ui.secret-input
        :id="$fieldId"
        wire:model="values.{{ $key }}"
        :label="$field['label']"
        :hasValue="$configured[$key] ?? false"
        :help="($configured[$key] ?? false) ? __('Stored — type a new value to replace it.') : ($field['help'] ?? __('Not configured yet.'))"
        :error="$errors->first('values.'.$key)"
    />
@else
    <x-ui.input
        :id="$fieldId"
        wire:model="values.{{ $key }}"
        :label="$field['label']"
        :help="$field['help'] ?? null"
        :error="$errors->first('values.'.$key)"
    />
@endif
