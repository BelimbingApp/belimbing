<x-layouts.app :title="__('Create Address')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Create Address')" :subtitle="__('Add a structured address record')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.addresses.index') }}">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('admin.addresses.store') }}" class="space-y-6">
                @csrf

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.input name="label" :value="old('label')" :label="__('Label')" type="text" :placeholder="__('HQ, Warehouse, Billing, etc.')" :error="$errors->first('label')" />
                    <x-ui.input name="phone" :value="old('phone')" :label="__('Phone')" type="text" :placeholder="__('Contact number for this location')" :error="$errors->first('phone')" />
                </div>

                <div class="space-y-4">
                    <x-ui.input name="line1" :value="old('line1')" :label="__('Address Line 1')" type="text" :placeholder="__('Street and number')" :error="$errors->first('line1')" />
                    <x-ui.input name="line2" :value="old('line2')" :label="__('Address Line 2')" type="text" :placeholder="__('Building, suite, floor (optional)')" :error="$errors->first('line2')" />
                    <x-ui.input name="line3" :value="old('line3')" :label="__('Address Line 3')" type="text" :placeholder="__('Additional address detail (optional)')" :error="$errors->first('line3')" />
                </div>

                @include('partials.address.geo-form', [
                    'countryIso' => old('country_iso'),
                    'admin1Code' => old('admin1_code'),
                    'postcode' => old('postcode'),
                    'locality' => old('locality'),
                    'countryOptions' => $countryOptions,
                    'admin1Options' => $admin1Options,
                ])

                <div class="border-t border-border-input pt-6">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Provenance and Verification') }}</h3>
                    <p class="mb-4 text-xs text-muted">{{ __('Tracks where this address came from and how it was processed — useful for auditing data quality and imports.') }}</p>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-ui.input name="source" :value="old('source', 'manual')" :label="__('Source')" type="text" :placeholder="__('manual, scan, paste, import_api')" :error="$errors->first('source')" />
                        <x-ui.input name="source_ref" :value="old('source_ref')" :label="__('Source Reference')" type="text" :placeholder="__('External reference ID (optional)')" :error="$errors->first('source_ref')" />
                        <x-ui.input name="parser_version" :value="old('parser_version')" :label="__('Parser Version')" type="text" :placeholder="__('Parser version (optional)')" :error="$errors->first('parser_version')" />
                        <x-ui.input name="parse_confidence" :value="old('parse_confidence')" :label="__('Parse Confidence')" type="number" step="0.0001" min="0" max="1" :placeholder="__('0.0000 to 1.0000')" :error="$errors->first('parse_confidence')" />

                        <div class="md:col-span-2">
                            <x-ui.select name="verification_status" :label="__('Verification Status')" :error="$errors->first('verification_status')">
                                <option value="unverified" @selected(old('verification_status', 'unverified') === 'unverified')>{{ __('Unverified') }}</option>
                                <option value="suggested" @selected(old('verification_status') === 'suggested')>{{ __('Suggested') }}</option>
                                <option value="verified" @selected(old('verification_status') === 'verified')>{{ __('Verified') }}</option>
                            </x-ui.select>
                        </div>
                    </div>
                </div>

                <x-ui.textarea name="raw_input" :label="__('Raw Input')" rows="4" :placeholder="__('Original pasted or scanned address block (optional)')" :error="$errors->first('raw_input')">{{ old('raw_input') }}</x-ui.textarea>

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">{{ __('Create Address') }}</x-ui.button>
                    <x-ui.button variant="ghost" as="a" href="{{ route('admin.addresses.index') }}">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
