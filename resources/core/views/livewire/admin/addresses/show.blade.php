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
                <x-ui.edit-in-place.text :label="__('Label')" :value="$address->label" field="label" />
                <x-ui.edit-in-place.text :label="__('Phone')" :value="$address->phone" field="phone" />
                <x-ui.edit-in-place.text :label="__('Address Line 1')" :value="$address->line1" field="line1" />
                <x-ui.edit-in-place.text :label="__('Address Line 2')" :value="$address->line2" field="line2" />
                <x-ui.edit-in-place.text :label="__('Address Line 3')" :value="$address->line3" field="line3" />
                <div>
                    <x-ui.combobox
                        wire:model.live="countryIso"
                        label="{{ __('Country') }}"
                        placeholder="{{ __('Search country...') }}"
                        :options="$countryOptions"
                    />
                </div>
                <div>
                    <x-ui.combobox
                        wire:model.live="admin1Code"
                        wire:key="show-admin1-{{ $countryIso ?? 'none' }}"
                        label="{{ __('State / Province') }}"
                        :hint="$admin1IsAuto ? __('(from postcode)') : null"
                        placeholder="{{ __('Search state...') }}"
                        :options="$admin1Options"
                    />
                </div>
                <div>
                    <x-ui.combobox
                        wire:model.live="postcode"
                        wire:key="show-postcode-{{ $countryIso ?? 'none' }}"
                        label="{{ __('Postcode') }}"
                        placeholder="{{ __('Search postcode...') }}"
                        :options="$postcodeOptions"
                        :editable="true"
                        search-url="{{ route('admin.addresses.postcodes.search') }}?country={{ $countryIso ?? '' }}"
                    />
                </div>
                <div>
                    <x-ui.combobox
                        wire:model.live="locality"
                        wire:key="show-locality-{{ $countryIso ?? 'none' }}-{{ $admin1Code ?? 'none' }}"
                        label="{{ __('Locality') }}"
                        :hint="$localityIsAuto ? __('(from postcode)') : null"
                        placeholder="{{ __('City / town') }}"
                        :options="$localityOptions"
                        :editable="true"
                        search-url="{{ route('admin.addresses.cities.search') }}?country={{ $countryIso ?? '' }}&admin1={{ $admin1Code ?? '' }}"
                    />
                </div>
                <x-ui.edit-in-place.select :label="__('Verification Status')" :value="$address->verificationStatus" save-method="saveVerificationStatus">
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
            </dl>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-1">{{ __('Provenance') }}</h3>
            <p class="text-xs text-muted mb-4">{{ __('Tracks where this address came from and how it was processed — useful for auditing data quality and imports.') }}</p>

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-ui.edit-in-place.text :label="__('Source')" :value="$address->source" field="source" />
                <x-ui.edit-in-place.text :label="__('Source Reference')" :value="$address->sourceRef" field="sourceRef" />
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

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
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
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($linkedEntities as $entity)
                            <tr wire:key="entity-{{ $entity->type }}-{{ $entity->model->id }}" class="hover:bg-surface-subtle/50 transition-colors">
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
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</div>
