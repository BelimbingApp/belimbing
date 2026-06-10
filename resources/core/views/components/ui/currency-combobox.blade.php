{{--
    Currency combobox: the single source of rendering for every currency INPUT
    selector. Wraps x-ui.combobox and sources its options from the SSOT currency
    data (GeoNames via CurrencyOptions). Stores the 3-letter code; shows the name.

    Props:
    - label, placeholder, required, error, disabled — forwarded to x-ui.combobox

    Binding: forward the consumer's `wire:model` / `wire:model.live` (and `id`)
    straight through to the inner combobox so two-way binding works end to end.
--}}
@props([
    'label' => null,
    'placeholder' => null,
    'required' => false,
    'error' => null,
    'disabled' => false,
])

@php($currencyOptions = app(\App\Modules\Core\Geonames\Services\CurrencyOptions::class)->options())

<x-ui.combobox
    {{ $attributes->whereStartsWith('wire:model') }}
    :id="$attributes->get('id')"
    :label="$label"
    :placeholder="$placeholder ?? __('Search currency...')"
    :required="$required"
    :error="$error"
    :disabled="$disabled"
    :options="$currencyOptions"
/>
