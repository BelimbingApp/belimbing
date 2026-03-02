@php
    $prefix = $namePrefix ?? '';
    $countryField = $prefix . 'country_iso';
    $admin1Field = $prefix . 'admin1_code';
    $postcodeField = $prefix . 'postcode';
    $localityField = $prefix . 'locality';
@endphp

<div x-data="{}" @geo:admin1-detected.window="$refs.admin1.value = $event.detail">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-ui.select
            :name="$countryField"
            :id="$prefix . 'country-select'"
            :label="__('Country')"
            :error="$errors->first($countryField)"
            hx-get="{{ route('admin.addresses.lookup.admin1') }}"
            hx-trigger="change"
            hx-target="#{{ $prefix }}admin1-select"
            hx-include="[name='{{ $countryField }}']"
            hx-swap="innerHTML"
        >
            <option value="">{{ __('Select country...') }}</option>
            @foreach ($countryOptions as $country)
                <option value="{{ $country->iso }}" @selected(($countryIso ?? null) === $country->iso)>
                    {{ $country->country }}
                </option>
            @endforeach
        </x-ui.select>

        <x-ui.select
            :name="$admin1Field"
            :id="$prefix . 'admin1-select'"
            x-ref="admin1"
            :label="__('State / Province')"
            :error="$errors->first($admin1Field)"
        >
            @include('partials.address.admin1-options', [
                'options' => $admin1Options ?? [],
                'selected' => $admin1Code ?? null,
            ])
        </x-ui.select>

        <x-ui.input
            :name="$postcodeField"
            :value="$postcode ?? ''"
            :label="__('Postcode')"
            type="text"
            :placeholder="__('Postal code')"
            :error="$errors->first($postcodeField)"
            hx-get="{{ route('admin.addresses.lookup.localities') }}"
            hx-trigger="input changed delay:500ms"
            hx-target="#{{ $prefix }}locality-select"
            hx-include="[name='{{ $countryField }}'],[name='{{ $postcodeField }}']"
            hx-swap="innerHTML"
        />

        <x-ui.select
            :name="$localityField"
            :id="$prefix . 'locality-select'"
            :label="__('Locality / City')"
            :error="$errors->first($localityField)"
        >
            @include('partials.address.locality-options', [
                'localities' => ($locality ?? null) !== null && $locality !== ''
                    ? [['value' => $locality, 'label' => $locality]]
                    : [],
                'selected' => $locality ?? null,
            ])
        </x-ui.select>
    </div>
</div>
