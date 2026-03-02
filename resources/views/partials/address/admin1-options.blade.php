{{-- HTMX fragment: admin1 <option> list for geo form --}}
<option value="">{{ __('Select state/province...') }}</option>
@foreach ($options as $option)
    <option value="{{ $option['value'] }}" @selected(($selected ?? null) === $option['value'])>
        {{ $option['label'] }}
    </option>
@endforeach
