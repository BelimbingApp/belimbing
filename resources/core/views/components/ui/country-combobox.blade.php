{{--
    Country combobox: the single source of rendering for every country INPUT
    selector. Wraps x-ui.combobox and sources its ~250 options from the SSOT
    country data (the GeoNames Country model) via the auth-only JSON search
    endpoint `admin.addresses.countries.search`, so the full list never bloats
    the initial page HTML. Stores the 2-letter ISO code; shows the country name.

    Props:
    - label, placeholder, required, error, disabled — forwarded to x-ui.combobox
    - includeAll: filter mode — prepend an "All countries" empty option so the
      consumer can model a nullable filter (selecting it clears the value)
    - allLabel: label for the empty option in filter mode

    Binding: forward the consumer's `wire:model` / `wire:model.live` (and `id`)
    straight through to the inner combobox so two-way binding works end to end.
--}}
@props([
    'label' => null,
    'placeholder' => null,
    'required' => false,
    'error' => null,
    'disabled' => false,
    'includeAll' => false,
    'allLabel' => null,
])

<x-ui.combobox
    {{ $attributes->whereStartsWith('wire:model') }}
    :id="$attributes->get('id')"
    :label="$label"
    :placeholder="$placeholder ?? __('Search country...')"
    :required="$required"
    :error="$error"
    :disabled="$disabled"
    search-url="{{ route('admin.addresses.countries.search') }}{{ $includeAll ? '?all='.urlencode($allLabel ?? __('All Countries')) : '' }}"
/>
