<div>
    <x-slot name="title">{{ __('Create Address') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Create Address')" :subtitle="__('Add a structured address record')">
            <x-slot name="actions">
                <a href="{{ route('admin.addresses.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="store" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input
                        id="address-label"
                        wire:model="label"
                        label="{{ __('Label') }}"
                        type="text"
                        placeholder="{{ __('HQ, Warehouse, Billing, etc.') }}"
                        :error="$errors->first('label')"
                    />

                    <x-ui.input
                        id="address-phone"
                        wire:model="phone"
                        label="{{ __('Phone') }}"
                        type="text"
                        placeholder="{{ __('Contact number for this location') }}"
                        :error="$errors->first('phone')"
                    />
                </div>

                <div class="space-y-4">
                    <x-ui.input
                        id="address-line1"
                        wire:model="line1"
                        label="{{ __('Address Line 1') }}"
                        type="text"
                        placeholder="{{ __('Street and number') }}"
                        :error="$errors->first('line1')"
                    />

                    <x-ui.input
                        id="address-line2"
                        wire:model="line2"
                        label="{{ __('Address Line 2') }}"
                        type="text"
                        placeholder="{{ __('Building, suite, floor (optional)') }}"
                        :error="$errors->first('line2')"
                    />

                    <x-ui.input
                        id="address-line3"
                        wire:model="line3"
                        label="{{ __('Address Line 3') }}"
                        type="text"
                        placeholder="{{ __('Additional address detail (optional)') }}"
                        :error="$errors->first('line3')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.combobox
                        wire:model.live="countryIso"
                        label="{{ __('Country') }}"
                        placeholder="{{ __('Search country...') }}"
                        :options="$countryOptions"
                        :error="$errors->first('countryIso')"
                    />

                    <x-ui.combobox
                        wire:model.live="admin1Code"
                        wire:key="create-admin1-{{ $countryIso ?? 'none' }}"
                        label="{{ __('State / Province') }}"
                        :hint="$admin1IsAuto ? __('(from postcode)') : null"
                        placeholder="{{ __('Search state...') }}"
                        :options="$admin1Options"
                        :error="$errors->first('admin1Code')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.combobox
                        wire:model.live="postcode"
                        wire:key="create-postcode-{{ $countryIso ?? 'none' }}"
                        label="{{ __('Postcode') }}"
                        placeholder="{{ __('Search postcode...') }}"
                        :options="$postcodeOptions"
                        :editable="true"
                        search-url="{{ route('admin.addresses.postcodes.search') }}?country={{ $countryIso ?? '' }}"
                        :error="$errors->first('postcode')"
                    />

                    <x-ui.combobox
                        wire:model.live="locality"
                        wire:key="create-locality-{{ $countryIso ?? 'none' }}-{{ $admin1Code ?? 'none' }}"
                        label="{{ __('Locality') }}"
                        :hint="$localityIsAuto ? __('(from postcode)') : null"
                        placeholder="{{ __('City / town') }}"
                        :options="$localityOptions"
                        :editable="true"
                        search-url="{{ route('admin.addresses.cities.search') }}?country={{ $countryIso ?? '' }}&admin1={{ $admin1Code ?? '' }}"
                        :error="$errors->first('locality')"
                    />
                </div>

                <div class="border-t border-border-input pt-6">
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-1">{{ __('Provenance and Verification') }}</h3>
                    <p class="text-xs text-muted mb-4">{{ __('Tracks where this address came from and how it was processed — useful for auditing data quality and imports.') }}</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-ui.input
                            id="address-source"
                            wire:model="source"
                            label="{{ __('Source') }}"
                            type="text"
                            placeholder="{{ __('manual, scan, paste, import_api') }}"
                            :error="$errors->first('source')"
                        />

                        <x-ui.input
                            id="address-source-ref"
                            wire:model="sourceRef"
                            label="{{ __('Source Reference') }}"
                            type="text"
                            placeholder="{{ __('External reference ID (optional)') }}"
                            :error="$errors->first('sourceRef')"
                        />

                        <x-ui.input
                            id="address-parser-version"
                            wire:model="parserVersion"
                            label="{{ __('Parser Version') }}"
                            type="text"
                            placeholder="{{ __('Parser version (optional)') }}"
                            :error="$errors->first('parserVersion')"
                        />

                        <x-ui.input
                            id="address-parse-confidence"
                            wire:model="parseConfidence"
                            label="{{ __('Parse Confidence') }}"
                            type="number"
                            step="0.0001"
                            min="0"
                            max="1"
                            placeholder="{{ __('0.0000 to 1.0000') }}"
                            :error="$errors->first('parseConfidence')"
                        />

                        <div class="md:col-span-2">
                            <x-ui.select id="address-verification-status" wire:model="verificationStatus" label="{{ __('Verification Status') }}" :error="$errors->first('verificationStatus')">
                                <option value="unverified">{{ __('Unverified') }}</option>
                                <option value="suggested">{{ __('Suggested') }}</option>
                                <option value="verified">{{ __('Verified') }}</option>
                            </x-ui.select>
                        </div>
                    </div>
                </div>

                <x-ui.textarea
                    id="address-raw-input"
                    wire:model="rawInput"
                    label="{{ __('Raw Input') }}"
                    rows="4"
                    placeholder="{{ __('Original pasted or scanned address block (optional)') }}"
                    :error="$errors->first('rawInput')"
                />

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create Address') }}
                    </x-ui.button>
                    <a href="{{ route('admin.addresses.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                        {{ __('Cancel') }}
                    </a>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
