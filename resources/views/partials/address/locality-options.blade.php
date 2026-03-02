{{-- HTMX fragment: locality <option> list for geo form --}}
<option value="">{{ __('Select locality...') }}</option>
@foreach ($localities as $locality)
    <option value="{{ $locality['value'] }}" @selected(($selected ?? null) === $locality['value'])>{{ $locality['label'] }}</option>
@endforeach
