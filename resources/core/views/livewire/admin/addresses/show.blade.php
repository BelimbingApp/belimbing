<div>
    <x-slot name="title">{{ __('Address Details') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Address Details')">
            <x-slot name="actions">
                @if($companyContextId)
                    <a href="{{ route('admin.companies.show', $companyContextId) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                        <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                        {{ __('Back to Company') }}
                    </a>
                @endif
                <a href="{{ route('admin.addresses.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to List') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if($timezoneWasAutoApplied)
            <x-ui.alert variant="info">
                {{ __('Company timezone was automatically updated based on the address locality.') }}
            </x-ui.alert>
        @endif

        @if($suggestedTimezone)
            <x-ui.alert variant="warning">
                <p class="text-sm">{{ __('The address locality suggests timezone :new, but the company currently uses :old.', ['new' => $suggestedTimezone, 'old' => $suggestedTimezoneOld]) }}</p>
                <div class="flex items-center gap-2 mt-2">
                    <x-ui.button variant="primary" size="sm" wire:click="acceptSuggestedTimezone">{{ __('Apply :tz', ['tz' => $suggestedTimezone]) }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" wire:click="dismissSuggestedTimezone">{{ __('Keep current') }}</x-ui.button>
                </div>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Address Details') }}</h3>

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-ui.edit-in-place.text id="address-label" :label="__('Label')" :value="$address->label" field="label" />
                <x-ui.edit-in-place.text id="address-phone" :label="__('Phone')" :value="$address->phone" field="phone" />
                <x-ui.edit-in-place.select id="address-verification-status" :label="__('Verification Status')" :value="$address->verificationStatus" save-method="saveVerificationStatus">
                    <x-slot name="read">
                        <x-ui.badge :variant="match($address->verificationStatus) {
                            'verified' => 'success',
                            'suggested' => 'warning',
                            default => 'default',
                        }">{{ ucfirst($address->verificationStatus) }}</x-ui.badge>
                    </x-slot>

                    <option value="unverified">{{ __('Unverified') }}</option>
                    <option value="suggested">{{ __('Suggested') }}</option>
                    <option value="verified">{{ __('Verified') }}</option>
                </x-ui.edit-in-place.select>

                <div class="md:col-span-2">
                    <dl class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <x-ui.edit-in-place.text id="address-line1" :label="__('Address Line 1')" :value="$address->line1" field="line1" />
                        <x-ui.edit-in-place.text id="address-line2" :label="__('Address Line 2')" :value="$address->line2" field="line2" />
                        <x-ui.edit-in-place.text id="address-line3" :label="__('Address Line 3')" :value="$address->line3" field="line3" />
                    </dl>
                </div>

                <div class="md:col-span-2 rounded-2xl border border-border-default bg-surface-subtle/40 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h4 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Location') }}</h4>
                            <p class="mt-1 text-sm text-ink">
                                {{ collect([
                                    $address->locality,
                                    $address->admin1?->name ?? $address->admin1Code,
                                    $address->postcode,
                                    $address->country?->country ?? $address->country_iso,
                                ])->filter()->implode(', ') ?: '-' }}
                            </p>
                        </div>

                        @unless($editingLocation)
                            <x-ui.button variant="ghost" size="sm" wire:click="openLocationEditor">
                                <x-icon name="heroicon-o-pencil-square" class="h-4 w-4" />
                                {{ __('Edit Location') }}
                            </x-ui.button>
                        @endunless
                    </div>

                    @unless($editingLocation)
                        <dl class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-4">
                            <div>
                                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Country') }}</dt>
                                <dd class="text-sm text-ink">{{ $address->country?->country ?? $address->country_iso ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('State / Province') }}</dt>
                                <dd class="text-sm text-ink">{{ $address->admin1?->name ?? $address->admin1Code ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Postcode') }}</dt>
                                <dd class="text-sm text-ink tabular-nums">{{ $address->postcode ?: '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Locality') }}</dt>
                                <dd class="text-sm text-ink">{{ $address->locality ?: '-' }}</dd>
                            </div>
                        </dl>
                    @else
                        <div class="mt-4 border-t border-border-default pt-4">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <x-ui.country-combobox
                                    id="address-location-country-iso"
                                    wire:model.live="countryIso"
                                    :label="__('Country')"
                                    :error="$errors->first('countryIso')"
                                />

                                <x-ui.combobox
                                    id="address-location-admin1-code"
                                    wire:model.live="admin1Code"
                                    wire:key="show-admin1-{{ $countryIso ?? 'none' }}"
                                    label="{{ __('State / Province') }}"
                                    :hint="$admin1IsAuto ? __('from postcode') : null"
                                    placeholder="{{ __('Search state...') }}"
                                    :options="$admin1Options"
                                    :error="$errors->first('admin1Code')"
                                />

                                <x-ui.combobox
                                    id="address-location-postcode"
                                    wire:model.live="postcode"
                                    wire:key="show-postcode-{{ $countryIso ?? 'none' }}"
                                    label="{{ __('Postcode') }}"
                                    placeholder="{{ __('Search postcode...') }}"
                                    :options="$postcodeOptions"
                                    :editable="true"
                                    search-url="{{ route('admin.addresses.postcodes.search') }}?country={{ $countryIso ?? '' }}"
                                    :error="$errors->first('postcode')"
                                />

                                <x-ui.combobox
                                    id="address-location-locality"
                                    wire:model.live="locality"
                                    wire:key="show-locality-{{ $countryIso ?? 'none' }}-{{ $admin1Code ?? 'none' }}"
                                    label="{{ __('Locality') }}"
                                    :hint="$localityIsAuto ? __('from postcode') : null"
                                    placeholder="{{ __('City / town') }}"
                                    :options="$localityOptions"
                                    :editable="true"
                                    search-url="{{ route('admin.addresses.cities.search') }}?country={{ $countryIso ?? '' }}&admin1={{ $admin1Code ?? '' }}"
                                    :error="$errors->first('locality')"
                                />
                            </div>

                            <div class="mt-4 flex items-center gap-2">
                                <x-ui.button variant="primary" size="sm" wire:click="saveLocation">
                                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                    {{ __('Apply Location') }}
                                </x-ui.button>
                                <x-ui.button variant="ghost" size="sm" wire:click="cancelLocationEditor">
                                    {{ __('Cancel') }}
                                </x-ui.button>
                            </div>
                        </div>
                    @endunless
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-1">{{ __('Provenance') }}</h3>
            <p class="text-xs text-muted mb-4">{{ __('Tracks where this address came from and how it was processed — useful for auditing data quality and imports.') }}</p>

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-ui.edit-in-place.text id="address-source" :label="__('Source')" :value="$address->source" field="source" />
                <x-ui.edit-in-place.text id="address-source-ref" :label="__('Source Reference')" :value="$address->sourceRef" field="sourceRef" />
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Parser Version') }}</dt>
                    <dd class="text-sm text-ink">{{ $address->parserVersion ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Parse Confidence') }}</dt>
                    <dd class="text-sm text-ink">{{ $address->parseConfidence !== null ? $address->parseConfidence : '-' }}</dd>
                </div>
            </dl>

            @if($address->rawInput)
                <dl class="mt-4">
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Raw Input') }}</dt>
                    <dd class="mt-1">
                        <pre class="text-sm text-ink bg-surface-subtle rounded-2xl p-4 overflow-x-auto">{{ $address->rawInput }}</pre>
                    </dd>
                </dl>
            @endif
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-1">{{ __('Linked Entities') }}</h3>
            <p class="text-xs text-muted mb-4">{{ __('Companies, employees, or other records that use this address. One address can be shared by multiple entities with different roles (e.g., billing, shipping).') }}</p>

            <x-ui.table container="flush" :caption="__('Linked entities')">

                    <x-slot name="head">
                        <tr>
                            <x-ui.sortable-th
                                column="type"
                                :sort-by="$linkedSortBy"
                                :sort-dir="$linkedSortDir"
                                action="sortLinked('type')"
                                :label="__('Entity Type')"
                            />
                            <x-ui.sortable-th
                                column="name"
                                :sort-by="$linkedSortBy"
                                :sort-dir="$linkedSortDir"
                                action="sortLinked('name')"
                                :label="__('Name')"
                            />
                            <x-ui.sortable-th
                                column="kind"
                                :sort-by="$linkedSortBy"
                                :sort-dir="$linkedSortDir"
                                action="sortLinked('kind')"
                                :label="__('Kind')"
                            />
                            <x-ui.sortable-th
                                column="is_primary"
                                :sort-by="$linkedSortBy"
                                :sort-dir="$linkedSortDir"
                                action="sortLinked('is_primary')"
                                :label="__('Primary')"
                            />
                            <x-ui.sortable-th
                                column="priority"
                                :sort-by="$linkedSortBy"
                                :sort-dir="$linkedSortDir"
                                action="sortLinked('priority')"
                                :label="__('Priority')"
                            />
                            <x-ui.sortable-th
                                column="valid_from"
                                :sort-by="$linkedSortBy"
                                :sort-dir="$linkedSortDir"
                                action="sortLinked('valid_from')"
                                :label="__('Valid From')"
                            />
                            <x-ui.sortable-th
                                column="valid_to"
                                :sort-by="$linkedSortBy"
                                :sort-dir="$linkedSortDir"
                                action="sortLinked('valid_to')"
                                :label="__('Valid To')"
                            />
                        </tr>
                    </x-slot>

                        @forelse($linkedEntities as $entity)
                            <tr wire:key="entity-{{ $entity->type }}-{{ $entity->model->id }}">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $entity->type }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    @if($entity->type === 'Company')
                                        <a href="{{ route('admin.companies.show', $entity->model) }}" wire:navigate class="text-accent hover:underline">{{ $entity->model->name }}</a>
                                    @elseif($entity->type === 'Employee')
                                        <a href="{{ route('admin.employees.show', $entity->model) }}" wire:navigate class="text-accent hover:underline">{{ $entity->model->full_name ?? $entity->model->id }}</a>
                                    @else
                                        {{ $entity->model->name ?? $entity->model->id }}
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    @if(is_array($entity->kind) && count($entity->kind) > 0)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($entity->kind as $k)
                                                <x-ui.badge variant="default">{{ ucfirst($k) }}</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $entity->is_primary ? __('Yes') : __('No') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $entity->priority ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $entity->valid_from ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $entity->valid_to ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No linked entities.') }}</td>
                            </tr>
                        @endforelse


            </x-ui.table>
        </x-ui.card>
    </div>
</div>
