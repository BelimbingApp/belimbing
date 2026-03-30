        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __('Addresses') }}
                    <x-ui.badge>{{ $addresses->count() }}</x-ui.badge>
                </h3>
                <div class="flex items-center gap-2">
                    <x-ui.button variant="primary" size="sm" wire:click="openAddressModal(null)">
                        <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        {{ __('Create & Attach') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" size="sm" wire:click="$set('showAttachModal', true)">
                        <x-icon name="heroicon-o-link" class="w-4 h-4" />
                        {{ __('Attach Existing') }}
                    </x-ui.button>
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Label') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Address') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Kind') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Primary') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Priority') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Valid From') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Valid To') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($addresses as $address)
                            <tr wire:key="address-{{ $address->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">
                                    <button wire:click="openAddressModal({{ $address->id }})" class="text-accent hover:underline cursor-pointer">{{ $address->label ?? '-' }}</button>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ collect([$address->line1, $address->locality, $address->country_iso])->filter()->implode(', ') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                    x-data="{ editing: false, selected: @js($address->pivot->kind ?? []) }"
                                >
                                    <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="k in selected" :key="k">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-subtle text-ink border border-border-default" x-text="k.charAt(0).toUpperCase() + k.slice(1)"></span>
                                            </template>
                                            <span x-show="selected.length === 0" class="text-muted">-</span>
                                        </div>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity shrink-0" />
                                    </div>
                                    <div x-show="editing" class="space-y-1">
                                        @foreach(['headquarters', 'billing', 'shipping', 'branch', 'other'] as $kindOption)
                                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                <input type="checkbox" value="{{ $kindOption }}" x-model="selected" class="rounded border-border-input accent-accent focus:ring-accent" />
                                                {{ __(ucfirst($kindOption)) }}
                                            </label>
                                        @endforeach
                                        <div class="flex items-center gap-2 mt-1">
                                            <button @click="$wire.saveAddressKinds({{ $address->id }}, selected); editing = false" class="px-2 py-0.5 text-xs font-medium rounded bg-accent text-accent-on hover:bg-accent-hover transition-colors">{{ __('Save') }}</button>
                                            <button @click="editing = false; selected = @js($address->pivot->kind ?? [])" class="px-2 py-0.5 text-xs font-medium rounded hover:bg-surface-subtle text-muted transition-colors">{{ __('Cancel') }}</button>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    <button
                                        wire:click="updateAddressPivot({{ $address->id }}, 'is_primary', {{ $address->pivot->is_primary ? '0' : '1' }})"
                                        class="cursor-pointer"
                                        title="{{ __('Toggle primary') }}"
                                    >
                                        @if($address->pivot->is_primary)
                                            <x-ui.badge variant="success">{{ __('Yes') }}</x-ui.badge>
                                        @else
                                            <span class="text-muted hover:text-ink transition-colors">{{ __('No') }}</span>
                                        @endif
                                    </button>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"
                                    x-data="{ editing: false, val: '{{ $address->pivot->priority }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <span x-text="val"></span>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                                    </div>
                                    <input
                                        x-show="editing"
                                        x-ref="input"
                                        x-model="val"
                                        @keydown.enter="editing = false; $wire.updateAddressPivot({{ $address->id }}, 'priority', val)"
                                        @keydown.escape="editing = false; val = '{{ $address->pivot->priority }}'"
                                        @blur="editing = false; $wire.updateAddressPivot({{ $address->id }}, 'priority', val)"
                                        type="number"
                                        min="0"
                                        class="w-16 px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $address->pivot->valid_from ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $address->pivot->valid_to ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <div class="inline-flex flex-col items-end gap-1">
                                        <a
                                            href="{{ route('admin.addresses.show', ['address' => $address, 'company' => $company->id]) }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg text-accent hover:bg-surface-subtle transition-colors"
                                        >
                                            <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-4 h-4" />
                                            {{ __('Open') }}
                                        </a>
                                        <x-ui.button
                                            variant="danger-ghost"
                                            size="sm"
                                            wire:click="unlinkAddress({{ $address->id }})"
                                            wire:confirm="{{ __('Are you sure you want to unlink this address?') }}"
                                        >
                                            <x-icon name="heroicon-o-link-slash" class="w-4 h-4" />
                                            {{ __('Unlink') }}
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No addresses linked.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card x-data="{ savedMsg: '' }" @timezone-saved.window="savedMsg = $event.detail.timezone ? '{{ __('Timezone saved:') }} ' + $event.detail.timezone : '{{ __('Timezone cleared.') }}'; setTimeout(() => savedMsg = '', 3000)">
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Timezone') }}</h3>
            <p class="text-xs text-muted mb-3">{{ __('Default timezone for this company. Used when displaying dates and times in Company mode.') }}</p>

            <div class="max-w-md space-y-3">
                <x-ui.combobox
                    id="company-timezone"
                    wire:model.live="companyTimezone"
                    :label="__('IANA Timezone')"
                    :placeholder="__('Search timezone...')"
                    :options="$timezoneOptions"
                />

                <p x-cloak x-show="savedMsg" x-transition.opacity.duration.200ms class="text-xs text-status-success flex items-center gap-1.5">
                    <x-icon name="heroicon-o-check-circle" class="w-3.5 h-3.5 shrink-0" />
                    <span x-text="savedMsg"></span>
                </p>

                @if($timezoneWasAutoApplied)
                    <x-ui.alert variant="info">
                        {{ __('Timezone was automatically set to :tz based on the primary address locality.', ['tz' => $companyTimezone]) }}
                    </x-ui.alert>
                @endif

                @if($suggestedTimezone)
                    <x-ui.alert variant="warning">
                        <p class="text-sm">{{ __('The primary address locality suggests timezone :new, but the current timezone is :old.', ['new' => $suggestedTimezone, 'old' => $suggestedTimezoneOld]) }}</p>
                        <div class="flex items-center gap-2 mt-2">
                            <x-ui.button variant="primary" size="sm" wire:click="acceptSuggestedTimezone">{{ __('Apply :tz', ['tz' => $suggestedTimezone]) }}</x-ui.button>
                            <x-ui.button variant="ghost" size="sm" wire:click="dismissSuggestedTimezone">{{ __('Keep current') }}</x-ui.button>
                        </div>
                    </x-ui.alert>
                @endif
            </div>
        </x-ui.card>

        <x-ui.modal wire:model="showAttachModal" class="max-w-lg">
            <div class="p-6 space-y-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Attach Address') }}</h3>

                <x-ui.select id="company-attach-address" wire:model="attachAddressId" :label="__('Address')">
                        <option value="0">{{ __('Select an address...') }}</option>
                        @foreach($availableAddresses as $addr)
                            <option value="{{ $addr->id }}">{{ $addr->label }} — {{ collect([$addr->line1, $addr->locality, $addr->country_iso])->filter()->implode(', ') }}</option>
                        @endforeach
                </x-ui.select>

                <div class="space-y-1">
                    <span class="block text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Kind') }}</span>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach(['headquarters', 'billing', 'shipping', 'branch', 'other'] as $kindOption)
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" value="{{ $kindOption }}" wire:model="attachKind" class="rounded border-border-input accent-accent focus:ring-accent" />
                                {{ __(ucfirst($kindOption)) }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <x-ui.checkbox id="company-attach-is-primary" wire:model="attachIsPrimary" label="{{ __('Primary Address') }}" />

                <div>
                    <x-ui.input id="company-attach-priority" wire:model="attachPriority" label="{{ __('Priority') }}" type="number" />
                    <p class="text-xs text-muted mt-1">{{ __('Lower number = higher priority. Used to order addresses of the same kind (0 = top).') }}</p>
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <x-ui.button variant="primary" wire:click="attachAddress">{{ __('Attach') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showAttachModal', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </div>
        </x-ui.modal>

        <x-ui.modal wire:model="showAddressModal" class="max-w-lg">
            <div class="p-6 space-y-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ $addressFormId === null ? __('Create & Attach Address') : __('Edit Address') }}
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input id="company-address-label" wire:model="label" label="{{ __('Label') }}" type="text" placeholder="{{ __('HQ, Warehouse, etc.') }}" :error="$errors->first('label')" />
                    <x-ui.input id="company-address-phone" wire:model="phone" label="{{ __('Phone') }}" type="text" placeholder="{{ __('Contact number') }}" :error="$errors->first('phone')" />
                </div>

                <x-ui.input id="company-address-line1" wire:model="line1" label="{{ __('Address Line 1') }}" type="text" placeholder="{{ __('Street and number') }}" :error="$errors->first('line1')" />
                <x-ui.input id="company-address-line2" wire:model="line2" label="{{ __('Address Line 2') }}" type="text" placeholder="{{ __('Building, suite (optional)') }}" :error="$errors->first('line2')" />
                <x-ui.input id="company-address-line3" wire:model="line3" label="{{ __('Address Line 3') }}" type="text" placeholder="{{ __('Additional detail (optional)') }}" :error="$errors->first('line3')" />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.combobox
                        wire:model.live="countryIso"
                        label="{{ __('Country') }}"
                        placeholder="{{ __('Search country...') }}"
                        :options="$countries->map(fn($c) => ['value' => $c->iso, 'label' => $c->country])->all()"
                        :error="$errors->first('countryIso')"
                    />

                    <x-ui.combobox
                        wire:model.live="admin1Code"
                        wire:key="modal-admin1-{{ $countryIso ?? 'none' }}"
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
                        wire:key="modal-postcode-{{ $countryIso ?? 'none' }}"
                        label="{{ __('Postcode') }}"
                        placeholder="{{ __('Search postcode...') }}"
                        :options="$postcodeOptions"
                        :editable="true"
                        search-url="{{ route('admin.addresses.postcodes.search') }}?country={{ $countryIso ?? '' }}"
                        :error="$errors->first('postcode')"
                    />

                    <x-ui.combobox
                        wire:model.live="locality"
                        wire:key="modal-locality-{{ $countryIso ?? 'none' }}-{{ $admin1Code ?? 'none' }}"
                        label="{{ __('Locality') }}"
                        :hint="$localityIsAuto ? __('(from postcode)') : null"
                        placeholder="{{ __('City / town') }}"
                        :options="$localityOptions"
                        :editable="true"
                        search-url="{{ route('admin.addresses.cities.search') }}?country={{ $countryIso ?? '' }}&admin1={{ $admin1Code ?? '' }}"
                        :error="$errors->first('locality')"
                    />
                </div>

                @if($addressFormId === null)
                <div class="border-t border-border-default pt-4">
                    <h4 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-3">{{ __('Link Settings') }}</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <span class="block text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Kind') }}</span>
                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                @foreach(['headquarters', 'billing', 'shipping', 'branch', 'other'] as $kindOption)
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="checkbox" value="{{ $kindOption }}" wire:model="kind" class="rounded border-border-input accent-accent focus:ring-accent" />
                                        {{ __(ucfirst($kindOption)) }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <x-ui.input id="company-address-priority" wire:model="priority" label="{{ __('Priority') }}" type="number" />
                            <p class="text-xs text-muted mt-1">{{ __('Lower number = higher priority. Used to order addresses of the same kind (0 = top).') }}</p>
                        </div>
                    </div>
                    <div class="mt-3">
                        <x-ui.checkbox id="company-address-is-primary" wire:model="isPrimary" label="{{ __('Primary Address') }}" />
                    </div>
                </div>
                @endif

                <div class="flex items-center gap-4 pt-2">
                    <x-ui.button variant="primary" wire:click="saveAddress">
                        {{ $addressFormId === null ? __('Create & Attach') : __('Save') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showAddressModal', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
